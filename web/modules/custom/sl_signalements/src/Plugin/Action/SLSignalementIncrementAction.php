<?php

namespace Drupal\sl_signalements\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\Component\Serialization\Json;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\sl_signalements\Event\LutteSimpleSignalEvent;

/**
 * @Action(
 *   id = "sl_signalements_increment",
 *   label = @Translation("SL Signalement Increment"),
 *   type = "node"
 * )
 */
class SLSignalementIncrementAction extends ActionBase implements ContainerFactoryPluginInterface {

  protected EventDispatcherInterface $dispatcher;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EventDispatcherInterface $dispatcher
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->dispatcher = $dispatcher;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('event_dispatcher')
    );
  }

  public function execute($node = NULL) {

    if (!$node instanceof NodeInterface || $node->bundle() !== 'confrontation') {
      return;
    }

    $raw  = $node->get('field_signalements')->value;
    $data = !empty($raw) ? Json::decode($raw) : [];

    $key        = $this->configuration['signal_key'] ?? 's_default';
    $data[$key] = ($data[$key] ?? 0) + 1;
    $count      = $data[$key];

    \Drupal::logger('sl_signalements')->info(
      'Signalement @key node @nid total=@count',
      [
        '@key'   => $key,
        '@nid'   => $node->id(),
        '@count' => $count,
      ]
    );

    $node->set('field_signalements', Json::encode($data));
    $node->setNewRevision(FALSE);
    $node->save();
  }

  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return TRUE;
  }
}