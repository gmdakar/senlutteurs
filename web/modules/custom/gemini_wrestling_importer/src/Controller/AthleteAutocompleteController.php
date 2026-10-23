<?php
namespace Drupal\gemini_wrestling_importer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\node\Entity\Node;

class AthleteAutocompleteController extends ControllerBase {

  public function handleAutocomplete(Request $request) {
    $results = [];
    $string = $request->query->get('q');

    if ($string) {
      $query = \Drupal::entityTypeManager()->getStorage('node')->getQuery();
      $nids = $query
        ->condition('type', 'athlete')
        ->condition('title', $string, 'CONTAINS')
        ->accessCheck(FALSE)
        ->condition('status', 1) 
        ->range(0, 10)
        ->sort('title', 'ASC')
        ->execute();

      $athletes = Node::loadMultiple($nids);

      foreach ($athletes as $athlete) {
        $results[] = ['value' => $athlete->getTitle(), 'label' => $athlete->getTitle()];
      }
    }

    return new JsonResponse($results);
  }
}
