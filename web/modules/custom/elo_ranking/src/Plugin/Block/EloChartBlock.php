<?php

namespace Drupal\elo_ranking\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Provides Elo Chart Block.
 *
 * @Block(
 *   id = "elo_chart_block",
 *   admin_label = "Elo Evolution Chart",
 *   category = "Custom"
 * )
 */
class EloChartBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected $routeMatch;

  public function __construct(array $configuration,
                              $plugin_id,
                              $plugin_definition,
                              RouteMatchInterface $route_match) {

    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->routeMatch = $route_match;
  }

  public static function create(ContainerInterface $container,
                                array $configuration,
                                $plugin_id,
                                $plugin_definition) {

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match')
    );
  }

public function build() {

  $node = $this->routeMatch->getParameter('node');

  if (!$node || $node->bundle() !== 'athlete') {
    return [];
  }

  $nid = $node->id();

  return [
    '#type' => 'container',
    '#attributes' => ['class' => ['elo-chart-wrapper']],

    'chart' => [
      '#markup' => '<canvas id="elo-chart" width="800" height="400"></canvas>',
    ],

    '#attached' => [
      'library' => ['elo_ranking/elo_chart'],
      'drupalSettings' => [
        'elo_ranking' => [
          'nid' => $nid
        ]
      ]
    ]
  ];
}
}