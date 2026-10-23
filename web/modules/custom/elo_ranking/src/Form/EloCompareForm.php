<?php

namespace Drupal\elo_ranking\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class EloCompareForm extends FormBase {

  public function getFormId() {
    return 'elo_compare_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attributes'] = ['id' => 'elo-compare-form-wrapper'];

    $form['athlete1'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'node',
      '#title' => $this->t('Lutteur 1'),
      '#selection_settings' => ['target_bundles' => ['athlete']],
      '#attributes' => [
        'data-id' => '1',
        'class' => ['athlete-autocomplete'],
        'placeholder' => $this->t('Nom du lutteur 1...'),
      ],
    ];

    $form['athlete2'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'node',
      '#title' => $this->t('Lutteur 2'),
      '#selection_settings' => ['target_bundles' => ['athlete']],
      '#attributes' => [
        'data-id' => '2',
        'class' => ['athlete-autocomplete'],
        'placeholder' => $this->t('Nom du lutteur 2...'),
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['compare'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Comparer'),
      '#attributes' => [
        'id' => 'elo-compare-btn',
        'type' => 'button',
        'class' => ['button', 'button--primary'],
      ],
    ];

    $form['actions']['reset'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Réinitialiser'),
      '#attributes' => [
        'id' => 'elo-reset-btn',
        'type' => 'button',
        'class' => ['button'],
      ],
    ];

    $form['chart_area'] = [
      '#markup' => '<div id="elo-chart-container" style="min-height: 450px; width: 100%; margin-top: 30px; position: relative;"></div>',
    ];

    $form['#attached']['library'][] = 'core/drupal.autocomplete';
    $form['#attached']['library'][] = 'elo_ranking/elo_compare';

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {}
}