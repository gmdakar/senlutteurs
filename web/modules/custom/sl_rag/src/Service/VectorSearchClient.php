<?php

namespace Drupal\sl_rag\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Http\ClientFactory;
use Psr\Log\LoggerInterface;

/**
 * Client HTTP vers l'API de recherche vectorielle (search_api.py + Pinecone).
 */
class VectorSearchClient
{

  protected ConfigFactoryInterface $configFactory;
  protected ClientFactory $httpClientFactory;
  protected LoggerInterface $logger;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    ClientFactory $httpClientFactory,
    LoggerInterface $logger
  ) {
    $this->configFactory = $configFactory;
    $this->httpClientFactory = $httpClientFactory;
    $this->logger = $logger;
  }

  /**
   * Indique si la recherche vectorielle est activée et configurée.
   */
  public function isEnabled(): bool
  {
    $config = $this->configFactory->get('sl_rag.settings');
    return (bool) $config->get('vector_enabled')
      && !empty($config->get('vector_api_url'));
  }

  /**
   * Interroge l'API vectorielle.
   *
   * @return array{success: bool, hits: array<int, array>, error: ?string}
   */
  public function search(string $query, ?int $topK = NULL, array $filters = []): array
  {
    $config = $this->configFactory->get('sl_rag.settings');
    $baseUrl = rtrim((string) $config->get('vector_api_url'), '/');
    $secret = (string) ($config->get('vector_api_secret') ?? '');
    $topK = $topK ?? (int) ($config->get('vector_top_k') ?: 10);
    $timeout = (int) ($config->get('vector_timeout') ?: 30);

    if ($baseUrl === '') {
      return ['success' => FALSE, 'hits' => [], 'error' => 'URL API vectorielle non configurée'];
    }

    $url = $baseUrl . '/search';
    $body = [
      'query' => $query,
      'top_k' => $topK,
    ];
    if (!empty($filters)) {
      $body['filters'] = $filters;
    }

    $headers = [
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    ];
    if ($secret !== '') {
      $headers['Authorization'] = 'Bearer ' . $secret;
    }

    $this->logger->info('VectorSearch → POST @url | query=@q | top_k=@k', [
      '@url' => $url,
      '@q' => $query,
      '@k' => $topK,
    ]);

    try {
      $client = $this->httpClientFactory->fromOptions([
        'timeout' => $timeout,
        'connect_timeout' => min(10, $timeout),
      ]);
      $response = $client->post($url, [
        'headers' => $headers,
        'json' => $body,
      ]);
      $raw = (string) $response->getBody();
      $data = json_decode($raw, TRUE);

      if (!is_array($data) || !isset($data['hits'])) {
        $this->logger->error('VectorSearch réponse invalide: @raw', ['@raw' => mb_substr($raw, 0, 500)]);
        return ['success' => FALSE, 'hits' => [], 'error' => 'Réponse API invalide'];
      }

      $hits = [];
      foreach ($data['hits'] as $hit) {
        $hits[] = [
          'id' => (string) ($hit['id'] ?? ''),
          'score' => (float) ($hit['score'] ?? 0),
          'metadata' => $hit['metadata'] ?? [],
          'text' => (string) ($hit['text'] ?? ''),
          'match_type' => (string) ($hit['match_type'] ?? 'vector'),
          'match_how' => (string) ($hit['match_how'] ?? ''),
        ];
      }

      $this->logger->info('VectorSearch ← @n hit(s)', ['@n' => count($hits)]);
      return ['success' => TRUE, 'hits' => $hits, 'error' => NULL];
    }
    catch (\Throwable $e) {
      $this->logger->error('VectorSearch échec: @msg', ['@msg' => $e->getMessage()]);
      return ['success' => FALSE, 'hits' => [], 'error' => $e->getMessage()];
    }
  }

}
