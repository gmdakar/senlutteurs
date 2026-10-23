<?php

namespace Drupal\gemini_nextcombats_cron\Commands;

use Drush\Commands\DrushCommands;
use Drush\Attributes\Command;
use Drush\Attributes\Argument;
use Drush\Attributes\Option;
use Drupal\misc_divers\Commands\CommandsBase;

/**
 * Drush commands for Import Videos of Combats next.
 */
class ImportCombatsNextCommands extends CommandsBase
{

  protected $queueWorkerManager;
  protected $queueFactory;
  protected $combatService;
  protected $entityTypeManager;

  public function __construct()
  {
    parent::__construct();
    $this->queueWorkerManager = \Drupal::service('plugin.manager.queue_worker');
    $this->queueFactory       = \Drupal::service('queue');
    $this->combatService      = \Drupal::service('gemini_nextcombats_cron.combat_service');
    $this->entityTypeManager  = \Drupal::service('entity_type.manager');
  }

  /**
   * Import fights next combats.
   * (ex. drush cni)
   */
  #[Command(name: 'combats_next:import', aliases: ['cni'])]
  #[Option(name: 'force', description: 'Force l\'exécution en ignorant la planification cron.')]
  #[Usage(name: 'drush cni --force', description: 'Force l\'import immédiat, hors fenêtre de planification')]
  public function ImportFightsNext($options = ['force' => FALSE])
  {

    if (!$options['force'] && !$this->isScheduledNow('combats_next:import')) {
      $this->io()->note('Hors fenêtre de planification. Utilisez --force pour exécuter immédiatement.');
      return;
    }

    $maxRetries = 3;
    $retries    = 0;
    $combats    = [];

    do {
      $combats = $this->combatService->fetchCombats();

      // ──────────────────────────────────────────────────────────────
      // SIMULATION : combat à venir en dur (Balla Gaye 1 vs Ada Fass 2),
      // à la place d'un appel réel à fetchCombats().
      // ──────────────────────────────────────────────────────────────
      /*$combats = [
        [
          'lutteur1'     => 'Balla Gaye 1',
          'lutteur2'     => 'Ada Fass 2',
          'date_precise' => date('Y-m-d', strtotime('+1 month')),
        ],
      ];*/

      if (!empty($combats)) break;
      $retries++;
      if ($retries < $maxRetries) {
        $this->io()->warning("Tentative $retries/$maxRetries vide — attente 60s...");
        sleep(60);
      }
    } while ($retries < $maxRetries);

    if (empty($combats)) {
      $this->io()->warning('Aucun combat trouvé après ' . $maxRetries . ' tentative(s).');
      return;
    }

    // Filtrage : lutteur inconnu ou placeholder YouTube
    $combats = array_filter($combats, function ($combat) {
      if (($combat['lutteur1'] ?? '?') === '?' || ($combat['lutteur2'] ?? '?') === '?') {
        return false;
      }
      return true;
    });

    foreach ($combats as $combat) {
      if (!empty($combat['date_precise'])) {
        $this->combatService->enqueueCombat($combat);
        $this->logger()->notice('Import next fight {data}', [
          'data' => print_r($combat, TRUE),
        ]);
      }
    }

    $this->io()->success('Next combats import executed successfully.');
  }
}
