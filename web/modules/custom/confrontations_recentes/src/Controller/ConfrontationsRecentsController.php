<?php

namespace Drupal\confrontations_recentes\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Drupal\confrontations_recentes\Service\CombatService;

class ConfrontationsRecentsController extends ControllerBase {

  protected CombatService $combatService;

  public function __construct(CombatService $combatService) {
    $this->combatService = $combatService;
  }

  /**
   * Run the recent confrontations import manually.
   */
  public function runImport(): Response {
    // Récupérer le token depuis la query string
    $token = \Drupal::request()->query->get('token');
    $settings_token = \Drupal::service('settings')->get('misc_divers_cron_key') ?? 'REMPLACE_MOI_CLE_DEV';

    if ($token !== $settings_token) {
      return new Response('Access denied: invalid token.', 403);
    }

    $maxRetries = 3;
    $retries = 0;

    do {
      $combats = $this->combatService->fetchCombats();
      \Drupal::logger('confrontations_recentes')->debug('Try @retries / Data combats: @data', ['@retries' => $retries + 1, '@data' => print_r($combats, TRUE)]);

      if (isset($combats) && count($combats)) {
        foreach ($combats as $combat) {
          $this->combatService->enqueueCombat($combat);
        }
        break;
      }

      $retries++;
      if ($retries < $maxRetries) {
        sleep(60); // Wait 1 minute before retrying
      }
    } while ($retries < $maxRetries);

    return new Response('Recent confrontations import executed successfully.');
  }

  public static function create($container) {
    return new static(
      $container->get('confrontations_recentes.combat_service')
    );
  }
}
