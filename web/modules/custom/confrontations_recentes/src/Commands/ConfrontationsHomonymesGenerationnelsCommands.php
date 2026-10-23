<?php

namespace Drupal\confrontations_recentes\Commands;

use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;
use Drupal\misc_divers\Commands\CommandsBase;
use Drupal\shared_lib\Tools\SomeTools;

/**
 * Drush command : détection des lutteurs "d'origine" (nom sans suffixe
 * générationnel) ayant au moins un homonyme générationnel en base (ex:
 * "Boy Faye" + "Boy Faye 2"/"Boy Faye Junior") ET un trou d'au moins 5 ans
 * entre 2 de leurs combats successifs (probable signe que les combats
 * récents appartiennent en réalité à l'homonyme plus jeune).
 *
 * Met en queue (confrontations_homonymes_generationnels_ai_queue, cron
 * 300s) tous les combats suspects (ceux à partir du trou détecté) pour
 * vérification/correction IA via le module request_ai (sl_live_search).
 */
class ConfrontationsHomonymesGenerationnelsCommands extends CommandsBase
{

    protected $queueWorkerManager;
    protected $queueFactory;
    protected $entityTypeManager;

    public function __construct()
    {
        parent::__construct();
        $this->queueWorkerManager = \Drupal::service('plugin.manager.queue_worker');
        $this->queueFactory = \Drupal::service('queue');
        $this->entityTypeManager = \Drupal::service('entity_type.manager');
    }

    #[Command(name: 'confrontations_homonymes_generationnels:run', aliases: ['chgr'])]
    #[Option(name: 'no-ai-run', description: 'Met les lots suspects en queue SANS les traiter immédiatement (laisse le cron s\'en charger, cron = 300s).')]
    #[Option(name: 'limit', description: 'Nombre max de lots traités immédiatement si --no-ai-run n\'est pas passé (défaut : 100).')]
    #[Option(name: 'queue-limit', description: 'Nombre max de pivots mis en queue par ce passage de détection (défaut : 0 = illimité).')]
    #[Option(name: 'force', description: 'Ignore le créneau horaire de cron configuré (misc_divers.settings) et lance la commande immédiatement.')]
    #[Option(name: 'force-requeue', description: 'Relance la détection même si la queue précédente n\'est pas encore vide (déconseillé : risque de doublons de lots).')]
    #[Option(name: 'enginef', description: 'Force le moteur IA (grok, gemini) pour les lots traités immédiatement par ce run. Par défaut : moteur configuré dans request_ai.settings.')]
    #[Usage(name: 'drush chgr --enginef=grok', description: 'Force le moteur Grok pour le traitement immédiat des pivots suspects')]
    public function confrontationsHomonymesGenerationnels(array $options = ['no-ai-run' => false, 'limit' => 100, 'queue-limit' => 0, 'force' => false, 'force-requeue' => false, 'enginef' => null])
    {
        if (empty($options['force']) && !$this->isScheduledNow('confrontations_homonymes_generationnels:run')) {
            return;
        }

        // Garde-fou anti-doublons : si la queue du run précédent n'a pas
        // encore été totalement vidée, on ne relance PAS la détection
        // (sinon les mêmes pivots seraient re-détectés et remis en queue
        // en double).
        $queue = $this->queueFactory->get('confrontations_homonymes_generationnels_ai_queue');
        $pending = $queue->numberOfItems();
        if ($pending > 0 && empty($options['force-requeue'])) {
            $this->io()->warning("$pending lot(s) encore en attente dans confrontations_homonymes_generationnels_ai_queue (run précédent pas terminé). Détection ignorée pour éviter les doublons. Utilisez --force-requeue pour outrepasser.");
            return;
        }

        $this->io()->section('Détection des lutteurs "d\'origine" avec homonyme(s) générationnel(s) et trou de carrière ≥5 ans.');

        $queueLimit = (int) ($options['queue-limit'] ?? 0);
        $nbQueued = SomeTools::queueSuspectedGenerationalHomonyms($queueLimit);

        if ($nbQueued === 0) {
            $this->io()->success('Aucun lutteur suspect détecté (rien à vérifier).');
            return;
        }

        $this->io()->text("$nbQueued pivot(s) suspect(s) mis en queue (confrontations_homonymes_generationnels_ai_queue).");

        if (empty($options['no-ai-run'])) {
            $this->drainAiQueue((int) ($options['limit'] ?? 100), $options['enginef'] ?? null);
        } else {
            $this->io()->text('Traitement différé au cron (--no-ai-run).');
        }

        $this->io()->success('Traitement terminé.');
    }

    /**
     * Traite immédiatement la queue
     * 'confrontations_homonymes_generationnels_ai_queue' au lieu d'attendre
     * le prochain passage cron.
     */
    private function drainAiQueue(int $limit = 100, ?string $enginef = null): void
    {
        $queue  = $this->queueFactory->get('confrontations_homonymes_generationnels_ai_queue');
        $worker = $this->queueWorkerManager->createInstance('confrontations_homonymes_generationnels_ai_queue');

        $processed = 0;
        while ($processed < $limit && $item = $queue->claimItem()) {
            try {
                $data = $item->data;
                if (!empty($enginef)) {
                    // Injecté au niveau de l'item plutôt que passé en
                    // paramètre de processItem() : sa signature est fixée
                    // par QueueWorkerInterface (appelée telle quelle par
                    // le cron Drupal), donc on force le moteur via $data,
                    // lu par
                    // ConfrontationsHomonymesGenerationnelsAiQueue::processItem().
                    $data['engine'] = $enginef;
                    $data['force_engine'] = true;
                }
                $worker->processItem($data);
                $queue->deleteItem($item);
            } catch (\Exception $e) {
                $queue->releaseItem($item);
                $this->io()->error('Erreur traitement item queue IA homonymes générationnels : ' . $e->getMessage());
                break;
            }
            $processed++;
        }

        $this->io()->text("$processed lot(s) traité(s) par l'IA.");
    }
}
