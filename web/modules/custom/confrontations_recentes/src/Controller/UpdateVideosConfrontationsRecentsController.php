<?php

namespace Drupal\confrontations_recentes\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Drupal\confrontations_recentes\Service\CombatService;

class UpdateVideosConfrontationsRecentsController extends ControllerBase {

  protected CombatService $combatService;

  public function __construct(CombatService $combatService) {
    $this->combatService = $combatService;
  }

  /**
   * Run the recent confrontations updates videos manually.
   */
  public function updateVideos(): Response {
    // Récupérer le token depuis la query string
    $token = \Drupal::request()->query->get('token');
    $settings_token = \Drupal::service('settings')->get('misc_divers_cron_key') ?? 'REMPLACE_MOI_CLE_DEV';

    if ($token !== $settings_token) {
      return new Response('Access denied: invalid token.', 403);
    }

    $combats = $this->combatService->updateVideosConfrontations();
    \Drupal::logger('confrontations_recentes')->debug('Data combats for update videos: @data', ['@data' => print_r($combats, TRUE)]);

    return new Response('Recent confrontations update videos enqueue executed.');
  }

  public static function create($container) {
    return new static(
      $container->get('confrontations_recentes.combat_service')
    );
  }
}