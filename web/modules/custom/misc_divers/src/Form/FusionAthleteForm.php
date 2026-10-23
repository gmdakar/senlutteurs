<?php

namespace Drupal\misc_divers\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\Element\EntityAutocomplete;

/**
 * Interface admin pour fusionner les doublons d'un athlète pivot.
 *
 * Route : /admin/misc-divers/fusion-athlete
 * Titre  : Fusion d'athlètes (doublons)
 */
class FusionAthleteForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'misc_divers_fusion_athlete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {

    // ── Résumé du dernier run (affiché après redirect) ───────────────────────
    $result = $form_state->get('last_result');

    $form['#prefix'] = '<div class="misc-divers-fusion-athlete">';
    $form['#suffix'] = '</div>';

    $form['description'] = [
      '#type'   => 'markup',
      '#markup' => '<p>'
        . $this->t(
          'Sélectionnez le <strong>lutteur pivot</strong> (celui à conserver). '
          . 'Tous les jumeaux déclarés dans son champ <em>field_same_athlete</em> '
          . 'verront leurs combats et a_venir transférés vers lui, puis seront supprimés.'
        )
        . '</p>',
    ];

    $form['pivot'] = [
      '#type'              => 'entity_autocomplete',
      '#target_type'       => 'node',
      '#title'             => $this->t('Lutteur pivot'),
      '#description'       => $this->t('Tapez le nom du lutteur à conserver. Seuls les nœuds de type <em>athlete</em> sont proposés.'),
      '#selection_handler' => 'default',
      '#selection_settings' => [
        'target_bundles' => ['athlete'],
      ],
      '#required'          => TRUE,
      '#size'              => 60,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type'        => 'submit',
      '#value'       => $this->t('Lancer la fusion'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $raw   = $form_state->getValue('pivot');
    $nid   = (int) EntityAutocomplete::extractEntityIdFromAutocompleteInput($raw);

    if ($nid <= 0) {
      // Tenter une interprétation directe si l'utilisateur saisit juste un NID
      $nid = (int) $raw;
    }

    if ($nid <= 0) {
      $form_state->setErrorByName('pivot', $this->t('Veuillez sélectionner un lutteur valide.'));
      return;
    }

    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $node    = $storage->load($nid);

    if (!$node || $node->bundle() !== 'athlete') {
      $form_state->setErrorByName('pivot', $this->t('Le nœud sélectionné n\'est pas un athlete (NID : @nid).', ['@nid' => $nid]));
      return;
    }

    if (!$node->hasField('field_same_athlete') || $node->get('field_same_athlete')->isEmpty()) {
      $form_state->setErrorByName('pivot', $this->t(
        '@title [NID @nid] n\'a aucun jumeau déclaré dans field_same_athlete.',
        ['@title' => $node->getTitle(), '@nid' => $nid]
      ));
      return;
    }

    // Stocker le NID résolu pour submit
    $form_state->set('pivot_nid', $nid);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $pivotNid = (int) $form_state->get('pivot_nid');

    $result = \Drupal\shared_lib\Tools\SomeTools::mergeFromPivot($pivotNid);

    if ($result === NULL) {
      $this->messenger()->addError($this->t(
        'Une erreur bloquante est survenue lors de la fusion. Consultez les logs système.'
      ));
      return;
    }

    $this->messenger()->addStatus($this->t(
      'Fusion terminée pour le pivot NID @nid : @combats combat(s) transféré(s), @avenir a_venir transféré(s), @deleted doublon(s) supprimé(s).',
      [
        '@nid'     => $pivotNid,
        '@combats' => $result['combats_transferred'],
        '@avenir'  => $result['avenir_transferred'],
        '@deleted' => $result['deleted'],
      ]
    ));

    // Redirection sur le nœud pivot pour vérification immédiate
    $form_state->setRedirect('entity.node.canonical', ['node' => $pivotNid]);
  }

}
