<?php

namespace Drupal\gemini_nextcombats_cron\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Drupal\gemini_nextcombats_cron\Service\CombatService;

class NextCombatsController extends ControllerBase {

  protected CombatService $combatService;

  public function __construct(CombatService $combatService) {
    $this->combatService = $combatService;
  }

  /**
   * Run the next combats import manually (with optional token protection).
   */
  public function runImport(): Response {
    // Récupérer le token depuis la query string
    $token = \Drupal::request()->query->get('token');
    $settings_token = \Drupal::service('settings')->get('misc_divers_cron_key') ?? 'REMPLACE_MOI_CLE_DEV';

    if ($token !== $settings_token) {
      return new Response('Access denied: invalid token.', 403);
    }

    $combats = $this->combatService->fetchCombats();

    foreach ($combats as $combat) {
      if (isset($combat["date_precise"]) && !empty($combat["date_precise"]))
        $this->combatService->enqueueCombat($combat);
    }

    return new Response('Next combats import executed successfully.');
  }

  public static function create($container) {
    return new static(
      $container->get('gemini_nextcombats_cron.combat_service')
    );
  }
}
