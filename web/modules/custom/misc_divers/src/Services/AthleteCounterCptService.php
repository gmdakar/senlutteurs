<?php

namespace Drupal\misc_divers\Services;

use Drupal\Core\State\StateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service de gestion du compteur de vues des athlètes.
 */
class AthleteCounterCptService {

  /**
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructeur.
   */
  public function __construct(
    StateInterface $state,
    QueueFactory $queue,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->state  = $state;
    $this->queue  = $queue->get('add_confrontations_athlete_queue');
    $this->config = $config_factory->get('misc_divers.settings');
    $this->logger = $logger_factory->get('misc_divers');
  }

  /**
   * Incrémente le compteur de vues et ajoute en queue si le seuil est atteint.
   *
   * @param int $nid
   *   L'ID du node athlète.
   *
   * @return int
   *   La valeur courante du compteur après incrément.
   */
  public function increment(int $nid): int {
    $cpt_state_key = "cpt_lutteur_" . $nid;
    $current_cpt   = $this->state->get($cpt_state_key, 0) + 1;
    $this->state->set($cpt_state_key, $current_cpt);

    $seuil = (int) ($this->config->get('seuil_cpt_to_complet_combats') ?? 5);

    if ($current_cpt === $seuil) {
      $this->queue->createItem([
        'nid' => $nid,
        'cpt' => $current_cpt,
      ]);

      $this->logger->info("AthleteCounterCptService : Seuil atteint pour l'athlète @nid (@cpt vus). Ajout à la file.", [
        '@nid' => $nid,
        '@cpt' => $current_cpt,
      ]);
    }

    return $current_cpt;
  }

  /**
   * Force l'ajout d'un athlète en queue sans condition de seuil.
   *
   * Utile pour déclencher manuellement la recherche de combats depuis
   * une commande Drush, un formulaire admin ou tout autre contexte
   * extérieur au presave (field_search_combats).
   * Réinitialise les compteurs State pour repartir proprement.
   *
   * @param int $nid
   *   L'ID du node athlète.
   */
  public function forceEnqueue(int $nid): void {
    $this->queue->createItem([
      'nid'   => $nid,
      'cpt'   => PHP_INT_MAX, // valeur sentinelle : bypasse le seuil dans le worker
      'force' => TRUE,        // bypasse aussi le guard field_not_check
    ]);

    // Remise à zéro du compteur de vues pour éviter un cycle fantôme
    // si l'incrément normal reprend par la suite.
    $this->state->set("cpt_lutteur_" . $nid, 0);

    $this->logger->notice(
      "AthleteCounterCptService::forceEnqueue — NID @nid ajouté en queue (force=true, compteur réinitialisé).",
      ['@nid' => $nid]
    );
  }

}