<?php

namespace Drupal\sl_warming\Drush\Commands;

use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Commands\DrushCommands;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Routing\RequestContext;

/**
 * Commande Drush pour le préchauffage applicatif du cache senlutteurs.com.
 */
class SlWarmingCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;

  /**
   * Kernel Drupal.
   */
  protected HttpKernelInterface $httpKernel;

  /**
   * Client HTTP.
   */
  protected ClientInterface $httpClient;

  /**
   * State API.
   */
  protected StateInterface $state;

  /**
   * Queue factory.
   */
  protected QueueFactory $queueFactory;

  /**
   * Routing context.
   */
  protected RequestContext $requestContext;

  /**
   * Domaine principal.
   */
  protected string $domain = 'senlutteurs.com';

  /**
   * Constructeur.
   */
  public function __construct(
    HttpKernelInterface $http_kernel,
    ClientInterface $http_client,
    StateInterface $state,
    QueueFactory $queue_factory,
    RequestContext $request_context
  ) {
    parent::__construct();

    $this->httpKernel = $http_kernel;
    $this->httpClient = $http_client;
    $this->state = $state;
    $this->queueFactory = $queue_factory;
    $this->requestContext = $request_context;
  }

  #[Command(name: 'sl-warming', aliases: ['slw'])]
  #[Option(
    name: 'page-limit',
    description: 'Nombre maximum de pages sitemap à traiter (0 = infini).'
  )]
  public function runNativeWarming(array $options = ['page-limit' => 0]): void {

    $pageLimit = (int) $options['page-limit'];

    $urlCount = 0;
    $hitCount = 0;
    $missCount = 0;

    $this->logger()->notice(
      "--- Début du warming applicatif : " . date('Y-m-d H:i:s') . " ---"
    );

    /**
     * IMPORTANT :
     * Force le contexte Drupal sur le vrai domaine.
     */
    $this->requestContext->setHost($this->domain);
    $this->requestContext->setScheme('https');
    $this->requestContext->setBaseUrl('');

    try {

      /**
       * Nettoyage queue purge.
       */
      $this->logger()->notice('Nettoyage de la queue purge...');

      $purgeQueue = $this->queueFactory->get('purge_processor_queue');

      if ($purgeQueue) {
        $purgeQueue->deleteQueue();
      }

      /**
       * Site alias Drush.
       */
      $self = $this->siteAliasManager()->getSelf();

      /**
       * IMPORTANT :
       * Force le --uri utilisé par tous les sous-process Drush.
       */
      $self->setUri('https://' . $this->domain);

      /**
       * Warm entités.
       */
      $this->logger()->notice('Warm entities...');

      $processEntity = $this->processManager()->drush(
        $self,
        'warmer:enqueue',
        ['entity'],
        [
          'uri' => 'https://' . $this->domain,
        ]
      );

      $processEntity->run();

      /**
       * Warm sitemap.
       */
      $this->logger()->notice('Warm sitemap...');

      $processSitemap = $this->processManager()->drush(
        $self,
        'warmer:enqueue',
        ['sitemap'],
        [
          'uri' => 'https://' . $this->domain,
        ]
      );

      $processSitemap->run();

      /**
       * Run warmer queue.
       */
      $this->logger()->notice('Run warmer queue...');

      $processQueue = $this->processManager()->drush(
        $self,
        'queue:run',
        ['warmer'],
        [
          'uri' => 'https://' . $this->domain,
        ]
      );

      $processQueue->run();

      /**
       * Flags état.
       */
      $this->state->set('warming_in_progress', 1);
      $this->state->set('warming_started_at', time());

      /**
       * User agents.
       */
      $uaDesktop = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)
AppleWebKit/537.36 (KHTML, like Gecko)
Chrome/120.0.0.0 Safari/537.36';

      $uaMobile = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)
AppleWebKit/605.1.15 (KHTML, like Gecko)
Version/16.0 Mobile/15E148 Safari/604.1';

      /**
       * Parcours sitemap.
       */
      $page = 1;

      while (TRUE) {

        if ($pageLimit > 0 && $page > $pageLimit) {
          break;
        }

        try {

          $sitemapUrl = sprintf(
            'https://%s/sitemap.xml?page=%d',
            $this->domain,
            $page
          );

          $response = $this->httpClient->request('GET', $sitemapUrl);

          $xml = $response->getBody()->getContents();

        }
        catch (\Exception $e) {

          $this->logger()->notice(
            "Fin sitemap ou erreur page $page"
          );

          break;
        }

        preg_match_all('/<loc>([^<]+)<\/loc>/', $xml, $matches);

        $urls = $matches[1] ?? [];

        /**
         * Ignore sous-sitemaps.
         */
        $urls = array_filter($urls, static function ($url) {
          return strpos($url, 'sitemap.xml') === FALSE;
        });

        if (empty($urls)) {
          break;
        }

        foreach ($urls as $url) {

          $urlCount++;

          $parsedUrl = parse_url($url);

          $path =
            ($parsedUrl['path'] ?? '/')
            . (isset($parsedUrl['query'])
              ? '?' . $parsedUrl['query']
              : '');

          /**
           * Throttling dynamique.
           */
          $this->applyThrottling();

          /**
           * Server vars.
           */
          $serverVars = [
            'HTTP_HOST' => $this->domain,
            'SERVER_NAME' => $this->domain,
            'HTTPS' => 'on',
            'SERVER_PORT' => 443,
          ];

          /**
           * DESKTOP.
           */
          $requestDesktop = Request::create(
            $url,
            'GET',
            [],
            [],
            [],
            $serverVars
          );

          $requestDesktop->headers->set('Host', $this->domain);

          $requestDesktop->headers->set(
            'User-Agent',
            $uaDesktop
          );

          $requestDesktop->headers->set(
            'Accept-Encoding',
            'gzip, deflate, br'
          );

          $requestDesktop->headers->set(
            'X-LiteSpeed-Cache-Control',
            'public, max-age=86400'
          );

          $responseDesktop = $this->httpKernel->handle(
            $requestDesktop,
            HttpKernelInterface::SUB_REQUEST
          );

          $lscacheDesktop = $responseDesktop
            ->headers
            ->get('X-LiteSpeed-Cache', 'miss');

          /**
           * MOBILE.
           */
          $requestMobile = Request::create(
            $url,
            'GET',
            [],
            [],
            [],
            $serverVars
          );

          $requestMobile->headers->set('Host', $this->domain);

          $requestMobile->headers->set(
            'User-Agent',
            $uaMobile
          );

          $requestMobile->headers->set(
            'Accept-Encoding',
            'gzip, deflate, br'
          );

          $requestMobile->headers->set(
            'X-LiteSpeed-Cache-Control',
            'public, max-age=86400'
          );

          $responseMobile = $this->httpKernel->handle(
            $requestMobile,
            HttpKernelInterface::SUB_REQUEST
          );

          $lscacheMobile = $responseMobile
            ->headers
            ->get('X-LiteSpeed-Cache', 'miss');

          /**
           * Stats HIT/MISS.
           */
          if (
            stripos($lscacheDesktop, 'hit') !== FALSE &&
            stripos($lscacheMobile, 'hit') !== FALSE
          ) {
            $hitCount++;
            $statusLabel = 'HIT';
          }
          else {
            $missCount++;
            $statusLabel = 'STORED/MISS';
          }

          $this->logger()->info(sprintf(
            '[%d] [%d] [%s] %s',
            $urlCount,
            $responseDesktop->getStatusCode(),
            $statusLabel,
            $path
          ));
        }

        $page++;
      }
    }
    catch (\Exception $e) {

      $this->logger()->error(
        'Erreur : ' . $e->getMessage()
      );
    }
    finally {

      $this->state->delete('warming_in_progress');

      $this->logger()->notice(
        '--- Fin du warming ---'
      );

      $this->logger()->success(sprintf(
        'URLs: %d | HITs: %d | MISS: %d',
        $urlCount,
        $hitCount,
        $missCount
      ));
    }
  }

  /**
   * Throttling dynamique.
   */
  private function applyThrottling(): void {

    $load = sys_getloadavg();

    $load1m = $load[0] ?? 0;

    if ($load1m > 3.0) {
      usleep(3000000);
    }
    elseif ($load1m > 2.0) {
      usleep(2000000);
    }
    elseif ($load1m > 1.0) {
      usleep(1000000);
    }
    else {
      usleep(100000);
    }
  }

}