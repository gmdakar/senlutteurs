<?php

namespace Drupal\misc_divers\Commands;

use Drush\Commands\DrushCommands;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\sl_live_search\Service\LiveSearchService;
use Drupal\shared_lib\Tools\SomeTools;

/**
 * Corrige le champ field_confrontation_date pour les combats dont la date
 * est fixée arbitrairement au 1er janvier (jour/mois inconnus à l'import,
 * seule l'année était sûre), en interrogeant l'IA par lots successifs.
 *
 * Règles de marquage de field_check_date_reelle = TRUE :
 *   - Année ≤ 1999 + date reste inconnue après au moins 1 recherche → TRUE
 *     (on ne re-cherche plus les vieux combats introuvables)
 *   - Année ≥ 2000 + l'IA confirme explicitement un vrai 01/01
 *     (date_confirme = 1) → TRUE
 *   - Année ≥ 2000 + date reste inconnue (pas de confirmation) → on ne marque pas
 *
 * Cooldown anti-boucle (State) :
 *   Tout combat resté au 01/01 après une vérification est placé en cooldown
 *   de 3 mois (STATE_COOLDOWN_KEY). Il ne peut pas être réinjecté dans la
 *   file avant expiration, même s'il n'est pas marqué field_check_date_reelle.
 *
 * Usage :
 *   drush date-reelle-update
 *   drush dru
 *   drush date-reelle-update --batch-size=10 --engine=grok
 *   drush date-reelle-update --reset-queue
 */
class DateReelleUpdateCommands extends DrushCommands
{
    const STATE_QUEUE_KEY = 'misc_divers.date_reelle_update.queue';
    /** Timestamp de dernière vérification par nid (cooldown anti-boucle). */
    const STATE_COOLDOWN_KEY = 'misc_divers.date_reelle_update.cooldown';
    /** Durée du cooldown en secondes (3 mois ≈ 90 jours). */
    const COOLDOWN_SECONDS = 90 * 24 * 60 * 60;
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
        $this->entityTypeManager = $entity_type_manager;
        $this->state             = $state;
        $this->liveSearchService = $live_search_service;
    }

    #[Command(name: 'date-reelle-update', aliases: ['dru'])]
    #[Option(name: 'engine', description: "Moteur IA à utiliser (grok|gemini). Par défaut : config globale.")]
    #[Option(name: 'batch-size', description: "Nombre de combats à traiter par lot. Par défaut : 20.")]
    #[Option(name: 'reset-queue', description: "Reconstruit la file d'attente à partir de zéro avant de traiter.")]
    #[Option(name: 'single-batch', description: "Traite un seul lot puis s'arrête (utile pour un cron appelé à intervalles réguliers).")]
    #[Usage(name: 'drush date-reelle-update', description: "Traite tous les combats datés du 1er janvier par lots successifs.")]
    #[Usage(name: 'drush date-reelle-update --batch-size=10 --reset-queue', description: "Reconstruit la file et traite par lots de 10.")]
    #[Usage(name: 'drush date-reelle-update --single-batch', description: "Traite un seul lot puis s'arrête (pour cron).")]
    public function dateReelleUpdate(array $options = ['engine' => NULL, 'batch-size' => self::DEFAULT_BATCH_SIZE, 'reset-queue' => FALSE, 'single-batch' => FALSE]): void
    {
        $storage     = $this->entityTypeManager->getStorage('node');
        $batchSize   = max(1, (int) ($options['batch-size'] ?? self::DEFAULT_BATCH_SIZE));
        $singleBatch = !empty($options['single-batch']);

        // ── 1. (Re)construction de la file si besoin ────────────────────────────
        $queue = $this->state->get(self::STATE_QUEUE_KEY, []);

        if (!empty($options['reset-queue']) || empty($queue)) {
            $queue = $this->buildQueue($storage);
            $this->state->set(self::STATE_QUEUE_KEY, $queue);
            $this->io()->writeln(sprintf(
                '<info>File reconstruite : %d combat(s) daté(s) du 1er janvier détecté(s).</info>',
                count($queue)
            ));
        }

        if (empty($queue)) {
            $this->io()->success('Aucun combat au 1er janvier à traiter. File vide.');
            return;
        }

        $this->io()->writeln(sprintf('<info>Début du traitement de %d combat(s) (par lots de %d)...</info>', count($queue), $batchSize));

        // ── 2. Boucle globale sur toute la file d'attente ─────────────────────────
        while (true) {
            $queue = $this->state->get(self::STATE_QUEUE_KEY, []);

            if (empty($queue)) {
                $this->io()->writeln('<info>File épuisée. Vérification de nouveaux candidats en base de données...</info>');
                $queue = $this->buildQueue($storage);
                $this->state->set(self::STATE_QUEUE_KEY, $queue);

                if (empty($queue)) {
                    $this->io()->success('Aucun nouveau combat au 1er janvier détecté. Fin définitive du traitement.');
                    break;
                }

                $this->io()->writeln(sprintf(
                    '<info>Nouveaux candidats détectés : %d combat(s) à traiter. Reprise...</info>',
                    count($queue)
                ));
            }

            // Prélèvement du lot
            $batchNids = array_slice($queue, 0, $batchSize);
            $combats   = $storage->loadMultiple($batchNids);

            if (empty($combats)) {
                $this->removeFromQueue($batchNids);
                continue;
            }

            // ── 3. Construction de la liste aplatie et compressée ───────────────────
            $combatsSansDateCertaine = [];
            $combatsById = [];

            foreach ($combats as $combat) {
                $fieldIdText = trim((string) ($combat->get('field_id_text')->value ?? ''));
                if ($fieldIdText === '') {
                    // Pas d'identifiant fiable pour matcher la réponse IA → on retire du lot.
                    continue;
                }

                $l1 = $combat->get('field_athlete_1')->entity;
                $l2 = $combat->get('field_athlete_2')->entity;
                if (!$l1 || !$l2) {
                    continue;
                }

                $combatsSansDateCertaine[] = [
                    'field_id_text' => $fieldIdText,
                    'lutteur1'      => $l1->label(),
                    'lutteur2'      => $l2->label(),
                    'date_actuelle' => $combat->get('field_confrontation_date')->value,
                ];
                $combatsById[$fieldIdText] = $combat;
            }

            if (empty($combatsSansDateCertaine)) {
                $this->io()->warning("Lot sans identifiant/athlètes exploitables — lot retiré de la file.");
                $this->removeFromQueue($batchNids);
                continue;
            }

            $jsonCombats = json_encode(
                $combatsSansDateCertaine,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            $this->io()->writeln(sprintf("\n--- Envoi d'un lot de %d combat(s) à l'IA ---", count($combatsSansDateCertaine)));

            // ── 4. Construction du prompt ────────────────────────────────────────────
            $prompt = $this->buildPrompt($jsonCombats);
            if (empty($prompt)) {
                return;
            }

            // ── 5. Appel liveSearch ───────────────────────────────────────────────────
            $engine      = $options['engine'] ?? 'gemini';
            $forceEngine = !empty($options['engine']);

            $result = $this->liveSearchService->liveSearch($prompt, $engine, 'normal', $forceEngine);

            if (empty($result['success'])) {
                $this->io()->error(sprintf(
                    "Échec de l'appel IA : %s%s. Arrêt du traitement pour éviter de bloquer.",
                    $result['error'] ?? 'erreur inconnue',
                    isset($result['retry_at']) ? ' (réessai après ' . $result['retry_at'] . ')' : ''
                ));
                return;
            }

            $data = SomeTools::decodeJson($result['content'] ?? '', __METHOD__);

            if (!is_array($data)) {
                $this->io()->error('Réponse IA invalide (JSON illisible) — arrêt de la boucle.');
                return;
            }

            // Tolère { "combats": [...] } en plus d'un tableau direct.
            if (isset($data['combats']) && is_array($data['combats'])) {
                $data = $data['combats'];
            }

            if (empty($data)) {
                $this->io()->warning("Réponse IA vide — lot retiré de la file.");
                $this->removeFromQueue($batchNids);
                continue;
            }

            // ── 6. Traitement du résultat : mise à jour de field_confrontation_date ─
            $updated  = 0;
            $notFound = 0;
            $skipped  = 0;
            $markedChecked = 0;

            // Garde la trace des field_id_text réellement mis à jour (date changée)
            // et de ceux explicitement confirmés comme vrai 01/01 par l'IA.
            $updatedFieldIds   = [];
            $confirmedFieldIds = [];

            foreach ($data as $item) {
                if (!is_array($item)) {
                    $skipped++;
                    continue;
                }

                $fieldIdText   = trim((string) ($item['field_id_text'] ?? ''));
                $dateReelle    = trim((string) ($item['date_reelle'] ?? ''));
                $dateConfirme  = (int) ($item['date_confirme'] ?? 0);

                if ($fieldIdText === '' || $dateReelle === '' || $dateReelle === '?') {
                    $skipped++; // toujours incertain → on ne touche pas la date
                    continue;
                }

                if (!isset($combatsById[$fieldIdText])) {
                    $this->io()->warning(sprintf('field_id_text "%s" inconnu localement, ignoré.', $fieldIdText));
                    $notFound++;
                    continue;
                }

                // Validation stricte du format YYYY-MM-DD.
                $dt = \DateTime::createFromFormat('Y-m-d', $dateReelle);
                if (!$dt || $dt->format('Y-m-d') !== $dateReelle) {
                    $this->io()->warning(sprintf('Date "%s" invalide pour %s, ignorée.', $dateReelle, $fieldIdText));
                    $skipped++;
                    continue;
                }

                $combat = $combatsById[$fieldIdText];

                // Re-vérifie que le combat est toujours au 1er janvier entre temps
                // (concurrence / déjà corrigé par un autre run).
                $currentDate = $combat->get('field_confrontation_date')->value;
                if (substr((string) $currentDate, 5) !== '01-01') {
                    $skipped++;
                    continue;
                }

                // Cas explicite : l'IA confirme que c'est un vrai 1er janvier.
                if ($dateConfirme === 1 && $dateReelle === $currentDate) {
                    $confirmedFieldIds[$fieldIdText] = TRUE;
                    $skipped++;
                    continue;
                }

                if ($dateReelle === $currentDate) {
                    // Reste au 01/01 sans confirmation explicite → on marquera plus bas.
                    $skipped++;
                    continue;
                }

                // Date réelle différente trouvée → on met à jour.
                $combat->set('field_confrontation_date', $dateReelle);
                $combat->setNewRevision(FALSE);

                // --- AJOUT : Forcer Pathauto à regénérer l'alias d'URL ---
                // Cela recochera la case "Générer automatiquement un alias d'URL"
                // et mettra à jour le path selon ton pattern basé sur la date.
                if ($combat->hasField('path')) {
                    $combat->path->pathauto = 1;
                }
                // ---------------------------------------------------------

                $combat->save();
                $updated++;
                $updatedFieldIds[$fieldIdText] = TRUE;

                \Drupal::logger('misc_divers')->notice(
                    'DateReelleUpdateCommands – about to update @id from @old to @new',
                    [
                        '@id'  => $combat->id(),
                        '@old' => $currentDate,
                        '@new' => $dateReelle,
                    ]
                );

                $this->io()->success(sprintf(
                    '[%d] %s : %s → %s (Mis à jour et loggé !)',
                    $combat->id(),
                    $combat->getTitle(),
                    $currentDate,
                    $dateReelle
                ));
            }

            // ── 6b. Marquer field_check_date_reelle = TRUE selon les règles métier :
            //     1. Année ≤ 1999 ET date reste inconnue après recherche → TRUE
            //        (on ne re-cherche plus les vieux combats introuvables)
            //     2. Année ≥ 2000 ET l'IA a explicitement confirmé un vrai 01/01
            //        (date_confirme = 1) → TRUE
            //     3. Année ≥ 2000 ET date reste inconnue (pas de confirmation)
            //        → on NE marque PAS (on pourra retenter plus tard)
            foreach ($combatsById as $fieldIdText => $combat) {
                // Déjà mis à jour avec une vraie date différente → pas besoin du flag.
                if (isset($updatedFieldIds[$fieldIdText])) {
                    continue;
                }

                $currentDate = $combat->get('field_confrontation_date')->value;
                if (substr((string) $currentDate, 5) !== '01-01') {
                    continue;
                }

                if (!$combat->hasField('field_check_date_reelle')) {
                    continue;
                }

                // Déjà coché → rien à faire.
                if ((bool) $combat->get('field_check_date_reelle')->value) {
                    continue;
                }

                $year = (int) substr((string) $currentDate, 0, 4);
                $isConfirmed = isset($confirmedFieldIds[$fieldIdText]);

                // Règle 1 : pré-2000 + date inconnue → on marque
                // Règle 2 : ≥ 2000 + confirmation explicite d'un vrai 01/01 → on marque
                // Règle 3 : ≥ 2000 + pas de confirmation → on ne marque PAS
                $shouldMark = FALSE;
                $reason     = '';

                if ($year <= 1999) {
                    $shouldMark = TRUE;
                    $reason     = 'pré-2000 + date inconnue après recherche';
                }
                elseif ($isConfirmed) {
                    $shouldMark = TRUE;
                    $reason     = 'vrai 01/01 confirmé par l\'IA (date_confirme=1)';
                }

                if (!$shouldMark) {
                    // ≥ 2000 et non confirmé → on laisse pour un éventuel re-traitement futur
                    continue;
                }

                $combat->set('field_check_date_reelle', TRUE);
                $combat->setNewRevision(FALSE);
                $combat->save();
                $markedChecked++;

                $this->io()->writeln(sprintf(
                    '<comment>[%d] %s : %s → field_check_date_reelle = TRUE (ne sera plus retraité)</comment>',
                    $combat->id(),
                    $combat->getTitle(),
                    $reason
                ));
            }

            // ── 6c. Enregistre le cooldown (3 mois) pour tous les combats du lot
            //     qui restent encore au 01/01 (marqués ou non). Ainsi ils ne
            //     reviendront pas dans la file avant expiration du cooldown.
            $nidsStillOn0101 = [];
            foreach ($combatsById as $fieldIdText => $combat) {
                if (isset($updatedFieldIds[$fieldIdText])) {
                    continue;
                }
                $currentDate = $combat->get('field_confrontation_date')->value;
                if (substr((string) $currentDate, 5) === '01-01') {
                    $nidsStillOn0101[] = (int) $combat->id();
                }
            }
            $this->recordCooldownForNids($nidsStillOn0101);

            // ── 7. Retrait du lot traité de la file ─────────────────────────────────
            $this->removeFromQueue($batchNids);

            $remainingCount = count($this->state->get(self::STATE_QUEUE_KEY, []));
            $this->io()->writeln(sprintf(
                'Lot validé : %d mis à jour, %d introuvable(s), %d ignoré(s), %d marqué(s) check_date_reelle. Restant : %d.',
                $updated,
                $notFound,
                $skipped,
                $markedChecked,
                $remainingCount
            ));

            // ── 8. Arrêt anticipé si --single-batch est demandé ─────────────────────
            if ($singleBatch) {
                $this->io()->writeln('<info>Mode --single-batch actif : arrêt après ce lot.</info>');
                break;
            }
        }
    }

    /**
     * Construit la file : tous les nœuds "confrontation" dont la date
     * est le 1er janvier (LIKE '%-01-01'), triés du plus récent au plus ancien.
     *
     * Exclut :
     *   - les confrontations déjà marquées field_check_date_reelle = 1
     *   - les confrontations encore sous cooldown (< 3 mois depuis la dernière vérif)
     */
    protected function buildQueue($storage): array
    {
        $connection = \Drupal::database();

        $nids = $connection->query("
            SELECT n.entity_id
            FROM {node__field_confrontation_date} n
            INNER JOIN {node_field_data} nfd ON nfd.nid = n.entity_id
            LEFT JOIN {node__field_check_date_reelle} chk
              ON chk.entity_id = n.entity_id
             AND chk.deleted = 0
            WHERE nfd.type = 'confrontation'
              AND n.field_confrontation_date_value LIKE '%-01-01'
              AND (chk.field_check_date_reelle_value IS NULL OR chk.field_check_date_reelle_value = 0)
            ORDER BY n.field_confrontation_date_value DESC
        ")->fetchCol();

        $nids = array_values(array_unique(array_map('intval', $nids)));

        // Filtre cooldown : exclure les nids vérifiés il y a moins de 3 mois.
        $cooldown = $this->state->get(self::STATE_COOLDOWN_KEY, []);
        $now = time();
        $nids = array_values(array_filter($nids, function ($nid) use ($cooldown, $now) {
            if (!isset($cooldown[$nid])) {
                return TRUE;
            }
            return ($now - (int) $cooldown[$nid]) >= self::COOLDOWN_SECONDS;
        }));

        return $nids;
    }

    protected function removeFromQueue(array $nids): void
    {
        $queue = $this->state->get(self::STATE_QUEUE_KEY, []);
        $queue = array_values(array_diff($queue, $nids));
        $this->state->set(self::STATE_QUEUE_KEY, $queue);
    }

    /**
     * Enregistre le cooldown pour une liste de nids (ceux qui restent au 01/01).
     * Empêche leur réinjection dans la file pendant COOLDOWN_SECONDS (3 mois).
     */
    protected function recordCooldownForNids(array $nids): void
    {
        if (empty($nids)) {
            return;
        }

        $cooldown = $this->state->get(self::STATE_COOLDOWN_KEY, []);
        $now = time();

        foreach ($nids as $nid) {
            $cooldown[(int) $nid] = $now;
        }

        // Nettoyage opportuniste des entrées expirées (> 3 mois) pour ne pas
        // laisser grossir indéfiniment le state.
        $threshold = $now - self::COOLDOWN_SECONDS;
        foreach ($cooldown as $nid => $ts) {
            if ((int) $ts < $threshold) {
                unset($cooldown[$nid]);
            }
        }

        $this->state->set(self::STATE_COOLDOWN_KEY, $cooldown);
    }

    /**
     * Construit le prompt envoyé à l'IA à partir de la configuration
     * request_ai.settings (même mécanisme que prompt_get_ecuries).
     */
    protected function buildPrompt(string $jsonCombats): string
    {
        $config         = \Drupal::config('request_ai.settings');
        $promptTemplate = $config->get('prompt_get_real_dates');

        if (empty($promptTemplate)) {
            \Drupal::logger('misc_divers')->error(
                'date-reelle-update : Le paramètre de configuration "prompt_get_real_dates" est vide ou non défini dans request_ai.settings.'
            );
            return '';
        }

        if (!str_contains($promptTemplate, '@combats_dates_incertaines')) {
            $promptTemplate .= "\n\n@combats_dates_incertaines";
        }

        return str_replace('@combats_dates_incertaines', $jsonCombats, $promptTemplate);
    }
}
