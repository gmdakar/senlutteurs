<?php
// modules/custom/sl_live_search/src/Commands/ResetLiveSearchCommands.php

namespace Drupal\sl_live_search\Commands;

use Drupal\Core\State\StateInterface;
use Drupal\Core\Database\Connection;
use Drush\Commands\DrushCommands;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;

class ResetLiveSearchCommands extends DrushCommands {

  public function __construct(
    private readonly StateInterface $state,
    private readonly \Drupal\Core\Database\Connection $database,
  ) {
    parent::__construct();
  }

  /**
   * Resets liveSearch state locks (success + quota).
   * CLI : ddev drush sl:live-search-reset --engine=gemini
   */
  #[Command(name: 'sl:live-search-reset')]
  #[Option(name: 'engine', description: 'Engine cible : grok ou gemini (defaut : tous)')]
  #[Usage(name: 'drush sl:live-search-reset', description: 'Reset tous les verrous')]
  #[Usage(name: 'drush sl:live-search-reset --engine=gemini', description: 'Reset verrous gemini uniquement')]
  public function reset($options = ['engine' => null]): void {

    $prefix = 'sl_live_search.';

    if ($options['engine']) {
      $prefixes = [
        $prefix . 'success.' . $options['engine'] . '.',
        $prefix . 'quota_exhausted.' . $options['engine'] . '.',
      ];
    }
    else {
      $prefixes = [$prefix];
    }

    $deleted = 0;
    foreach ($prefixes as $p) {
      $deleted += $this->deleteByPrefix($p);
    }

    if ($deleted === 0) {
      $this->logger()->warning('Aucun verrou trouve.');
    }
    else {
      $this->logger()->success(dt('@n verrou(s) supprime(s).', ['@n' => $deleted]));
    }
  }

  /**
   * Supprime toutes les entrees State dont la cle commence par $prefix.
   */
  private function deleteByPrefix(string $prefix): int {
    $keys = $this->database->select('key_value', 'kv')
      ->fields('kv', ['name'])
      ->condition('collection', 'state')
      ->condition('name', $this->database->escapeLike($prefix) . '%', 'LIKE')
      ->execute()
      ->fetchCol();

    if (empty($keys)) {
      return 0;
    }

    $this->state->deleteMultiple($keys);

    foreach ($keys as $key) {
      $this->logger()->success(dt('Supprime : @key', ['@key' => $key]));
    }

    return count($keys);
  }

}