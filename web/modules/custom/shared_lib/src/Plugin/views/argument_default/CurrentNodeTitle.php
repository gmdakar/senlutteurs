<?php

namespace Drupal\shared_lib\Plugin\views\argument_default;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\views\Plugin\views\argument_default\ArgumentDefaultPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Fournit le titre du nœud courant comme valeur par défaut de contextual filter.
 *
 * Remplace le plugin "token" ([current-page:title]) pour l'argument
 * search_api_fulltext de la vue lutteurs_indexed : en lisant directement le
 * paramètre "node" de la route active plutôt qu'en passant par le module
 * Token / views_argument_token, on évite le crash
 * "has no corresponding route" qui survient quand ce bloc est rendu en
 * placeholder BigPipe (contexte de route perdu lors du replatformement du
 * token). Ça rend aussi le display normalement cacheable par URL, ce qui
 * n'était pas le cas avec le plugin token (cache max-age forcé à -1).
 *
 * @ViewsArgumentDefault(
 *   id = "current_node_title",
 *   title = @Translation("Titre du nœud courant (sans token)")
 * )
 */
class CurrentNodeTitle extends ArgumentDefaultPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->routeMatch = $container->get('current_route_match');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getArgument() {
    $node = $this->routeMatch->getParameter('node');
    return $node ? $node->label() : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return ['url'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return Cache::PERMANENT;
  }

}
