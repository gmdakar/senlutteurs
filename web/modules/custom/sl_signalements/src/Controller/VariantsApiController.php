<?php

namespace Drupal\sl_signalements\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Site\Settings;
use Drupal\Component\Utility\Crypt;
use Drupal\node\Entity\Node;

/**
 * Endpoint GET /api/sl/variants/{nid}
 *
 * Retourne les variantes de noms pour les deux athlètes d'une confrontation,
 * afin que le front puisse afficher des boutons supplémentaires de signalement.
 *
 * Réponse exemple :
 * {
 *   "athlete_1": {
 *     "nid": 42, "name": "Talfa",
 *     "variants": [
 *       { "nid": 87, "name": "Talfa 2",     "token": "abc123", "slot": "athlete_1" },
 *       { "nid": 91, "name": "Talfa Junior", "token": "def456", "slot": "athlete_1" }
 *     ]
 *   },
 *   "athlete_2": { ... }
 * }
 */
class VariantsApiController extends ControllerBase {

  public function variants(int $nid): JsonResponse {

    $node = Node::load($nid);
    if (!$node || $node->bundle() !== 'confrontation') {
      return new JsonResponse(['error' => 'Confrontation introuvable'], 404);
    }

    $salt   = Settings::getHashSalt();
    $result = [];

    foreach (['athlete_1' => 'field_athlete_1', 'athlete_2' => 'field_athlete_2'] as $slot => $field) {
      $field_obj = $node->get($field);
      $athlete   = ($field_obj && !$field_obj->isEmpty()) ? $field_obj->entity : NULL;

      if (!$athlete) {
        $result[$slot] = NULL;
        continue;
      }

      $base_name = $athlete->label();
      $variants  = $this->findVariants($base_name, (int) $athlete->id());

      // Génère un token HMAC pour chaque variante trouvée
      // Format token : HMAC( nid:type:variant_nid , salt )
      $variants_with_tokens = [];
      foreach ($variants as $v) {
        $type         = 's_variante_' . $slot; // s_variante_athlete_1 | s_variante_athlete_2
        $data         = $nid . ':' . $type . ':' . $v['nid'];
        $raw_token    = Crypt::hmacBase64($data, $salt);
        $safe_token   = str_replace(['+', '/', '='], ['-', '_', ''], $raw_token);

        $variants_with_tokens[] = [
          'nid'   => $v['nid'],
          'name'  => $v['name'],
          'token' => $safe_token,
          'slot'  => $slot,
          'type'  => $type,
        ];
      }

      $result[$slot] = [
        'nid'      => (int) $athlete->id(),
        'name'     => $base_name,
        'variants' => $variants_with_tokens,
      ];
    }

    return new JsonResponse($result);
  }

  /**
   * findVariants - Version ultra-robuste pour lutteurs sénégalais
   *
   * Règles de nettoyage appliquées dans l'ordre :
   * 1. Supprimer tout le contenu entre parenthèses
   * 2. Supprimer les suffixes numériques (2, 3, n°2, #3, N° 4, etc.)
   * 3. Supprimer les suffixes textuels courants (Junior, Jr, Senior, Sr, I, II, III, IV, V, etc.)
   * 4. Nettoyage avancé des espaces et caractères spéciaux
   * 5. Retourner TOUS les athlètes dont le titre COMMENCE par le préfixe obtenu
   *
   * @param string $name     Nom complet de l'athlète
   * @param int    $exclude  NID à exclure (l'athlète actuel)
   * @return array<array{nid: int, name: string}>
   */
  /**
   * findVariants - Version corrigée et renforcée
   */
  private function findVariants(string $name, int $exclude): array
  {
      if (empty(trim($name))) {
          return [];
      }

      $base = $this->athleteBaseName($name);

      if (mb_strlen($base) < 4) {
          return [];
      }

      $storage = \Drupal::entityTypeManager()->getStorage('node');

      $query = $storage->getQuery()
          ->condition('type', 'athlete')
          ->condition('title', $base . '%', 'LIKE')
          ->condition('status', 1)
          ->condition('nid', $exclude, '!=')
          ->accessCheck(FALSE)
          ->sort('title', 'ASC');

      $nids = $query->execute();

      $found = [];
      foreach ($nids as $nid) {
          $node = Node::load($nid);
          if ($node) {
              $found[] = [
                  'nid'  => (int) $nid,
                  'name' => $node->getTitle(),
              ];
          }
      }

      return $found;
  }

  /**
   * Normalisation améliorée - Spécialement pour "Pape Mbaye 2 (Fadam)"
   */
  private function athleteBaseName(string $name): string
  {
      $base = trim($name);

      // 1. Supprimer tout entre parenthèses
      $base = preg_replace('/\s*\([^)]*\)/u', ' ', $base);

      // 2. Supprimer les suffixes numériques et "2", "3", etc. plus agressivement
      $base = preg_replace('/\s+(?:n°?|#|no\.?|numéro)?\s*\d+\b/ui', '', $base);
      $base = preg_replace('/\s+\d+(?:er|ème)?\b/ui', '', $base);
      $base = preg_replace('/\s+\d+$/u', '', $base);

      // 3. Supprimer les suffixes textuels
      $suffixes = [
          'Junior', 'Jr', 'Senior', 'Sr',
          'I', 'II', 'III', 'IV', 'V', 'VI',
          'Petit', 'Grand', 'Jeune', 'Aîné', 'Aine'
      ];

      $pattern = '/\s+(?:' . implode('|', array_map('preg_quote', $suffixes)) . ')\b/ui';
      $base = preg_replace($pattern, '', $base);

      // 4. Nettoyage des espaces
      $base = preg_replace('/\s+/', ' ', $base);
      $base = trim($base);

      return $base;
  }

}