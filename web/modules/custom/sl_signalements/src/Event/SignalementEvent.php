<?php

namespace Drupal\sl_signalements\Event;

use Drupal\node\NodeInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Event ECA pour signalements de combats. (OBSOLETE pour l'instant!!)
 *
 * IMPORTANT :
 * - Ce format est compatible avec ECA (tokens exposables)
 * - Permet key / count / node utilisables dans Modeler
 */
class SignalementEvent extends Event {

  public const EVENT_NAME = 'sl_signalements.signalement';

  protected NodeInterface $node;
  protected string $key;
  protected int $count;

  /**
   * Constructeur
   */
  public function __construct(
    NodeInterface $node,
    string $key,
    int $count
  ) {
    $this->node = $node;
    $this->key = $key;
    $this->count = $count;
  }

  /**
   * Node concerné
   */
  public function getNode(): NodeInterface {
    return $this->node;
  }

  /**
   * Clé métier du signalement (s_lutte_simple, etc.)
   */
  public function getKey(): string {
    return $this->key;
  }

  /**
   * Compteur du signalement
   */
  public function getCount(): int {
    return $this->count;
  }

  /**
   * 🔥 IMPORTANT POUR ECA :
   * Exposition explicite des tokens
   *
   * Sans ça, Modeler ne verra PAS key/count
   */
  public function getTokenValues(): array {
    return [
      'node' => $this->node,
      'key' => $this->key,
      'count' => $this->count,
    ];
  }

}