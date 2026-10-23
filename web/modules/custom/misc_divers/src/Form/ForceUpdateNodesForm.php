<?php

namespace Drupal\misc_divers\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\shared_lib\Tools\SomeTools;
use Drupal\search_api\Entity\Index;

/**
 * Formulaire pour forcer la mise à jour de tous les nodes d'un type.
 */
class ForceUpdateNodesForm extends FormBase {

  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  public function getFormId(): string {
    return 'misc_divers_force_update_nodes_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    /** @var \Drupal\shared_lib\Service\NodeTranslationService $translation_service */
    $translation_service = \Drupal::service('shared_lib.node_translation');
    $options = [];
    foreach ($node_types as $type) {
      $options[$type->id()] = $translation_service->isBundleTranslatable($type->id())
        ? $this->t('@label (traductible : toutes les langues du site seront complétées)', ['@label' => $type->label()])
        : $type->label();
    }

    $form['content_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Select content types to update'),
      '#options' => $options,
      '#required' => TRUE,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Force update nodes'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $selected_types = array_filter($form_state->getValue('content_types'));

    if (empty($selected_types)) {
      $this->messenger()->addError($this->t('No content type selected.'));
      return;
    }

    $batch = [
      'title' => $this->t('Updating nodes...'),
      'operations' => [],
      'finished' => '\Drupal\misc_divers\Form\ForceUpdateNodesForm::batchFinished',
      'init_message' => $this->t('Starting node update...'),
      'progress_message' => $this->t('Processed @current of @total nodes.'),
      'error_message' => $this->t('Node update has encountered an error.'),
    ];

    foreach ($selected_types as $type) {
      $nids = \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
        ->condition('type', $type)
        ->execute();

      foreach ($nids as $nid) {
        $batch['operations'][] = [
          '\Drupal\misc_divers\Form\ForceUpdateNodesForm::processSingleNode',
          [$nid],
        ];
      }
    }

    batch_set($batch);
  }

  /**
   * Traite un seul node et FORCE l'écriture de la nouvelle date en BDD.
   */
  public static function processSingleNode($nid, array &$context) {
    // 1. Reset du cache mémoire pour travailler sur des données fraîches
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $node_storage->resetCache([$nid]);

    /** @var \Drupal\node\NodeInterface $node */
    $node = $node_storage->load($nid);
    if (!$node) {
      return;
    }

    // Logique métier pour le type 'athlete'
    if ($node->bundle() === 'athlete') {
      $value = SomeTools::technicalWrestlerName(SomeTools::correctNames($node->getTitle()));
      $node->set('field_athlete_nom_technique', $value);
    }

    // Logique métier pour le type 'confrontation'
    if ($node->bundle() === 'confrontation') {
      if ($node->get('field_is_lutte_simple')->isEmpty()) {
        $node->set('field_is_lutte_simple', 0);
      }
    }

    // Désactivation de Pathauto sur cette instance pour éviter les calculs CPU
    if ($node->hasField('path')) {
      $node->path->pathauto = 0; 
    }

    // 🔥 ETAPE 1 POUR LA DATE : On force le timestamp interne du Node à "maintenant"
    $node->setChangedTime(\Drupal::time()->getRequestTime());

    // 🔥 ETAPE 2 POUR LA DATE : On force le gestionnaire de stockage de Drupal 
    // à enregistrer que la date A CHANGÉ, ce qui bypass le blocage "aucune modif".
    if (method_exists($node_storage, 'updateChangedTime')) {
      $node_storage->updateChangedTime($node);
    }

    // Simulation de l'objet original pour contourner le crash getAlias() du cœur
    if (!isset($node->original)) {
      $node->original = clone $node;
    }

    $node->setNewRevision(FALSE);

    // Types traductibles : s'assurer que le node existe dans TOUTES les langues
    // du site. Fait APRÈS le clone de $node->original ci-dessus, pour que la
    // sauvegarde considère bien ces traductions comme nouvelles. Les
    // traductions déjà existantes (éventuellement retouchées) ne sont pas touchées.
    $translations_created = [];
    try {
      $translations_created = \Drupal::service('shared_lib.node_translation')->ensureTranslations($node);
    }
    catch (\Throwable $t) {
      \Drupal::logger('misc_divers')->warning('Traductions non créées pour le node @nid : @msg', [
        '@nid' => $nid,
        '@msg' => $t->getMessage(),
      ]);
    }

    // Sécurité absolue contre le crash CLI
    try {
      $node->save();
      $context['results']['processed_nodes'][] = $node->id();
      if ($translations_created) {
        $context['results']['translations_created'] = ($context['results']['translations_created'] ?? 0) + count($translations_created);
        $context['results']['translated_nodes'] = ($context['results']['translated_nodes'] ?? 0) + 1;
      }
    }
    catch (\Throwable $t) {
      \Drupal::logger('misc_divers')->warning('Crash d’alias évité lors du forçage de date sur le node @nid : @msg', [
        '@nid' => $nid,
        '@msg' => $t->getMessage(),
      ]);
      $context['results']['errors'][] = $nid;
    }

    $context['message'] = t('Processing node: @title', ['@title' => $node->getTitle()]);
  }

  /**
   * Callback de fin de batch.
   */
  public static function batchFinished($success, $results, $operations) {
    $messenger = \Drupal::messenger();

    if ($success) {
      $count = !empty($results['processed_nodes']) ? count($results['processed_nodes']) : 0;
      $errors_count = !empty($results['errors']) ? count($results['errors']) : 0;

      $messenger->addStatus(
        t('@count nodes ont été mis à jour avec succès.', ['@count' => $count])
      );

      if (!empty($results['translations_created'])) {
        $messenger->addStatus(
          t('@count traductions créées sur @nodes nodes (langues manquantes du site).', [
            '@count' => $results['translations_created'],
            '@nodes' => $results['translated_nodes'] ?? 0,
          ])
        );
      }

      if ($errors_count > 0) {
        $messenger->addWarning(
          t('@count nodes ont ignoré le crash d’alias (voir les rapports de logs de Drupal).', ['@count' => $errors_count])
        );
      }

      // Déclenchement propre de l'indexation Search API (Max 500 items immédiats pour la RAM)
      $indexes = Index::loadMultiple();
      foreach ($indexes as $index) {
        if (!$index->status()) {
          continue;
        }
        try {
          $limit = (PHP_SAPI === 'cli') ? 500 : 200;
          $indexed = $index->indexItems($limit);
          $messenger->addStatus(
            t('Index "@index" réindexé (@count items).', [
              '@index' => $index->label(),
              '@count' => $indexed,
            ])
          );
        }
        catch (\Exception $e) {
          $messenger->addError(
            t('Erreur indexation "@index": @msg', [
              '@index' => $index->label(),
              '@msg' => $e->getMessage(),
            ])
          );
        }
      }
    }
    else {
      $messenger->addError(t('Le processus s’est terminé avec des erreurs majeures.'));
    }
  }

}