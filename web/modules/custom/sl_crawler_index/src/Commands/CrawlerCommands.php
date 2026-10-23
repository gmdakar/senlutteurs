<?php

declare(strict_types=1);

namespace Drupal\sl_crawler_index\Commands;

use Drush\Commands\DrushCommands;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\sl_crawler_index\Service\SitemapParser;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;

/**
 * Commandes Drush pour la gestion de l'indexation.
 */
class CrawlerCommands extends DrushCommands {

  public function __construct(
    protected SitemapParser $parser,
    protected QueueFactory $queueFactory,
    protected QueueWorkerManagerInterface $queueWorkerManager
  ) {
    parent::__construct();
  }

  /**
   * Charge toutes les URLs des sitemaps dans la queue.
   */
  #[Command(name: 'sl:queue-load', aliases: ['slql'])]
  #[Option(name: 'reset', description: "Vider la queue avant de charger")]
  #[Usage(name: 'drush sl:queue-load', description: "Charge tous les sitemaps dans la queue")]
  public function queueLoad(array $options = ['reset' => FALSE]): void {
    $queue = $this->queueFactory->get('sl_crawler_index_queue');

    if ($options['reset']) {
      $queue->deleteQueue();
      $this->output()->writeln("<comment>🗑️  Queue vidée.</comment>");
    }

    $this->output()->writeln("🔍 Chargement de tous les sitemaps...\n");

    $loaded = 0;
    $page = 1;

    while (true) {
      $urls = $this->parser->fetchUrlsFromPage($page);

      if (empty($urls)) {
        $this->output()->writeln("📄 Fin des sitemaps à la page $page.");
        break;
      }

      $filtered = array_filter($urls, fn($url) => !str_contains($url, 'sitemap.xml'));

      if (empty($filtered)) {
        $this->output()->writeln("📄 Page $page = sitemap index uniquement — arrêt.");
        break;
      }

      foreach ($filtered as $url) {
        $queue->createItem(['url' => $url]);
        $loaded++;
      }

      $this->output()->writeln("📥 Page $page : " . count($filtered) . " URLs (total queue : $loaded)");
      $page++;
    }

    $this->output()->writeln("\n" . str_repeat("─", 60));
    $this->output()->writeln("<info>✅ $loaded URLs chargées dans la queue.</info>");
  }

  /**
   * Traite un lot d'URLs depuis la queue.
   */
  #[Command(name: 'sl:crawl-index', aliases: ['slci'])]
  #[Option(name: 'step', description: "Nombre d'URLs à traiter")]
  #[Usage(name: 'drush sl:crawl-index --step=200', description: "Traite 200 URLs")]
  public function crawlIndex(array $options = ['step' => 200]): void {
    $step = max(1, min(200, (int) $options['step']));
    $queue = $this->queueFactory->get('sl_crawler_index_queue');
    $total = $queue->numberOfItems();

    if ($total === 0) {
      $this->output()->writeln("<comment>⚠️  Queue vide. Lancez d'abord : drush sl:queue-load</comment>");
      return;
    }

    $this->output()->writeln("⚙️  Traitement de $step URL(s) sur $total en attente...\n");

    $worker = $this->queueWorkerManager->createInstance('sl_crawler_index_queue');
    $processed = 0;
    $errors = 0;

    while ($processed + $errors < $step && ($item = $queue->claimItem())) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;
        $this->output()->writeln("<info>  ✅ Indexée : {$item->data['url']}</info>");
      } catch (SuspendQueueException $e) {
        $queue->releaseItem($item);
        $this->output()->writeln("<error>🛑 Quota Google dépassé. Arrêt immédiat.</error>");
        break;
      } catch (\Exception $e) {
        $queue->releaseItem($item);
        $errors++;
        $this->output()->writeln("<error>  ❌ Échec : {$item->data['url']} — " . $e->getMessage() . "</error>");
      }
    }

    $remaining = $queue->numberOfItems();
    $this->output()->writeln("\n" . str_repeat("─", 60));
    $this->output()->writeln("✅ Traitées : <info>$processed</info>  ❌ Erreurs : <comment>$errors</comment>  📦 Restantes : <info>$remaining</info>");
  }

}