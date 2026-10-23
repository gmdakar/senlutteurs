<?php

namespace Drupal\combat_propositions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class VoteController extends ControllerBase {

 /**
   * Enregistre un vote pour une proposition de combat.
   */
  public function vote(Request $request, $id) {
    // 1. SÉCURITÉ ANTI-ROBOT : Bloque l'accès direct (non-AJAX)
    // C'est ici que Googlebot recevra une 403 et verra ton NOINDEX.
    if (!$request->isXmlHttpRequest()) {
      throw new AccessDeniedHttpException();
    }

    $response = new AjaxResponse();
    $database = \Drupal::database();
    
    // On récupère la session proprement via la requête
    $session = $request->getSession();

    // 2. VÉRIFICATION DOUBLE VOTE
    if ($session->get('combat_vote_' . $id, FALSE)) {
      $message = $this->t('Vous avez déjà voté pour cette proposition.');
      $response->addCommand(new HtmlCommand('#combat-vote-message-' . $id, $message));
      return $response;
    }

    // 3. MISE À JOUR DES VOTES
    $database->update('combat_propositions')
      ->fields(['votes' => 1])
      ->condition('id', $id)
      ->expression('votes', 'votes + 1')
      ->execute();

    // Marquer le vote en session
    $session->set('combat_vote_' . $id, TRUE);

    // 4. RÉCUPÉRATION DES DONNÉES POUR LE MESSAGE DE RETOUR
    $row = $database->select('combat_propositions', 'c')
      ->fields('c', ['lutteur_1', 'lutteur_2', 'proposals', 'votes'])
      ->condition('id', $id)
      ->execute()
      ->fetchObject();

    // SÉCURITÉ : Si l'ID n'existe pas ou a été supprimé (évite les Warnings PHP)
    if (!$row) {
      $message = $this->t("Cette proposition n'est plus disponible.");
      $response->addCommand(new HtmlCommand('#combat-vote-message-' . $id, $message));
      return $response;
    }

    // 5. CONSTRUCTION DU MESSAGE (Sécurisé pour le multilingue avec le Cast (string))
    $text = $this->t('@lutteur1 / @lutteur2 Votes: @votes', [
      '@lutteur1' => (string) $row->lutteur_1,
      '@lutteur2' => (string) $row->lutteur_2,
      '@votes' => (int) ($row->votes + (isset($row->proposals) ? $row->proposals : 0)),
    ]);

    $message = $text . ' (' . $this->t('Merci pour votre vote !') . ')';

    // 6. ENVOI DE LA RÉPONSE AJAX
    $response->addCommand(new HtmlCommand('#combat-vote-message-' . $id, $message));

    return $response;
  }

}