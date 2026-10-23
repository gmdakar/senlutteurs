<?php

namespace Drupal\sl_signalements\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Réinitialisation manuelle (admin) du flood anti-doublon des signalements.
 *
 * Gère les deux backends possibles pour la protection flood :
 *  - Redis (prod)      : purge directe des clés Redis correspondantes.
 *  - Base de données   : fallback utilisé quand le module redis n'est pas
 *                         installé/actif (typiquement en dev local), où le
 *                         flood core stocke ses entrées dans la table
 *                         {flood}.
 *
 * Reproduit à l'identique la logique de la commande `drush php:eval` déjà
 * utilisée manuellement en prod pour ce même besoin.
 */
class FloodResetController extends ControllerBase {

  /**
   * Préfixe des événements flood liés aux signalements (voir
   * SignalementApiController::FLOOD_VERSION).
   */
  const FLOOD_EVENT_PREFIX = 'sl_vote_';

  /**
   * Purge le flood des signalements (Redis si dispo, sinon DB).
   */
  public function reset() {
    $deleted = 0;
    $backend = 'database';
    $redis_error = NULL;

    // ── 1. Tentative Redis (prod) ────────────────────────────────────────
    if (\Drupal::hasService('redis.factory')) {
      try {
        $client = \Drupal::service('redis.factory')->getClient();

        if ($client) {
          $backend = 'redis';
          $keys = $client->keys('*flood*');

          foreach ($keys as $key) {
            $raw = is_string($key) ? $key : (string) $key;
            if (str_contains($raw, self::FLOOD_EVENT_PREFIX) || str_contains($raw, 'flood')) {
              $client->del($raw);
              $deleted++;
            }
          }
        }
      }
      catch (\Throwable $e) {
        // Redis déclaré mais indisponible/erreur : on retombe sur la DB.
        $redis_error = $e->getMessage();
        $backend = 'database';
        $deleted = 0;
      }
    }

    // ── 2. Fallback base de données (dev local sans Redis, ou échec Redis) ─
    if ($backend === 'database') {
      $deleted = (int) \Drupal::database()->delete('flood')
        ->condition('event', self::FLOOD_EVENT_PREFIX . '%', 'LIKE')
        ->execute();
    }

    \Drupal::logger('sl_signalements')->notice(
      'Flood reset (signalements) | backend=@backend | deleted=@n | uid=@uid@redis_error',
      [
        '@backend'     => $backend,
        '@n'           => $deleted,
        '@uid'         => \Drupal::currentUser()->id(),
        '@redis_error' => $redis_error ? " | redis_error=$redis_error" : '',
      ]
    );

    return new JsonResponse([
      'status'  => 'ok',
      'backend' => $backend,
      'deleted' => $deleted,
    ]);
  }

}
