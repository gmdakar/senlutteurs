<?php

declare(strict_types=1);

namespace Drupal\sl_crawler_index\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

class SitemapParser {

  public function __construct(
    protected ClientInterface $httpClient,
    protected LoggerChannelFactoryInterface $loggerFactory
  ) {}

  public function fetchUrlsFromPage(int $page): array {
    $urls = [];
    $endpoint = "https://www.senlutteurs.com/sitemap.xml?page=" . $page;
    $logger = $this->loggerFactory->get('sl_crawler_index');

    $logger->debug("GoogleIndexer - [SITEMAP] Début de la requête : @url", [
      '@url' => $endpoint,
    ]);

    try {
      $response = $this->httpClient->request('GET', $endpoint, [
        'timeout' => 15,
        'http_errors' => false,
      ]);

      $status = $response->getStatusCode();
      $logger->debug("GoogleIndexer - [SITEMAP] Status HTTP reçu : @status", [
        '@status' => $status,
      ]);

      if ($status !== 200) {
        $logger->warning("GoogleIndexer - [SITEMAP] Status @status pour la page @page.", [
          '@status' => $status,
          '@page' => $page,
        ]);
        return [];
      }

      $rawBody = $response->getBody()->getContents();
      $logger->debug("GoogleIndexer - [SITEMAP] Taille du corps reçu : @size octets", [
        '@size' => strlen($rawBody),
      ]);

      if (empty($rawBody)) {
        $logger->warning("GoogleIndexer - [SITEMAP] Le corps de la page @page est vide.", [
          '@page' => $page,
        ]);
        return [];
      }

      $xml = simplexml_load_string($rawBody);

      if ($xml === false) {
        $logger->error("GoogleIndexer - [SITEMAP] Échec critique du parsing XML pour la page @page.", [
          '@page' => $page,
        ]);
        return [];
      }

      foreach ($xml->url as $entry) {
        $loc = (string) $entry->loc;
        if (!empty($loc)) {
          $urls[] = $loc;
        }
      }

      $logger->info("GoogleIndexer - [SITEMAP] Page @page analysée : @count URLs trouvées.", [
        '@page' => $page,
        '@count' => count($urls),
      ]);

    } catch (\Exception $e) {
      $logger->error("GoogleIndexer - [SITEMAP] Exception lors du crawl page @page : @msg", [
        '@page' => $page,
        '@msg' => $e->getMessage(),
      ]);
    }

    return $urls;
  }

  /**
   * Retourne true si la page existe (contient des URLs).
   */
  public function pageHasUrls(int $page): bool {
    return !empty($this->fetchUrlsFromPage($page));
  }

}
