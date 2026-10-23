<?php

namespace Drupal\combats_manquants\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Bloc affichant le formulaire public de proposition de combat manquant.
 *
 * @Block(
 *   id = "combat_manquant_form_block",
 *   admin_label = @Translation("Formulaire - Proposer un combat manquant")
 * )
 */
class CombatManquantFormBlock extends BlockBase {

  public function build() {
    $build = [];
    $build['form'] = \Drupal::formBuilder()->getForm('\Drupal\combats_manquants\Form\CombatManquantForm');
    $build['#cache']['max-age'] = 0;
    return $build;
  }

}
