<?php

namespace Drupal\gemini_nextcombats_cron\Plugin\QueueWorker;

use Drupal\shared_lib\Plugin\QueueWorker\BaseCombatQueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\Entity\Node;

/**
 * @QueueWorker(
 *   id = "update_videos_combats_next_queue",
 *   title = @Translation("Process Updates videos Combats Next Queue"),
 *   cron = {"time" = 60}
 * )
 */
class UpdateVideosCombatsNextQueue extends BaseCombatQueueWorker implements \Drupal\Core\Plugin\ContainerFactoryPluginInterface {

  // No constructor — inherited from base.

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

  public function processItem($item) {
   // \Drupal::logger('update_videos_combats_next_queue')->debug('Data 2 next fights for update videos: @data', ['@data' => $item]);
    $this->updateVideosCombatNext($item);
  }

}
