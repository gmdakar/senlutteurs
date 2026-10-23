<?php

namespace Drupal\misc_divers\Commands;

use Drush\Commands\DrushCommands;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\Entity\Node;
use Drupal\sl_live_search\Service\LiveSearchService;
use Drupal\shared_lib\Tools\SomeTools;

/**
 * Met à jour le champ field_ecurie des athlètes qui n'ont pas d'écurie,
 * en interrogeant l'IA via LiveSearchService par lots successifs.
 *
 * Usage :
 *   drush ecurie-update
 *   drush eu
 *   drush ecurie-update --batch-size=10 --engine=grok
 *   drush ecurie-update --reset-queue
 */
class EcurieUpdateCommands extends DrushCommands
{
    const STATE_QUEUE_KEY = 'misc_divers.ecurie_update.queue';
    const DEFAULT_BATCH_SIZE = 20;

    protected EntityTypeManagerInterface $entityTypeManager;
    protected StateInterface $state;
    protected LiveSearchService $liveSearchService;

    public function __construct(
        EntityTypeManagerInterface $entity_type_manager,
        StateInterface $state,
        LiveSearchService $live_search_service
    ) {
        parent::__construct();
        $this->entityTypeManager  = $entity_type_manager;
        $this->state              = $state;
        $this->liveSearchService  = $live_search_service;
    }

    #[Command(name: 'ecurie-update', aliases: ['eu'])]
    #[Option(name: 'engine', description: "Moteur IA à utiliser (grok|gemini). Par défaut : config globale.")]
    #[Option(name: 'batch-size', description: "Nombre de lutteurs à traiter par lot. Par défaut : 20.")]
    #[Option(name: 'reset-queue', description: "Reconstruit la file d'attente à partir de zéro avant de traiter.")]
    #[Option(name: 'single-batch', description: "Traite un seul lot puis s'arrête (utile pour un cron appelé à intervalles réguliers).")]
    #[Usage(name: 'drush ecurie-update', description: "Traite tous les lutteurs sans écurie par lots successifs.")]
    #[Usage(name: 'drush ecurie-update --batch-size=10 --reset-queue', description: "Reconstruit la file et traite par lots de 10.")]
    #[Usage(name: 'drush ecurie-update --single-batch', description: "Traite un seul lot puis s'arrête (pour cron).")]
    public function ecurieUpdate(array $options = ['engine' => NULL, 'batch-size' => self::DEFAULT_BATCH_SIZE, 'reset-queue' => FALSE, 'single-batch' => FALSE]): void
    {
        $storage = $this->entityTypeManager->getStorage('node');
        $batchSize = max(1, (int) ($options['batch-size'] ?? self::DEFAULT_BATCH_SIZE));
        $singleBatch = !empty($options['single-batch']);

        // ── 1. (Re)construction de la file si besoin ────────────────────────────
        $queue = $this->state->get(self::STATE_QUEUE_KEY, []);

        if (!empty($options['reset-queue']) || empty($queue)) {
            $queue = $this->buildQueue($storage);
            $this->state->set(self::STATE_QUEUE_KEY, $queue);
            $this->io()->writeln(sprintf(
                '<info>File reconstruite : %d lutteur(s) sans écurie détecté(s).</info>',
                count($queue)
            ));
        }

        if (empty($queue)) {
            $this->io()->success('Aucun lutteur sans écurie à traiter. File vide.');
            return;
        }

        $this->io()->writeln(sprintf('<info>Début du traitement de %d lutteur(s) (par lots de %d)...</info>', count($queue), $batchSize));

        // ── 2. Boucle globale sur toute la file d'attente ─────────────────────────
        while (true) {
            $queue = $this->state->get(self::STATE_QUEUE_KEY, []);

            // SI LA FILE EST VIDE : On tente de la reconstruire automatiquement pour voir s'il y a de nouveaux candidats
            if (empty($queue)) {
                $this->io()->writeln('<info>File épuisée. Vérification de nouveaux candidats en base de données...</info>');
                $queue = $this->buildQueue($storage);
                $this->state->set(self::STATE_QUEUE_KEY, $queue);

                if (empty($queue)) {
                    $this->io()->success('Aucun nouveau lutteur sans écurie détecté. Fin définitive du traitement.');
                    break; // Vraie fin : vraiment aucun candidat en base
                }

                $this->io()->writeln(sprintf(
                    '<info>Nouveaux candidats détectés : %d lutteur(s) à traiter. Reprise...</info>',
                    count($queue)
                ));
            }

            // Prélèvement du lot
            $batchNids = array_slice($queue, 0, $batchSize);
            $athletes  = $storage->loadMultiple($batchNids);

            if (empty($athletes)) {
                // Les NIDs en file n'existent plus -> purge du lot et continuation
                $this->removeFromQueue($batchNids);
                continue;
            }

            // ── 3. Construction de la liste aplatie et compressée ───────────────────
            $lutteursSansEcurie = [];
            foreach ($athletes as $athlete) {
                $lutteursSansEcurie[] = [
                    'id'  => (int) $athlete->id(),
                    'nom' => $athlete->getTitle(),
                ];
            }

            $jsonLutteurs = json_encode(
                $lutteursSansEcurie,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            $this->io()->writeln(sprintf("\n--- Envoi d'un lot de %d lutteur(s) à l'IA ---", count($lutteursSansEcurie)));

            // ── 4. Construction du prompt ────────────────────────────────────────────
            $prompt = $this->buildPrompt($jsonLutteurs);
            if (empty($prompt)) {
                return;
            }

            // ── 5. Appel liveSearch ───────────────────────────────────────────────────
            entropy_engine:
            $engine      = $options['engine'] ?? 'gemini';
            $forceEngine = !empty($options['engine']);

            $result = $this->liveSearchService->liveSearch($prompt, $engine, 'normal', $forceEngine);

            if (empty($result['success'])) {
                $this->io()->error(sprintf(
                    "Échec de l'appel IA : %s%s. Arrêt du traitement pour éviter de bloquer.",
                    $result['error'] ?? 'erreur inconnue',
                    isset($result['retry_at']) ? ' (réessai après ' . $result['retry_at'] . ')' : ''
                ));
                // On stoppe la boucle pour laisser la file intacte en vue d'une relance ultérieure
                return;
            }

            $data = json_decode($result['content'] ?? '[]', true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                $this->io()->error('Réponse IA invalide (JSON illisible) — arrêt de la boucle.');
                return;
            }

            if (empty($data)) {
                $this->io()->warning("Réponse IA vide — lot retiré de la file.");
                $this->removeFromQueue($batchNids);
                continue;
            }

            // ── 6. Traitement du résultat : mise à jour de field_ecurie ─────────────
            $updated  = 0;
            $notFound = 0;
            $skipped  = 0;

            $athletesByNorm = [];
            foreach ($athletes as $athlete) {
                $norm = SomeTools::normalizeForDistance($athlete->getTitle());
                $athletesByNorm[$norm] = $athlete;
            }

            foreach ($data as $item) {
                $lutteurNom  = trim((string) ($item['lutteur'] ?? ''));
                $ecurieNom   = trim((string) ($item['ecurie'] ?? ''));

                if ($lutteurNom === '' || $ecurieNom === '' || $this->isUnknownEcurieName($ecurieNom)) {
                    $skipped++;
                    continue;
                }

                $normLutteur = SomeTools::normalizeForDistance($lutteurNom);
                $athlete     = $athletesByNorm[$normLutteur] ?? null;

                if (!$athlete) {
                    foreach ($athletes as $candidate) {
                        if (SomeTools::fuzzyMatchName($lutteurNom, $candidate->getTitle())) {
                            $athlete = $candidate;
                            break;
                        }
                    }
                }

                if (!$athlete) {
                    $notFound++;
                    continue;
                }

                $ecurieTitle = SomeTools::correctNames($ecurieNom);

                if ($ecurieTitle === '' || is_numeric($ecurieTitle) || $this->isUnknownEcurieName($ecurieTitle)) {
                    $skipped++;
                    continue;
                }

                $eNids = $storage->getQuery()
                    ->accessCheck(false)
                    ->condition('type', 'ecurie')
                    ->condition('title', $ecurieTitle)
                    ->execute();

                if (!empty($eNids)) {
                    $ecurieId = reset($eNids);
                } else {
                    try {
                        $ecurieNode = Node::create([
                            'type'   => 'ecurie',
                            'title'  => $ecurieTitle,
                            'status' => 1,
                        ]);
                        $ecurieNode->save();
                        $ecurieId = $ecurieNode->id();
                    } catch (\Exception $e) {
                        $skipped++;
                        continue;
                    }
                }

                if (!$athlete->get('field_ecurie')->isEmpty()) {
                    $currentEcurieId   = (int) $athlete->get('field_ecurie')->target_id;
                    $currentEcurieNode = $currentEcurieId ? $storage->load($currentEcurieId) : null;
                    $currentIsUnknown = !$currentEcurieNode || $this->isUnknownEcurieName($currentEcurieNode->getTitle());

                    if (!$currentIsUnknown) {
                        $skipped++;
                        continue;
                    }
                }

                try {
                    $athlete->set('field_ecurie', ['target_id' => $ecurieId]);
                    $athlete->setNewRevision(false);
                    $athlete->save();
                    $updated++;
                } catch (\Exception $e) {
                    $skipped++;
                }
            }

            // ── 7. Retrait du lot traité de la file ─────────────────────────────────
            $this->removeFromQueue($batchNids);

            $remainingCount = count($this->state->get(self::STATE_QUEUE_KEY, []));
            $this->io()->writeln(sprintf(
                'Lot validé : %d mis à jour, %d introuvable(s), %d ignoré(s). Restant : %d.',
                $updated,
                $notFound,
                $skipped,
                $remainingCount
            ));

            // ── 8. Arrêt anticipé si --single-batch est demandé ─────────────────────
            if ($singleBatch) {
                $this->io()->writeln('<info>Mode --single-batch actif : arrêt après ce lot.</info>');
                break;
            }
        }
    }

    protected function isUnknownEcurieName(string $ecurieName): bool
    {
        $norm = SomeTools::normalizeForDistance($ecurieName);
        return $norm === '' || $norm === '?' || str_contains($norm, 'inconnu');
    }

    protected function buildQueue($storage): array
    {
        $allAthleteNids = $storage->getQuery()
            ->accessCheck(false)
            ->condition('type', 'athlete')
            ->execute();

        $withoutEcurie = [];

        foreach ($storage->loadMultiple($allAthleteNids) as $athlete) {
            if ($athlete->get('field_ecurie')->isEmpty()) {
                $withoutEcurie[] = (int) $athlete->id();
                continue;
            }

            $ecurieId  = (int) $athlete->get('field_ecurie')->target_id;
            $ecurieNode = $ecurieId ? $storage->load($ecurieId) : null;

            if (!$ecurieNode || $this->isUnknownEcurieName($ecurieNode->getTitle())) {
                $withoutEcurie[] = (int) $athlete->id();
            }
        }

        return array_values(array_unique(array_map('intval', $withoutEcurie)));
    }

    protected function removeFromQueue(array $nids): void
    {
        $queue = $this->state->get(self::STATE_QUEUE_KEY, []);
        $queue = array_values(array_diff($queue, $nids));
        $this->state->set(self::STATE_QUEUE_KEY, $queue);
    }

    /**
     * Construit le prompt JSON simple envoyé à l'IA.
     */
    /**
     * Construit le prompt envoyé à l'IA à partir de la configuration.
     */
    protected function buildPrompt(string $jsonLutteurs): string
    {
        $config = \Drupal::config('request_ai.settings');
        $promptTemplate = $config->get('prompt_get_ecuries');

        if (empty($promptTemplate)) {
            $this->logger()->error(
                'ecurie-update : Le paramètre de configuration "prompt_get_ecuries" est vide ou non défini dans request_ai.settings.'
            );
            $promptTemplate = '@lutteurs_sans_ecurie';
            return '';
        } else {
            // Si le token de remplacement n'est pas présent, on l'ajoute pour éviter l'échec de substitution
            if (!str_contains($promptTemplate, '@lutteurs_sans_ecurie')) {
                $promptTemplate .= "\n\n@lutteurs_sans_ecurie";
            }
        }

        return str_replace('@lutteurs_sans_ecurie', $jsonLutteurs, $promptTemplate);
    }
}
