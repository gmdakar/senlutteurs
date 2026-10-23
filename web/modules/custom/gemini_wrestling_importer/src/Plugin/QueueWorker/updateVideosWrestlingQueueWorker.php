<?php

namespace Drupal\gemini_wrestling_importer\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\QueueWorkerInterface;
use Drupal\shared_lib\Plugin\QueueWorker\BaseCombatQueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes wrestling search & updating (reset) all videos of fights for each given wrestler.
 *
 * @QueueWorker(
 *   id = "updatevideos_wrestling_importer_queue",
 *   title = @Translation("Update videos Wrestling Importer Queue Worker"),
 *   cron = {"time" = 60}
 * )
 */
class updateVideosWrestlingQueueWorker extends BaseCombatQueueWorker implements \Drupal\Core\Plugin\ContainerFactoryPluginInterface {


  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('logger.channel.default'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($item) {
    $this->updateVideosConfrontation($item, $resetVideos=true); //on efface les videos existantes
  }
}
