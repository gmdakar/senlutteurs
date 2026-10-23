<?php

namespace Drupal\misc_divers\Commands;

use Drush\Commands\DrushCommands;
use Drupal\misc_divers\Traits\CronScheduleTrait;

/**
 * Classe de base pour toutes les commandes Drush du projet.
 */
abstract class CommandsBase extends DrushCommands {
  use CronScheduleTrait;
}