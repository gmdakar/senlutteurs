<?php

namespace Drupal\confrontations_recentes\Plugin\QueueWorker;

use Drupal\shared_lib\Plugin\QueueWorker\BaseCombatQueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\shared_lib\Tools\SomeTools;
use Drupal\Core\Queue\SuspendQueueException;

/**
 * Traite un par un les lots de combats suspects d'être mal rattachés à un
 * lutteur "d'origine" alors qu'ils appartiennent en réalité à un de ses
 * homonymes générationnels (ex: "Boy Faye" vs "Boy Faye 2"/"Boy Faye
 * Junior"), mis en queue par
 * AthleteDeduplicationService::queueSuspectedGenerationalHomonyms() suite
 * à la détection d'un trou d'au moins 5 ans dans la carrière du pivot.
 *
 * Pour chaque lot (un seul lutteur pivot, tous ses combats suspects
 * depuis le trou détecté) on :
 *  1. Interroge l'IA via sl_live_search avec le nom du pivot, son écurie,
 *     ses homonymes générationnels déjà connus en base, une liste de
 *     suggestions de noms proches (SomeTools::findSimilarAthleteNames), et
 *     la liste des combats suspects (adversaire, date, résultat).
 *  2. Pour chaque combat, applique le verdict IA :
 *     - le combat appartient bien au pivot : rien à faire.
 *     - le combat appartient à un homonyme (existant en base parmi les
 *       suggestions, ou un nouveau nom généré par l'IA selon les règles de
 *       nommage demandées) : met à jour le champ field_athlete_1/2
 *       concerné sur le node confrontation avec le bon athlète
 *       (récupéré ou créé via getOrCreateAthlete, avec split "Nom/Ecurie"
 *       si présent).
 *
 * @QueueWorker(
 *   id = "confrontations_homonymes_generationnels_ai_queue",
 *   title = @Translation("Résolution IA des homonymes générationnels suspects"),
 *   cron = {"time" = 300}
 * )
 */
class ConfrontationsHomonymesGenerationnelsAiQueue extends BaseCombatQueueWorker implements \Drupal\Core\Plugin\ContainerFactoryPluginInterface
{

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
    {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->get('config.factory'),
            $container->get('http_client'),
            $container->get('logger.channel.default'),
            $container->get('entity_type.manager')
        );
    }

    /**
     * @param array $data
     *   ['pivot_nid', 'pivot_name', 'ecurie_name', 'homonymes_connus',
     *   'suggestions', 'gap_annees', 'gap_date_avant', 'gap_date_apres',
     *   'combats' => [
     *      ['nid', 'field_id_text', 'pivot_field', 'adversaire', 'date', 'vainqueur'], ...
     *   ]]
     */
    public function processItem($data)
    {
        if (empty($data['combats']) || empty($data['pivot_nid'])) {
            return;
        }

        $storage = $this->entityTypeManager->getStorage('node');

        // Pré-vérification : le pivot existe toujours, et ne re-garder que
        // les combats encore existants ET dont le champ pivot pointe
        // toujours vers le pivot (un autre traitement a pu déjà corriger
        // le lien entre la mise en queue et le passage de cet item).
        $pivotNode = $storage->load($data['pivot_nid']);
        if (!$pivotNode) {
            return;
        }

        $stillSuspect = [];
        foreach ($data['combats'] as $c) {
            $node = $storage->load($c['nid']);
            if (!$node || empty($c['pivot_field']) || !$node->hasField($c['pivot_field'])) {
                continue;
            }
            if (
                $node->hasField('field_check_homonymie')
                && (bool) $node->get('field_check_homonymie')->value
            ) {
                // Déjà vérifié entre-temps par un autre passage (lot
                // recoupant partiellement ce combat) : on ne le renvoie
                // pas une seconde fois à l'IA.
                continue;
            }
            $currentTarget = $node->get($c['pivot_field'])->target_id;
            if ((int) $currentTarget !== (int) $data['pivot_nid']) {
                // Déjà corrigé entre-temps.
                continue;
            }
            $stillSuspect[] = $c;
        }

        if (empty($stillSuspect)) {
            $this->logger->info('Lot homonymes générationnels ignoré (@pivot) : tous les combats du lot sont déjà corrigés ou supprimés.', [
                '@pivot' => $data['pivot_name'] ?? '?',
            ]);
            return;
        }

        $data['combats'] = $stillSuspect;

        $prompt = $this->buildPrompt($data);
        if (trim($prompt) === '') {
            $this->logger->debug('ConfrontationsHomonymesGenerationnelsAiQueue : prompt_ai_homonymes_generationnels non configuré, lot ignoré.');
            return;
        }

        $this->logger->notice('Prompt homonymes générationnels IA envoyé (@pivot, @n combat(s), trou de @gap an(s)) : @prompt', [
            '@pivot'  => $data['pivot_name'] ?? '?',
            '@n'      => count($data['combats']),
            '@gap'    => $data['gap_annees'] ?? '?',
            '@prompt' => $prompt,
        ]);

        /** @var \Drupal\sl_live_search\Service\LiveSearchService $liveSearch */
        $liveSearch = \Drupal::service('sl_live_search.live_search_service');
        // $data['engine'] / $data['force_engine'] : injectés par
        // ConfrontationsHomonymesGenerationnelsCommands::drainAiQueue()
        // lorsque l'option CLI --enginef a été passée, pour forcer le
        // moteur IA de ce run.
        $response   = !empty($data['engine'])
            ? $liveSearch->liveSearch($prompt, $data['engine'], 'normal', !empty($data['force_engine']))
            : $liveSearch->liveSearch($prompt);

        if (empty($response['success']) || empty($response['content'])) {
            $this->logger->warning('ConfrontationsHomonymesGenerationnelsAiQueue : liveSearch échec/vide (@pivot). Erreur : @err. Traitement de la queue suspendu (item remis en queue). Lot : @lot', [
                '@pivot' => $data['pivot_name'] ?? '?',
                '@err'   => $response['error'] ?? 'inconnu',
                '@lot'   => $this->formatCombatList($data['combats'], $storage),
            ]);

            // Échec au niveau de l'appel IA lui-même (quota, clé, réseau) :
            // on suspend le traitement de CETTE queue pour le passage de
            // cron en cours — l'item reste en queue et sera retraité
            // automatiquement au prochain cron.
            throw new SuspendQueueException(
                'Appel IA (sl_live_search) en échec : ' . ($response['error'] ?? 'raison inconnue')
            );
        }

        $decoded = SomeTools::decodeJson($response['content'], __METHOD__);
        $verdicts = is_array($decoded) && isset($decoded['verdicts']) ? $decoded['verdicts'] : $decoded;

        if (!is_array($verdicts) || empty($verdicts)) {
            $this->logger->warning('ConfrontationsHomonymesGenerationnelsAiQueue : réponse IA invalide/vide (@pivot). Contenu brut : @raw. Lot : @lot', [
                '@pivot' => $data['pivot_name'] ?? '?',
                '@raw'   => $response['content'],
                '@lot'   => $this->formatCombatList($data['combats'], $storage),
            ]);
            return;
        }

        $this->applyVerdicts($data, $verdicts, $storage);
    }

    /**
     * Applique les verdicts IA combat par combat. Garde-fou strict : si la
     * réponse ne couvre pas TOUS les combats fournis, on n'applique RIEN
     * (mieux vaut réessayer au prochain passage qu'appliquer un verdict
     * partiel incohérent).
     */
    protected function applyVerdicts(array $groupData, array $verdicts, $storage): void
    {
        $byTechId = [];
        foreach ($verdicts as $v) {
            if (!empty($v['field_id_text'])) {
                $byTechId[(string) $v['field_id_text']] = $v;
            }
        }

        if (count($byTechId) < count($groupData['combats'])) {
            $this->logger->warning('ConfrontationsHomonymesGenerationnelsAiQueue : réponse IA incomplète (@got/@expected verdicts) - @pivot, AUCUNE modification appliquée. Lot : @lot', [
                '@got'      => count($byTechId),
                '@expected' => count($groupData['combats']),
                '@pivot'    => $groupData['pivot_name'] ?? '?',
                '@lot'      => $this->formatCombatList($groupData['combats'], $storage),
            ]);
            return;
        }

        // Suggestions déjà connues en base (autres que le pivot lui-même),
        // utilisées pour tenter de rattacher la correction IA à un
        // athlète EXISTANT avant d'en créer un nouveau.
        $suggestions = array_merge(
            $groupData['suggestions'] ?? [],
            $groupData['homonymes_connus'] ?? []
        );

        $pivotTechName = $this->technicalWrestlerName($groupData['pivot_name'] ?? '');

        $reattachedSummary = [];
        $untouchedSummary   = [];

        foreach ($groupData['combats'] as $c) {
            $v = $byTechId[$c['field_id_text']] ?? null;
            $correctedName = trim((string) ($v['nom_lutteur_correct'] ?? ''));

            if ($correctedName === '') {
                $untouchedSummary[] = "NID {$c['nid']} (verdict vide)";
                continue;
            }

            // Compare le nom brut proposé (avant split éventuel "/Ecurie")
            // au pivot : si équivalent, le combat appartient bien au
            // pivot, rien à faire — mais il a bien été VÉRIFIÉ par l'IA,
            // donc on le marque pour ne plus jamais le renvoyer dans un
            // futur prompt.
            $correctedNamePart = trim(explode('/', $correctedName)[0]);
            if ($this->technicalWrestlerName($correctedNamePart) === $pivotTechName) {
                $untouchedSummary[] = "NID {$c['nid']} \"{$c['adversaire']}\" ({$c['date']}) - confirmé pivot";
                $this->markCombatChecked($c['nid'], $storage);
                continue;
            }

            $node = $storage->load($c['nid']);
            if (!$node || empty($c['pivot_field']) || !$node->hasField($c['pivot_field'])) {
                continue;
            }

            // 1. Tente d'abord de faire correspondre le nom corrigé à un
            // homonyme DÉJÀ existant en base (suggestions), pour éviter de
            // créer un doublon si l'IA a reformulé légèrement le nom.
            $matchedExisting = null;
            foreach ($suggestions as $suggestionTitle) {
                if ($this->technicalWrestlerName($suggestionTitle) === $this->technicalWrestlerName($correctedNamePart)) {
                    $matchedExisting = $suggestionTitle;
                    break;
                }
            }

            $finalName = $matchedExisting ?? $correctedName;
            $ecurie    = null;

            if (str_contains($finalName, '/')) {
                $parts     = explode('/', $finalName, 2);
                $finalName = trim($parts[0]);
                $ecurie    = trim($parts[1]) !== '' ? trim($parts[1]) : null;
            }

            if ($finalName === '') {
                continue;
            }

            // 2. getOrCreateAthlete() (hérité de BaseCombatQueueWorker) :
            // retrouve l'athlète existant (par nom technique) ou le crée,
            // en gérant l'écurie si fournie.
            $athlete = $this->getOrCreateAthlete($finalName, $ecurie);
            if (!$athlete) {
                $untouchedSummary[] = "NID {$c['nid']} - échec rattachement à \"@$finalName\"";
                continue;
            }

            $node->set($c['pivot_field'], ['target_id' => $athlete->id()]);
            if ($node->hasField('field_check_homonymie')) {
                $node->set('field_check_homonymie', TRUE);
            }
            $node->setNewRevision(FALSE);
            $node->save();

            $reattachedSummary[] = "NID {$c['nid']} \"{$c['adversaire']}\" ({$c['date']}) → \"{$athlete->label()}\" [nid {$athlete->id()}]" . ($matchedExisting ? ' (existant)' : ' (créé)');

            $this->logger->error('livesearch success HOMONYME GÉNÉRATIONNEL (verdict IA) : NID @nid "@title" - combat réattribué de "@pivot" vers "@new" [nid @newnid]. Raison IA : @reason', [
                '@nid'    => $c['nid'],
                '@title'  => $node->getTitle(),
                '@pivot'  => $groupData['pivot_name'] ?? '?',
                '@new'    => $athlete->label(),
                '@newnid' => $athlete->id(),
                '@reason' => $v['commentaire'] ?? '',
            ]);
        }

        $this->logger->notice('BILAN LOT HOMONYMES GÉNÉRATIONNELS (@pivot, trou de @gap an(s) entre @avant et @apres) — Réattribué(s) : @reattached | Laissé(s) tel quel : @untouched', [
            '@pivot'      => $groupData['pivot_name'] ?? '?',
            '@gap'        => $groupData['gap_annees'] ?? '?',
            '@avant'      => $groupData['gap_date_avant'] ?? '?',
            '@apres'      => $groupData['gap_date_apres'] ?? '?',
            '@reattached' => !empty($reattachedSummary) ? implode(' ; ', $reattachedSummary) : '(aucun)',
            '@untouched'  => !empty($untouchedSummary) ? implode(' ; ', $untouchedSummary) : '(aucun)',
        ]);
    }

    /**
     * Construit le prompt à partir du template configurable
     * confrontations_recentes.settings:prompt_ai_homonymes_generationnels.
     * Placeholders : @pivot, @ecurie, @homonymes, @suggestions,
     * @gap_annees, @gap_date_avant, @gap_date_apres, @combats_json.
     */
    protected function buildPrompt(array $data): string
    {
        $promptTemplate = $this->configFactory
            ->get('confrontations_recentes.settings')
            ->get('prompt_ai_homonymes_generationnels') ?? '';

        if (trim($promptTemplate) === '') {
            return '';
        }

        $combatsJson = json_encode($data['combats'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $homonymesJson = json_encode($data['homonymes_connus'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $suggestionsJson = json_encode($data['suggestions'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return strtr($promptTemplate, [
            '@pivot'          => $data['pivot_name'] ?? '',
            '@ecurie'         => !empty($data['ecurie_name']) ? $data['ecurie_name'] : 'inconnue',
            '@homonymes'      => $homonymesJson,
            '@suggestions'    => $suggestionsJson,
            '@gap_annees'     => isset($data['gap_annees']) ? (string) $data['gap_annees'] : '',
            '@gap_date_avant' => $data['gap_date_avant'] ?? '',
            '@gap_date_apres' => $data['gap_date_apres'] ?? '',
            '@combats_json'   => $combatsJson,
        ]);
    }

    /**
     * Marque field_check_homonymie = TRUE sur un combat confirmé comme
     * appartenant bien au pivot (verdict IA = pas de réattribution). Le
     * cas "réattribué" est déjà géré inline dans applyVerdicts() car le
     * node y est de toute façon sauvegardé pour field_athlete_1/2.
     */
    protected function markCombatChecked(int $nid, $storage): void
    {
        $node = $storage->load($nid);
        if (!$node || !$node->hasField('field_check_homonymie')) {
            return;
        }
        $node->set('field_check_homonymie', TRUE);
        $node->setNewRevision(FALSE);
        $node->save();
    }

    /**
     * Formate la liste des combats d'un lot pour les logs.
     */
    protected function formatCombatList(array $combats, $storage): string
    {
        $parts = [];
        foreach ($combats as $c) {
            $node  = $storage->load($c['nid']);
            $title = $node ? $node->getTitle() : '(introuvable)';
            $parts[] = sprintf(
                'NID %d "%s" [%s] (vs %s, %s)',
                $c['nid'],
                $title,
                $c['field_id_text'] ?? '?',
                $c['adversaire'] ?? '?',
                $c['date'] ?? '?'
            );
        }
        return implode(' ; ', $parts);
    }
}
