<?php

namespace Drupal\confrontations_recentes\Plugin\QueueWorker;

use Drupal\shared_lib\Plugin\QueueWorker\BaseCombatQueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\shared_lib\Tools\SomeTools;
use Drupal\Core\Queue\SuspendQueueException;

/**
 * Traite un par un les lots de combats suspectés d'être de la lutte simple
 * (non marqués field_is_lutte_simple) pour des lutteurs dont au moins 50%
 * des combats connus le sont déjà, mis en queue par
 * ConfrontationDeduplicationService::queueSuspectedLutteSimpleCombats().
 *
 * Pour chaque lot (même lutteur pivot, 3 combats maximum), on :
 *  1. Revérifie en base que les combats du lot ne sont toujours pas marqués
 *     field_is_lutte_simple (un autre traitement a pu les marquer entre la
 *     mise en queue et le passage de cet item) — les combats déjà marqués
 *     sont retirés du lot avant l'appel IA, sans bloquer le reste.
 *  2. Interroge l'IA via le service centralisé sl_live_search (module
 *     request_ai) avec un prompt configurable, en lui donnant le nom du
 *     lutteur, son écurie (si connue) et la liste des combats restants.
 *  3. Applique le verdict IA combat par combat (garde-fous stricts : aucune
 *     mise à jour n'est appliquée si la réponse est incomplète ou invalide).
 *  4. Règle de propagation "même jour" : si au moins 2 combats d'un même
 *     lutteur, à la même date (nouveaux + déjà existants en base), sont
 *     (ou deviennent) marqués lutte simple, tous les autres combats de ce
 *     même lutteur à cette même date et pas encore marqués sont
 *     automatiquement marqués lutte simple à leur tour (au-delà du lot
 *     traité, en re-questionnant la base).
 *
 * @QueueWorker(
 *   id = "confrontations_lutte_simple_ai_queue",
 *   title = @Translation("Résolution IA des combats suspectés de lutte simple"),
 *   cron = {"time" = 300}
 * )
 */
class ConfrontationsLutteSimpleAiQueue extends BaseCombatQueueWorker implements \Drupal\Core\Plugin\ContainerFactoryPluginInterface
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
     *   ['pivot_nid', 'pivot_name', 'ecurie_name', 'ratio', 'combats' => [
     *      ['nid', 'field_id_text', 'lutteur1', 'lutteur2', 'date', 'vainqueur'], ...
     *   ]]
     */
    public function processItem($data)
    {
        if (empty($data['combats'])) {
            return;
        }

        $storage = $this->entityTypeManager->getStorage('node');

        // Pré-vérification : ne garder que les combats encore existants,
        // toujours pas marqués field_is_lutte_simple, ET pas déjà cochés
        // field_check_is_lutte_simple (déjà tranché "non" par l'IA
        // précédemment). Un autre traitement a pu changer ça entre la mise
        // en queue et le passage de cet item : inutile de re-solliciter
        // l'IA dans ce cas.
        $stillSuspect = [];
        foreach ($data['combats'] as $c) {
            $node = $storage->load($c['nid']);
            if (!$node) {
                continue;
            }
            if ($node->hasField('field_is_lutte_simple') && (bool) $node->get('field_is_lutte_simple')->value) {
                // Déjà marqué entre-temps : on l'ignore silencieusement.
                continue;
            }
            if ($node->hasField('field_check_is_lutte_simple') && (bool) $node->get('field_check_is_lutte_simple')->value) {
                // Déjà tranché "non" par l'IA entre-temps : on l'ignore.
                continue;
            }
            $stillSuspect[] = $c;
        }

        if (empty($stillSuspect)) {
            $this->logger->info('Lot lutte simple ignoré (@pivot) : tous les combats du lot sont déjà marqués ou supprimés.', [
                '@pivot' => $data['pivot_name'] ?? '?',
            ]);
            return;
        }

        $data['combats'] = $stillSuspect;

        $prompt = $this->buildPrompt($data);
        if (trim($prompt) === '') {
            $this->logger->debug('ConfrontationsLutteSimpleAiQueue : prompt_ai_lutte_simple non configuré, lot ignoré.');
            return;
        }

        $this->logger->notice('Prompt lutte simple IA envoyé (@pivot, @n combat(s)) : @prompt', [
            '@pivot'  => $data['pivot_name'] ?? '?',
            '@n'      => count($data['combats']),
            '@prompt' => $prompt,
        ]);

        /** @var \Drupal\sl_live_search\Service\LiveSearchService $liveSearch */
        $liveSearch = \Drupal::service('sl_live_search.live_search_service');
        // $data['engine'] / $data['force_engine'] : injectés par
        // ConfrontationsLutteSimpleCommands::drainAiQueue() lorsque
        // l'option CLI --enginef a été passée, pour forcer le moteur IA
        // de ce run.
        $response   = !empty($data['engine'])
            ? $liveSearch->liveSearch($prompt, $data['engine'], 'normal', !empty($data['force_engine']))
            : $liveSearch->liveSearch($prompt);

        if (empty($response['success']) || empty($response['content'])) {
            $this->logger->warning('ConfrontationsLutteSimpleAiQueue : liveSearch échec/vide (@pivot). Erreur : @err. Traitement de la queue suspendu (item remis en queue). Lot : @lot', [
                '@pivot' => $data['pivot_name'] ?? '?',
                '@err'   => $response['error'] ?? 'inconnu',
                '@lot'   => $this->formatCombatList($data['combats'], $storage),
            ]);

            // Échec au niveau de l'appel IA lui-même (pas un problème propre
            // à ce lot de combats) : le plus souvent clé API absente/quota
            // épuisé/panne réseau. On suspend le traitement de CETTE queue
            // pour le passage de cron en cours — l'item reste en queue et
            // sera retraité automatiquement au prochain cron, une fois le
            // problème résolu (nouvelle clé API disponible, etc.).
            throw new SuspendQueueException(
                'Appel IA (sl_live_search) en échec : ' . ($response['error'] ?? 'raison inconnue')
            );
        }

        $verdicts = SomeTools::decodeJson($response['content'], __METHOD__);

        if (!is_array($verdicts) || empty($verdicts)) {
            $this->logger->warning('ConfrontationsLutteSimpleAiQueue : réponse IA invalide/vide (@pivot). Contenu brut : @raw. Lot : @lot', [
                '@pivot' => $data['pivot_name'] ?? '?',
                '@raw'   => $response['content'],
                '@lot'   => $this->formatCombatList($data['combats'], $storage),
            ]);
            return;
        }

        $this->applyVerdicts($data, $verdicts, $storage);
    }

    /**
     * Applique les verdicts IA avec garde-fou strict : on n'applique RIEN si
     * la réponse est incohérente (moins de verdicts que de combats fournis,
     * ou field_id_text inconnu).
     */
    protected function applyVerdicts(array $groupData, array $verdicts, $storage): void
    {
        // Indexation des verdicts par field_id_text (seul identifiant fiable
        // fourni à l'IA).
        $byTechId = [];
        foreach ($verdicts as $v) {
            if (!empty($v['field_id_text'])) {
                $byTechId[(string) $v['field_id_text']] = $v;
            }
        }

        // Garde-fou : il faut un verdict pour CHAQUE combat encore soumis.
        if (count($byTechId) < count($groupData['combats'])) {
            $this->logger->warning('ConfrontationsLutteSimpleAiQueue : réponse IA incomplète (@got/@expected verdicts) - @pivot, AUCUNE modification appliquée. Lot : @lot', [
                '@got'      => count($byTechId),
                '@expected' => count($groupData['combats']),
                '@pivot'    => $groupData['pivot_name'] ?? '?',
                '@lot'      => $this->formatCombatList($groupData['combats'], $storage),
            ]);
            return;
        }

        $markedSummary   = [];
        $untouchedSummary = [];
        $touchedDates    = [];

        foreach ($groupData['combats'] as $c) {
            $v = $byTechId[$c['field_id_text']] ?? null;

            // Seule une valeur explicitement positive (1, "1", true) compte
            // comme "oui, lutte simple". TOUT le reste - 0, absent, null,
            // ou une valeur inattendue (ex. texte "unknown"/"je ne sais
            // pas" renvoyé par erreur au lieu d'un 0/1) - est traité comme
            // "pas de oui explicite" : pour nous, "non" et "je ne sais pas"
            // reçoivent exactement le même traitement.
            $isLutteSimple = $v !== null
                && isset($v['is_lutte_simple'])
                && in_array($v['is_lutte_simple'], [1, '1', true], true);

            if (!$isLutteSimple) {
                $untouchedSummary[] = "NID {$c['nid']} \"{$c['lutteur1']} vs {$c['lutteur2']}\"";

                // Pas de "oui" explicite (verdict "non" ou incertain, pour
                // nous c'est équivalent) : on coche field_check_is_lutte_simple
                // pour ne plus jamais remettre ce combat en queue IA. Ça ne
                // touche pas field_is_lutte_simple, et n'empêche pas la
                // propagation "même jour" de le corriger plus tard si le
                // contexte le justifie (elle ne regarde pas ce champ).
                $checkNode = $storage->load($c['nid']);
                if (
                    $checkNode && $checkNode->hasField('field_check_is_lutte_simple')
                    && !(bool) $checkNode->get('field_check_is_lutte_simple')->value
                ) {
                    $checkNode->set('field_check_is_lutte_simple', TRUE);
                    $checkNode->setNewRevision(FALSE);
                    $checkNode->save();

                    $this->logger->info('VÉRIFIÉ - PAS DE "OUI" IA (non ou incertain) : NID @nid "@title" [@id] - ne sera plus reproposé à l\'IA (propagation "même jour" toujours possible). Raison IA : @reason', [
                        '@nid'    => $c['nid'],
                        '@title'  => $checkNode->getTitle(),
                        '@id'     => $c['field_id_text'],
                        '@reason' => $v['raison'] ?? ($v['commentaire'] ?? ''),
                    ]);
                }

                continue;
            }

            $node = $storage->load($c['nid']);
            if (!$node || !$node->hasField('field_is_lutte_simple')) {
                continue;
            }

            if (!(bool) $node->get('field_is_lutte_simple')->value) {
                $node->set('field_is_lutte_simple', TRUE);
                $node->setNewRevision(FALSE);
                $node->save();

                $this->logger->error('livesearch success LUTTE SIMPLE (verdict IA) : NID @nid "@title" [@id] - @l1 vs @l2 le @date. Raison IA : @reason', [
                    '@nid'    => $c['nid'],
                    '@title'  => $node->getTitle(),
                    '@id'     => $c['field_id_text'],
                    '@l1'     => $c['lutteur1'] ?? '?',
                    '@l2'     => $c['lutteur2'] ?? '?',
                    '@date'   => $c['date'] ?? '?',
                    '@reason' => $v['raison'] ?? ($v['commentaire'] ?? ''),
                ]);

                $markedSummary[] = "NID {$c['nid']} \"{$node->getTitle()}\"";
            }

            if (!empty($c['date'])) {
                $touchedDates[$c['date']] = TRUE;
            }
        }

        $this->logger->notice('BILAN LOT LUTTE SIMPLE (@pivot) — Marqué(s) lutte simple : @marked | Laissé(s) tel quel : @untouched', [
            '@pivot'     => $groupData['pivot_name'] ?? '?',
            '@marked'    => !empty($markedSummary) ? implode(' ; ', $markedSummary) : '(aucun)',
            '@untouched' => !empty($untouchedSummary) ? implode(' ; ', $untouchedSummary) : '(aucun)',
        ]);

        // Propagation "même jour" : pour chaque date touchée par ce lot, on
        // re-questionne la base (au-delà du lot courant, qui ne contient
        // que 3 combats maximum) pour voir si le pivot a, à cette date, au
        // moins 2 combats désormais marqués lutte simple. Si oui, les
        // autres combats du pivot à cette même date encore non marqués sont
        // eux aussi automatiquement marqués.
        if (!empty($touchedDates) && !empty($groupData['pivot_nid'])) {
            $this->propagateSameDayLutteSimple((int) $groupData['pivot_nid'], array_keys($touchedDates), $storage);
        }
    }

    /**
     * Pour un lutteur pivot et une liste de dates données, vérifie si au
     * moins 2 de ses combats à chaque date sont marqués field_is_lutte_simple
     * = TRUE ; si oui, marque automatiquement les autres combats du pivot à
     * cette même date qui ne le sont pas encore.
     */
    protected function propagateSameDayLutteSimple(int $pivotId, array $dates, $storage): void
    {
        foreach ($dates as $date) {
            if (empty($date)) {
                continue;
            }

            $fight_ids = $storage->getQuery()
                ->condition('type', 'confrontation')
                ->condition('field_confrontation_date', $date)
                ->condition(
                    $storage->getQuery()->orConditionGroup()
                        ->condition('field_athlete_1', $pivotId)
                        ->condition('field_athlete_2', $pivotId)
                )
                ->accessCheck(false)
                ->execute();

            if (count($fight_ids) < 2) {
                continue;
            }

            $fights = $storage->loadMultiple($fight_ids);

            $lutteSimpleCount = 0;
            $notYetMarked = [];
            foreach ($fights as $fight) {
                if (!$fight->hasField('field_is_lutte_simple')) {
                    continue;
                }
                if ((bool) $fight->get('field_is_lutte_simple')->value) {
                    $lutteSimpleCount++;
                } else {
                    $notYetMarked[] = $fight;
                }
            }

            if ($lutteSimpleCount < 2 || empty($notYetMarked)) {
                continue;
            }

            foreach ($notYetMarked as $fight) {
                $fight->set('field_is_lutte_simple', TRUE);
                $fight->setNewRevision(FALSE);
                $fight->save();

                $this->logger->info('LUTTE SIMPLE (propagation même jour) : NID @nid "@title" le @date - au moins 2 combats du même lutteur déjà en lutte simple ce jour-là.', [
                    '@nid'   => $fight->id(),
                    '@title' => $fight->getTitle(),
                    '@date'  => $date,
                ]);
            }
        }
    }

    /**
     * Construit le prompt à partir du template configurable
     * confrontations_recentes.settings:prompt_ai_lutte_simple.
     * Placeholders : @pivot, @ecurie, @ratio, @combats_json.
     */
    protected function buildPrompt(array $data): string
    {
        $promptTemplate = $this->configFactory
            ->get('confrontations_recentes.settings')
            ->get('prompt_ai_lutte_simple') ?? '';

        if (trim($promptTemplate) === '') {
            return '';
        }

        $combatsJson = json_encode($data['combats'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return strtr($promptTemplate, [
            '@pivot'        => $data['pivot_name'] ?? '',
            '@ecurie'       => !empty($data['ecurie_name']) ? $data['ecurie_name'] : 'inconnue',
            '@ratio'        => isset($data['ratio']) ? (string) $data['ratio'] : '',
            '@combats_json' => $combatsJson,
        ]);
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
                'NID %d "%s" [%s] (%s vs %s, %s)',
                $c['nid'],
                $title,
                $c['field_id_text'] ?? '?',
                $c['lutteur1'] ?? '?',
                $c['lutteur2'] ?? '?',
                $c['date'] ?? '?'
            );
        }
        return implode(' ; ', $parts);
    }
}
