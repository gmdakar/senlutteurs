<?php

namespace Drupal\gemini_nextcombats_cron\Commands;

use Drush\Commands\DrushCommands;
use Drush\Attributes\Command;
use Drush\Attributes\Argument;
use Drush\Attributes\Option;
use Drupal\misc_divers\Commands\CommandsBase;

/**
 * Drush commands for Updating Videos of Combats next.
 */
class UpdateVideosCombatsNextCommands extends CommandsBase {

  protected $queueWorkerManager;
  protected $queueFactory;
  protected $combatService;
  protected $entityTypeManager;

  public function __construct() {
    parent::__construct();
    $this->queueWorkerManager = \Drupal::service('plugin.manager.queue_worker');
    $this->queueFactory = \Drupal::service('queue');
    $this->combatService = \Drupal::service('gemini_nextcombats_cron.combat_service');
    $this->entityTypeManager = \Drupal::service('entity_type.manager');
  }

  /**
  * Update fights next combat face 2 face videos.
  * (ex. drush cnuv)
  */
  #[Command(name: 'combats_next:update_videos', aliases: ['cnuv'])]
  public function updateVideoFightsNext() {
    
    //check granularity authorization cron execution
    if (!$this->isScheduledNow('combats_next:update_videos')) {
      return; 
    }
    
    $this->io()->title("Starting update: combats_next:update_videos");
    
    $combats = $this->combatService->updateVideosCombatsNext();
    
    $this->logger()->notice('Du : {data}', [
      'data' => print_r($combats, TRUE),
    ]);

    $this->io()->success('Update process completed.');
  }
}
