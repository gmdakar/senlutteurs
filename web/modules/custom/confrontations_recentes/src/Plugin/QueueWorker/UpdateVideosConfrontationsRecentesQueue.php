<?php

namespace Drupal\confrontations_recentes\Plugin\QueueWorker;

use Drupal\shared_lib\Plugin\QueueWorker\BaseCombatQueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\Entity\Node;

/**
 * @QueueWorker(
 *   id = "update_videos_confrontations_recentes_queue",
 *   title = @Translation("Process Updates videos Confrontations récentes Queue"),
 *   cron = {"time" = 120}
 * )
 */
class UpdateVideosConfrontationsRecentesQueue extends BaseCombatQueueWorker implements \Drupal\Core\Plugin\ContainerFactoryPluginInterface {

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
    $this->updateVideosConfrontation($item);
  }

}
