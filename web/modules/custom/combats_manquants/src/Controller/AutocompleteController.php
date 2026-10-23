<?php

namespace Drupal\combats_manquants\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Autocomplétion "lutteur" pour le formulaire de proposition de combat.
 *
 * Contrairement à l'autocomplétion basique de combat_propositions, celle-ci
 * renvoie également le nom de l'écurie de chaque lutteur trouvé (clé
 * "ecurie" supplémentaire, en plus des clés standard "value"/"label"
 * attendues par le widget jQuery UI autocomplete de Drupal core), afin que
 * le JS du formulaire puisse pré-remplir automatiquement le champ caché
 * "écurie" correspondant lors de la sélection (voir combats_manquants.js).
 */
class AutocompleteController extends ControllerBase {

  public function handleAutocomplete(Request $request) {
    // Bloquer Google et les accès directs http (on accepte que du ajax).
    if (!$request->isXmlHttpRequest()) {
      throw new AccessDeniedHttpException();
    }

    $string = $request->query->get('q');
    $matches = [];

    if (trim((string) $string) === '') {
      return new JsonResponse($matches);
    }

    $query = \Drupal::entityQuery('node')
      ->condition('type', 'athlete')
      ->condition('title', $string, 'CONTAINS')
      ->accessCheck(FALSE)
      ->sort('title', 'ASC')
      ->range(0, 25);
    $nids = $query->execute();

    if (!empty($nids)) {
      $nodes = \Drupal\node\Entity\Node::loadMultiple($nids);
      foreach ($nodes as $node) {
        $ecurieLabel = '';
        if ($node->hasField('field_ecurie') && !$node->get('field_ecurie')->isEmpty()) {
          $ecurieEntity = $node->get('field_ecurie')->entity;
          if ($ecurieEntity) {
            $ecurieLabel = (string) $ecurieEntity->label();
          }
        }

        $matches[] = [
          // Valeur injectée dans le champ texte à la sélection : le nom
          // seul, pour rester compatible avec la validation par nom
          // technique côté formulaire.
          'value' => $node->getTitle(),
          // Libellé affiché dans la liste déroulante : nom + écurie pour
          // lever les homonymies.
          'label' => $ecurieLabel !== ''
            ? $node->getTitle() . ' / ' . $ecurieLabel
            : $node->getTitle(),
          // Clé additionnelle, lue par combats_manquants.js (ui.item.ecurie)
          // pour pré-remplir le champ caché "écurie" correspondant.
          'ecurie' => $ecurieLabel,
        ];
      }
    }

    return new JsonResponse($matches);
  }

}
