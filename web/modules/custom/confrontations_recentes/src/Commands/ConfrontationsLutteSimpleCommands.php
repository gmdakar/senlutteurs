<?php

namespace Drupal\confrontations_recentes\Commands;

use Drush\Commands\DrushCommands;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;
use Drupal\misc_divers\Commands\CommandsBase;
use Drupal\shared_lib\Tools\SomeTools;

/**
 * Drush command : détection des combats potentiellement de lutte simple
 * non encore marqués comme tels (field_is_lutte_simple).
 *
 * Cible les lutteurs ayant déjà au moins 50% de leurs combats connus
 * marqués lutte simple, et met en queue (par lots de 3 combats max, triés
 * par date) leurs combats restants pas encore marqués, pour résolution IA
 * via le module request_ai (queue confrontations_lutte_simple_ai_queue,
 * cron toutes les 300s).
 */
class ConfrontationsLutteSimpleCommands extends CommandsBase
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

    #[Command(name: 'confrontations_lutte_simple:run', aliases: ['clsr'])]
    #[Option(name: 'no-ai-run', description: 'Met les lots suspects en queue SANS les traiter immédiatement (laisse le cron s\'en charger, cron = 300s).')]
    #[Option(name: 'limit', description: 'Nombre max de lots traités immédiatement si --no-ai-run n\'est pas passé (défaut : 100).')]
    #[Option(name: 'queue-limit', description: 'Nombre max de lots mis en queue par ce passage de détection (défaut : 0 = illimité, tout mettre en queue).')]
    #[Option(name: 'force', description: 'Ignore le créneau horaire de cron configuré (misc_divers.settings) et lance la commande immédiatement.')]
    #[Option(name: 'force-requeue', description: 'Relance la détection même si la queue précédente n\'est pas encore vide (déconseillé : risque de doublons de lots).')]
    #[Option(name: 'enginef', description: 'Force le moteur IA (grok, gemini) pour les lots traités immédiatement par ce run. Par défaut : moteur configuré dans request_ai.settings.')]
    #[Usage(name: 'drush clsr --enginef=grok', description: 'Force le moteur Grok pour le traitement immédiat des lots suspects')]
    public function confrontationsLutteSimple(array $options = ['no-ai-run' => false, 'limit' => 100, 'queue-limit' => 0, 'force' => false, 'force-requeue' => false, 'enginef' => null])
    {
        // Vérification de la granularité d'exécution cron, sauf --force qui
        // permet de lancer la commande immédiatement quelle que soit la
        // config horaire (misc_divers.settings) - utile pour tester/relancer
        // manuellement sans attendre le prochain créneau programmé.
        if (empty($options['force']) && !$this->isScheduledNow('confrontations_lutte_simple:run')) {
            return;
        }

        // Garde-fou anti-doublons : si la queue du run précédent n'a pas
        // encore été totalement vidée (cron pas encore repassé, ou
        // suspendu suite à une erreur IA), on ne relance PAS la détection.
        // Sinon on re-détecterait les mêmes combats encore non marqués et
        // on recréerait des lots équivalents en double dans la queue.
        $queue = $this->queueFactory->get('confrontations_lutte_simple_ai_queue');
        $pending = $queue->numberOfItems();
        if ($pending > 0 && empty($options['force-requeue'])) {
            $this->io()->warning("$pending lot(s) encore en attente dans confrontations_lutte_simple_ai_queue (run précédent pas terminé). Détection ignorée pour éviter les doublons. Utilisez --force-requeue pour outrepasser.");
            return;
        }

        $this->io()->section('Détection des lutteurs suspects de lutte simple (≥ 20% de leurs combats déjà marqués).');

        $queueLimit = (int) ($options['queue-limit'] ?? 0);
        $nbQueued = SomeTools::queueSuspectedLutteSimpleCombats($queueLimit);

        if ($nbQueued === 0) {
            $this->io()->success('Aucun lot suspect détecté (rien à vérifier).');
            return;
        }

        $this->io()->text("$nbQueued lot(s) suspect(s) (3 combats max/lot) mis en queue (confrontations_lutte_simple_ai_queue).");

        if (empty($options['no-ai-run'])) {
            $this->drainAiQueue((int) ($options['limit'] ?? 100), $options['enginef'] ?? null);
        } else {
            $this->io()->text('Traitement différé au cron (--no-ai-run).');
        }

        $this->io()->success('Traitement terminé.');
    }

    /**
     * Traite immédiatement la queue 'confrontations_lutte_simple_ai_queue'
     * au lieu d'attendre le prochain passage cron.
     */
    private function drainAiQueue(int $limit = 100, ?string $enginef = null): void
    {
        $queue  = $this->queueFactory->get('confrontations_lutte_simple_ai_queue');
        $worker = $this->queueWorkerManager->createInstance('confrontations_lutte_simple_ai_queue');

        $processed = 0;
        while ($processed < $limit && $item = $queue->claimItem()) {
            try {
                $data = $item->data;
                if (!empty($enginef)) {
                    // Injecté au niveau de l'item plutôt que passé en
                    // paramètre de processItem() : sa signature est fixée
                    // par QueueWorkerInterface (appelée telle quelle par
                    // le cron Drupal), donc on force le moteur via $data,
                    // lu par ConfrontationsLutteSimpleAiQueue::processItem().
                    $data['engine'] = $enginef;
                    $data['force_engine'] = true;
                }
                $worker->processItem($data);
                $queue->deleteItem($item);
            } catch (\Exception $e) {
                $queue->releaseItem($item);
                $this->io()->error('Erreur traitement item queue IA lutte simple : ' . $e->getMessage());
                break;
            }
            $processed++;
        }

        $this->io()->text("$processed lot(s) traité(s) par l'IA.");
    }
}
