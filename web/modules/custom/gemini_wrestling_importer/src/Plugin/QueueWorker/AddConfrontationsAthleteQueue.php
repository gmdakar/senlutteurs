<?php

namespace Drupal\gemini_wrestling_importer\Plugin\QueueWorker;

use Drupal\shared_lib\Plugin\QueueWorker\BaseCombatQueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Permet d'ajouter pour traitement via cron chaque athlète marqué comme ayant moins de N combats.
 * L'idée étant de faire une requête (gemini) pour chercher ses éventuels combats supplémentaires.
 * CE WORKER, vu qu'il n'a pas la sous-option cron 600, est ignoré par le cron à son déclenchement,
 * il sera donc 100 % manuel via drush command.
 *
 * @QueueWorker(
 *   id = "add_confrontations_athlete_queue",
 *   title = @Translation("Process Add new Confrontations for an Athlete LESS Queue"),
 * )
 */
class AddConfrontationsAthleteQueue extends BaseCombatQueueWorker implements ContainerFactoryPluginInterface {

  /**
   * Le service Gemini spécifique.
   */
  protected $geminiService;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    $configFactory,
    $httpClient,
    $logger,
    $entityTypeManager,
    $geminiService
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $configFactory,
      $httpClient,
      $logger,
      $entityTypeManager
    );
    $this->geminiService = $geminiService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('logger.channel.default'),
      $container->get('entity_type.manager'),
      $container->get('gemini_wrestling_importer.service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $config = \Drupal::config('misc_divers.settings');
    $seuil_max_nb_reset_cpt_lutteur = (int) ($config->get('seuil_max_nb_reset_cpt_lutteur') ?? 2);
    $seuil_cpt_to_complet_combats   = (int) ($config->get('seuil_cpt_to_complet_combats') ?? 5);

    $nid   = $data['nid'] ?? NULL;
    $cpt   = $data['cpt'] ?? 0;
    $force = !empty($data['force']); // TRUE si déclenché par field_search_combats.

    if (!$nid) {
      $this->logger->warning('AddConfrontationsAthleteQueue processItem ignoré : nid manquant dans $data.');
      return;
    }

    // Guard seuil : entièrement bypassé si force=true.
    if (!$force && $cpt < $seuil_cpt_to_complet_combats) {
      $node_for_log  = \Drupal\node\Entity\Node::load($nid);
      $title_for_log = $node_for_log ? $node_for_log->getTitle() : 'inconnu';

      $state = \Drupal::state();
      $state->set("reset_cpt_lutteur_" . $nid, 0);
      $state->set("cpt_lutteur_" . $nid, $cpt);

      $this->logger->info(
        'AddConfrontationsAthleteQueue : Seuil non atteint (@cpt/@seuil) pour "@title" (NID @nid). Compteurs réinitialisés. Item supprimé.',
        [
          '@cpt'   => $cpt,
          '@seuil' => $seuil_cpt_to_complet_combats,
          '@title' => $title_for_log,
          '@nid'   => $nid,
        ]
      );

      throw new \Drupal\gemini_wrestling_importer\Exception\ThresholdNotReachedException(
        "Seuil non atteint ($cpt/$seuil_cpt_to_complet_combats)"
      );
    }

    // Chargement du node pour les vérifications préalables.
    $node = \Drupal\node\Entity\Node::load($nid);
    if (!$node) {
      $this->logger->warning('AddConfrontationsAthleteQueue processItem ignoré : node introuvable pour nid=@nid.', [
        '@nid' => $nid,
      ]);
      throw new \Drupal\Core\Queue\RequeueException("Node $nid introuvable temporairement");
    }

    // Guard field_not_check : bypassé si force=true.
    if (!$force && $node->hasField('field_not_check') && (int) $node->get('field_not_check')->value === 1) {
      $this->logger->info(
        'AddConfrontationsAthleteQueue : "@title" (NID @nid) déjà marqué field_not_check, item ignoré.',
        ['@title' => $node->getTitle(), '@nid' => $nid]
      );
      return;
    }

    $wrestler_name = $node->getTitle();

    if ($force) {
      $this->logger->notice(
        'AddConfrontationsAthleteQueue [FORCE via field_search_combats] : "@title" (NID @nid) — guards seuil et field_not_check court-circuités.',
        ['@title' => $wrestler_name, '@nid' => $nid]
      );
    }

    $this->logger->info('AddConfrontationsAthleteQueue Début processItem pour "@name" (nid=@nid).', [
      '@name' => $wrestler_name,
      '@nid'  => $nid,
    ]);

    $mode   = \Drupal::config('gemini_wrestling_importer.settings')->get('wrestling_importer_use_diff') ? 'diff' : 'normal';
    $engine = \Drupal::config('gemini_wrestling_importer.settings')->get('wrestling_importer_use_llm') ?? 'grok';

    // Appel au service IA pour récupérer les combats.
    $result = $this->geminiService->getFightsFromAiRequest($wrestler_name, $engine, $mode);

    $this->logger->warning('AddConfrontationsAthleteQueue Résultat brut getFightsFromAiRequest pour "@name" : success=@s, skipped=@sk, invalid_json_empty=@sj, fights_count=@c.', [
      '@name' => $wrestler_name,
      '@s'    => $result['success'] ? 'true' : 'false',
      '@sk'   => !empty($result['skipped']) ? 'true' : 'false',
      '@sj'   => !empty($result['invalid_json_empty']) ? 'true' : 'false',
      '@c'    => isset($result['fights']) && is_array($result['fights']) ? count($result['fights']) : '0',
    ]);

    // Echec API.
    if (!$result['success']) {
      $error = $result['error'] ?? 'unknown';
      $this->logger->warning('AddConfrontationsAthleteQueue Echec API pour "@name" : @error — on requeue.', [
        '@name'  => $wrestler_name,
        '@error' => $error,
      ]);
      throw new \Drupal\Core\Queue\RequeueException("Echec API Gemini : $error");
    }

    // Import des combats si présents.
    $fights = $result['fights'] ?? [];
    if (!empty($fights)) {
      $this->logger->info('AddConfrontationsAthleteQueue @count combat(s) à importer pour "@name".', [
        '@name'  => $wrestler_name,
        '@count' => count($fights),
      ]);
      $this->geminiService->importFights($fights, $wrestler_name);
    }

    $this->logger->info('AddConfrontationsAthleteQueue Traitement terminé pour "@name". Gestion des compteurs...', [
      '@name' => $wrestler_name,
    ]);

    // GESTION DES COMPTEURS ET CYCLES.
    // Si force=true, on ne touche pas aux compteurs de cycle : l'item était
    // hors-cycle normal, on ne veut pas perturber l'état existant.
    if (!$force) {
      $state           = \Drupal::state();
      $reset_state_key = "reset_cpt_lutteur_" . $nid;
      $nb_resets       = $state->get($reset_state_key, 0) + 1;
      $state->set($reset_state_key, $nb_resets);
      $state->set("cpt_lutteur_" . $nid, 0);

      if ($nb_resets >= $seuil_max_nb_reset_cpt_lutteur) {
        // Rechargement du node APRÈS importFights pour éviter d'écraser
        // un objet périmé (getOrCreateAthleteLocked peut avoir re-sauvegardé ce node).
        $node = \Drupal\node\Entity\Node::load($nid);

        if ($node && $node->bundle() === 'athlete' && $node->hasField('field_not_check')) {
          $node->set('field_not_check', 1);
          $node->setNewRevision(FALSE);
          $node->save();

          $state->delete("cpt_lutteur_" . $nid);
          $state->delete($reset_state_key);

          $this->logger->info("AddConfrontationsAthleteQueue Compteur 'cpt_lutteur_@nid' supprimé du State car le seuil final est atteint.", [
            '@nid' => $nid,
          ]);

          $this->logger->notice("AddConfrontationsAthleteQueue Lutteur [NID:@nid] marqué 'field_not_check' définitivement (@n cycles).", [
            '@nid' => $nid,
            '@n'   => $seuil_max_nb_reset_cpt_lutteur,
          ]);
        }
      }
      else {
        $this->logger->info("AddConfrontationsAthleteQueue Cycle @nb_resets complété pour @name. Prochain cycle attendu.", [
          '@nb_resets' => $nb_resets,
          '@name'      => $wrestler_name,
        ]);
      }
    }
    else {
      $this->logger->info(
        'AddConfrontationsAthleteQueue [FORCE] : compteurs de cycle non modifiés pour "@name" (NID @nid).',
        ['@name' => $wrestler_name, '@nid' => $nid]
      );
    }
  }

}