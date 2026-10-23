<?php

namespace Drupal\sl_signalements\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Site\Settings;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\sl_signalements\Tools\SToolsActions;
use Drupal\Component\Serialization\Json;

class SignalementApiController extends ControllerBase
{

  // Définissez une version dans vos constantes ou paramètres
  const FLOOD_VERSION = 'v1.3';

  protected SToolsActions $tools;

  public function __construct(SToolsActions $tools)
  {
    $this->tools = $tools;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('sl_signalements.tools_actions')
    );
  }

  public function receive($id, $type, $token)
  {

    try {

      \Drupal::logger('sl_signalements')->notice("API HIT | type=@type id=@id", [
        '@type' => $type,
        '@id'   => $id,
      ]);

      $request = \Drupal::request(); // ✅ FIX IMPORTANT

      // 🔐 1. HMAC SECURITY
      $salt      = Settings::getHashSalt();
      $expected_safe = '';

      $variant_nid_for_token = NULL;

      $is_variante_type = in_array($type, ['s_variante_athlete_1', 's_variante_athlete_2'], TRUE);

      if ($is_variante_type) {

        $content_early = $request->getContent();
        $body_early    = !empty($content_early) ? json_decode($content_early, TRUE) : [];

        $variant_nid_for_token = isset($body_early['variant_nid']) && is_numeric($body_early['variant_nid'])
          ? (int) $body_early['variant_nid']
          : NULL;

        if (!$variant_nid_for_token) {
          return new JsonResponse(['error' => $this->t('variant_nid manquant')], 400);
        }

        $hmac_data = $id . ':' . $type . ':' . $variant_nid_for_token;
      } else {
        $hmac_data = $id . ':' . $type;
      }

      $expected      = Crypt::hmacBase64($hmac_data, $salt);
      $expected_safe = str_replace(['+', '/', '='], ['-', '_', ''], $expected);

      if (!hash_equals($expected_safe, $token)) {
        return new JsonResponse(['error' => $this->t('Jeton invalide')], 403);
      }

      // 🧾 2. TYPES AUTORISÉS
      $allowed_types = [
        's_litige',
        's_nul',
        's_combat_doublon',
        's_vainqueur_lutteur1',
        's_vainqueur_lutteur2',
        's_combat_fantome',
        's_lutte_simple',
        's_lutte_frappe',
        's_variante_athlete_1',
        's_variante_athlete_2',
      ];

      if (!in_array($type, $allowed_types, TRUE)) {
        return new JsonResponse(['error' => $this->t('Type invalide')], 400);
      }

      // 🚫 3. FLOOD PROTECTION
      $ip   = (string) $request->getClientIp();
      $ua   = (string) $request->headers->get('User-Agent', '');
      $salt = Settings::getHashSalt(); // Assurez-vous que le salt est disponible
      $spam_identifier = substr(hash('sha256', $ip . $ua . $salt), 0, 32);

      // 1. Récupérer le contenu de manière sécurisée
      $content = $request->getContent();
      $data = !empty($content) ? json_decode($content, TRUE) : [];

      // 2. Extraire la valeur avec une vérification
      $is_logged_in_request = isset($data['is_logged_in']) ? (bool) $data['is_logged_in'] : false;

      // 3. Appliquer la logique de force_apply
      $force_apply = $request->headers->get('X-Force-Apply') === '1' || $is_logged_in_request;

      $current_user = \Drupal::currentUser();
      $is_admin     = $current_user->hasPermission('administer nodes')
        || in_array('administrator', $current_user->getRoles());
      $is_logged_in = !$current_user->isAnonymous();

      $bypass_limit = $is_admin || $is_logged_in || $force_apply;
      $flood = \Drupal::flood();
      $vote_identifier = substr(hash('sha256', $ip . $ua . $salt), 0, 32);
      $vote_event = 'sl_vote_' . self::FLOOD_VERSION . '_' . $id;

      // LOG POUR DÉBOGUER
      $connection = \Drupal::database();
      $query = $connection->select('flood', 'f')
        ->fields('f')
        ->condition('event', $vote_event)
        ->execute()
        ->fetchAll();

      \Drupal::logger('sl_signalements')->info('DEBUG FLOOD: Il y a @count entrée(s) pour @event', [
        '@count' => count($query),
        '@event' => $vote_event,
      ]);

      if (!$bypass_limit && !$flood->isAllowed($vote_event, 1, 86400, $vote_identifier)) {
        return new JsonResponse(['error' => $this->t('Vous avez déjà effectué un signalement pour ce combat!')], 403);
      }

      // 📦 4. LOAD NODE
      $node = Node::load($id);
      if (!$node || $node->bundle() !== 'confrontation') {
        return new JsonResponse(['error' => $this->t('Confrontation introuvable')], 404);
      }

      // ✅ Register flood
      $flood->register('sl_spam', 60, $spam_identifier);
      $flood->register($vote_event, 86400, $vote_identifier);

      // 📥 5. LECTURE BODY JSON
      $content = $request->getContent();
      $athlete_name = NULL;
      $lutteur_nid  = NULL;

      if (!empty($content)) {
        $body = json_decode($content, TRUE);

        if (isset($body['athlete_name'])) {
          $athlete_name = trim($body['athlete_name']);
        }

        if (isset($body['lutteur_nid'])) {
          $lutteur_nid = (int) $body['lutteur_nid'];
        }
      }

      // 📊 6. COMPTEURS (conservés à titre indicatif/statistique uniquement :
      // ne déclenche plus aucune action - voir point 7).
      $raw       = $node->get('field_signalements')->value;
      $data_json = $this->decodeJson($raw);

      $data_json[$type] = ($data_json[$type] ?? 0) + 1;
      $count            = $data_json[$type];

      $node->set('field_signalements', Json::encode($data_json));

      // 🔥 7. DÉCLENCHEMENT DE L'ACTION
      //
      // L'ancien mécanisme à seuil (count >= max_nb_signalements) est
      // désormais désactivé : SEUL le rôle admin conserve son super pouvoir
      // (bypass_limit = is_admin || is_logged_in || force_apply) et exécute
      // l'action immédiatement, sans aucun contrôle.
      //
      // Pour tout le reste (utilisateur anonyme, sans force_apply), CHAQUE
      // signalement est désormais soumis à une validation IA préalable
      // (queue sl_signalements_ai_queue, prompt configurable via
      // /admin/config/content/sl-signalements), à l'image de
      // combats_manquants : l'action n'est appliquée en base QUE si l'IA la
      // confirme, de façon totalement asynchrone (cron).
      if ($bypass_limit) {

        // 📝 Log dblog en error() pour TOUTE action confirmée exécutée en
        // mode "super pouvoir" (bypass : admin, connecté ou force_apply),
        // qu'elle vienne d'un internaute anonyme ou d'un utilisateur
        // connecté/admin (choix assumé de Sene, même convention que pour
        // les actions confirmées par l'IA dans SlSignalementsAiQueue).
        \Drupal::logger('sl_signalements')->error(
          "SIGNALEMENT Action | type=@type | nid=@nid | title=@title | athlete_name=@athlete_name | lutteur_nid=@lutteur_nid | variant_nid=@variant_nid | is_admin=@is_admin | is_logged_in=@is_logged_in | ip=@ip | ua=@ua",
          [
            '@type'          => $type,
            '@nid'           => $node->id(),
            '@title'         => $node->getTitle(),
            '@athlete_name'  => $athlete_name ?? '-',
            '@lutteur_nid'   => $lutteur_nid ?? '-',
            '@variant_nid'   => $variant_nid_for_token ?? '-',
            '@is_admin'      => $is_admin ? 'oui' : 'non',
            '@is_logged_in'  => $is_logged_in ? 'oui' : 'non',
            '@ip'            => $ip,
            '@ua'            => $ua,
          ]
        );

        $result = $this->tools->appliquerReglesMetier(
          $node,
          $type,
          $athlete_name,
          $lutteur_nid,
          $variant_nid_for_token
        );

        if ($result === FALSE) {
          return new JsonResponse(['status' => 'deleted', 'type' => $type]);
        }

        $data_json[$type] = 0;
        $node->set('field_signalements', Json::encode($data_json));
        $node->setNewRevision(FALSE);
        $node->save();

        return new JsonResponse([
          'status' => 'success',
          'type'   => $type,
        ]);
      }

      // Pas de bypass : mise en queue pour validation IA, SANS application
      // immédiate. On enregistre quand même le compteur ci-dessus (utile à
      // titre de statistique), puis on sauvegarde le node tel quel.
      $node->setNewRevision(FALSE);
      $node->save();

      $pendingKey = \Drupal\sl_signalements\Plugin\QueueWorker\SlSignalementsAiQueue::buildPendingKey(
        (int) $node->id(),
        $type,
        $variant_nid_for_token
      );

      if (!\Drupal\sl_signalements\Plugin\QueueWorker\SlSignalementsAiQueue::isPending($pendingKey)) {
        \Drupal::queue('sl_signalements_ai_queue')->createItem([
          'nid'          => (int) $node->id(),
          'type'         => $type,
          'athlete_name' => $athlete_name,
          'lutteur_nid'  => $lutteur_nid,
          'variant_nid'  => $variant_nid_for_token,
          'submitted'    => \Drupal::time()->getRequestTime(),
        ]);
        \Drupal\sl_signalements\Plugin\QueueWorker\SlSignalementsAiQueue::markPending($pendingKey);
      }

      return new JsonResponse([
        'status' => 'pending_ai_review',
        'type'   => $type,
      ]);
    } catch (\Throwable $e) {

      \Drupal::logger('sl_signalements')->error(
        "API ERROR | @msg | @file:@line",
        [
          '@msg'  => $e->getMessage(),
          '@file' => $e->getFile(),
          '@line' => $e->getLine(),
        ]
      );

      return new JsonResponse(['error' => $this->t('Erreur interne')], 500);
    }
  }

  private function decodeJson($raw): array
  {
    if (empty($raw)) return [];
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }
}
