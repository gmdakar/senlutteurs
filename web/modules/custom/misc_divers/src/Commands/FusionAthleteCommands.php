<?php

namespace Drupal\misc_divers\Commands;

use Drush\Commands\DrushCommands;
use Drupal\misc_divers\Commands\CommandsBase;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;

/**
 * Fusionne les doublons d'athlètes déclarés via field_same_athlete.
 *
 * Usage :
 *   drush fusion-athlete --nid=123
 *   drush fa --nid=123
 */
class FusionAthleteCommands extends CommandsBase {

  #[Command(name: 'fusion-athlete', aliases: ['fa'])]
  #[Option(name: 'nid', description: 'NID du nœud pivot (athlete)')]
  #[Usage(name: 'drush fusion-athlete --nid=123', description: 'Fusionne les doublons vers le pivot NID 123')]
  public function fusionAthlete(array $options = ['nid' => NULL]): void {

    $pivotNid = (int) ($options['nid'] ?? 0);

    if ($pivotNid <= 0) {
      $this->io()->error('Argument manquant : --nid=<NID du pivot>');
      return;
    }

    $result = \Drupal\shared_lib\Tools\SomeTools::mergeFromPivot($pivotNid, $this->io());

    if ($result === NULL) {
      // Les messages d'erreur ont déjà été affichés par mergeFromPivot()
      return;
    }

    $this->io()->success(sprintf(
      'Fusion terminée. %d combat(s) transféré(s), %d a_venir transféré(s), %d doublon(s) supprimé(s).',
      $result['combats_transferred'],
      $result['avenir_transferred'],
      $result['deleted']
    ));
  }

}
