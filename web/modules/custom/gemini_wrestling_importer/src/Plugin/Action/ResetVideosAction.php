<?php

namespace Drupal\gemini_wrestling_importer\Plugin\Action;

use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\node\NodeInterface;

/**
 * Réinitialiser les vidéos pour les confrontations.
 *
 * @Action(
 *   id = "gemini_wrestling_importer_reset_videos_action",
 *   label = @Translation("Réinitialiser les vidéos (Confrontation)"),
 *   type = "node",
 *   confirm = TRUE
 * )
 */
class ResetVideosAction extends ViewsBulkOperationsActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if (!$entity instanceof NodeInterface) {
      return;
    }

    // On cible uniquement le type "confrontation".
    if ($entity->bundle() !== 'confrontation') {
      return;
    }

    // Vérifie que le champ existe.
    if ($entity->hasField('field_reset_videos')) {
      $entity->set('field_reset_videos', 1);
      $entity->save();
    }

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    if ($object instanceof NodeInterface && $object->bundle() === 'confrontation') {
      $result = $object->access('update', $account, TRUE);
      return $return_as_object ? $result : $result->isAllowed();
    }

    return $return_as_object ? AccessResult::forbidden() : FALSE;
  }

}