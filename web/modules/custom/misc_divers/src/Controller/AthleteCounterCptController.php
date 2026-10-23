<?php

namespace Drupal\misc_divers\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\misc_divers\Services\AthleteCounterCptService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller pour l'incrémentation asynchrone du compteur d'athlètes.
 */
class AthleteCounterCptController extends ControllerBase {

  /**
   * Le service de compteur d'athlètes.
   *
   * @var \Drupal\misc_divers\Services\AthleteCounterCptService
   */
  protected $athleteCounterCptService;

  /**
   * Constructeur avec injection du service personnalisé.
   *
   * @param \Drupal\misc_divers\Services\AthleteCounterCptService $athlete_counter_service
   *   Le service métier pour gérer les compteurs.
   */
  public function __construct(AthleteCounterCptService $athlete_counter_service) {
    $this->athleteCounterCptService = $athlete_counter_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('misc_divers.athlete_cpt_counter') // On récupère l'ID défini dans services.yml
    );
  }

  /**
   * Incrémente le compteur et délègue la gestion de la file d'attente au service.
   *
   * @param int|string $nid
   *   L'ID de l'athlète.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   La réponse JSON.
   */
  public function increment($nid) {
    
    // On appelle la méthode du service. 
    // Toute la logique (State, Seuil, Queue) est maintenant là-bas.
    $current_cpt = $this->athleteCounterCptService->increment($nid);

    return new JsonResponse([
      'status' => 'ok',
      'nid' => $nid,
      'current_cpt' => $current_cpt
    ]);
  }

}