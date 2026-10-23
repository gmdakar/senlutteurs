<?php

namespace Drupal\elo_ranking\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Gère l'historique ELO pour les graphiques et le statut du système.
 */
class EloHistoryController extends ControllerBase {

  /**
   * La connexion à la base de données.
   */
  protected $database;

  /**
   * Injection de dépendances propre.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Fournit les données pour le graphique de carrière.
   * Format JSON optimisé pour Chart.js.
   */
  public function history($nid) {
    if (!is_numeric($nid)) {
      return new JsonResponse(['error' => 'Invalid ID'], 400);
    }

    try {
      $results = $this->database->select('elo_history', 'h')
        ->fields('h', ['fight_date', 'elo_after', 'id'])
        ->condition('wrestler_id', $nid)
        // ESSENTIEL : Chronologie exacte pour éviter les inversions sur le graphique
        ->orderBy('fight_date', 'ASC')
        ->orderBy('id', 'ASC') 
        ->execute()
        ->fetchAll();

      $history = [];
      foreach ($results as $row) {
        // FIX PHP 8.1+ : Sécurisation contre le null avant strtotime
        $date_str = (string) ($row->fight_date ?? '');
        $timestamp = strtotime($date_str);
        
        if ($timestamp) {
          $history[] = [
            'date_display' => date('d/m/Y', $timestamp),
            'elo' => (float) $row->elo_after,
          ];
        }
      }

      $response = new CacheableJsonResponse($history);
      
      // Invalidation du cache : On lie le cache de cette réponse au tag du lutteur
      // Si le score du lutteur change (via EloManager), ce JSON sera purgé automatiquement
      $cache_metadata = new CacheableMetadata();
      $cache_metadata->addCacheTags(['node:' . $nid]);
      $response->addCacheableDependency($cache_metadata);
      
      // Le navigateur / CDN doit revalider à chaque affichage : sinon un ancien JSON
      // (calculé avant un rebuild) reste servi et le graphique contredit le palmarès.
      // Le cache Drupal reste invalidé par le tag node:NID.
      $response->headers->set('Cache-Control', 'no-cache, must-revalidate');

      return $response;

    } catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * Vérifie l'état du recalcul en temps réel (Anti-cache pour l'UI).
   */
  public function checkStatus() {
    $in_progress = \Drupal::state()->get('elo_rebuild_in_progress', FALSE);
    $in_fully_reindexing = \Drupal::state()->get('search_api_is_indexing', 0);
    
    $response = new JsonResponse([
      'rebuild_active' => (bool) $in_progress || (int) $in_fully_reindexing,
      'timestamp' => time(),
    ]);

    // On s'assure que le navigateur ne cache jamais cette vérification de statut
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');

    return $response;
  }
}