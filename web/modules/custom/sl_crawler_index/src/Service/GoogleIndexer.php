<?php

declare(strict_types=1);

namespace Drupal\sl_crawler_index\Service;

use Drupal\sl_crawler_index\Exception\QuotaExceededException;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\Middleware\AuthTokenMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

class GoogleIndexer {

  const INDEXING_SCOPE = 'https://www.googleapis.com/auth/indexing';
  const INDEXING_ENDPOINT = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

  private string $keyFile;

  public function __construct(
    protected LoggerChannelFactoryInterface $loggerFactory
  ) {
    $this->keyFile = DRUPAL_ROOT . '/../private/google-service-account.json';
  }

  protected function getClient(): Client {
    $credentials = new ServiceAccountCredentials(
      self::INDEXING_SCOPE,
      $this->keyFile
    );

    $middleware = new AuthTokenMiddleware($credentials);
    $stack = HandlerStack::create();
    $stack->push($middleware);

    return new Client([
      'handler' => $stack,
      'base_uri' => 'https://indexing.googleapis.com',
      'auth' => 'google_auth',
    ]);
  }

  public function notifyUrl(string $url, string $type = 'URL_UPDATED'): bool {
    $logger = $this->loggerFactory->get('sl_crawler_index');

    // Strip le préfixe /web parasite (document root Drupal)
    $url = preg_replace('#(https?://[^/]+)/web/#', '$1/', $url);

    if (!file_exists($this->keyFile)) {
      $logger->warning("GoogleIndexer - [INDEXING] Fichier de clé introuvable : @file", [
        '@file' => $this->keyFile,
      ]);
      return false;
    }

    try {
      $client = $this->getClient();
      $response = $client->post(self::INDEXING_ENDPOINT, [
        'json' => [
          'url' => $url,
          'type' => $type,
        ],
      ]);

      $logger->info("GoogleIndexer - [INDEXING] @type : @url - Status: @status", [
        '@type' => $type,
        '@url' => $url,
        '@status' => $response->getStatusCode(),
      ]);

      return $response->getStatusCode() === 200;

    } catch (ClientException $e) {
      $status = $e->getResponse()->getStatusCode();

      $logger->warning("GoogleIndexer - [INDEXING] Erreur HTTP @status pour @url", [
        '@status' => $status,
        '@url' => $url,
      ]);

      if ($status === 429) {
        throw new QuotaExceededException("Quota Google dépassé pour : $url");
      }

      return false;

    } catch (GuzzleException $e) {
      $logger->warning("GoogleIndexer - [INDEXING] Erreur réseau pour @url : @msg", [
        '@url' => $url,
        '@msg' => $e->getMessage(),
      ]);
      return false;

    } catch (\Exception $e) {
      $logger->warning("GoogleIndexer - [INDEXING] Erreur inattendue pour @url : @msg", [
        '@url' => $url,
        '@msg' => $e->getMessage(),
      ]);
      return false;
    }
  }

  public function deleteUrl(string $url): bool {
    return $this->notifyUrl($url, 'URL_DELETED');
  }

}