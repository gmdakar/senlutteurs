<?php

namespace Drupal\combat_propositions\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\shared_lib\Tools\SomeTools;

class CombatProposalForm extends FormBase {

  public function getFormId() {
    return 'combat_proposal_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['lutteur_1'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fighter 1'),
      '#autocomplete_route_name' => 'combat_propositions.autocomplete_lutteur',
      '#required' => TRUE,
      '#attributes' => [
      'placeholder' => $this->t('Enter the first few letters of his name..'),
    ],
    ];
    $form['lutteur_2'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fighter 2'),
      '#autocomplete_route_name' => 'combat_propositions.autocomplete_lutteur',
      '#required' => TRUE,
      '#attributes' => [
      'placeholder' => $this->t('Enter the first few letters of his name..'),
    ],
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Propose'),
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'wrapper' => 'combat-proposal-form-container',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('In progress...'),
        ],
      ],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $lutteur_1_raw = $form_state->getValue('lutteur_1');
    $lutteur_2_raw = $form_state->getValue('lutteur_2');

    // CORRECT : On enlève le "\" devant SomeTools car il est importé via "use" en haut du fichier
    $lutteur_1_tech = SomeTools::technicalWrestlerName(SomeTools::correctNames($lutteur_1_raw));
    $lutteur_2_tech = SomeTools::technicalWrestlerName(SomeTools::correctNames($lutteur_2_raw));

    // 2. Vérification d'unicité sur les noms techniques
    if ($lutteur_1_tech === $lutteur_2_tech) {
      $form_state->setErrorByName('lutteur_2', $this->t('Les deux lutteurs doivent être différents.'));
      return;
    }

    $node_storage = \Drupal::entityTypeManager()->getStorage('node');

    // 3. Fonction de vérification de l'existence du node "athlete"
    $check_exists = function ($tech_name) use ($node_storage) {
      $nids = $node_storage->getQuery()
        ->condition('type', 'athlete')
        ->condition('field_athlete_nom_technique', $tech_name)
        ->accessCheck(FALSE)
        ->range(0, 1)
        ->execute();
      return !empty($nids);
    };

    // 4. Validation finale : si le nom technique généré n'existe pas en base
    if (!$check_exists($lutteur_1_tech)) {
      $form_state->setErrorByName('lutteur_1', $this->t('Le lutteur "@name" n\'est pas reconnu.', ['@name' => $lutteur_1_raw]));
    }

    if (!$check_exists($lutteur_2_tech)) {
      $form_state->setErrorByName('lutteur_2', $this->t('Le lutteur "@name" n\'est pas reconnu.', ['@name' => $lutteur_2_raw]));
    }
    
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $lutteur_1 = $form_state->getValue('lutteur_1');
    $lutteur_2 = $form_state->getValue('lutteur_2');

    $database = \Drupal::database();
    $session = \Drupal::requestStack()->getCurrentRequest()->getSession();

    $query = $database->select('combat_propositions', 'c')
      ->fields('c', ['id', 'proposals']);

    $and1 = $query->andConditionGroup()
      ->condition('lutteur_1', $lutteur_1)
      ->condition('lutteur_2', $lutteur_2);

    $and2 = $query->andConditionGroup()
      ->condition('lutteur_1', $lutteur_2)
      ->condition('lutteur_2', $lutteur_1);

    $or = $query->orConditionGroup()
      ->condition($and1)
      ->condition($and2);

    $query->condition($or);

    $existing = $query->execute()->fetchObject();
    \Drupal::logger('combat_propositions')->notice('existing: ' . ($existing ? 'true' : 'false'));

    if ($existing) {
      // Maintenant on est sûr que $existing->id existe.
      $has_voted = $session->get('combat_vote_' . $existing->id, FALSE);
      \Drupal::logger('combat_propositions')->notice('combat_vote_' . $existing->id . ': ' . ($has_voted ? 'true' : 'false'));

      if (!$has_voted) { // éviter le multivote pour le même navigateur
        $database->update('combat_propositions')
          ->fields(['proposals' => $existing->proposals + 1])
          ->condition('id', $existing->id)
          ->execute();

        // marquer en session que ce navigateur a proposé pour ce combat
        $session->set('combat_vote_' . $existing->id, TRUE);
      }
    }
    else {
      // insertion et récupération de l'ID inséré
      $inserted_id = $database->insert('combat_propositions')
        ->fields([
          'lutteur_1' => $lutteur_1,
          'lutteur_2' => $lutteur_2,
          'proposals' => 1,
          'votes' => 0,
          'created' => \Drupal::time()->getRequestTime(),
        ])
        ->execute();

      // marquer en session pour éviter multi-insert depuis le même navigateur
      if ($inserted_id) {
        $session->set('combat_vote_' . $inserted_id, TRUE);
      }
    }
  }

  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    if ($form_state->getErrors()) {
      $response->addCommand(new HtmlCommand('#combat-proposal-form-container', $form));
    }
    else {
      $message = $this->t('Your suggestion has been successfully recorded. Thank you!');
      $response->addCommand(new HtmlCommand('#combat-proposal-form-container', '<div class="combat-proposal-success">' . $message . '</div>'));

       // Charger le max  de propositions de votes depuis la config
      $config = \Drupal::config('misc_divers.settings');
      $max_votes_proposition = (int) ($config->get('max_votes_proposition') ?? 5);
      $query = \Drupal::database()->select('combat_propositions', 'c');
      $query->fields('c', ['id', 'lutteur_1', 'lutteur_2', 'proposals', 'votes']);
      $query->addExpression('c.proposals + c.votes', 'proposalsvotes');
      $query->orderBy('proposalsvotes', 'DESC');
      $query->range(0, $max_votes_proposition);

      $results = $query->execute()->fetchAll();


      $items = [];
      $session = \Drupal::requestStack()->getCurrentRequest()->getSession();

      foreach ($results as $row) {
        $combat_id = $row->id;
        $has_voted = $session->get('combat_vote_' . $combat_id, FALSE);

        //$text = $this->t('@lutteur1 / @lutteur2 — Propositions: @proposals Votes: @votes', [
        $text = $this->t('@lutteur1 / @lutteur2 Votes: @votes', [
          '@lutteur1' => $row->lutteur_1,
          '@lutteur2' => $row->lutteur_2,
          //'@proposals' => $row->proposals,
          '@votes' => $row->votes + $row->proposals,
        ]);

        if ($has_voted) {
          $items[] = [
            '#markup' => '<div id="combat-vote-message-' . $combat_id . '">' . $text . ' (' . $this->t('You have already voted') . ')</div>',
          ];
        }
        else {
          $url = Url::fromRoute('combat_propositions.vote', ['id' => $combat_id]);
          $link = Link::fromTextAndUrl($this->t('Voter'), $url);
          $link_render = $link->toRenderable();
          $link_render['#attributes']['class'][] = 'use-ajax';

          $items[] = [
            '#markup' => '<div id="combat-vote-message-' . $combat_id . '">' . $text . ' </div>',
            'vote_link' => $link_render,
          ];
        }
      }

      $list_render = [
        '#theme' => 'item_list',
        '#items' => $items,
      ];

      $renderer = \Drupal::service('renderer');
      $rendered_list = $renderer->renderRoot($list_render);

      $response->addCommand(new HtmlCommand('#combat-propositions-list', $rendered_list));
    }

    return $response;
  }
}