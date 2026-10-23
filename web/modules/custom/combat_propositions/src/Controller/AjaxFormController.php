<?php

namespace Drupal\combat_propositions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\combat_propositions\Form\CombatProposalForm;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AjaxFormController extends ControllerBase {

  public function loadForm(Request $request) {
    // SÉCURITÉ : On rejette Googlebot et les accès directs http (on accepte que du ajax)
    if (!$request->isXmlHttpRequest()) {
      throw new AccessDeniedHttpException();
    }

    $response = new AjaxResponse();
    $form = $this->formBuilder()->getForm(CombatProposalForm::class);
    $response->addCommand(new HtmlCommand('#combat-proposal-form-container', $form));
    return $response;
  }

}