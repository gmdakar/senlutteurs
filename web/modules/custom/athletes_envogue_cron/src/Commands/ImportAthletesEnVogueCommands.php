<?php

namespace Drupal\athletes_envogue_cron\Commands;

use Drush\Commands\DrushCommands;
use Drush\Attributes\Command;
use Drush\Attributes\Argument;
use Drush\Attributes\Option;
use Drupal\misc_divers\Commands\CommandsBase;
/**
 * Drush commands for Import Athletes En Vogue.
 */
class ImportAthletesEnVogueCommands extends CommandsBase {

  protected $queueWorkerManager;
  protected $queueFactory;
  protected $athleteService;
  protected $entityTypeManager;

  public function __construct() {
    parent::__construct();
    $this->queueWorkerManager = \Drupal::service('plugin.manager.queue_worker');
    $this->queueFactory = \Drupal::service('queue');
    $this->athleteService = \Drupal::service('athletes_envogue_cron.athletes_service');
    $this->entityTypeManager = \Drupal::service('entity_type.manager');
  }

  /**
  * Import athletes en vogue.
  * (ex. drush avi)
  */
  #[Command(name: 'athletes_envogue:import', aliases: ['avi'])]
  public function ImportAthletesEnVogue() {

    //check granularity authorization cron execution
    if (!$this->isScheduledNow('athletes_envogue:import')) {
      return; 
    }

    $athletes = $this->athleteService->fetchAthletesEnVogue();
    $athletes = array_reverse($athletes);

    foreach ($athletes as $athlete) {
        if (!is_array($athlete)) {
            continue;
        }

        $lutteur_name = $athlete['nom'] ?? null;
        if (!$lutteur_name) {
            continue;
        }

        if ($this->athleteService->athleteExists($lutteur_name)) {
            $this->athleteService->enqueueAthlete($athlete);
            $this->logger->debug('<pre>@athlete enqueue</pre>', ['@athlete' => print_r($athlete, TRUE)]);
        }
    }

    $this->io()->success('Athletes en vogue import executed successfully.');
  }
}
