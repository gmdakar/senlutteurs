<?php

declare(strict_types=1);

namespace Drupal\sl_crawler_index\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\sl_crawler_index\Exception\QuotaExceededException;
use Drupal\sl_crawler_index\Service\GoogleIndexer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @QueueWorker(
 *   id = "sl_crawler_index_queue",
 *   title = @Translation("SL Crawler Indexing Worker"),
 * )
 */
class CrawlerQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected GoogleIndexer $googleIndexer
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('sl_crawler_index.google_indexer')
    );
  }

  public function processItem($data): void {
    if (empty($data['url'])) {
      return;
    }

    $url = $data['url'];
    $type = $data['type'] ?? 'URL_UPDATED';

    try {
      $this->googleIndexer->notifyUrl($url, $type);
    } catch (QuotaExceededException $e) {
      // Stoppe le cron proprement, remet l'item en queue, pas de log error.
      throw new SuspendQueueException($e->getMessage());
    }
  }

}