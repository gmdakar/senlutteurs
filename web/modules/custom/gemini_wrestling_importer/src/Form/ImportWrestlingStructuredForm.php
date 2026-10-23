<?php

namespace Drupal\gemini_wrestling_importer\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\shared_lib\Tools\SomeTools;

/**
 * Formulaire d’import structuré des combats (lutteur / JSON).
 */
class ImportWrestlingStructuredForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['gemini_wrestling_importer.fight_pairs'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'import_wrestling_structured_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('gemini_wrestling_importer.fight_pairs');

    // Initialisation des données du formulaire
    if (!$form_state->has('num_pairs')) {
      $initial_pairs = $config->get('pairs') ?? [];
      $form_state->set('num_pairs', max(1, count($initial_pairs)));
      $form_state->set('pairs_data', $initial_pairs);
    }

    $num_pairs = $form_state->get('num_pairs');
    $pairs_data = $form_state->get('pairs_data');

    $form['#tree'] = TRUE;

    // Récupération des lutteurs existants
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $nids = $node_storage->getQuery()
      ->condition('type', 'athlete')
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->sort('title', 'ASC')
      ->execute();
    $athletes = $node_storage->loadMultiple($nids);

    $fighter_options = ['' => $this->t('- Sélectionner un lutteur -')];
    foreach ($athletes as $athlete) {
      $fighter_options[$athlete->getTitle()] = $athlete->getTitle();
    }

    // Récupérer les valeurs actuelles du formulaire ou les données sauvegardées
    // Utiliser getValue avec pairs_data comme valeur par défaut, car pairs_data
    // est mis à jour dans removePairSubmit et est la source la plus fiable après l'action AJAX.
    $pairs_to_display = $form_state->getValue('pairs', $pairs_data);
    $pairs_to_display = array_pad($pairs_to_display, $num_pairs, ['fighter' => '', 'fights' => '']);

    $form['pairs'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Entrées lutteur / combats JSON'),
      '#tree' => TRUE,
      '#prefix' => '<div id="pairs-wrapper">',
      '#suffix' => '</div>',
    ];

    // Construction dynamique des paires
    for ($index = 0; $index < $num_pairs; $index++) {
      $pair = $pairs_to_display[$index] ?? ['fighter' => '', 'fights' => ''];
      $fighter_name = $pair['fighter'] ?? ''; // Assure que c'est une chaîne pour #title

      $form['pairs'][$index] = [
        '#type' => 'details',
        '#title' => $this->t('Entrée @num (@fighter)', [
          '@num' => $index + 1,
          '@fighter' => $fighter_name ?: $this->t('Nouvelle')
        ]),
        '#open' => TRUE,
      ];

      $form['pairs'][$index]['fighter'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Nom du lutteur'),
        '#default_value' => $fighter_name,
        '#required' => TRUE,
        '#autocomplete_route_name' => 'gemini_wrestling_importer.autocomplete_athlete',
      ];


      $form['pairs'][$index]['fights'] = [
        '#type' => 'textarea',
        '#title' => $this->t('JSON des combats'),
        '#default_value' => $pair['fights'] ?? '',
        '#description' => $this->t('Collez ici le JSON des combats pour ce lutteur.<br>NB: Pour forcer la mise à jour (add) uniquement des liens vidéos de ces combats, saisir juste le mot clé __update_videos__'),
        '#required' => TRUE,
      ];

      // Bouton pour importer cette entrée individuellement
      $form['pairs'][$index]['import'] = [
        '#type' => 'submit',
        '#value' => $this->t('Importer cette entrée'),
        '#submit' => ['::importSinglePairSubmit'],
        '#name' => 'import-' . $index,
        '#limit_validation_errors' => [['pairs', $index]],
      ];

      // Bouton de suppression
      $form['pairs'][$index]['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Supprimer cette entrée'),
        '#submit' => ['::removePairSubmit'],
        '#name' => 'remove-' . $index,
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::ajaxRebuildForm',
          'wrapper' => 'pairs-wrapper',
        ],
        '#attributes' => ['class' => ['button', 'button--danger']],
      ];
    }

    $form['actions'] = ['#type' => 'actions'];

    // Bouton ajouter une entrée
    $form['actions']['add_pair'] = [
      '#type' => 'submit',
      '#value' => $this->t('Ajouter une entrée'),
      '#submit' => ['::addPairSubmit'],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::ajaxRebuildForm',
        'wrapper' => 'pairs-wrapper',
      ],
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Enregistrer les entrées'),
      '#button_type' => 'primary',
    ];

    return parent::buildForm($form, $form_state);
  }

  // Ajouter une paire
  public function addPairSubmit(array &$form, FormStateInterface $form_state) {
    $form_state->set('num_pairs', $form_state->get('num_pairs') + 1);
    $form_state->setRebuild(TRUE);
  }

/**
   * Supprimer un couple spécifique.
   */
  public function removePairSubmit(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $name = $triggering_element['#name'];

    if (preg_match('/remove-(\d+)/', $name, $matches)) {
      $index_to_remove = (int) $matches[1];
      $pairs = $form_state->getValue('pairs') ?? [];

      if (isset($pairs[$index_to_remove])) {
        unset($pairs[$index_to_remove]);
        $pairs = array_values($pairs);
        $form_state->setValue('pairs', $pairs);

        $num_pairs = $form_state->get('num_pairs');
        if ($num_pairs > 0) {
          $form_state->set('num_pairs', $num_pairs - 1);
        }

        $form_state->setRebuild(TRUE);
      }
    }
  }

  public function importSinglePairSubmit(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $name = $triggering_element['#name'];

    if (preg_match('/import-(\d+)/', $name, $matches)) {
      $index = (int) $matches[1];
      $pairs = $form_state->getValue('pairs', []);
      $pair = $pairs[$index] ?? null;

      if (!$pair) {
        $this->messenger()->addError($this->t('Impossible de trouver cette entrée.'));
        return;
      }

      $fighter = $pair['fighter'] ?? '';
      $combats_json = $pair['fights'] ?? '';

      //In case we just want to force (reset) only and update (api youtube search) of all videos links (face 2 face and combats/fights)
      if ($combats_json === '__update_videos__') 
      {
        $imported = \Drupal::service('gemini_wrestling_importer.import_service')->updateVideosFights($fighter);
        if ($imported)
          $this->messenger()->addStatus($this->t('Mise à jour effectué pour tous les liens videos des combats de @fighter.', ['@fighter' => $fighter]));
        return;
      }

      $decoded_data = SomeTools::decodeJson($combats_json, __METHOD__);
      
      // Adaptation de la structure de données pour le service d'importation
      if (isset($decoded_data['combats_lutte']) && is_array($decoded_data['combats_lutte'])) {
        $combats_array = $decoded_data['combats_lutte'];
      } elseif (is_array($decoded_data)) {
        $combats_array = $decoded_data;
      } else {
        $combats_array = [];
      }

      if (empty($fighter) || empty($combats_array)) {
        $this->messenger()->addError($this->t('Veuillez remplir le lutteur et fournir un JSON de combats valide.'));
        return;
      }
      
      $data_to_import = ['combats_lutte' => $combats_array];
      
      $imported = \Drupal::service('gemini_wrestling_importer.import_service')
        ->importFights($combats_array, $fighter);

      if ($imported) {
        $count = count($combats_array);
        $this->messenger()->addStatus($this->t('@count combat(s) importé(s) avec succès pour @fighter.', ['@count' => $count, '@fighter' => $fighter]));
      } else {
        $this->messenger()->addWarning($this->t('Aucun combat n\'a pu être importé pour @fighter. (Vérifiez le format JSON).', ['@fighter' => $fighter]));
      }
    }
  }

  public function ajaxRebuildForm(array &$form, FormStateInterface $form_state) {
    return $form['pairs'];
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $pairs = $form_state->getValue('pairs', []);
    $cleaned_pairs = [];
    foreach ($pairs as $pair) {
      if (!empty($pair['fighter']) || !empty($pair['fights'])) {
        $cleaned_pairs[] = $pair;
      }
    }

    $this->configFactory()
      ->getEditable('gemini_wrestling_importer.fight_pairs')
      ->set('pairs', $cleaned_pairs)
      ->save();

    $form_state->set('pairs_data', $cleaned_pairs);
    $form_state->set('num_pairs', max(1, count($cleaned_pairs)));

    $this->messenger()->addStatus($this->t('Les entrées lutteur/combats ont été enregistrées.'));
  }

}