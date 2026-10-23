<?php

namespace Drupal\confrontations_recentes\Commands;

use Drush\Commands\DrushCommands;
use Drush\Attributes\Command;
use Drush\Attributes\Argument;
use Drush\Attributes\Option;
use Drupal\misc_divers\Commands\CommandsBase;
use Drush\Attributes\Usage;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Drush commands for Import Confrontations récentes.
 */
class ConfrontationsRecentesCommands extends CommandsBase
{
  /**
   * La fabrique de configuration Drupal.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  protected $queueWorkerManager;
  protected $queueFactory;
  protected $combatService;
  protected $entityTypeManager;

  public function __construct()
  {
    parent::__construct();
    $this->queueWorkerManager = \Drupal::service('plugin.manager.queue_worker');
    $this->queueFactory = \Drupal::service('queue');
    $this->combatService = \Drupal::service('confrontations_recentes.combat_service');
    $this->entityTypeManager = \Drupal::service('entity_type.manager');
    $this->configFactory = \Drupal::service('config.factory');
  }

  /**
   * Import Confrontations Recentes.
   * (ex. drush cri)
   */
  #[Command(name: 'confrontations_recentes:import', aliases: ['cri'])]
  #[Option(name: 'force', description: 'Force l\'exécution en ignorant la planification cron.')]
  #[Usage(name: 'drush cri --force', description: 'Force l\'import immédiat, hors fenêtre de planification')]
  public function ConfrontationsRecentes($options = ['force' => FALSE])
  {

    //check granularity authorization cron execution
    if (!$options['force'] && !$this->isScheduledNow('confrontations_recentes:import')) {
      $this->io()->note('Hors fenêtre de planification. Utilisez --force pour exécuter immédiatement.');
      return;
    }

    $maxRetries = 3;
    $retries = 0;

    do {
      $combatsData = $this->combatService->fetchCombats();

      // ──────────────────────────────────────────────────────────────
      // SIMULATION : combat unique en dur (pour tester la vérification
      // IA anti-erreur de génération de nom, cf. verifyAndCorrectFighters()),
      // à la place d'un appel réel à fetchCombats().
      // ──────────────────────────────────────────────────────────────
      /*$combatsData = [
        'combats_lutte_recents' => [
          [
            'lutteur1'        => 'Alboury',
            'lutteur2'        => 'Bruce Lee',
            'date_precise'    => '2017-01-01',
            'resultat'        => '?',
            'face2face'       => '',
            'video_combat'    => '',
            'tendance'        => '',
            'is_lutte_simple' => FALSE,
          ],
        ],
      ];*/

      \Drupal::logger('confrontations_recentes')->debug('Try @retries / Data combats: @data', ['@retries' => $retries + 1, '@data' => print_r($combatsData, TRUE)]);

      // ──────────────────────────────────────────────────────────────
      // CORRECTION DU BUG : on extrait correctement la liste des combats
      // (parfois fetchCombats() retourne le wrapper complet, parfois seulement la liste)
      // ──────────────────────────────────────────────────────────────
      if (isset($combatsData['combats_lutte_recents']) && is_array($combatsData['combats_lutte_recents'])) {
        $combats = $combatsData['combats_lutte_recents'];
      } else {
        $combats = is_array($combatsData) ? $combatsData : [];
      }

      if (count($combats)) {
        foreach ($combats as $combat) {
          $this->combatService->enqueueCombat($combat);   // ← maintenant toujours un array
        }
        break;
      }

      $retries++;
      if ($retries < $maxRetries) {
        sleep(60); // Wait 1 minute before retrying
      }
    } while ($retries < $maxRetries);

    $this->io()->success('Confrontations Recentes import executed successfully.');
  }

  /**
   * Met à jour les combats aux résultats inconnus via requête IA.
   */
  #[Command(name: 'confrontations_recentes:update-unknown-results', aliases: ['crur'])]
  #[Option(name: 'force', description: 'Force l\'exécution en ignorant la planification cron.')]
  #[Option(name: 'enginef', description: 'Le moteur de recherche IA à utiliser (grok, gemini)')]
  #[Option(name: 'days', description: 'Fenêtre en nombre de jours pour filtrer les combats récents (défaut : 30).')]
  #[Usage(name: 'drush crur --enginef=grok --days=15', description: 'Met à jour les résultats inconnus avec le moteur Grok sur 15 jours')]
  public function updateUnknownResults($options = ['force' => FALSE, 'enginef' => NULL, 'days' => 30])
  {

    if (!$options['force'] && !$this->isScheduledNow('confrontations_recentes:update-unknown-results')) {
      $this->io()->note('Hors fenêtre de planification. Utilisez --force pour exécuter immédiatement.');
      return;
    }

    $config = $this->configFactory->get('request_ai.settings');

    // Détermination du moteur (option Drush OU config formulaire OU 'grok' par défaut)
    $engine_force = $options['enginef'] ?? $config->get('engine') ?? 'grok';
    $days = (int) ($options['days'] ?? 30);

    $this->io()->note(sprintf('Update Unknown Results : %s (fenêtre : %d jours)', $engine_force, $days));

    $result = $this->combatService->updateUnknownResultsFights($days, $engine_force); // on s'interesse au combats créé y a moins de $days jours

    $this->io()->success(sprintf(
      'Update Unknown Results : %d combat(s) vérifié(s), %d résultat(s), %d date(s), %d is_lutte_simple mis à jour.',
      $result['checked'],
      $result['updated'],
      $result['dates'] ?? 0,
      $result['lutte_simple'] ?? 0
    ));
  }

  /**
   * Liste les confrontations des N derniers jours impliquant un athlète avec field_a_surveiller = 1.
   *
   * Exemples :
   *   drush cras
   *   drush cras --days=30
   *   drush cras --days=90
   */
  #[Command(name: 'confrontations_recentes:list-a-surveiller', aliases: ['cras'])]
  #[Option(name: 'days', description: 'Nombre de jours à remonter (défaut: 7)')]
  #[Usage(name: 'drush cras --days=30', description: 'Liste les confrontations des 30 derniers jours')]
  public function listConfrontationsASurveiller($options = ['days' => 7])
  {
    $days = (int) $options['days'];

    $now       = new \DateTime('now', new \DateTimeZone('UTC'));
    $sinceDate = (clone $now)->modify("-{$days} days");

    $sinceDateStr = $sinceDate->format('Y-m-d');
    $nowDateStr   = $now->format('Y-m-d');

    /** @var \Drupal\Core\Entity\Sql\SqlContentEntityStorage $storage */
    $storage = $this->entityTypeManager->getStorage('node');

    // 1. Récupérer les nids des athlètes à surveiller.
    $athleteNids = $storage->getQuery()
      ->condition('type', 'athlete')
      ->condition('field_a_surveiller', 1)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($athleteNids)) {
      $this->io()->warning("Aucun athlète avec field_a_surveiller = 1 trouvé.");
      return;
    }

    $this->io()->note(count($athleteNids) . " athlète(s) à surveiller trouvé(s).");

    // 2. Groupe OR
    $orGroup = $storage->getQuery()
      ->accessCheck(FALSE)
      ->orConditionGroup()
      ->condition('field_athlete_1', $athleteNids, 'IN')
      ->condition('field_athlete_2', $athleteNids, 'IN');

    // 3. Requête principale
    $nids = $storage->getQuery()
      ->condition('type', 'confrontation')
      ->condition('field_confrontation_date', $sinceDateStr, '>=')
      ->condition('field_confrontation_date', $nowDateStr, '<=')
      ->condition($orGroup)
      ->accessCheck(FALSE)
      ->sort('field_confrontation_date', 'DESC')
      ->execute();

    if (empty($nids)) {
      $this->io()->note("Aucune confrontation trouvée sur les $days derniers jours impliquant un athlète surveillé.");
      return;
    }

    /** @var \Drupal\node\Entity\Node[] $nodes */
    $nodes = $storage->loadMultiple($nids);

    $this->io()->title(count($nodes) . " confrontation(s) trouvée(s) sur les $days derniers jours");

    $rows = [];
    foreach ($nodes as $node) {
      $date = $node->hasField('field_confrontation_date')
        ? $node->get('field_confrontation_date')->value
        : '';

      $rows[] = [
        'NID'   => $node->id(),
        'Date'  => $date,
        'Title' => $node->getTitle(),
      ];

      // Log pour suivi facile dans /admin/reports/dblog
      \Drupal::logger('confrontations_recentes')->info(
        'SUSPECT combat (@days derniers jours) : @title (nid @nid, @date)',
        [
          '@days'  => $days,
          '@title' => $node->getTitle(),
          '@nid'   => $node->id(),
          '@date'  => $date,
        ]
      );
    }

    $this->io()->table(['NID', 'Date', 'Title'], $rows);
  }
}
