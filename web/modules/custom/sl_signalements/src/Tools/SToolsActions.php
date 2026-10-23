<?php

namespace Drupal\sl_signalements\Tools;

use Drupal\node\NodeInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\shared_lib\Tools\SomeTools;

class SToolsActions {

  use StringTranslationTrait;

  private function logAction(string $action, NodeInterface $node): void {
    // Log volontairement en error() : toute action de signalement
    // effectivement appliquée (confirmée par l'IA, ou exécutée en mode
    // "super pouvoir" admin/bypass) doit rester très visible dans les logs
    // dblog, même si ce n'est pas une erreur à proprement parler (choix
    // assumé de Sene, convention déjà en place dans ce module).
    \Drupal::logger('sl_signalements')->error(
      'Signalements ACTIONS | @action | ID @nid - "@title"',
      [
        '@action' => $action,
        '@nid'    => $node->id(),
        '@title'  => $node->label(),
      ]
    );
  }

  public function appliquerReglesMetier(
    NodeInterface $node,
    string $key,
    ?string $athlete_name = NULL,
    ?int $lutteur_nid = NULL,
    ?int $variant_nid = NULL
  ): bool {

    switch ($key) {

      case 's_nul':
        $node->set('field_vainqueur', 'Nul');
        break;

      case 's_litige':
        $node->set('field_vainqueur', 'Litige (sans verdict)');
        break;

      case 's_lutte_simple':
        $node->set('field_is_lutte_simple', 1);
        break;

      case 's_lutte_frappe':
        $node->set('field_is_lutte_simple', 0);
        break;

      case 's_vainqueur_lutteur1':
      case 's_vainqueur_lutteur2':
        $resolved = $this->resolveWinnerByName($node, $athlete_name);
        if ($resolved) {
          $node->set('field_vainqueur', $resolved);
        }
        break;

      case 's_combat_doublon':
        $this->removeCombatDoublon($node, $lutteur_nid);
        return FALSE;

      case 's_combat_fantome':
        // Combat jamais réellement existé : on ne supprime plus le node,
        // on le dépublie (conservation en base, hors ligne côté front).
        if ($node->id()) {
          $node->setUnpublished();
          $node->setNewRevision(FALSE);
          $node->save();
          $this->logAction('FANTOME : dépublié (au lieu de supprimé)', $node);
        }
        return FALSE;

      case 's_variante_athlete_1':
      case 's_variante_athlete_2':
        $slot = ($key === 's_variante_athlete_1') ? 'field_athlete_1' : 'field_athlete_2';
        $this->remplacerVarianteAthlete($node, $slot, $variant_nid);
        break;
    }

    return TRUE;
  }

  private function remplacerVarianteAthlete(
    NodeInterface $node,
    string $slot,
    ?int $variant_nid
  ): void {

    if (!$variant_nid) return;

    $field_before = $node->get($slot);
    $old_athlete  = (!$field_before->isEmpty()) ? $field_before->entity : NULL;

    if (!$old_athlete) return;

    $old_name = $old_athlete->label();
    $old_nid  = (int) $old_athlete->id();

    if ($old_nid === $variant_nid) return;

    $variant_node = \Drupal\node\Entity\Node::load($variant_nid);

    if (!$variant_node || $variant_node->bundle() !== 'athlete') return;

    $new_name = $variant_node->label();

    // remplacement local uniquement
    $node->set($slot, ['target_id' => $variant_nid]);

    $this->logAction("VARIANTE: '{$old_name}' → '{$new_name}'", $node);

    // update vainqueur si besoin
    $vainqueur = (string) $node->get('field_vainqueur')->value;

    if (mb_strtolower($vainqueur) === mb_strtolower($old_name)) {
      $node->set('field_vainqueur', $new_name);
    }
  }

  private function resolveWinnerByName(NodeInterface $node, ?string $athlete_name): ?string {

    if (!$athlete_name) return NULL;

    foreach (['field_athlete_1', 'field_athlete_2'] as $field_name) {

      $field = $node->get($field_name);
      if ($field->isEmpty()) continue;

      $athlete = $field->entity;
      if (!$athlete) continue;

      $official = $athlete->label();

      if (mb_strtolower(trim($athlete_name)) === mb_strtolower(trim($official))) {
        return $official;
      }
    }

    return NULL;
  }

  public function removeCombatDoublon(NodeInterface $node, ?int $lutteur_nid = NULL): void {

    if (!$lutteur_nid) return;

    $lutteur = \Drupal\node\Entity\Node::load($lutteur_nid);

    if ($lutteur && $lutteur->bundle() === 'athlete') {
      SomeTools::findAndDeleteDuplicateCombat($node, $lutteur);
    }
  }
}