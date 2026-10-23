<?php

namespace Drupal\confrontations_recentes\Commands;

use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;
use Drupal\misc_divers\Commands\CommandsBase;

/**
 * Drush commands : purge définitive des confrontations dépubliées.
 */
class ConfrontationsPurgeCommands extends CommandsBase
{

    protected $entityTypeManager;

    public function __construct()
    {
        parent::__construct();
        $this->entityTypeManager = \Drupal::service('entity_type.manager');
    }

    /**
     * Supprime définitivement les nodes "confrontation" dépubliés depuis
     * au moins N jours (basé sur la date de dernière modification du node).
     *
     * Exemples :
     *   drush confrontations_purge:run --days=30
     *   drush cpurge --days=90 --force
     */
    #[Command(name: 'confrontations_purge:run', aliases: ['cpurge'])]
    #[Option(name: 'days', description: 'Nombre de jours minimum depuis la dépublication (basé sur la date de modif) pour être purgé (défaut : 30).')]
    #[Option(name: 'force', description: 'Supprime réellement les nodes. Sans cette option, la commande simule (dry-run) et liste seulement ce qui serait supprimé.')]
    #[Usage(name: 'drush cpurge --days=60 --force', description: 'Supprime définitivement les confrontations dépubliées depuis 60 jours ou plus')]
    #[Usage(name: 'drush cpurge --days=60', description: 'Simule la suppression (dry-run) sans rien supprimer')]
    public function purgeConfrontations(array $options = ['days' => 30, 'force' => false])
    {
        $days  = (int) ($options['days'] ?? 30);
        $force = (bool) ($options['force'] ?? false);

        if ($days < 1) {
            $this->io()->error('Le paramètre --days doit être un entier positif.');
            return;
        }

        $now          = new \DateTime('now', new \DateTimeZone('UTC'));
        $thresholdTs  = (clone $now)->modify("-{$days} days")->getTimestamp();

        /** @var \Drupal\Core\Entity\Sql\SqlContentEntityStorage $storage */
        $storage = $this->entityTypeManager->getStorage('node');

        // Nodes "confrontation", dépubliés (status = 0), dont la date de
        // dernière modification est antérieure ou égale au seuil.
        $nids = $storage->getQuery()
            ->condition('type', 'confrontation')
            ->condition('status', 0)
            ->condition('changed', $thresholdTs, '<=')
            ->accessCheck(FALSE)
            ->execute();

        if (empty($nids)) {
            $this->io()->success("Aucune confrontation dépubliée depuis $days jour(s) ou plus.");
            return;
        }

        $this->io()->note(count($nids) . " confrontation(s) dépubliée(s) depuis $days jour(s) ou plus.");

        $rows = [];
        foreach ($storage->loadMultiple($nids) as $node) {
            $rows[] = [
                'NID'   => $node->id(),
                'Title' => $node->getTitle(),
                'Modifié le' => (new \DateTime('@' . $node->getChangedTime()))->format('Y-m-d H:i'),
            ];
        }
        $this->io()->table(['NID', 'Title', 'Modifié le'], $rows);

        if (!$force) {
            $this->io()->note('Mode simulation (dry-run) : aucun node supprimé. Utilisez --force pour supprimer réellement.');
            return;
        }

        $deleted = 0;
        foreach ($storage->loadMultiple($nids) as $node) {
            try {
                $nid = $node->id();
                $title = $node->getTitle();
                $node->delete();
                $deleted++;
                \Drupal::logger('confrontations_recentes')->notice(
                    'Purge définitive de la confrontation dépubliée : @title (nid @nid).',
                    ['@title' => $title, '@nid' => $nid]
                );
            } catch (\Exception $e) {
                $this->io()->error('Erreur suppression nid ' . $node->id() . ' : ' . $e->getMessage());
            }
        }

        $this->io()->success("$deleted confrontation(s) supprimée(s) définitivement.");
    }
}
