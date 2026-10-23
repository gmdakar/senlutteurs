<?php

namespace Drupal\misc_divers\Commands;

use Drush\Commands\DrushCommands;
use Drush\Attributes\Command;
use Drush\Attributes\Argument;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\misc_divers\Form\ForceUpdateNodesForm;

/**
 * Lance la mise à jour forcée des nodes via batch Drush.
 */
class ForceUpdateNodesCommands extends DrushCommands {

  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
  }

  #[Command(name: 'misc:force-update-nodes', description: 'Lister tous les types de contenus.', aliases: ['fun'])]
  #[Option(name: 'types', description: 'Liste des types de contenu séparés par des virgules (ex: athlete,confrontation). Si absent, liste les types disponibles.')]
  #[Option(name: 'all', description: 'Traiter tous les types de contenu disponibles.')]
  #[Usage(name: 'drush misc:force-update-nodes --types=athlete,confrontation', description: 'Met à jour les nodes athlete et confrontation.')]
  #[Usage(name: 'drush misc:force-update-nodes --all', description: 'Met à jour tous les types de contenu.')]
  #[Usage(name: 'drush misc:force-update-nodes', description: 'Affiche les types de contenu disponibles.')]
  public function forceUpdateNodes(array $options = ['types' => NULL, 'all' => FALSE]): void {

    $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $available_types = array_keys($node_types);

    // Sans option : lister les types disponibles et sortir.
    if (empty($options['types']) && empty($options['all'])) {
      $this->io()->section('Types de contenu disponibles');
      foreach ($node_types as $id => $type) {
        $this->io()->writeln(sprintf('  <info>%s</info> (%s)', $id, $type->label()));
      }
      $this->io()->note('Utilisez --types=type1,type2 ou --all pour lancer la mise à jour.');
      return;
    }

    // Résoudre la liste des types à traiter.
    if (!empty($options['all'])) {
      $selected_types = $available_types;
    }
    else {
      $requested = array_map('trim', explode(',', $options['types']));
      $invalid = array_diff($requested, $available_types);
      if (!empty($invalid)) {
        $this->io()->error(sprintf(
          'Types inconnus : %s. Types disponibles : %s',
          implode(', ', $invalid),
          implode(', ', $available_types)
        ));
        return;
      }
      $selected_types = $requested;
    }

    // Compter les nodes à traiter.
    $operations = [];
    foreach ($selected_types as $type) {
      $nids = \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
        ->condition('type', $type)
        ->execute();

      if (empty($nids)) {
        $this->io()->writeln(sprintf('<comment>Aucun node trouvé pour le type "%s", ignoré.</comment>', $type));
        continue;
      }

      $this->io()->writeln(sprintf('  → <info>%s</info> : %d nodes', $type, count($nids)));

      foreach ($nids as $nid) {
        $operations[] = [
          '\Drupal\misc_divers\Form\ForceUpdateNodesForm::processSingleNode',
          [$nid],
        ];
      }
    }

    if (empty($operations)) {
      $this->io()->warning('Aucun node à traiter.');
      return;
    }

    $this->io()->writeln(sprintf('<info>%d nodes au total à traiter.</info>', count($operations)));

    if (!$this->io()->confirm('Confirmer la mise à jour ?', TRUE)) {
      $this->io()->writeln('Annulé.');
      return;
    }

    $batch = [
      'title'            => t('Mise à jour forcée des nodes...'),
      'operations'       => $operations,
      'finished'         => '\Drupal\misc_divers\Form\ForceUpdateNodesForm::batchFinished',
      'init_message'     => t('Démarrage...'),
      'progress_message' => t('Traitement @current sur @total.'),
      'error_message'    => t('Erreur lors de la mise à jour.'),
    ];

    batch_set($batch);
    drush_backend_batch_process();
  }

}