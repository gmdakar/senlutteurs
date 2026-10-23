<?php

namespace Drupal\athletes_envogue_cron\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Drupal\athletes_envogue_cron\Service\AthleteService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use RuntimeException;

/**
 * @SuppressWarnings("CamelCaseVariableName")
 */
class AthletesEnvogueController extends ControllerBase {

  protected AthleteService $athleteService;
  protected RequestStack $requestStack;
  protected ConfigFactoryInterface $config;
  protected LoggerInterface $logger;

  public function __construct(
      AthleteService $athleteService,
      RequestStack $requestStack,
      ConfigFactoryInterface $configFactory,
      LoggerInterface $logger
  ) {
    $this->athleteService = $athleteService;
    $this->requestStack = $requestStack;
    $this->config = $configFactory;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var LoggerInterface $logger */
    $logger = $container->get('logger.channel.default');

    return new static(
      $container->get('athletes_envogue_cron.athletes_service'),
      $container->get('request_stack'),
      $container->get('config.factory'),
      $logger
    );
  }

  /**
   * Run the athlete import manually (protected by settings token).
   */
  public function runImport(): Response {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
        throw new RuntimeException('No current request available.');
    }

    // Récupération du token depuis l'URL
    $token = $request->query->get('token');

    // Token attendu dans settings.php
    /** @phpstan-ignore-next-line */
    $settings_token =\Drupal::service('settings')->get('misc_divers_cron_key') ?? 'REMPLACE_MOI_CLE_DEV';

    // Log pour debug
    $this->logger->debug('Token reçu: @token, Token attendu: @expected', [
        '@token' => $token ?? 'NULL',
        '@expected' => $settings_token,
    ]);

    if ($token !== $settings_token) {
        return new Response('Access denied: invalid token.', 403);
    }

    $athletes = $this->athleteService->fetchAthletesEnVogue();
    $athletes = array_reverse($athletes);

    foreach ($athletes as $athlete) {
        if (!is_array($athlete)) {
            continue;
        }

        $lutteur_name = $athlete['nom'] ?? null;
        if (!$lutteur_name) {
            continue;
        }

        if ($this->athleteService->athleteExists($lutteur_name)) {
            $this->athleteService->enqueueAthlete($athlete);
            $this->logger->debug('<pre>@athlete enqueue</pre>', ['@athlete' => print_r($athlete, TRUE)]);
        }
    }

    return new Response('Athlete import executed successfully.');
  }


}
