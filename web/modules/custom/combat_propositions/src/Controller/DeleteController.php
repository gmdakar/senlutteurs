<?php

namespace Drupal\combat_propositions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

class DeleteController extends ControllerBase {

  /**
   * Supprime une proposition de combat de la base de données.
   *
   * @param int $id
   * L'ID de la proposition de combat à supprimer.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   * Redirige vers la page d'accueil avec un message de statut.
   */
  public function delete($id) {
    // Vérification de la permission.
    if (!\Drupal::currentUser()->hasPermission('administer combat propositions')) {
      \Drupal::messenger()->addError($this->t('Accès refusé. Cette action est réservée à un administrateur.'));
      return $this->redirect('<front>');
    }

    $database = \Drupal::database();

    try {
      // Exécution de la suppression
      $deleted_rows = $database->delete('combat_propositions')
        ->condition('id', $id)
        ->execute();

      if ($deleted_rows) {
        \Drupal::messenger()->addStatus($this->t('La proposition de combat n°@id a été supprimée avec succès.', ['@id' => $id]));
      }
      else {
        \Drupal::messenger()->addWarning($this->t('La proposition de combat n\'a pas été trouvée ou n\'a pas pu être supprimée.'));
      }
    }
    catch (\Exception $e) {
      \Drupal::messenger()->addError($this->t('Une erreur est survenue lors de la suppression.'));
      \Drupal::logger('combat_propositions')->error('Erreur de suppression du combat @id: @message', [
        '@id' => $id,
        '@message' => $e->getMessage(),
      ]);
    }

    // Redirige vers la page principale.
    return $this->redirect('<front>');
  }
}