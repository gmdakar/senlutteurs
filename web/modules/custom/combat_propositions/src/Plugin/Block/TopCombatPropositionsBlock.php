<?php

namespace Drupal\combat_propositions\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Bloc affichant la liste des 5 combats les plus proposés avec vote.
 *
 * @Block(
 * id = "top_combat_propositions_block",
 * admin_label = @Translation("Top propositions combats")
 * )
 */
class TopCombatPropositionsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected $database;
  protected $requestStack;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $database, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->database = $database;
    // Correction de la faute de frappe
    $this->requestStack = $request_stack;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('request_stack')
    );
  }

  public function build() {

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
    
    // Utilisation directe de \Drupal::requestStack() pour la robustesse
    $session = \Drupal::requestStack()->getCurrentRequest()->getSession(); 

    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
    
    $can_delete = \Drupal::currentUser()->hasPermission('administer combat propositions');

    // Helper: load an athlete node by title, preferring the current language.
    $load_athlete = function (string $title) use ($node_storage, $langcode) {
      // 1. Prefer exact match in current language.
      $nodes = $node_storage->loadByProperties([
        'title' => $title,
        'type' => 'athlete',
        'langcode' => $langcode,
      ]);
      if ($nodes) {
        $node = reset($nodes);
        return $node->hasTranslation($langcode) ? $node->getTranslation($langcode) : $node;
      }
      // 2. Fallback: any language (take first), then try to get current translation.
      $nodes = $node_storage->loadByProperties([
        'title' => $title,
        'type' => 'athlete',
      ]);
      if ($nodes) {
        $node = reset($nodes);
        if ($node->hasTranslation($langcode)) {
          return $node->getTranslation($langcode);
        }
        return $node;
      }
      return NULL;
    };

    foreach ($results as $row) {
      $combat_id = $row->id;
      $has_voted = $session->get('combat_vote_' . $combat_id, FALSE);

      $lutteur1_node = $load_athlete($row->lutteur_1);
      $lutteur2_node = $load_athlete($row->lutteur_2);

      // Build links or fallback text
      $lutteur1_link = $lutteur1_node
        ? Link::fromTextAndUrl($lutteur1_node->label(), $lutteur1_node->toUrl())->toString()
        : $this->t('[Inconnu]');
      $lutteur2_link = $lutteur2_node
        ? Link::fromTextAndUrl($lutteur2_node->label(), $lutteur2_node->toUrl())->toString()
        : $this->t('[Inconnu]');

      $text = $this->t('@lutteur1 / @lutteur2 Votes: @votes', [
        '@lutteur1' => $lutteur1_link,
        '@lutteur2' => $lutteur2_link,
        '@votes' => $row->votes + $row->proposals,
      ]);

      // --- DÉBUT DE LA LOGIQUE D'ALIGNEMENT À DROITE ---
      $actions_group = [];

      if ($has_voted) {
          // Message si déjà voté
          $actions_group[] = $this->t('You have already voted');
      }
      else {
          // Bouton Voter (AJAX)
          $url = Url::fromRoute('combat_propositions.vote', ['id' => $combat_id]);
          $link = Link::fromTextAndUrl($this->t('Vote'), $url);
          $link_render = $link->toRenderable();
          $link_render['#attributes']['class'][] = 'use-ajax';
          $actions_group[] = \Drupal::service('renderer')->render($link_render);
      }
      
      // Lien de suppression (avec icône 🗑️)
      if ($can_delete) {
          $delete_url = Url::fromRoute('combat_propositions.delete', ['id' => $combat_id]);
          $delete_link = Link::fromTextAndUrl($this->t('🗑️'), $delete_url);
          //$actions_group[] = \Drupal::service('renderer')->render($delete_link->toRenderable());
          $delete_render_array = $delete_link->toRenderable();
          $actions_group[] = \Drupal::service('renderer')->render($delete_render_array);

      }

      // Concaténer toutes les actions dans une seule chaîne HTML
      $actions_html = '<div class="combat-actions" style="display: flex; gap: 10px;">' . implode(' | ', $actions_group) . '</div>';

      // Créer la structure flex pour l'alignement :
      $items[] = [
          '#type' => 'container',
          '#attributes' => [
              'id' => 'combat-item-container-' . $combat_id, 
              // Assure l'alignement à gauche/droite et le centrage vertical
              'style' => 'display: flex; justify-content: space-between; align-items: center; width: 100%;',
          ],
          'text' => ['#markup' => '<div id="combat-vote-message-' . $combat_id . '">' . $text . '</div>'],
          'actions' => ['#markup' => $actions_html],
      ];
      // --- FIN DE LA LOGIQUE D'ALIGNEMENT À DROITE ---
    }

    if (empty($items)) {
      $items[] = $this->t('No proposals at the moment.');
    }

    $build = [];
    $build['list_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'combat-propositions-list'],
      'list' => [
        '#theme' => 'item_list',
        '#items' => $items,
        // Ces attributs sont conservés pour correspondre à votre structure LI/UL
        '#attributes' => ['class' => ['list-group']],
        '#wrapper_attributes' => ['class' => ['item-list']],
      ],
    ];

    $build['show_form_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Propose a fight'),
      '#url' => Url::fromRoute('combat_propositions.ajax_form'),
      '#attributes' => [
        'class' => ['use-ajax', 'button', 'button--primary'],
        'id' => 'show-combat-proposal-form',
        'style' => 'display:block; margin-top:1em; text-align:center;',
      ],
    ];

    $build['form_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'combat-proposal-form-container'],
    ];

    $build['#attached']['library'][] = 'core/drupal.ajax';
    $build['#cache']['max-age'] = 0;

    return $build;
  }
}