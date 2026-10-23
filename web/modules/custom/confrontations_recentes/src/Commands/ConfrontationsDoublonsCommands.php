<?php

namespace Drupal\confrontations_recentes\Commands;

use Drush\Commands\DrushCommands;
use Drush\Attributes\Command;
use Drush\Attributes\Argument;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;
use Drupal\misc_divers\Commands\CommandsBase;
use Drupal\shared_lib\Tools\SomeTools;

/**
 * Drush commands for Import Confrontations doublons.
 */
class ConfrontationsDoublonsCommands extends CommandsBase
{

    protected $queueWorkerManager;
    protected $queueFactory;
    protected $combatService;
    protected $entityTypeManager;

    public function __construct()
    {
        parent::__construct();
        $this->queueWorkerManager = \Drupal::service('plugin.manager.queue_worker');
        $this->queueFactory = \Drupal::service('queue');
        $this->combatService = \Drupal::service('confrontations_recentes.combat_service');
        $this->entityTypeManager = \Drupal::service('entity_type.manager');
    }

    #[Command(name: 'confrontations_doublons:run', aliases: ['cdbl'])]
    #[Option(name: 'task', description: 'La tâche à lancer : "combats", "athletes", "ecuries", "fake_video", "ai_combats", "ai_cross", "ai_avenir", "avenir" ou "all". Exemple : drush cdbl --task=combats')]
    #[Option(name: 'stepbystep', description: 'Mode interactif : confirmation manuelle pour chaque suspect.')]
    #[Option(name: 'no-ai-run', description: 'Avec le task "combats"/"ai_combats"/"ai_cross"/"all" : met les groupes en queue SANS les traiter immédiatement (laisse le cron s\'en charger).')]
    #[Option(name: 'enginef', description: 'Force le moteur IA (grok, gemini) pour le prompt principal des groupes ambigus traités par ce run. Par défaut : moteur configuré dans request_ai.settings.')]
    #[Option(name: 'days', description: 'Fenêtre max en jours pour la détection cross-athlètes (task ai_cross / combats / all). Défaut : 7. Exemple : drush cdbl --task=ai_cross --days=7')]
    #[Usage(name: 'drush cdbl --task=combats --enginef=grok', description: 'Force le moteur Grok pour le traitement immédiat des doublons ambigus')]
    #[Usage(name: 'drush cdbl --task=ai_cross --days=7', description: 'Doublons cross-athlètes sur une fenêtre de 7 jours')]
    public function ConfrontationsDoublons(array $options = ['task' => 'all', 'stepbystep' => false, 'no-ai-run' => false, 'enginef' => null, 'days' => 7])
    {

        //check granularity authorization cron execution
        if (!$this->isScheduledNow('confrontations_doublons:run')) {
            return;
        }

        $task      = $options['task'] ?? 'all';
        $stepbystep  = (bool) ($options['stepbystep'] ?? false);

        // 1. Potentials Duplicated Combats
        if ($task === 'all' || $task === 'combats') {
            $this->io()->section('Nettoyage forcé des doublons techniques.');

            $details = SomeTools::forceCleanTechnicalDoublons();

            if (empty($details)) {
                $this->io()->success("Aucun doublon technique trouvé.");
            } else {
                foreach ($details as $line) {
                    $this->io()->text("- $line");
                }
                $this->io()->success(count($details) . " nœuds traités et supprimés.");
            }

            $this->io()->section('Analyse des combats potentiellement en doublons (Fuzzy).');
            SomeTools::getOrCleanPotentialsDuplicatedCombats();
        }

        // 1-bis. Résolution IA des groupes ambigus (même pivot + même date,
        // adversaire trop différent en texte pour le Levenshtein).
        if ($task === 'all' || $task === 'combats' || $task === 'ai_combats') {
            $this->io()->section('Détection IA des doublons ambigus (dates proches ≤7j, adversaire mal identifié).');

            $nbQueued = SomeTools::queueAmbiguousNearDateCombats();

            if ($nbQueued === 0) {
                $this->io()->success('Aucun groupe ambigu détecté.');
            } else {
                $this->io()->text("$nbQueued groupe(s) suspect(s) mis en queue (confrontations_doublons_ai_queue).");

                if (empty($options['no-ai-run'])) {
                    $this->drainAiQueue(100, $options['enginef'] ?? null);
                } else {
                    $this->io()->text('Traitement différé au cron (--no-ai-run).');
                }
            }
        }

        // 1-ter. Doublons cross-athlètes : 2 combats ≤ $days j, aucun NID commun,
        // noms proches des deux côtés (ex. Confiance/Confiance Tp + Fils De
        // Falaye/Elou Fils De Falaye) → IA same_fight + fusions éventuelles.
        if ($task === 'all' || $task === 'combats' || $task === 'ai_cross') {
            $days = max(1, (int) ($options['days'] ?? 7));
            $this->io()->section("Détection IA des doublons cross-athlètes (≤{$days}j, sans pivot NID commun).");

            $nbCross = SomeTools::queueCrossAthleteNearDateCombats($days);

            if ($nbCross === 0) {
                $this->io()->success('Aucune paire cross-athlètes suspecte détectée.');
            } else {
                $this->io()->text("$nbCross paire(s) mise(s) en queue (confrontations_cross_athlete_doublons_ai_queue).");

                if (empty($options['no-ai-run'])) {
                    $this->drainCrossAthleteAiQueue(50, $options['enginef'] ?? null);
                } else {
                    $this->io()->text('Traitement différé au cron (--no-ai-run).');
                }
            }
        }

        // 2. Clean Duplicate Athletes
        if ($task === 'all' || $task === 'athletes') {
            $this->io()->section('Nettoyage des athlètes en doublon');
            SomeTools::cleanDuplicateAthletes($stepbystep ? $this->io() : null);
        }

        // 3. Clean Duplicated Ecuries
        if ($task === 'all' || $task === 'ecuries') {
            $this->io()->section('Nettoyage des écuries en doublon');

            $data = \Drupal::state()->get('misc_divers.ecuries_data', []);
            SomeTools::cleanDuplicatedEcuries($data, $stepbystep ? $this->io() : null);

            SomeTools::cleanPhantomEcuries();
            SomeTools::cleanOrphanEcuries();
        }

        // - 4. Traitement des fausses vidéos YouTube
        if ($task === 'all' || $task === 'fake_video') {
            $this->io()->section('Nettoyage des URLs vidéos ciblées (https://www.youtube.com/watch?v=)');
            SomeTools::cleanFakeVideoUrls($stepbystep ? $this->io() : null);
        }

        // 5. Clean Duplicate A_VENIR (déterministe : même paire d'IDs, dates différentes)
        if ($task === 'all' || $task === 'avenir' || $task === 'combats') {
            $this->io()->section('Nettoyage des doublons a_venir (même adversaires, date différente)');
            SomeTools::cleanDuplicateAvenir($stepbystep ? $this->io() : null);
        }

        // 5-bis. a_venir ambigus : même date + un protagoniste commun → résolution IA
        // Règle métier : un lutteur ne peut pas avoir deux combats à venir le même jour.
        if ($task === 'all' || $task === 'avenir' || $task === 'combats' || $task === 'ai_avenir') {
            $this->io()->section('Détection IA des doublons a_venir (même date, protagoniste commun).');

            $nbQueued = SomeTools::queueAmbiguousAvenirSameDateCombats();

            if ($nbQueued === 0) {
                $this->io()->success('Aucun nouveau groupe a_venir ambigu détecté.');
            } else {
                $this->io()->text("$nbQueued groupe(s) a_venir suspect(s) mis en queue (a_venir_doublons_ai_queue).");
            }

            // Toujours drainer la queue (y compris items relâchés après un
            // échec technique précédent, même si aucun nouveau groupe n'a
            // été mis en queue ce passage).
            if (empty($options['no-ai-run'])) {
                $this->drainAvenirAiQueue(100, $options['enginef'] ?? null);
            } else {
                $this->io()->text('Traitement différé au cron (--no-ai-run).');
            }
        }

        $this->io()->success("Traitement terminé (Tâche : $task).");
    }

    /**
     * Traite immédiatement la queue 'confrontations_doublons_ai_queue' au
     * lieu d'attendre le prochain passage cron (cron = 300s sur le worker).
     */
    private function drainAiQueue(int $limit = 100, ?string $enginef = null): void
    {
        $queue  = $this->queueFactory->get('confrontations_doublons_ai_queue');
        $worker = $this->queueWorkerManager->createInstance('confrontations_doublons_ai_queue');

        $processed = 0;
        while ($processed < $limit && $item = $queue->claimItem()) {
            try {
                $data = $item->data;
                if (!empty($enginef)) {
                    // Injecté au niveau de l'item plutôt que passé en
                    // paramètre de processItem() : sa signature est fixée
                    // par QueueWorkerInterface (appelée telle quelle par
                    // le cron Drupal), donc on force le moteur via $data,
                    // lu par ConfrontationsDoublonsAiQueue::doProcessItem().
                    $data['engine'] = $enginef;
                    $data['force_engine'] = true;
                }
                $worker->processItem($data);
                $queue->deleteItem($item);
            } catch (\Throwable $e) {
                // \Throwable (et non \Exception) : capture aussi les
                // erreurs fatales PHP (\Error, \TypeError...), qui
                // n'héritent PAS de \Exception depuis PHP 7. Sans ce
                // changement, une erreur fatale ici plantait le process
                // sans jamais atteindre ce catch : $queue->releaseItem()
                // n'était jamais appelé (item "claimed" orphelin, invisible
                // dans les vues classiques de la queue) ET
                // SomeTools::clearDoublonGroupPending() n'était jamais
                // atteint côté ConfrontationsDoublonsAiQueue::processItem()
                // — le marqueur "pending" restait alors bloqué à vie même
                // si l'item lui-même avait disparu. Voir aussi le TTL
                // ajouté sur ce marqueur (DOUBLONS_AI_PENDING_TTL_SECONDS)
                // en filet de sécurité complémentaire.
                $queue->releaseItem($item);
                $this->io()->error('Erreur traitement item queue IA : ' . $e->getMessage());
                break;
            }
            $processed++;
        }

        $this->io()->text("$processed groupe(s) traité(s) par l'IA.");
    }

    /**
     * Traite immédiatement la queue 'confrontations_cross_athlete_doublons_ai_queue'.
     */
    private function drainCrossAthleteAiQueue(int $limit = 50, ?string $enginef = null): void
    {
        $queue  = $this->queueFactory->get('confrontations_cross_athlete_doublons_ai_queue');
        $worker = $this->queueWorkerManager->createInstance('confrontations_cross_athlete_doublons_ai_queue');

        $processed = 0;
        while ($processed < $limit && $item = $queue->claimItem()) {
            try {
                $data = $item->data;
                if (!empty($enginef)) {
                    $data['engine'] = $enginef;
                    $data['force_engine'] = true;
                }
                $worker->processItem($data);
                $queue->deleteItem($item);
            } catch (\Throwable $e) {
                $queue->releaseItem($item);
                $this->io()->error('Erreur traitement item queue cross-athlete IA : ' . $e->getMessage());
                break;
            }
            $processed++;
        }

        $this->io()->text("$processed paire(s) cross-athlètes traitée(s) par l'IA.");
    }

    /**
     * Traite immédiatement la queue 'a_venir_doublons_ai_queue'.
     */
    private function drainAvenirAiQueue(int $limit = 100, ?string $enginef = null): void
    {
        $queue  = $this->queueFactory->get('a_venir_doublons_ai_queue');
        $worker = $this->queueWorkerManager->createInstance('a_venir_doublons_ai_queue');

        $processed = 0;
        while ($processed < $limit && $item = $queue->claimItem()) {
            try {
                $data = $item->data;
                if (!empty($enginef)) {
                    $data['engine'] = $enginef;
                    $data['force_engine'] = true;
                }
                $worker->processItem($data);
                $queue->deleteItem($item);
            } catch (\Throwable $e) {
                $queue->releaseItem($item);
                $this->io()->error('Erreur traitement item queue a_venir IA : ' . $e->getMessage());
                break;
            }
            $processed++;
        }

        $this->io()->text("$processed groupe(s) a_venir traité(s) par l'IA.");
    }


}
