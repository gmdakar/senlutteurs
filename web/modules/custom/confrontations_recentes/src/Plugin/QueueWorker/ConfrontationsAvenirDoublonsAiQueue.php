<?php

namespace Drupal\confrontations_recentes\Plugin\QueueWorker;

use Drupal\shared_lib\Plugin\QueueWorker\BaseCombatQueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\shared_lib\Tools\SomeTools;
use Drupal\Core\Queue\SuspendQueueException;

/**
 * Résolution IA des doublons a_venir (même date + un protagoniste commun).
 *
 * Règle métier : un lutteur ne peut pas avoir deux combats à venir distincts
 * le même jour. Les groupes sont constitués par
 * ConfrontationDeduplicationService::queueAmbiguousAvenirSameDateCombats().
 * L'IA tranche quelle variante conserver (delete=0/1 par field_id_text).
 * Double validation croisée (moteur principal puis Grok en aveugle) avant
 * toute suppression réelle — même pattern que ConfrontationsDoublonsAiQueue.
 *
 * @QueueWorker(
 *   id = "a_venir_doublons_ai_queue",
 *   title = @Translation("Résolution IA des doublons a_venir (même date)"),
 *   cron = {"time" = 120}
 * )
 */
class ConfrontationsAvenirDoublonsAiQueue extends BaseCombatQueueWorker implements \Drupal\Core\Plugin\ContainerFactoryPluginInterface
{

    protected const AI_CALL_MAX_ATTEMPTS = 2;
    protected const AI_CALL_RETRY_DELAY_SECONDS = 10;

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
     *   Voir queueAmbiguousAvenirSameDateCombats() payload.
     */
    public function processItem($data)
    {
        $techIds = array_map('strval', array_column($data['combats'] ?? [], 'field_id_text'));
        $this->doProcessItem($data);
        SomeTools::clearAvenirDoublonGroupPending($techIds);
    }

    protected function doProcessItem($data)
    {
        if (empty($data['combats']) || count($data['combats']) < 2) {
            return;
        }

        $debugPivotIds = $this->configFactory
            ->get('gemini_nextcombats_cron.settings')
            ->get('prompt_ai_doublons_avenir_debug_pivot_athlete') ?? [];
        if (!is_array($debugPivotIds)) {
            $debugPivotIds = $debugPivotIds ? [(int) $debugPivotIds] : [];
        }
        $debugPivotIds = array_values(array_filter(array_map('intval', $debugPivotIds)));
        if (!empty($debugPivotIds) && !in_array((int) ($data['pivot_nid'] ?? 0), $debugPivotIds, true)) {
            return;
        }

        $storage = $this->entityTypeManager->getStorage('node');

        $stillExisting = 0;
        foreach ($data['combats'] as $c) {
            $node = $storage->load($c['nid']);
            if ($node && $node->isPublished()) {
                $stillExisting++;
            }
        }
        if ($stillExisting < 2) {
            $this->logger->info('Groupe a_venir IA ignoré (plus assez de nœuds existants) - @pivot le @date.', [
                '@pivot' => $data['pivot_name'] ?? '?',
                '@date'  => $data['date'] ?? '?',
            ]);
            return;
        }

        $prompt = $this->buildPrompt($data);
        if (trim($prompt) === '') {
            $this->logger->debug('ConfrontationsAvenirDoublonsAiQueue : prompt_ai_doublons_avenir non configuré, groupe ignoré.');
            return;
        }

        $this->logger->notice('Prompt doublons a_venir IA envoyé (@pivot le @date) : @prompt', [
            '@pivot'  => $data['pivot_name'] ?? '?',
            '@date'   => $data['date'] ?? '?',
            '@prompt' => $prompt,
        ]);

        $response = $this->callLiveSearchWithRetry(
            $prompt,
            $data,
            'principal a_venir (moteur par défaut)',
            $data['engine'] ?? null,
            !empty($data['force_engine'])
        );

        $decoded = SomeTools::decodeJson($response['content'], __METHOD__);

        // Accepte soit un array de verdicts, soit {"verdicts":[...]}
        $verdicts = null;
        if (is_array($decoded)) {
            if (isset($decoded['verdicts']) && is_array($decoded['verdicts'])) {
                $verdicts = $decoded['verdicts'];
            }
            elseif (isset($decoded[0]) && is_array($decoded[0])) {
                $verdicts = $decoded;
            }
        }

        if (!is_array($verdicts) || empty($verdicts)) {
            $this->logger->warning('ConfrontationsAvenirDoublonsAiQueue : réponse IA invalide/vide (@pivot le @date). Contenu brut : @raw. Lot : @lot', [
                '@pivot' => $data['pivot_name'] ?? '?',
                '@date'  => $data['date'] ?? '?',
                '@raw'   => $response['content'],
                '@lot'   => $this->formatCombatList($data['combats'], $storage),
            ]);
            return;
        }

        $this->applyVerdicts($data, $verdicts, $storage);
    }

    protected function callLiveSearchWithRetry(string $prompt, array $groupData, string $context, ?string $engine = null, bool $forceEngine = false): array
    {
        $lastError = 'inconnue';

        for ($attempt = 1; $attempt <= self::AI_CALL_MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                $this->logger->warning('Appel IA a_venir (@context) : tentative @attempt/@max après @delay s (@pivot le @date). Erreur précédente : @err', [
                    '@context' => $context,
                    '@attempt' => $attempt,
                    '@max'     => self::AI_CALL_MAX_ATTEMPTS,
                    '@delay'   => self::AI_CALL_RETRY_DELAY_SECONDS,
                    '@pivot'   => $groupData['pivot_name'] ?? '?',
                    '@date'    => $groupData['date'] ?? '?',
                    '@err'     => $lastError,
                ]);
                sleep(self::AI_CALL_RETRY_DELAY_SECONDS);
            }

            /** @var \Drupal\sl_live_search\Service\LiveSearchService $liveSearch */
            $liveSearch = \Drupal::service('sl_live_search.live_search_service');
            $response = ($engine !== null)
                ? $liveSearch->liveSearch($prompt, $engine, 'normal', $forceEngine)
                : $liveSearch->liveSearch($prompt);

            if (!empty($response['success']) && !empty($response['content'])) {
                return $response;
            }

            $lastError = $response['error'] ?? 'inconnue';
            $this->logger->warning('Appel IA a_venir (@context) échec/vide (tentative @attempt/@max) - @pivot le @date. Erreur : @err', [
                '@context' => $context,
                '@attempt' => $attempt,
                '@max'     => self::AI_CALL_MAX_ATTEMPTS,
                '@pivot'   => $groupData['pivot_name'] ?? '?',
                '@date'    => $groupData['date'] ?? '?',
                '@err'     => $lastError,
            ]);
        }

        $this->logger->error('ConfrontationsAvenirDoublonsAiQueue : échec IA @context (@pivot le @date) après @n tentatives : @err', [
            '@context' => $context,
            '@pivot'   => $groupData['pivot_name'] ?? '?',
            '@date'    => $groupData['date'] ?? '?',
            '@n'       => self::AI_CALL_MAX_ATTEMPTS,
            '@err'     => $lastError,
        ]);
        throw new SuspendQueueException("Échec IA a_venir doublons ($context): $lastError");
    }

    protected function applyVerdicts(array $groupData, array $verdicts, $storage): void
    {
        $byTechId = [];
        foreach ($verdicts as $v) {
            if (!empty($v['field_id_text'])) {
                $byTechId[(string) $v['field_id_text']] = $v;
            }
        }

        if (count($byTechId) < count($groupData['combats'])) {
            $this->logger->warning('ConfrontationsAvenirDoublonsAiQueue : réponse IA incomplète (@got/@expected) - @pivot le @date, AUCUNE suppression. Lot : @lot', [
                '@got'      => count($byTechId),
                '@expected' => count($groupData['combats']),
                '@pivot'    => $groupData['pivot_name'] ?? '?',
                '@date'     => $groupData['date'] ?? '?',
                '@lot'      => $this->formatCombatList($groupData['combats'], $storage),
            ]);
            return;
        }

        $keepCount = 0;
        foreach ($groupData['combats'] as $c) {
            $v = $byTechId[$c['field_id_text']] ?? null;
            if ($v !== null && empty($v['delete'])) {
                $keepCount++;
            }
        }
        if ($keepCount < 1) {
            $this->logger->warning('ConfrontationsAvenirDoublonsAiQueue : IA propose de tout supprimer - @pivot le @date, AUCUNE suppression (garde-fou). Lot : @lot', [
                '@pivot' => $groupData['pivot_name'] ?? '?',
                '@date'  => $groupData['date'] ?? '?',
                '@lot'   => $this->formatCombatList($groupData['combats'], $storage),
            ]);
            return;
        }

        // Double validation Grok si suppressions proposées.
        $hasPendingDeletions = false;
        foreach ($byTechId as $v) {
            if (!empty($v['delete'])) {
                $hasPendingDeletions = true;
                break;
            }
        }

        if ($hasPendingDeletions) {
            $grokByTechId = $this->getGrokVerdicts($groupData);
            foreach ($groupData['combats'] as $c) {
                $v = $byTechId[$c['field_id_text']] ?? null;
                if ($v === null || empty($v['delete'])) {
                    continue;
                }
                $grokVerdict = $grokByTechId[$c['field_id_text']] ?? null;
                $grokAgrees = $grokVerdict !== null && !empty($grokVerdict['delete']);
                if (!$grokAgrees) {
                    $byTechId[$c['field_id_text']]['delete'] = 0;
                    $this->logger->warning('a_venir DÉSACCORD IA (principal=delete, Grok=conserve) - Suppression ANNULÉE : NID @nid [@id] le @date.', [
                        '@nid'  => $c['nid'],
                        '@id'   => $c['field_id_text'],
                        '@date' => $c['date'] ?? '?',
                    ]);
                }
            }
        }

        // Identifier le keeper (premier delete=0) pour transfert face2face.
        $keeperNid = null;
        foreach ($groupData['combats'] as $c) {
            $v = $byTechId[$c['field_id_text']] ?? null;
            if ($v !== null && empty($v['delete'])) {
                $keeperNid = (int) $c['nid'];
                break;
            }
        }

        $keeper = $keeperNid ? $storage->load($keeperNid) : null;
        $deleted = [];
        $kept = [];

        foreach ($groupData['combats'] as $c) {
            $v = $byTechId[$c['field_id_text']] ?? null;
            if ($v === null) {
                continue;
            }
            if (empty($v['delete'])) {
                $kept[] = $c['nid'];
                continue;
            }
            $node = $storage->load($c['nid']);
            if (!$node) {
                continue;
            }

            // Transfert face2face vers le keeper.
            if ($keeper && $node->hasField('field_avenir_face2face') && $keeper->hasField('field_avenir_face2face')) {
                $oldUris = [];
                foreach ($node->get('field_avenir_face2face')->getValue() as $item) {
                    if (!empty($item['uri'])) {
                        $oldUris[] = $item['uri'];
                    }
                }
                if (!empty($oldUris)) {
                    $existing = [];
                    foreach ($keeper->get('field_avenir_face2face')->getValue() as $item) {
                        if (!empty($item['uri'])) {
                            $existing[] = $item['uri'];
                        }
                    }
                    $merged = array_values(array_unique(array_merge($existing, $oldUris)));
                    $keeper->set('field_avenir_face2face', array_map(static fn($uri) => ['uri' => $uri], $merged));
                    $keeper->setNewRevision(false);
                    $keeper->save();
                }
            }

            try {
                $node->delete();
                $deleted[] = $c['nid'];
                $this->logger->notice('a_venir doublon IA SUPPRIMÉ : NID @nid [@id] "@l1 vs @l2" le @date — raison: @raison', [
                    '@nid'    => $c['nid'],
                    '@id'     => $c['field_id_text'],
                    '@l1'     => $c['lutteur1'] ?? '?',
                    '@l2'     => $c['lutteur2'] ?? '?',
                    '@date'   => $c['date'] ?? '?',
                    '@raison' => $v['raison'] ?? ($v['commentaire'] ?? ''),
                ]);
            }
            catch (\Throwable $e) {
                $this->logger->error('a_venir doublon IA : échec suppression NID @nid : @msg', [
                    '@nid' => $c['nid'],
                    '@msg' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->notice('a_venir doublon IA bilan (@pivot le @date) : conservés [@kept] | supprimés [@del]', [
            '@pivot' => $groupData['pivot_name'] ?? '?',
            '@date'  => $groupData['date'] ?? '?',
            '@kept'  => implode(',', $kept) ?: '-',
            '@del'   => implode(',', $deleted) ?: '-',
        ]);
    }

    protected function getGrokVerdicts(array $groupData): array
    {
        $prompt = $this->buildPrompt($groupData);
        if (trim($prompt) === '') {
            return [];
        }
        try {
            $response = $this->callLiveSearchWithRetry(
                $prompt,
                $groupData,
                'confirmation Grok (a_venir doublons)',
                'grok',
                true
            );
            $decoded = SomeTools::decodeJson($response['content'], __METHOD__);
            $verdicts = [];
            if (is_array($decoded)) {
                if (isset($decoded['verdicts']) && is_array($decoded['verdicts'])) {
                    $verdicts = $decoded['verdicts'];
                }
                elseif (isset($decoded[0])) {
                    $verdicts = $decoded;
                }
            }
            $byTechId = [];
            foreach ($verdicts as $v) {
                if (!empty($v['field_id_text'])) {
                    $byTechId[(string) $v['field_id_text']] = $v;
                }
            }
            return $byTechId;
        }
        catch (\Throwable $e) {
            $this->logger->warning('ConfrontationsAvenirDoublonsAiQueue : échec double validation Grok : @msg', [
                '@msg' => $e->getMessage(),
            ]);
            return [];
        }
    }

    protected function buildPrompt(array $data): string
    {
        $promptTemplate = $this->configFactory
            ->get('gemini_nextcombats_cron.settings')
            ->get('prompt_ai_doublons_avenir') ?? '';

        if (trim($promptTemplate) === '') {
            return '';
        }

        return strtr($promptTemplate, [
            '@pivot'                    => $data['pivot_name'] ?? '',
            '@ecurie_pivot'             => !empty($data['ecurie_pivot']) ? $data['ecurie_pivot'] : 'inconnue',
            '@nb_combats_pivot'         => (string) ($data['nb_combats_pivot'] ?? 0),
            '@date'                     => $data['date'] ?? '',
            '@combats_json'             => json_encode($data['combats'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '@nb_combats_adversaires'   => json_encode($data['nb_combats_adversaires'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '@historique_adversaires'   => json_encode($data['historique_adversaires'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '@suggestions_adversaires'  => json_encode($data['suggestions_adversaires'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

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
