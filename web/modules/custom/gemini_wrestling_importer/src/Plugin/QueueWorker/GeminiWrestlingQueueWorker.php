<?php

namespace Drupal\gemini_wrestling_importer\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\QueueWorkerInterface;
use Drupal\gemini_wrestling_importer\Batch;

/**
 * Processes wrestling fights import for each wrestler.
 *
 * @QueueWorker(
 *   id = "gemini_wrestling_importer_queue",
 *   title = @Translation("Gemini Wrestling Importer Queue Worker"),
 * )
 */
class GeminiWrestlingQueueWorker extends QueueWorkerBase implements QueueWorkerInterface {

  /**
   * {@inheritdoc}
   */
  public function processItem($data): mixed {
    $nid = $data['nid'] ?? NULL;
    if ($nid) {
      // Appel à ton Batch::importWrestlerFights directement.
      return Batch::importWrestlerFights($nid);
      //return Batch::importWrestlersForceUpdate($nid);
    }
  }
}
