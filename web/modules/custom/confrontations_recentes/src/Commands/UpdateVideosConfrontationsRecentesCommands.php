<?php

namespace Drupal\confrontations_recentes\Commands;

use Drush\Commands\DrushCommands;
use Drush\Attributes\Command;
use Drush\Attributes\Argument;
use Drush\Attributes\Option;
use Drupal\misc_divers\Commands\CommandsBase;

/**
 * Drush commands for Updating Videos of Confrontations Recentes.
 */
class UpdateVideosConfrontationsRecentesCommands extends CommandsBase {

  protected $queueWorkerManager;
  protected $queueFactory;
  protected $combatService;
  protected $entityTypeManager;

  public function __construct() {
    parent::__construct();
    $this->queueWorkerManager = \Drupal::service('plugin.manager.queue_worker');
    $this->queueFactory = \Drupal::service('queue');
    $this->combatService = \Drupal::service('confrontations_recentes.combat_service');
    $this->entityTypeManager = \Drupal::service('entity_type.manager');
  }

  /**
   * Update fights confrontations récentes videos, les plus récents (date de creation)
   */
  #[Command(name: 'confrontations_recentes:update_videos', aliases: ['cruv'])]
  // On définit l'option 'top' (ex. drush cruv --top 10)
  #[Option(name: 'top', description: 'Number of fights to update.')]
  public function updateVideoFightsRecents(array $options = ['top' => 10]) {
    //check granularity authorization cron execution
    if (!$this->isScheduledNow('confrontations_recentes:update_videos')) {
      return; 
    }
  
    // On récupère la valeur dans le tableau $options
    $limit = (int) ($options['top'] ?? 10);

    $this->io()->title("Starting upconfrontations_recentes:update_videos : Latest $limit fights");
    
    //les plus récents (date de creation)
    $combats = $this->combatService->updateVideosConfrontations($limit);
    
    $this->logger()->notice('Du : {data}', [
      'data' => print_r($combats, TRUE),
    ]);

    $this->io()->success('Update process completed.');
  }
   
}
