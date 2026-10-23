<?php

namespace Drupal\athletes_envogue_cron\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\node\NodeInterface;
use Drupal\athletes_envogue_cron\Service\AthleteService; 

/**
 * @QueueWorker(
 *   id = "athlete_envogue_queue",
 *   title = @Translation("Process en vogue athletes Queue"),
 *   cron = {"time" = 60}
 * )
 */
class ProcessAthleteEnVogueQueue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  protected ConfigFactoryInterface $configFactory;
  protected ClientInterface $httpClient;
  protected LoggerInterface $logger;
  protected AthleteService $athleteService;
  protected string $apiKey;

  public function __construct(
      array $configuration,
      $plugin_id,
      $plugin_definition,
      ConfigFactoryInterface $config_factory,
      ClientInterface $http_client,
      LoggerInterface $logger,
      AthleteService $athleteService
  ) {
      parent::__construct($configuration, $plugin_id, $plugin_definition);

      $this->configFactory = $config_factory;
      $this->httpClient = $http_client;
      $this->logger = $logger;
      $this->athleteService = $athleteService;
      $this->apiKey = $this->configFactory->get('athletes_envogue_cron.settings')->get('api_key_grok') ?? '';
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
      return new static(
          $configuration,
          $plugin_id,
          $plugin_definition,
          $container->get('config.factory'),
          $container->get('http_client'),
          $container->get('logger.channel.default'),
          $container->get('athletes_envogue_cron.athletes_service') // <- Injection correcte
      );
  }

  public function processItem($data) {
    $lutteur_name = $data['nom'] ?? null;
    $lutteur_isking = $data['is_king'];

    if (!$lutteur_name) {
        $this->logger->warning('No athlete name provided in queue item.');
        return;
    }

    // Appel du service → profite du cache statique interne
    $node = $this->athleteService->athleteExists($lutteur_name);

    if ($node instanceof NodeInterface) {
        $node->set('field_en_vogue', time());
        $node->set('field_king', (int) $lutteur_isking);
        sleep(5);
        print ($node->label() . '(' . $node->id() . ' isking:'.(int) $lutteur_isking.') est en vogue'."\n");
        $node->save(); // décommente pour appliquer la mise à jour
        \Drupal::logger('athletes_envogue_cron')->info($node->label() . '(' . $node->id() . ' isking:'.(int) $lutteur_isking.') est en vogue');
    }
    else {
        $this->logger->info('Athlete not found: ' . $lutteur_name);
    }
  }

}
