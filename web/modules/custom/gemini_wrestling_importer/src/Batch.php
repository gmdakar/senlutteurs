<?php

namespace Drupal\gemini_wrestling_importer;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\Entity\Node;
use Drupal\shared_lib\Tools\SomeTools;

/**
 * Provides the batch operations for importing wrestling fights.
 */
class Batch {
  use StringTranslationTrait;

  /**
   * Importer les combats d'un lutteur.
   * Compatible Batch UI et Drush CLI.
   */
  public static function importWrestlerFights($nid, array &$context = []) {
    $service = \Drupal::service('gemini_wrestling_importer.service');
    $node    = Node::load($nid);

    if (!isset($context['results'])) {
      $context['results'] = [];
    }

    if (!$node || $node->getType() !== 'athlete') {
      $context['results'][] = "Lutteur $nid introuvable.";
      return;
    }

    $wrestlerName       = $node->getTitle();
    $context['message'] = t('Traitement des combats pour @name...', ['@name' => $wrestlerName]);

    $config = \Drupal::config('gemini_wrestling_importer.settings');
    $engine = $config->get('wrestling_importer_use_llm') ?? 'grok';
    $mode   = $config->get('wrestling_importer_use_diff') ? 'diff' : 'normal';
    $result = $service->getFightsFromAiRequest($wrestlerName, $engine, $mode);

    if (!empty($result['success']) && !empty($result['fights'])) {
      // La détection diff/normal est déléguée à importFights().
      $service->importFights($result['fights'], $wrestlerName);
      $context['results'][]    = "Import terminé pour {$wrestlerName}.";
      $context['wrestlerName'] = "Traitement de $wrestlerName";
    }
    else {
      $error                = $result['error'] ?? 'aucun combat trouvé';
      $context['results'][] = "Échec pour {$wrestlerName} : {$error}.";
    }
  }

  /**
   * ########## IMPORTANT  !!!! #############: Pour utiliser cette fonction alors modifier le fichier
   * web\modules\custom\gemini_wrestling_importer\src\Plugin\QueueWorker\GeminiWrestlingQueueWorker.php
   */
  public static function importWrestlersForceUpdate($nid, array &$context = []) {
    $node = Node::load($nid);

    if (!isset($context['results'])) {
      $context['results'] = [];
    }

    if (!$node || $node->getType() !== 'athlete') {
      $context['results'][] = "Lutteur $nid introuvable.";
      return;
    }

    $node         = Node::load($nid);
    $wrestlerName = trim($node->getTitle());

    if ($node->hasField('field_athlete_nom_technique')) {
      if ($node->get('field_athlete_nom_technique')->isEmpty()) {
        $value = SomeTools::technicalWrestlerName($wrestlerName);
      }
      else {
        $value = $node->get('field_athlete_nom_technique')->value;
      }

      $value = str_replace('_', '', $value);

      $node->set('field_athlete_nom_technique', $value);
      $node->setTitle($wrestlerName);
      $node->save();

      $context['message']      = t('Traitement du lutteur pour @name...', ['@name' => $wrestlerName]);
      $context['results'][]    = "Import terminé pour {$wrestlerName}.";
      $context['wrestlerName'] = "Traitement de $wrestlerName";
    }
  }

  /**
   * Callback de fin de batch.
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      \Drupal::messenger()->addStatus(t('L\'importation des combats a été terminée avec succès.'));
    }
    else {
      \Drupal::messenger()->addError(t('L\'importation a échoué. Veuillez vérifier les logs.'));
    }

    if (!empty($results)) {
      foreach ($results as $message) {
        \Drupal::logger('gemini_wrestling_importer')->notice($message);
      }
    }
  }
}