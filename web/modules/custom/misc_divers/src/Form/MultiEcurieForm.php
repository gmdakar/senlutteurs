<?php
namespace Drupal\misc_divers\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

class MultiEcurieForm extends FormBase {

  protected $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container) {
    return new static($container->get('entity_type.manager'));
  }

  public function getFormId() {
    return 'misc_divers_multi_ecurie_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    // Récupération des options (Nodes de type Ecurie)
    $options = $this->getEcurieOptions();

    $form['#tree'] = TRUE; // Crucial pour structurer les données envoyées
    $form['container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'ecurie-fieldset-wrapper'],
    ];

    // Initialisation du nombre d'items
    $saved_data = \Drupal::state()->get('misc_divers.ecuries_data', []);
    $num_items = $form_state->get('num_items');
    if (empty($num_items)) {
        $num_items = count($saved_data) ?: 1;
        $form_state->set('num_items', $num_items);
    }

    for ($i = 0; $i < $num_items; $i++) {
      $form['container']['items'][$i] = [
        '#type' => 'details',
        '#title' => $this->t('Sous-section @num', ['@num' => $i + 1]),
        '#open' => TRUE,
      ];

      $form['container']['items'][$i]['ecuries'] = [
        '#type' => 'select',
        '#title' => $this->t('Sélectionner les écuries'),
        '#options' => $options,
        '#multiple' => TRUE,
        '#size' => 10,
        '#default_value' => $this->getSavedValue($i), // Pour la persistance au chargement
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    
    $form['actions']['add_item'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add item'),
      '#submit' => ['::addOne'],
      '#ajax' => [
        'callback' => '::addMoreCallback',
        'wrapper' => 'ecurie-fieldset-wrapper',
      ],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sauvegarder'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Callback AJAX pour retourner le conteneur mis à jour.
   */
  public function addMoreCallback(array &$form, FormStateInterface $form_state) {
    return $form['container'];
  }

  /**
   * Incrémente le compteur d'items.
   */
  public function addOne(array &$form, FormStateInterface $form_state) {
    $num_items = $form_state->get('num_items') + 1;
    $form_state->set('num_items', $num_items);
    $form_state->setRebuild();
  }

  /**
   * Récupère la liste des titres de nodes 'ecurie'.
   */
  private function getEcurieOptions() {
    $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['type' => 'ecurie']);
    $options = [];
    foreach ($nodes as $node) {
      $options[$node->id()] = $node->label(). ' ('.$node->id().')';
    }
    asort($options);
    return $options;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue(['container', 'items']);
    // Nettoyage et sauvegarde dans l'ED de l'état (State API) ou config
    \Drupal::state()->set('misc_divers.ecuries_data', $values);
    
    $this->messenger()->addStatus($this->t('Configuration sauvegardée avec succès.'));
  }

  private function getSavedValue($index) {
    $saved = \Drupal::state()->get('misc_divers.ecuries_data', []);
    return $saved[$index]['ecuries'] ?? [];
  }
}