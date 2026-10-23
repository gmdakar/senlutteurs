<?php

namespace Drupal\elo_ranking\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\State\StateInterface;
use Drupal\shared_lib\Tools\SomeTools;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gère le calcul ELO haute performance pour senlutteurs.com.
 * Version Finale : Fix PHP 8.1, Synchronisation Temps Réel et Gestion des Rois.
 */
class EloManager
{

  /**
   * Cache local des scores pour la session actuelle (PHP static-like).
   */
  private array $eloCache = [];

  /**
   * Map des règnes de rois : [athlete_id => [['debut' => ISO, 'fin' => ISO]]].
   */
  private array $roiMap = [];

  /**
   * Injection de dépendances via promotion de propriété (PHP 8.2).
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly StateInterface $state,
  ) {}

  /**
   * Reconstruit l'intégralité du classement (via Drush).
   * Exclut les combats de type "Lutte Simple".
   */
  public function rebuildAll(?SymfonyStyle $io = NULL): void
  {
    $start_time = microtime(TRUE);
    $this->state->set('elo_rebuild_in_progress', TRUE);

    if (PHP_SAPI === 'cli') {
      ini_set('memory_limit', '2G');
      set_time_limit(0);
    }

    $transaction = $this->database->startTransaction();

    if ($io) {
      $io->title('Rebuild Global ELO - senlutteurs.com');
    }

    try {
      // Nettoyage des tables
      $this->database->truncate('elo_rating')->execute();
      $this->database->truncate('elo_history')->execute();
      $this->eloCache = [];

      $this->preloadRoiMap();

      // IMPORTANT : un seul enregistrement par confrontation.
      // Sans filtre de langue, chaque jointure sur les tables de champs ou sur
      // node_field_data renvoie une ligne PAR TRADUCTION, et le même combat
      // était alors appliqué N fois (N = nombre de langues) au calcul ELO.
      $query = $this->database->select('node_field_data', 'nc');
      $query->condition('nc.default_langcode', 1);
      $query->condition('nc.type', 'confrontation');
      // Seuls les combats publiés comptent (comme le palmares affiché sur le site).
      $query->condition('nc.status', 1);

      $query->join('node__field_athlete_1', 'a1', 'a1.entity_id = nc.nid AND a1.langcode = nc.langcode AND a1.deleted = 0 AND a1.delta = 0');
      $query->join('node__field_athlete_2', 'a2', 'a2.entity_id = nc.nid AND a2.langcode = nc.langcode AND a2.deleted = 0 AND a2.delta = 0');
      $query->join('node__field_confrontation_date', 'd', 'd.entity_id = nc.nid AND d.langcode = nc.langcode AND d.deleted = 0 AND d.delta = 0');
      $query->join('node__field_vainqueur', 'v', 'v.entity_id = nc.nid AND v.langcode = nc.langcode AND v.deleted = 0 AND v.delta = 0');
      $query->join('node_field_data', 'n1', 'n1.nid = a1.field_athlete_1_target_id AND n1.default_langcode = 1');

      // Jointure pour le champ "Lutte Simple" (même langue que le combat).
      $query->leftJoin('node__field_is_lutte_simple', 'ls', 'ls.entity_id = nc.nid AND ls.langcode = nc.langcode AND ls.deleted = 0 AND ls.delta = 0');

      // Filtrage (On garde valeur 0 ou NULL)
      $or_group = $query->orConditionGroup()
        ->condition('ls.field_is_lutte_simple_value', 0)
        ->isNull('ls.field_is_lutte_simple_value');
      $query->condition($or_group);

      $query->fields('a1', ['field_athlete_1_target_id', 'entity_id']);
      $query->fields('a2', ['field_athlete_2_target_id']);
      $query->fields('d', ['field_confrontation_date_value']);
      $query->fields('v', ['field_vainqueur_value']);
      $query->fields('n1', ['title']);
      $query->orderBy('d.field_confrontation_date_value', 'ASC');
      $query->orderBy('nc.nid', 'ASC');

      $results = $query->execute()->fetchAll();
      $total = count($results);

      if ($io) {
        $io->writeln("Traitement de $total combats (Lutte avec Frappe uniquement)...");
        $io->progressStart($total);
      }

      $seen = [];
      foreach ($results as $fight) {
        // Garde-fou : une confrontation n'est traitée qu'une seule fois.
        if (isset($seen[$fight->entity_id])) {
          if ($io) $io->progressAdvance();
          continue;
        }
        $seen[$fight->entity_id] = TRUE;
        $this->processFight($fight);
        if ($io) $io->progressAdvance();
      }

      $this->syncEloToNodes();
      $this->updateBestEloFlag();

      unset($transaction);
      if ($io) {
        $io->progressFinish();
        $this->triggerSearchApiReindex($io);
      }
    } catch (\Exception $e) {
      if (isset($transaction)) $transaction->rollBack();
      if ($io) $io->error('Erreur lors du rebuild : ' . $e->getMessage());
      throw $e;
    } finally {
      $this->state->delete('elo_rebuild_in_progress');
      // Invalidation correcte du cache de la liste des lutteurs
      Cache::invalidateTags(['node_list:athlete']);
    }
  }

  /**
   * Traite un combat individuel. sauf les luttes simples (sans frappe)
   * PUBLIC : Utilisé par Rebuild ET par les hooks du .module.
   */
  public function processFight($fight): void
  {

    // ✅ FIX: stdClass != Entity (plus de get())
    $is_lutte_simple = $fight->field_is_lutte_simple ?? 0;
    if ($is_lutte_simple) {
      return; // On ne lance pas le calcul ELO
    }

    $nid1 = $fight->field_athlete_1_target_id;
    $nid2 = $fight->field_athlete_2_target_id;
    $date = $fight->field_confrontation_date_value;
    $vainqueur_label = trim(strtolower($fight->field_vainqueur_value ?? ''));

    // Sécurité : Ignorer les combats sans résultat ou sans date
    if (!$date || in_array($vainqueur_label, ['sans verdict', '?', ''])) return;

    // S'assurer que les Rois sont chargés pour le calcul du K-Factor
    if (empty($this->roiMap)) {
      $this->preloadRoiMap();
    }

    $elo1 = $this->getRatingFromCache($nid1);
    $elo2 = $this->getRatingFromCache($nid2);

    $isRoi1 = $this->isRoiAtDate($nid1, $date);
    $isRoi2 = $this->isRoiAtDate($nid2, $date);

    $K = ($isRoi1 || $isRoi2) ? 48 : 32;

    $expected1 = 1 / (1 + pow(10, ($elo2 - $elo1) / 400));
    $expected2 = 1 - $expected1;

    if (strcasecmp($vainqueur_label, 'nul') === 0) {
      $score1 = $isRoi2 ? 0.55 : 0.5;
      $score2 = $isRoi1 ? 0.55 : 0.5;
    } else {
      $score1 = (SomeTools::technicalWrestlerName($vainqueur_label) == SomeTools::technicalWrestlerName($fight->title)) ? 1 : 0;
      $score2 = 1 - $score1;
    }

    $new1 = $elo1 + $K * ($score1 - $expected1);
    $new2 = $elo2 + $K * ($score2 - $expected2);

    $this->updateRatingInMemoryAndDb($nid1, $new1);
    $this->updateRatingInMemoryAndDb($nid2, $new2);

    foreach ([$nid1 => [$elo1, $new1], $nid2 => [$elo2, $new2]] as $id => $vals) {
      $this->database->merge('elo_history')
        ->key('wrestler_id', $id)
        ->key('confrontation_id', $fight->entity_id)
        ->fields([
          // Valeurs arrondies : identiques à elo_rating et au calcul suivant.
          'elo_before' => (int) round($vals[0]),
          'elo_after'  => (int) round($vals[1]),
          'delta'      => (int) round($vals[1]) - (int) round($vals[0]),
          'fight_date' => $date,
        ])->execute();
    }
  }

  /**
   * Récupère le score actuel (depuis le cache PHP ou la DB).
   */
  private function getRatingFromCache($nid): float
  {
    if (!isset($this->eloCache[$nid])) {
      $val = $this->database->select('elo_rating', 'e')
        ->fields('e', ['elo'])
        ->condition('wrestler_id', $nid)
        ->execute()->fetchField();
      $this->eloCache[$nid] = $val ?: 1000.0;
    }
    return (float) $this->eloCache[$nid];
  }

  private function updateRatingInMemoryAndDb($nid, $elo): void
  {
    $rounded = (int)round($elo);
    $this->eloCache[$nid] = $rounded;
    $this->database->merge('elo_rating')->key('wrestler_id', $nid)->fields(['elo' => $rounded])->execute();
  }

  /**
   * Synchronise les scores ET les rangs vers les nodes, RESET LE CACHE.
   * PUBLIC : Appelé après processFight pour impacter l'affichage Drupal.
   */
  public function syncEloToNodes(): void
  {
    if (empty($this->eloCache)) return;

    $node_storage = $this->entityTypeManager->getStorage('node');
    $tags = ['node_list:athlete'];

    // --- Calcul des rangs en mémoire, sans requête par athlète ---
    // Tri décroissant par ELO ; gestion des ex-aequo (même rang).
    $sorted = $this->eloCache;
    arsort($sorted); // conserve nid => elo, trié par elo desc

    $ranks = [];
    $rank = 0;
    $previous_elo = null;
    $position = 0;

    foreach ($sorted as $nid => $elo) {
      $position++;
      if ($elo !== $previous_elo) {
        $rank = $position; // rang "1224" (skip standard competition ranking)
        $previous_elo = $elo;
      }
      $ranks[$nid] = $rank;
    }

    foreach ($this->eloCache as $nid => $elo) {
      $vid = $this->database->select('node_field_data', 'n')
        ->fields('n', ['vid'])
        ->condition('nid', $nid)
        ->orderBy('default_langcode', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if (!$vid) {
        continue;
      }

      // PK node__elo_rating = (entity_id, deleted, delta, langcode).
      // merge() only on entity_id breaks once multi-lang rows exist.
      $this->database->update('node__elo_rating')
        ->fields([
          'revision_id' => $vid,
          'elo_rating_value' => $elo,
        ])
        ->condition('entity_id', $nid)
        ->condition('deleted', 0)
        ->execute();

      $langcodes = $this->database->select('node_field_data', 'n')
        ->fields('n', ['langcode'])
        ->condition('nid', $nid)
        ->execute()
        ->fetchCol();
      if (!$langcodes) {
        $langcodes = ['fr'];
      }
      foreach (array_unique($langcodes) as $langcode) {
        $this->database->merge('node__elo_rating')
          ->keys([
            'entity_id' => $nid,
            'deleted' => 0,
            'delta' => 0,
            'langcode' => $langcode,
          ])
          ->fields([
            'revision_id' => $vid,
            'elo_rating_value' => $elo,
            'bundle' => 'athlete',
          ])
          ->execute();
      }

      $rankValue = $ranks[$nid] ?? null;
      if ($rankValue !== null && $rankValue > 100) {
        $this->database->delete('node__field_elo_ranking')
          ->condition('entity_id', $nid)
          ->execute();
      }
      else {
        $this->database->update('node__field_elo_ranking')
          ->fields([
            'revision_id' => $vid,
            'field_elo_ranking_value' => $rankValue,
          ])
          ->condition('entity_id', $nid)
          ->condition('deleted', 0)
          ->execute();

        foreach (array_unique($langcodes) as $langcode) {
          $this->database->merge('node__field_elo_ranking')
            ->keys([
              'entity_id' => $nid,
              'deleted' => 0,
              'delta' => 0,
              'langcode' => $langcode,
            ])
            ->fields([
              'revision_id' => $vid,
              'field_elo_ranking_value' => $rankValue,
              'bundle' => 'athlete',
            ])
            ->execute();
        }
      }

      $tags[] = 'node:' . $nid;
    }

    // CRUCIAL : Force Drupal à vider le cache interne pour ces IDs
    $node_storage->resetCache(array_keys($this->eloCache));
    Cache::invalidateTags($tags);
  }

  /**
   * Remet à NULL toutes les valeurs de field_elo_ranking déjà en base qui
   * dépassent la limite d'affichage (par défaut 100). Utile après coup pour
   * corriger les valeurs écrites avant la mise en place du plafond dans
   * syncEloToNodes().
   *
   * @param int $limit
   *   Rang maximal autorisé (inclus) pour que la valeur reste renseignée.
   *
   * @return int
   *   Nombre de nodes mis à jour.
   */
  public function resetRankingOverLimit(int $limit = 100): int
  {
    $nids = $this->database->select('node__field_elo_ranking', 'r')
      ->fields('r', ['entity_id'])
      ->condition('field_elo_ranking_value', $limit, '>')
      ->execute()
      ->fetchCol();

    if (empty($nids)) {
      return 0;
    }

    // Le champ field_elo_ranking_value est NOT NULL en base : on ne peut pas
    // y écrire NULL, on supprime donc la ligne (équivalent à un champ vide
    // pour Drupal).
    $this->database->delete('node__field_elo_ranking')
      ->condition('field_elo_ranking_value', $limit, '>')
      ->execute();

    // Si la révision courante est stockée séparément (table de révision du
    // champ), on l'aligne également.
    if ($this->database->schema()->tableExists('node_revision__field_elo_ranking')) {
      $this->database->delete('node_revision__field_elo_ranking')
        ->condition('field_elo_ranking_value', $limit, '>')
        ->execute();
    }

    $this->entityTypeManager->getStorage('node')->resetCache($nids);
    Cache::invalidateTags(array_merge(['node_list:athlete'], array_map(fn($nid) => 'node:' . $nid, $nids)));

    return count($nids);
  }

  private function preloadRoiMap(): void
  {
    $query = $this->database->select('node__field_athlete', 'a');
    $query->join('node_field_data', 'n', 'n.nid = a.entity_id');
    $query->join('node__field_date_debut', 'd', 'd.entity_id = a.entity_id');
    $query->leftJoin('node__field_date_fin', 'f', 'f.entity_id = a.entity_id');
    $query->fields('a', ['field_athlete_target_id'])->fields('d', ['field_date_debut_value'])->fields('f', ['field_date_fin_value']);
    $query->condition('n.type', 'roi_des_arenes')->condition('n.status', 1);

    foreach ($query->execute() as $row) {
      $this->roiMap[$row->field_athlete_target_id][] = [
        'debut' => $row->field_date_debut_value,
        'fin'   => $row->field_date_fin_value,
      ];
    }
  }

  /**
   * Vérifie si un lutteur est Roi à une date précise.
   * Sécurisé contre les valeurs null pour PHP 8.1+.
   */
  private function isRoiAtDate($wrestler_id, $date): bool
  {
    if (!$date || !isset($this->roiMap[$wrestler_id])) return false;

    $fDate = substr((string)$date, 0, 10);
    foreach ($this->roiMap[$wrestler_id] as $r) {
      if (empty($r['debut'])) continue;

      $deb = substr((string)$r['debut'], 0, 10);
      $fin = !empty($r['fin']) ? substr((string)$r['fin'], 0, 10) : NULL;

      if (($fDate >= $deb) && (!$fin || $fDate <= $fin)) return true;
    }
    return false;
  }

  private function triggerSearchApiReindex(?SymfonyStyle $io): void
  {
    $index_storage = $this->entityTypeManager->getStorage('search_api_index');

    foreach ($index_storage->loadMultiple() as $index) {
      if (!$index->status()) continue;

      if ($io) {
        $io->section("Réindexation Search API : " . $index->label());
      }

      // Marque tous les items comme "à réindexer"
      $index->reindex();

      // Récupération du nombre total d'items à traiter
      $remaining = $index->getTrackerInstance()->getRemainingItemsCount();

      if ($remaining > 0) {
        if ($io) {
          $io->progressStart($remaining);
        }

        $limit = 500; // On indexe par paquets pour éviter les Timeouts

        // Boucle d'indexation avec progression
        while ($index->getTrackerInstance()->getRemainingItemsCount() > 0) {
          $indexed = $index->indexItems($limit);
          if ($indexed <= 0) break; // Sécurité si rien n'est indexé

          if ($io) {
            $io->progressAdvance($indexed);
          }

          // Nettoyage de la mémoire cache de Drupal pour éviter les fuites
          \Drupal::service('entity.memory_cache')->deleteAll();
        }

        if ($io) {
          $io->progressFinish();
          $io->success("Index " . $index->label() . " synchronisé.");
        }
      }
    }
  }

  private function updateBestEloFlag(): void
  {
    if (empty($this->eloCache)) return;
    $max = max($this->eloCache);
    $this->database->update('node__field_best_elo')->fields(['field_best_elo_value' => 0])->execute();
    $ids = array_keys($this->eloCache, $max);
    if (!empty($ids)) {
      $this->database->update('node__field_best_elo')->fields(['field_best_elo_value' => 1])
        ->condition('entity_id', $ids, 'IN')->execute();
    }
  }
}
