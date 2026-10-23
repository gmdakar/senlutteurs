<?php

namespace Drupal\misc_divers\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\simple_sitemap\Manager\Generator;
use Drupal\simple_sitemap\Queue\QueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller pour exécuter le cron misc et générer le sitemap par lot.
 */
class MiscDiversCronController extends ControllerBase {

  protected Generator $generator;

  public function __construct(Generator $generator) {
    $this->generator = $generator;
  }

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('simple_sitemap.generator')
    );
  }
  /**
   * Force la regeneration du sitemap.xml 
   * (Attention: si pour un type de contenu sa config metatag a changé, il ne pourra pas
   * automatiquement mettre à jour les contenus associés sauf si la date de change 
   * de ses contenus a été modifiée entre temps.)
   */
  public function run(Request $request): JsonResponse {
    try {
      // Clé secrète depuis settings.php
      $secret = \Drupal::service('settings')->get('misc_divers_cron_key') ?? 'REMPLACE_MOI_CLE_DEV';
      $key = $request->query->get('key');
      if ($key !== $secret) {
        return $this->jsonResponseError('Invalid token', 403);
      }

      // Charger le nombre d’itérations depuis la config
      //$config = \Drupal::config('misc_divers.settings');
      //$max_iterations = (int) ($config->get('cron_iterations') ?? 5);

      $i = 0;
      //do {
        $this->generator->generate(QueueWorker::GENERATE_TYPE_CRON);
        $i++;
      //} while ($i < $max_iterations);

      // Rebuild queue pour la prochaine fois
      $this->generator
        ->setSitemaps(array_keys(\Drupal\simple_sitemap\Entity\SimpleSitemap::loadMultiple()))
        ->rebuildQueue();

      return $this->jsonResponse(true, "Sitemap generation processed ({$i} iterations).");

    } catch (\Exception $e) {
      return $this->jsonResponseError($e->getMessage());
    }
  }

  protected function jsonResponse(bool $lot_processed, string $message): JsonResponse {
    $response = new JsonResponse([
      'status' => 'success',
      'lot_processed' => $lot_processed,
      'message' => $message,
    ]);
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
    return $response;
  }

  protected function jsonResponseError(string $message, int $code = 500): JsonResponse {
    $response = new JsonResponse([
      'status' => 'error',
      'message' => $message,
    ], $code);
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
    return $response;
  }

}
