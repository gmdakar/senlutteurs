<?php

namespace Drupal\combat_propositions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AutocompleteController extends ControllerBase {

  public function handleAutocomplete(Request $request) {
    // Bloquer Google et les accès directs http (on accepte que du ajax)
    if (!$request->isXmlHttpRequest()) {
      throw new AccessDeniedHttpException();
    }

    $string = $request->query->get('q');
    $matches = [];

    if ($string === NULL || $string === '') {
      return new JsonResponse($matches);
    }

    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();

    $query = \Drupal::entityQuery('node')
      ->condition('type', 'athlete')
      ->condition('title', $string, 'CONTAINS')
      ->condition('langcode', $langcode)
      ->accessCheck(FALSE)
      ->range(0, 10);
    $nids = $query->execute();

    if (!empty($nids)) {
      $nodes = \Drupal\node\Entity\Node::loadMultiple($nids);
      $seen = [];
      foreach ($nodes as $node) {
        // Prefer the translation in the current language if available.
        if ($node->hasTranslation($langcode)) {
          $node = $node->getTranslation($langcode);
        }
        $title = $node->getTitle();
        // Avoid duplicate titles in the dropdown.
        if (!isset($seen[$title])) {
          $matches[] = ['value' => $title];
          $seen[$title] = TRUE;
        }
      }
    }

    return new JsonResponse($matches);
  }
}
