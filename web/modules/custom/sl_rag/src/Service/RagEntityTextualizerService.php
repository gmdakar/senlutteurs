<?php

namespace Drupal\sl_rag\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Transforme les entités Drupal structurées (athletes) en documents
 * textuels riches + métadonnées filtrables pour le RAG / injection de prompt.
 */
class RagEntityTextualizerService
{

  const DEFAULT_RECENT_COMBATS = 12;

  protected EntityTypeManagerInterface $entityTypeManager;
  protected LoggerInterface $logger;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LoggerInterface $logger
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $logger;
  }

  /**
   * Textualise un nœud athlete.
   *
   * @return array{id: string, text: string, metadata: array}|null
   */
  public function textualizeAthlete(NodeInterface $athlete, int $recentCombatsLimit = self::DEFAULT_RECENT_COMBATS): ?array
  {
    if ($athlete->bundle() !== 'athlete') {
      return null;
    }

    $nid = (int) $athlete->id();
    $nom = trim((string) $athlete->getTitle());
    $nomTechnique = $athlete->hasField('field_athlete_nom_technique') && !$athlete->get('field_athlete_nom_technique')->isEmpty()
      ? trim((string) $athlete->get('field_athlete_nom_technique')->value)
      : $nom;

    $ecurieName = '';
    $ecurieId = null;
    if ($athlete->hasField('field_ecurie') && !$athlete->get('field_ecurie')->isEmpty()) {
      $ecurieNode = $athlete->get('field_ecurie')->entity;
      if ($ecurieNode) {
        $ecurieName = (string) $ecurieNode->getTitle();
        $ecurieId = (int) $ecurieNode->id();
      }
    }

    $isKing = $athlete->hasField('field_king') && (int) $athlete->get('field_king')->value === 1;

    // ELO : module elo_ranking
    // - score : champ node "elo_rating" (sync depuis table elo_rating)
    // - rang  : champ "field_elo_ranking" (uniquement top 100)
    $eloScore = null;
    $eloRank = null;
    foreach (['elo_rating', 'field_elo', 'field_elo_score', 'field_classement_elo', 'field_points_elo'] as $eloField) {
      if ($athlete->hasField($eloField) && !$athlete->get($eloField)->isEmpty()) {
        $eloScore = (float) $athlete->get($eloField)->value;
        break;
      }
    }
    // Fallback table technique elo_rating (wrestler_id, elo)
    if ($eloScore === null) {
      try {
        $db = \Drupal::database();
        if ($db->schema()->tableExists('elo_rating')) {
          $val = $db->select('elo_rating', 'e')
            ->fields('e', ['elo'])
            ->condition('wrestler_id', $nid)
            ->execute()
            ->fetchField();
          if ($val !== FALSE && $val !== NULL) {
            $eloScore = (float) $val;
          }
        }
      }
      catch (\Throwable $e) {
        // ignore
      }
    }
    foreach (['field_elo_ranking', 'field_elo_rank', 'field_rang_elo', 'field_classement'] as $rankField) {
      if ($athlete->hasField($rankField) && !$athlete->get($rankField)->isEmpty()) {
        $eloRank = (int) $athlete->get($rankField)->value;
        break;
      }
    }

    $stats = $this->computeAthleteStats($athlete, $recentCombatsLimit);

    $lines = [];
    $lines[] = '[FICHE LUTTEUR]';
    $lines[] = 'Nom / Alias : ' . $nom;
    if ($nomTechnique !== '' && $nomTechnique !== $nom) {
      $lines[] = 'Nom technique : ' . $nomTechnique;
    }
    $lines[] = 'Écurie : ' . ($ecurieName !== '' ? $ecurieName : 'Inconnue');
    $lines[] = 'Statut : ' . ($isKing ? 'Roi des arènes (actif)' : 'Actif');
    if ($eloScore !== null) {
      if ($eloRank !== null && $eloRank >= 1 && $eloRank <= 100) {
        $lines[] = 'Classement ELO actuel : ' . round($eloScore) . ' points (Rang #' . $eloRank . ' du top 100).';
      }
      else {
        $lines[] = 'Classement ELO actuel : ' . round($eloScore) . ' points.';
      }
    }
    $lines[] = 'Palmarès global : ' . $stats['total'] . ' combats, '
      . $stats['victoires'] . ' victoire' . ($stats['victoires'] > 1 ? 's' : '') . ', '
      . $stats['defaites'] . ' défaite' . ($stats['defaites'] > 1 ? 's' : '') . ', '
      . $stats['sans_decision'] . ' sans décision.';
    if ($stats['periode_debut'] || $stats['periode_fin']) {
      $debut = $stats['periode_debut'] ?: '?';
      $fin = $stats['periode_fin'] ?: "aujourd'hui";
      $lines[] = "Période d'activité : $debut à $fin.";
    }
    $lines[] = '';

    if (!empty($stats['recent_combats'])) {
      $lines[] = '[HISTORIQUE ET COMBATS RECENTS]';
      foreach ($stats['recent_combats'] as $c) {
        $resultLabel = $this->formatResultLabel($c['resultat'], $nom, $c['adversaire']);
        $dateStr = $c['date'] ? $this->formatDateFr($c['date']) : 'date inconnue';
        $lines[] = '- ' . $resultLabel . ' contre ' . ($c['adversaire'] ?: 'adversaire inconnu') . ' le ' . $dateStr . '.';
      }
      $lines[] = '';
    }

    $lines[] = '[STATISTIQUES CLES]';
    $taux = $stats['total'] > 0 ? round(($stats['victoires'] / $stats['total']) * 100, 1) : 0;
    $lines[] = 'Taux de victoire : ' . $taux . '%.';
    if ($stats['victoires'] > 0 && $stats['defaites'] === 0 && $stats['sans_decision'] === 0) {
      $lines[] = 'Invaincu.';
    }
    if ($isKing) {
      $lines[] = 'Actuellement Roi des arènes.';
    }
    if ($eloScore !== null) {
      if ($eloRank !== null && $eloRank >= 1 && $eloRank <= 100) {
        $lines[] = 'Classement ELO : ' . round($eloScore) . ' points, rang #' . $eloRank . ' (top 100).';
      }
      else {
        $lines[] = 'Classement ELO : ' . round($eloScore) . ' points (hors top 100 ou rang non publié).';
      }
    }
    if ($ecurieName !== '') {
      $lines[] = 'Membre de l\'écurie ' . $ecurieName . '.';
    }

    $text = implode("\n", $lines);

    $metadata = [
      'drupal_id'     => $nid,
      'entity_type'   => 'athlete',
      'nom'           => $nom,
      'nom_technique' => $nomTechnique,
      'ecurie'        => $ecurieName !== '' ? $ecurieName : null,
      'ecurie_id'     => $ecurieId,
      'is_king'       => $isKing,
      'victoires'     => $stats['victoires'],
      'defaites'      => $stats['defaites'],
      'sans_decision' => $stats['sans_decision'],
      'total_combats' => $stats['total'],
      'taux_victoire' => $taux,
      'elo_score'     => $eloScore,
      'elo_rank'      => $eloRank,
      'periode_debut' => $stats['periode_debut'],
      'periode_fin'   => $stats['periode_fin'],
    ];

    return [
      'id'       => 'athlete_' . $nid,
      'text'     => $text,
      'metadata' => $metadata,
    ];
  }

  /**
   * Exporte un ensemble d'athletes.
   *
   * @param array $options
   *   limit, nids, recent_combats, order
   *
   * @return array<int, array{id: string, text: string, metadata: array}>
   */
  public function exportAthletes(array $options = []): array
  {
    $limit = array_key_exists('limit', $options) ? $options['limit'] : null;
    $nids = $options['nids'] ?? [];
    $recentCombats = (int) ($options['recent_combats'] ?? self::DEFAULT_RECENT_COMBATS);
    $order = $options['order'] ?? 'nid';

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'athlete')
      ->condition('status', 1);

    if (!empty($nids)) {
      $query->condition('nid', $nids, 'IN');
    }

    if ($order === 'title') {
      $query->sort('title', 'ASC');
    }
    else {
      $query->sort('nid', 'ASC');
    }

    if ($limit !== null && $limit > 0 && empty($nids)) {
      $query->range(0, (int) $limit);
    }

    $foundNids = $query->execute();
    if (empty($foundNids)) {
      $this->logger->notice('RagEntityTextualizer: aucun athlete trouvé.');
      return [];
    }

    $documents = [];
    foreach ($storage->loadMultiple($foundNids) as $node) {
      $doc = $this->textualizeAthlete($node, $recentCombats);
      if ($doc !== null) {
        $documents[] = $doc;
      }
    }

    $this->logger->info('RagEntityTextualizer: @count document(s) généré(s).', ['@count' => count($documents)]);
    return $documents;
  }

  /**
   * Calcule stats + combats récents.
   */
  public function computeAthleteStats(NodeInterface $athlete, int $recentLimit = self::DEFAULT_RECENT_COMBATS): array
  {
    $athleteId = (int) $athlete->id();
    $athleteName = trim((string) $athlete->getTitle());
    $storage = $this->entityTypeManager->getStorage('node');

    $query = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'confrontation');

    $orGroup = $query->orConditionGroup()
      ->condition('field_athlete_1', $athleteId)
      ->condition('field_athlete_2', $athleteId);

    $allNids = $query->condition($orGroup)
      ->sort('field_confrontation_date', 'DESC')
      ->execute();

    $total = 0;
    $wins = 0;
    $losses = 0;
    $noDecision = 0;
    $dates = [];
    $recent = [];

    if (!empty($allNids)) {
      foreach ($storage->loadMultiple($allNids) as $fight) {
        $total++;
        $date = $fight->hasField('field_confrontation_date') ? $fight->get('field_confrontation_date')->value : null;
        if ($date) {
          $dates[] = $date;
        }

        $a1 = $fight->hasField('field_athlete_1') ? $fight->get('field_athlete_1')->entity : null;
        $a2 = $fight->hasField('field_athlete_2') ? $fight->get('field_athlete_2')->entity : null;
        $isA1 = $a1 && (int) $a1->id() === $athleteId;
        $opponent = $isA1 ? $a2 : $a1;
        $opponentName = $opponent ? (string) $opponent->label() : '';

        $vainqueur = $fight->hasField('field_vainqueur') ? trim((string) $fight->get('field_vainqueur')->value) : '';
        $resultCategory = $this->categorizeResult($vainqueur, $athleteName, $opponentName);

        if ($resultCategory === 'win') {
          $wins++;
        }
        elseif ($resultCategory === 'loss') {
          $losses++;
        }
        else {
          $noDecision++;
        }

        if (count($recent) < $recentLimit) {
          $recent[] = [
            'date'       => $date,
            'adversaire' => $opponentName,
            'resultat'   => $vainqueur,
          ];
        }
      }
    }

    sort($dates);
    $periodeDebut = null;
    $periodeFin = null;
    if (!empty($dates)) {
      $periodeDebut = substr($dates[0], 0, 4);
      $periodeFin = substr(end($dates), 0, 4);
      $lastTs = strtotime(end($dates));
      if ($lastTs && (time() - $lastTs) < (18 * 30 * 24 * 3600)) {
        $periodeFin = null;
      }
    }

    return [
      'total'          => $total,
      'victoires'      => $wins,
      'defaites'       => $losses,
      'sans_decision'  => $noDecision,
      'periode_debut'  => $periodeDebut,
      'periode_fin'    => $periodeFin,
      'recent_combats' => $recent,
    ];
  }

  protected function categorizeResult(string $vainqueur, string $athleteName, string $opponentName): string
  {
    $v = mb_strtolower(trim($vainqueur));
    if ($v === '' || $v === '?' || $v === 'inconnu' || $v === 'n/a' || $v === 'sans decision' || $v === 'sans décision') {
      return 'unknown';
    }

    $athleteLower = mb_strtolower($athleteName);
    $opponentLower = mb_strtolower($opponentName);

    if ($v === $athleteLower || str_contains($v, $athleteLower) || str_contains($athleteLower, $v)) {
      return 'win';
    }
    if ($opponentLower !== '' && ($v === $opponentLower || str_contains($v, $opponentLower) || str_contains($opponentLower, $v))) {
      return 'loss';
    }

    foreach (preg_split('/\s+/', $athleteLower) as $tok) {
      if (mb_strlen($tok) >= 3 && str_contains($v, $tok)) {
        return 'win';
      }
    }
    foreach (preg_split('/\s+/', $opponentLower) as $tok) {
      if (mb_strlen($tok) >= 3 && str_contains($v, $tok)) {
        return 'loss';
      }
    }

    return 'unknown';
  }

  protected function formatResultLabel(?string $resultat, string $athleteName, string $opponentName): string
  {
    $cat = $this->categorizeResult((string) $resultat, $athleteName, $opponentName);
    return match ($cat) {
      'win'  => 'Victoire',
      'loss' => 'Défaite',
      default => 'Résultat indéterminé' . ($resultat ? " ($resultat)" : ''),
    };
  }

  protected function formatDateFr(?string $isoDate): string
  {
    if (!$isoDate) {
      return 'date inconnue';
    }
    $ts = strtotime($isoDate);
    return $ts === false ? $isoDate : date('d/m/Y', $ts);
  }

}
