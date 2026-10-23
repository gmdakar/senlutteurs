<?php

namespace Drupal\gemini_nextcombats_cron\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Drupal\gemini_nextcombats_cron\Service\CombatService;

class UpdateVideosNextCombatsController extends ControllerBase {

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

    $combats = $this->combatService->updateVideosCombatsNext();
    \Drupal::logger('gemini_nextcombats_cron')->debug('Data next fights for update videos: @data', ['@data' => print_r($combats, TRUE)]);

    return new Response('Next fights update videos enqueue executed.');
  }

  public static function create($container) {
    return new static(
      $container->get('gemini_nextcombats_cron.combat_service')
    );
  }
}