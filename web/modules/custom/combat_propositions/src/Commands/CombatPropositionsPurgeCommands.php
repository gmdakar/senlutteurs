<?php

namespace Drupal\combat_propositions\Commands;

use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;
use Drupal\misc_divers\Commands\CommandsBase;

/**
 * Drush commands : purge des propositions de combat obsolètes.
 */
class CombatPropositionsPurgeCommands extends CommandsBase
{

    protected $database;
    protected $entityTypeManager;

    public function __construct()
    {
        parent::__construct();
        $this->database = \Drupal::database();
        $this->entityTypeManager = \Drupal::service('entity_type.manager');
    }

    /**
     * Purge la table combat_propositions selon deux règles :
     *
     *  1) Le combat proposé (lutteur_1 vs lutteur_2, peu importe l'ordre) a
     *     déjà eu lieu cette année (node "confrontation" avec
     *     field_confrontation_date dans l'année en cours), OU fait déjà
     *     partie des combats annoncés ("a_venir").
     *  2) La proposition a moins de 10 votes stricts (proposals + votes)
     *     ET existe depuis au moins 1 mois (champ "created").
     *
     * Exemples :
     *   drush combat_propositions_purge:run
     *   drush cppurge --force
     *   drush cppurge --min-votes=5 --force
     */
    #[Command(name: 'combat_propositions_purge:run', aliases: ['cppurge'])]
    #[Option(name: 'min-votes', description: 'Seuil strict de votes (proposals + votes) en-dessous duquel une proposition ancienne est purgeable (défaut : 10).')]
    #[Option(name: 'force', description: 'Supprime réellement les lignes. Sans cette option, la commande simule (dry-run) et liste seulement ce qui serait supprimé.')]
    #[Usage(name: 'drush cppurge --force', description: 'Purge réellement les propositions devenues obsolètes ou peu populaires')]
    #[Usage(name: 'drush cppurge', description: 'Simule la purge (dry-run) sans rien supprimer')]
    public function purgeCombatPropositions(array $options = ['min-votes' => 10, 'force' => false])
    {
        $minVotes = (int) ($options['min-votes'] ?? 10);
        $force    = (bool) ($options['force'] ?? false);

        $rows = $this->database->select('combat_propositions', 'c')
            ->fields('c', ['id', 'lutteur_1', 'lutteur_2', 'proposals', 'votes', 'created'])
            ->execute()
            ->fetchAll();

        if (empty($rows)) {
            $this->io()->success('Aucune proposition de combat en base.');
            return;
        }

        $nodeStorage = $this->entityTypeManager->getStorage('node');

        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $oneMonthAgoTs = (clone $now)->modify('-1 month')->getTimestamp();

        $yearStart = $now->format('Y') . '-01-01';
        $yearEnd   = $now->format('Y') . '-12-31';

        // Cache des résolutions "titre lutteur → nid athlete" pour éviter
        // de requêter deux fois le même nom.
        $athleteIdCache = [];
        $resolveAthleteId = function (string $name) use (&$athleteIdCache, $nodeStorage): ?int {
            if ($name === '') {
                return null;
            }
            if (array_key_exists($name, $athleteIdCache)) {
                return $athleteIdCache[$name];
            }
            $matches = $nodeStorage->loadByProperties(['type' => 'athlete', 'title' => $name]);
            $node = reset($matches);
            return $athleteIdCache[$name] = $node ? (int) $node->id() : null;
        };

        $toDelete = [];

        foreach ($rows as $row) {
            $id1 = $resolveAthleteId((string) $row->lutteur_1);
            $id2 = $resolveAthleteId((string) $row->lutteur_2);

            $reason = null;

            // Règle 1 : le combat a déjà eu lieu cette année, ou est déjà
            // annoncé dans "a_venir".
            if ($id1 !== null && $id2 !== null) {
                $confrontationQuery = $nodeStorage->getQuery()
                    ->condition('type', 'confrontation')
                    ->condition('field_confrontation_date', $yearStart, '>=')
                    ->condition('field_confrontation_date', $yearEnd, '<=')
                    ->accessCheck(false)
                    ->range(0, 1);
                $pairGroup = $confrontationQuery->orConditionGroup()
                    ->condition($confrontationQuery->andConditionGroup()
                        ->condition('field_athlete_1', $id1)
                        ->condition('field_athlete_2', $id2))
                    ->condition($confrontationQuery->andConditionGroup()
                        ->condition('field_athlete_1', $id2)
                        ->condition('field_athlete_2', $id1));
                $confrontationExists = $confrontationQuery->condition($pairGroup)->execute();

                if (!empty($confrontationExists)) {
                    $reason = 'combat déjà disputé cette année';
                } else {
                    $avenirQuery = $nodeStorage->getQuery()
                        ->condition('type', 'a_venir')
                        ->accessCheck(false)
                        ->range(0, 1);
                    $avenirPairGroup = $avenirQuery->orConditionGroup()
                        ->condition($avenirQuery->andConditionGroup()
                            ->condition('field_lutteur_1', $id1)
                            ->condition('field_lutteur_2', $id2))
                        ->condition($avenirQuery->andConditionGroup()
                            ->condition('field_lutteur_1', $id2)
                            ->condition('field_lutteur_2', $id1));
                    $avenirExists = $avenirQuery->condition($avenirPairGroup)->execute();

                    if (!empty($avenirExists)) {
                        $reason = 'combat déjà annoncé (a_venir)';
                    }
                }
            }

            // Règle 2 : peu de votes ET ancienneté ≥ 1 mois.
            if ($reason === null) {
                $totalVotes = (int) $row->proposals + (int) $row->votes;
                $created = (int) $row->created;

                if ($totalVotes < $minVotes && $created > 0 && $created <= $oneMonthAgoTs) {
                    $reason = "faible engagement ($totalVotes vote(s) < $minVotes) après 1 mois";
                }
            }

            if ($reason !== null) {
                $toDelete[] = [
                    'id' => (int) $row->id,
                    'label' => $row->lutteur_1 . ' vs ' . $row->lutteur_2,
                    'reason' => $reason,
                ];
            }
        }

        if (empty($toDelete)) {
            $this->io()->success('Aucune proposition à purger.');
            return;
        }

        $this->io()->note(count($toDelete) . ' proposition(s) à purger.');
        $this->io()->table(['ID', 'Combat', 'Raison'], array_map(
            fn(array $r) => [$r['id'], $r['label'], $r['reason']],
            $toDelete
        ));

        if (!$force) {
            $this->io()->note('Mode simulation (dry-run) : aucune ligne supprimée. Utilisez --force pour supprimer réellement.');
            return;
        }

        $deleted = 0;
        foreach ($toDelete as $entry) {
            try {
                $this->database->delete('combat_propositions')
                    ->condition('id', $entry['id'])
                    ->execute();
                $deleted++;
                \Drupal::logger('combat_propositions')->notice(
                    'Purge de la proposition @label (id @id) : @reason.',
                    ['@label' => $entry['label'], '@id' => $entry['id'], '@reason' => $entry['reason']]
                );
            } catch (\Exception $e) {
                $this->io()->error('Erreur suppression id ' . $entry['id'] . ' : ' . $e->getMessage());
            }
        }

        $this->io()->success("$deleted proposition(s) de combat purgée(s).");
    }
}
