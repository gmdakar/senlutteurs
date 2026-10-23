<?php

namespace Drupal\confrontations_recentes\Plugin\QueueWorker;

use Drupal\shared_lib\Plugin\QueueWorker\BaseCombatQueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\shared_lib\Tools\SomeTools;
use Drupal\Core\Queue\SuspendQueueException;

/**
 * Résolution IA des doublons de combats cross-athlètes (fenêtre --days,
 * défaut 7 j ; aucun NID pivot commun ; noms proches des deux côtés).
 *
 * Mis en queue par
 * ConfrontationDeduplicationService::queueCrossAthleteNearDateCombats($days).
 *
 * @QueueWorker(
 *   id = "confrontations_cross_athlete_doublons_ai_queue",
 *   title = @Translation("Résolution IA des doublons cross-athlètes"),
 *   cron = {"time" = 300}
 * )
 */
class ConfrontationsCrossAthleteDoublonsAiQueue extends BaseCombatQueueWorker implements \Drupal\Core\Plugin\ContainerFactoryPluginInterface
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
     *   ['date', 'combats' => [
     *     ['nid','field_id_text','lutteur1','lutteur2','lutteur1_nid','lutteur2_nid','date','vainqueur'],
     *     ...
     *   ]]
     */
    public function processItem($data)
    {
        $techIds = array_map('strval', array_column($data['combats'] ?? [], 'field_id_text'));
        // SuspendQueueException se propage : item reste en queue, pending non libéré.
        $this->doProcessItem($data);
        SomeTools::clearDoublonGroupPending($techIds);
    }

    protected function doProcessItem(array $data): void
    {
        if (empty($data['combats']) || count($data['combats']) !== 2) {
            return;
        }

        $prompt = $this->buildPrompt($data);
        if ($prompt === '') {
            $this->logger->debug('ConfrontationsCrossAthleteDoublonsAiQueue : prompt_ai_cross_athlete_doublons non configuré, paire ignorée.');
            return;
        }

        $storage = $this->entityTypeManager->getStorage('node');

        // Rechargement défensif : les 2 combats doivent encore être publiés.
        $still = [];
        foreach ($data['combats'] as $c) {
            $node = $storage->load($c['nid']);
            if ($node && $node->isPublished()) {
                $still[] = $c;
            }
        }
        if (count($still) < 2) {
            return;
        }
        $data['combats'] = $still;

        $engine = $data['engine'] ?? null;
        $forceEngine = !empty($data['force_engine']);

        $response = $this->callLiveSearchWithRetry($prompt, $data, 'cross-athlete principal', $engine, $forceEngine);
        $verdict = $this->parseVerdict($response['content'] ?? '');
        if ($verdict === null) {
            $this->logger->warning('Cross-athlete : JSON IA invalide — paire non traitée (@ids).', [
                '@ids' => implode(' | ', array_column($data['combats'], 'field_id_text')),
            ]);
            return;
        }

        if (empty($verdict['same_fight'])) {
            SomeTools::markDoublonGroupVerifiedKept(array_column($data['combats'], 'field_id_text'));
            $this->logger->info('Cross-athlete : IA same_fight=0 — paire conservée telle quelle (@ids). Raison : @r', [
                '@ids' => implode(' | ', array_column($data['combats'], 'field_id_text')),
                '@r' => $verdict['raison'] ?? '',
            ]);
            return;
        }

        // Double validation Grok si le moteur principal n'est pas déjà Grok.
        $defaultEngine = (string) ($this->configFactory->get('request_ai.settings')->get('default_engine') ?? 'gemini');
        $primaryIsGrok = ($forceEngine && $engine === 'grok') || (!$forceEngine && $defaultEngine === 'grok');
        if (!$primaryIsGrok) {
            $grokResponse = $this->callLiveSearchWithRetry($prompt, $data, 'cross-athlete Grok', 'grok', true);
            $grokVerdict = $this->parseVerdict($grokResponse['content'] ?? '');
            if ($grokVerdict === null || empty($grokVerdict['same_fight'])) {
                $this->logger->warning('Cross-athlete : désaccord ou échec Grok (same_fight) — aucune action. Raison principal : @r', [
                    '@r' => $verdict['raison'] ?? '',
                ]);
                return;
            }
        }

        $this->applyVerdict($data, $verdict);
    }

    /**
     * Applique : dépub combat doublon, fusions (direction = max combats),
     * dépub athlètes orphelins (0 combat published).
     */
    protected function applyVerdict(array $data, array $verdict): void
    {
        $storage = $this->entityTypeManager->getStorage('node');
        $combats = $data['combats'];
        $byTech = [];
        foreach ($combats as $c) {
            $byTech[$c['field_id_text']] = $c;
        }

        $keepId = (string) ($verdict['keep_combat_field_id'] ?? '');
        if ($keepId === '' || !isset($byTech[$keepId])) {
            // Fallback : garder le combat au NID le plus bas.
            usort($combats, static fn($a, $b) => $a['nid'] <=> $b['nid']);
            $keepId = $combats[0]['field_id_text'];
        }

        $athleteNidsInvolved = [];
        foreach ($combats as $c) {
            $athleteNidsInvolved[] = (int) ($c['lutteur1_nid'] ?? 0);
            $athleteNidsInvolved[] = (int) ($c['lutteur2_nid'] ?? 0);
            if ($c['field_id_text'] === $keepId) {
                continue;
            }
            $node = $storage->load($c['nid']);
            if ($node && $node->isPublished()) {
                $node->setUnpublished();
                $node->setNewRevision(FALSE);
                $node->save();
                $storage->resetCache([$c['nid']]);
                $this->logger->info('Cross-athlete SUPPRESSION combat doublon : NID @nid [@id] (@l1 vs @l2). Raison IA : @r', [
                    '@nid' => $c['nid'],
                    '@id' => $c['field_id_text'],
                    '@l1' => $c['lutteur1'] ?? '?',
                    '@l2' => $c['lutteur2'] ?? '?',
                    '@r' => $verdict['raison'] ?? '',
                ]);
            }
        }
        $athleteNidsInvolved = array_values(array_unique(array_filter($athleteNidsInvolved)));

        // Fusions suggérées par l'IA, direction corrigée par nb de combats.
        $fusions = $verdict['fusions'] ?? [];
        if (is_array($fusions)) {
            foreach ($fusions as $fusion) {
                $fromName = trim((string) ($fusion['from'] ?? ''));
                $toName = trim((string) ($fusion['to'] ?? ''));
                if ($fromName === '' || $toName === '' || mb_strtolower($fromName) === mb_strtolower($toName)) {
                    continue;
                }
                $fromNid = $this->resolveAthleteNidByName($fromName, $athleteNidsInvolved);
                $toNid = $this->resolveAthleteNidByName($toName, $athleteNidsInvolved);
                if (!$fromNid || !$toNid || $fromNid === $toNid) {
                    $this->logger->warning('Cross-athlete fusion ignorée (NID introuvable) : "@from" → "@to".', [
                        '@from' => $fromName,
                        '@to' => $toName,
                    ]);
                    continue;
                }

                // Garde-fou : survivant = plus de combats published ; égalité → NID plus ancien.
                $countFrom = $this->countPublishedCombats($fromNid);
                $countTo = $this->countPublishedCombats($toNid);
                $pivotNid = $toNid;
                $twinNid = $fromNid;
                if ($countFrom > $countTo || ($countFrom === $countTo && $fromNid < $toNid)) {
                    $pivotNid = $fromNid;
                    $twinNid = $toNid;
                    $this->logger->notice('Cross-athlete : direction IA inversée (plus de combats / ancienneté) : pivot=@pivot twin=@twin (counts @cf/@ct).', [
                        '@pivot' => $pivotNid,
                        '@twin' => $twinNid,
                        '@cf' => $countFrom,
                        '@ct' => $countTo,
                    ]);
                }

                $this->mergeTwinIntoPivot($pivotNid, $twinNid);
            }
        }

        // Orphelins : athlètes impliqués sans aucun combat published.
        foreach ($athleteNidsInvolved as $aid) {
            if ($this->countPublishedCombats($aid) > 0) {
                continue;
            }
            $athlete = $storage->load($aid);
            if ($athlete && $athlete->isPublished()) {
                $athlete->setUnpublished();
                $athlete->setNewRevision(FALSE);
                $athlete->save();
                $storage->resetCache([$aid]);
                $this->logger->info('Cross-athlete : athlète orphelin dépublié NID @nid "@title" (0 combat published).', [
                    '@nid' => $aid,
                    '@title' => $athlete->getTitle(),
                ]);
            }
        }
    }

    /**
     * Pose field_same_athlete sur le pivot puis appelle AthleteMergeService.
     */
    protected function mergeTwinIntoPivot(int $pivotNid, int $twinNid): void
    {
        if ($pivotNid <= 0 || $twinNid <= 0 || $pivotNid === $twinNid) {
            return;
        }
        $storage = $this->entityTypeManager->getStorage('node');
        $pivot = $storage->load($pivotNid);
        $twin = $storage->load($twinNid);
        if (!$pivot || !$twin || $pivot->bundle() !== 'athlete' || $twin->bundle() !== 'athlete') {
            return;
        }
        if (!$pivot->hasField('field_same_athlete')) {
            $this->logger->error('Cross-athlete : champ field_same_athlete absent — fusion impossible.');
            return;
        }

        $existing = [];
        foreach ($pivot->get('field_same_athlete') as $item) {
            $tid = (int) ($item->target_id ?? 0);
            if ($tid > 0) {
                $existing[$tid] = $tid;
            }
        }
        $existing[$twinNid] = $twinNid;
        $pivot->set('field_same_athlete', array_values($existing));
        $pivot->setNewRevision(FALSE);
        $pivot->save();
        $storage->resetCache([$pivotNid]);

        try {
            /** @var \Drupal\shared_lib\Service\AthleteMergeService $merge */
            $merge = \Drupal::service('shared_lib.athlete_merge');
            $result = $merge->mergeFromPivot($pivotNid);
            $this->logger->notice('Cross-athlete fusion exécutée : twin @twin → pivot @pivot. Résultat : @res', [
                '@twin' => $twinNid,
                '@pivot' => $pivotNid,
                '@res' => $result ? json_encode($result) : 'NULL',
            ]);
        }
        catch (\Throwable $e) {
            $this->logger->error('Cross-athlete fusion échouée (@twin → @pivot) : @msg', [
                '@twin' => $twinNid,
                '@pivot' => $pivotNid,
                '@msg' => $e->getMessage(),
            ]);
        }
    }

    protected function resolveAthleteNidByName(string $name, array $candidateNids): ?int
    {
        $storage = $this->entityTypeManager->getStorage('node');
        $nameLower = mb_strtolower(trim($name));
        foreach ($candidateNids as $nid) {
            $node = $storage->load($nid);
            if ($node && mb_strtolower(trim($node->getTitle())) === $nameLower) {
                return (int) $nid;
            }
        }
        // Fallback : recherche globale exacte sur le titre.
        $ids = $storage->getQuery()
            ->condition('type', 'athlete')
            ->condition('title', $name)
            ->accessCheck(FALSE)
            ->range(0, 1)
            ->execute();
        return $ids ? (int) reset($ids) : null;
    }

    protected function countPublishedCombats(int $athleteId): int
    {
        if ($athleteId <= 0) {
            return 0;
        }
        $storage = $this->entityTypeManager->getStorage('node');
        return (int) $storage->getQuery()
            ->condition('type', 'confrontation')
            ->condition('status', 1)
            ->condition(
                $storage->getQuery()->orConditionGroup()
                    ->condition('field_athlete_1', $athleteId)
                    ->condition('field_athlete_2', $athleteId)
            )
            ->accessCheck(FALSE)
            ->count()
            ->execute();
    }

    protected function parseVerdict(string $content): ?array
    {
        $json = SomeTools::extractPureJson($content);
        if ($json === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !array_key_exists('same_fight', $data)) {
            return null;
        }
        $data['same_fight'] = (int) !empty($data['same_fight']);
        return $data;
    }

    protected function buildPrompt(array $data): string
    {
        $template = $this->configFactory
            ->get('confrontations_recentes.settings')
            ->get('prompt_ai_cross_athlete_doublons') ?? '';
        if (trim($template) === '') {
            return '';
        }
        $combatsJson = json_encode($data['combats'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return strtr($template, [
            '@combats_json' => $combatsJson,
            '@date' => $data['date'] ?? '',
        ]);
    }

    protected function callLiveSearchWithRetry(string $prompt, array $groupData, string $context, ?string $engine = null, bool $forceEngine = false): array
    {
        $lastError = 'inconnue';
        for ($attempt = 1; $attempt <= self::AI_CALL_MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
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
            $this->logger->warning('Appel IA (@context) échec (tentative @attempt/@max) : @err', [
                '@context' => $context,
                '@attempt' => $attempt,
                '@max' => self::AI_CALL_MAX_ATTEMPTS,
                '@err' => $lastError,
            ]);
        }
        throw new SuspendQueueException(
            'Appel IA (' . $context . ') en échec après ' . self::AI_CALL_MAX_ATTEMPTS . ' tentative(s) : ' . $lastError
        );
    }

}
