<?php

/**
 * Script Drush : liste les nid/title des nodes "confrontation" des N
 * derniers jours ayant au moins un lutteur (node "athlete") avec
 * field_a_surveiller = 1.
 *
 * Usage :
 *   drush scr list_confrontations_a_surveiller.php
 *   drush scr list_confrontations_a_surveiller.php 30
 *   drush scr list_confrontations_a_surveiller.php 90
 *
 * Le premier argument positionnel est le nombre de jours (30 par défaut
 * si non précisé). Ne PAS utiliser --days=X : drush valide strictement
 * ses propres options et rejette celles qu'il ne connaît pas, même
 * placées après le nom du script.
 *
 * Placer ce fichier dans le dossier scripts/ de votre site Drupal
 * (ou passer le chemin complet à drush scr).
 */

// Récupération du nombre de jours depuis le premier argument positionnel
// (variable $extra fournie automatiquement par `drush scr`).
$days = 7;
if (!empty($extra) && is_array($extra) && isset($extra[0]) && is_numeric($extra[0])) {
    $days = (int) $extra[0];
}

$now       = new \DateTime('now', new \DateTimeZone('UTC'));
$sinceDate = (clone $now)->modify("-{$days} days");

$sinceDateStr = $sinceDate->format('Y-m-d');
$nowDateStr   = $now->format('Y-m-d');

/** @var \Drupal\Core\Entity\Sql\SqlContentEntityStorage $storage */
$storage = \Drupal::entityTypeManager()->getStorage('node');

// 1. Récupérer les nids des athlètes à surveiller.
$athleteNids = $storage->getQuery()
    ->condition('type', 'athlete')
    ->condition('field_a_surveiller', 1)
    ->accessCheck(FALSE)
    ->execute();

if (empty($athleteNids)) {
    echo "Aucun athlète avec field_a_surveiller = 1\n";
    return;
}

echo count($athleteNids) . " athlète(s) à surveiller trouvé(s).\n";

// 2. Groupe OR : field_athlete_1 IN (...) OR field_athlete_2 IN (...).
$orGroup = $storage->getQuery()
    ->accessCheck(FALSE)
    ->orConditionGroup()
    ->condition('field_athlete_1', $athleteNids, 'IN')
    ->condition('field_athlete_2', $athleteNids, 'IN');

// 3. Requête principale sur les confrontations des N derniers jours.
$nids = $storage->getQuery()
    ->condition('type', 'confrontation')
    ->condition('field_confrontation_date', $sinceDateStr, '>=')
    ->condition('field_confrontation_date', $nowDateStr, '<=')
    ->condition($orGroup)
    ->accessCheck(FALSE)
    ->sort('field_confrontation_date', 'DESC')
    ->execute();

if (empty($nids)) {
    echo "Aucune confrontation trouvée sur les $days derniers jours impliquant un athlète surveillé.\n";
    return;
}

/** @var \Drupal\node\Entity\Node[] $nodes */
$nodes = $storage->loadMultiple($nids);

echo "\n" . count($nodes) . " confrontation(s) trouvée(s) sur les $days derniers jours :\n";
echo str_pad('NID', 8) . str_pad('DATE', 14) . "TITLE\n";
echo str_repeat('-', 70) . "\n";

foreach ($nodes as $node) {
    $date = $node->hasField('field_confrontation_date')
        ? $node->get('field_confrontation_date')->value
        : '';

    echo str_pad($node->id(), 8)
        . str_pad($date, 14)
        . $node->getTitle() . "\n";

    // Log dblog (watchdog) pour retrouver facilement ces combats via
    // /admin/reports/dblog en filtrant sur "SUSPECT combat".
    \Drupal::logger('confrontations_recentes')->info(
        'SUSPECT combat (@days derniers jours) : @title (nid @nid, @date) :',
        [
            '@days'  => $days,
            '@title' => $node->getTitle(),
            '@nid'   => $node->id(),
            '@date'  => $date,
        ]
    );
}
