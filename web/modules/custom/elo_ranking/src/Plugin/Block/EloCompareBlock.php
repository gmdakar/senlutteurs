<?php

namespace Drupal\elo_ranking\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides Elo comparison block.
 *
 * @Block(
 * id = "elo_compare_block",
 * admin_label = @Translation("Comparateur de carrières")
 * )
 */
class EloCompareBlock extends BlockBase {

  public function build() {
    // On appelle le formulaire via son namespace complet
    return \Drupal::formBuilder()->getForm('\Drupal\elo_ranking\Form\EloCompareForm');
  }

}