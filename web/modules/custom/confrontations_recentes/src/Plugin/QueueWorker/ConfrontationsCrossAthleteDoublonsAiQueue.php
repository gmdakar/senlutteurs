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

        $pairLabel = $this->formatPairLabel($data['combats']);
        $this->logger->info('Cross-athlete TRAITEMENT paire : @pair', ['@pair' => $pairLabel]);

        $response = $this->callLiveSearchWithRetry($prompt, $data, 'cross-athlete principal', $engine, $forceEngine);
        $verdict = $this->parseVerdict($response['content'] ?? '');
        if ($verdict === null) {
            $this->logger->warning('Cross-athlete : JSON IA invalide — AUCUNE ACTION. Paire : @pair | Réponse brute (200c) : @raw', [
                '@pair' => $pairLabel,
                '@raw' => mb_substr((string) ($response['content'] ?? ''), 0, 200),
            ]);
            return;
        }

        $this->logger->info('Cross-athlete VERDICT IA : same_fight=@sf keep_combat=@keep keep_names=@names fusions=@fus | raison=@r | paire=@pair', [
            '@sf' => (int) !empty($verdict['same_fight']),
            '@keep' => (string) ($verdict['keep_combat_field_id'] ?? ''),
            '@names' => json_encode($verdict['keep_names'] ?? [], JSON_UNESCAPED_UNICODE),
            '@fus' => json_encode($verdict['fusions'] ?? [], JSON_UNESCAPED_UNICODE),
            '@r' => (string) ($verdict['raison'] ?? ''),
            '@pair' => $pairLabel,
        ]);

        $techIds = array_column($data['combats'], 'field_id_text');

        if (empty($verdict['same_fight'])) {
            // Décision terminale : pas le même combat → ne plus jamais re-proposer.
            SomeTools::markDoublonGroupVerifiedKept($techIds);
            $this->logger->info('Cross-athlete ACTION=AUCUNE (same_fight=0) — les 2 combats restent published. Paire ignorée à l\'avenir. Paire : @pair | Raison IA : @r', [
                '@pair' => $pairLabel,
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
                // Désaccord Grok = arbitrage terminé sans action → ignorer à l\'avenir
                // (sinon la même paire est re-queue à chaque run).
                SomeTools::markDoublonGroupVerifiedKept($techIds);
                $this->logger->warning('Cross-athlete ACTION=AUCUNE (désaccord/échec Grok) — les 2 combats restent published. Paire ignorée à l\'avenir. Paire : @pair | Raison principal : @r | Grok same_fight=@gsf raison=@gr', [
                    '@pair' => $pairLabel,
                    '@r' => $verdict['raison'] ?? '',
                    '@gsf' => $grokVerdict === null ? 'null' : (string) (int) !empty($grokVerdict['same_fight']),
                    '@gr' => $grokVerdict['raison'] ?? '',
                ]);
                return;
            }
            $this->logger->info('Cross-athlete double validation Grok OK (same_fight=1). Paire : @pair', [
                '@pair' => $pairLabel,
            ]);
            // Fusions : intersection Gemini ∩ Grok (seule l'IA décide, double accord requis).
            $verdict['fusions'] = $this->intersectFusions(
                $verdict['fusions'] ?? [],
                $grokVerdict['fusions'] ?? [],
                $pairLabel
            );
            // keep_combat : si Grok propose le même field_id, on le garde ; sinon on laisse le principal.
            $grokKeep = (string) ($grokVerdict['keep_combat_field_id'] ?? '');
            $primaryKeep = (string) ($verdict['keep_combat_field_id'] ?? '');
            if ($grokKeep !== '' && $primaryKeep !== '' && $grokKeep !== $primaryKeep) {
                $this->logger->notice('Cross-athlete keep_combat désaccord Gemini="@g" Grok="@k" — on conserve le choix principal. Paire : @pair', [
                    '@g' => $primaryKeep,
                    '@k' => $grokKeep,
                    '@pair' => $pairLabel,
                ]);
            }
        }

        $this->applyVerdict($data, $verdict, $pairLabel);
        // Après application (dépub / fusions) : mémoriser pour ne plus re-proposer
        // le même couple field_id_text (même si un combat reste published).
        SomeTools::markDoublonGroupVerifiedKept($techIds);
    }

    /**
     * Résumé lisible des 2 combats d'une paire pour les logs (inclut le title).
     */
    protected function formatPairLabel(array $combats): string
    {
        $storage = $this->entityTypeManager->getStorage('node');
        $parts = [];
        foreach ($combats as $c) {
            $nid = (int) ($c['nid'] ?? 0);
            $title = $c['title'] ?? '';
            if ($title === '' && $nid > 0) {
                $node = $storage->load($nid);
                $title = $node ? $node->getTitle() : '';
            }
            $parts[] = sprintf(
                'NID=%s title="%s" [%s] %s(nid=%s) vs %s(nid=%s) date=%s vainqueur=%s',
                $c['nid'] ?? '?',
                $title !== '' ? $title : '?',
                $c['field_id_text'] ?? '?',
                $c['lutteur1'] ?? '?',
                $c['lutteur1_nid'] ?? '?',
                $c['lutteur2'] ?? '?',
                $c['lutteur2_nid'] ?? '?',
                $c['date'] ?? '?',
                $c['vainqueur'] ?? '?'
            );
        }
        return implode(' || ', $parts);
    }

    /**
     * Applique : dépub combat doublon, fusions (direction = max combats),
     * dépub athlètes orphelins (0 combat published).
     *
     * @param string $pairLabel Résumé des 2 combats (pour logs).
     */
    protected function applyVerdict(array $data, array $verdict, string $pairLabel = ''): void
    {
        $storage = $this->entityTypeManager->getStorage('node');
        $combats = $data['combats'];
        $byTech = [];
        foreach ($combats as $c) {
            $byTech[$c['field_id_text']] = $c;
        }

        $keepId = (string) ($verdict['keep_combat_field_id'] ?? '');
        $keepFallback = FALSE;
        if ($keepId === '' || !isset($byTech[$keepId])) {
            // Fallback : garder le combat au NID le plus bas.
            usort($combats, static fn($a, $b) => $a['nid'] <=> $b['nid']);
            $keepId = $combats[0]['field_id_text'];
            $keepFallback = TRUE;
        }

        $kept = $byTech[$keepId] ?? NULL;
        $keptTitle = '?';
        if (!empty($kept['nid'])) {
            $keptNode = $storage->load($kept['nid']);
            $keptTitle = $keptNode ? $keptNode->getTitle() : '?';
        }
        $this->logger->info('Cross-athlete ACTION=CONSERVER combat : NID=@nid title="@title" [@id] @l1 vs @l2 date=@date vainqueur=@v@fallback | paire=@pair', [
            '@nid' => $kept['nid'] ?? '?',
            '@title' => $keptTitle,
            '@id' => $keepId,
            '@l1' => $kept['lutteur1'] ?? '?',
            '@l2' => $kept['lutteur2'] ?? '?',
            '@date' => $kept['date'] ?? '?',
            '@v' => $kept['vainqueur'] ?? '?',
            '@fallback' => $keepFallback ? ' (fallback NID min, keep_combat_field_id IA invalide)' : '',
            '@pair' => $pairLabel,
        ]);

        $actions = [];
        $athleteNidsInvolved = [];
        foreach ($combats as $c) {
            $athleteNidsInvolved[] = (int) ($c['lutteur1_nid'] ?? 0);
            $athleteNidsInvolved[] = (int) ($c['lutteur2_nid'] ?? 0);
            if ($c['field_id_text'] === $keepId) {
                continue;
            }
            $node = $storage->load($c['nid']);
            if ($node && $node->isPublished()) {
                $combatTitle = $node->getTitle();
                $node->setUnpublished();
                $node->setNewRevision(FALSE);
                $node->save();
                $storage->resetCache([$c['nid']]);
                $actions[] = sprintf(
                    'DEPUB_COMBAT nid=%s title="%s" [%s] %s vs %s date=%s',
                    $c['nid'],
                    $combatTitle,
                    $c['field_id_text'],
                    $c['lutteur1'] ?? '?',
                    $c['lutteur2'] ?? '?',
                    $c['date'] ?? '?'
                );
                $this->logger->info('Cross-athlete ACTION=DEPUB_COMBAT doublon : NID=@nid title="@title" [@id] @l1 vs @l2 date=@date vainqueur=@v | Raison IA : @r | paire=@pair', [
                    '@nid' => $c['nid'],
                    '@title' => $combatTitle,
                    '@id' => $c['field_id_text'],
                    '@l1' => $c['lutteur1'] ?? '?',
                    '@l2' => $c['lutteur2'] ?? '?',
                    '@date' => $c['date'] ?? '?',
                    '@v' => $c['vainqueur'] ?? '?',
                    '@r' => $verdict['raison'] ?? '',
                    '@pair' => $pairLabel,
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
                    $this->logger->warning('Cross-athlete ACTION=FUSION_IGNORE (NID introuvable) : "@from" → "@to" | paire=@pair', [
                        '@from' => $fromName,
                        '@to' => $toName,
                        '@pair' => $pairLabel,
                    ]);
                    continue;
                }

                // Garde-fou : survivant = plus de combats published ; égalité → NID plus ancien.
                $countFrom = $this->countPublishedCombats($fromNid);
                $countTo = $this->countPublishedCombats($toNid);
                $pivotNid = $toNid;
                $twinNid = $fromNid;
                $inverted = FALSE;
                if ($countFrom > $countTo || ($countFrom === $countTo && $fromNid < $toNid)) {
                    $pivotNid = $fromNid;
                    $twinNid = $toNid;
                    $inverted = TRUE;
                }

                $pivotTitle = $storage->load($pivotNid)?->getTitle() ?? '?';
                $twinTitle = $storage->load($twinNid)?->getTitle() ?? '?';
                if ($inverted) {
                    $this->logger->notice('Cross-athlete direction IA inversée : IA "@from"→"@to" devient twin=@twin("@tt") → pivot=@pivot("@pt") (combats published @cf/@ct). Paire : @pair', [
                        '@from' => $fromName,
                        '@to' => $toName,
                        '@pivot' => $pivotNid,
                        '@pt' => $pivotTitle,
                        '@twin' => $twinNid,
                        '@tt' => $twinTitle,
                        '@cf' => $countFrom,
                        '@ct' => $countTo,
                        '@pair' => $pairLabel,
                    ]);
                }

                $this->mergeTwinIntoPivot($pivotNid, $twinNid);
                $actions[] = sprintf(
                    'FUSION twin=%s("%s") → pivot=%s("%s")%s',
                    $twinNid,
                    $twinTitle,
                    $pivotNid,
                    $pivotTitle,
                    $inverted ? ' [direction inversée]' : ''
                );
                $this->logger->info('Cross-athlete ACTION=FUSION : twin NID=@twin "@tt" → pivot NID=@pivot "@pt" (counts published before: from=@cf to=@ct). Paire : @pair', [
                    '@twin' => $twinNid,
                    '@tt' => $twinTitle,
                    '@pivot' => $pivotNid,
                    '@pt' => $pivotTitle,
                    '@cf' => $countFrom,
                    '@ct' => $countTo,
                    '@pair' => $pairLabel,
                ]);
            }
        }

        // Orphelins : athlètes impliqués sans aucun combat published.
        foreach ($athleteNidsInvolved as $aid) {
            if ($this->countPublishedCombats($aid) > 0) {
                continue;
            }
            $athlete = $storage->load($aid);
            if ($athlete && $athlete->isPublished()) {
                $title = $athlete->getTitle();
                $athlete->setUnpublished();
                $athlete->setNewRevision(FALSE);
                $athlete->save();
                $storage->resetCache([$aid]);
                $actions[] = sprintf('DEPUB_ORPHELIN nid=%s "%s"', $aid, $title);
                $this->logger->info('Cross-athlete ACTION=DEPUB_ORPHELIN : NID=@nid "@title" (0 combat published après traitement). Paire : @pair', [
                    '@nid' => $aid,
                    '@title' => $title,
                    '@pair' => $pairLabel,
                ]);
            }
        }

        $this->logger->notice('Cross-athlete BILAN paire : @pair | Actions : @actions | Raison IA : @r', [
            '@pair' => $pairLabel,
            '@actions' => $actions ? implode(' ; ', $actions) : 'aucune (combat déjà dépublié ?)',
            '@r' => $verdict['raison'] ?? '',
        ]);
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


    /**
     * Intersection des fusions proposées par deux moteurs IA.
     * Une fusion n'est retenue que si les deux listes contiennent la même
     * paire (from/to), dans un sens ou l'autre, après normalisation.
     *
     * @param array $primary
     * @param array $secondary
     * @param string $pairLabel
     *
     * @return array
     */
    protected function intersectFusions(array $primary, array $secondary, string $pairLabel = ''): array
    {
        $norm = static function (string $s): string {
            $s = mb_strtolower(trim($s));
            $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
            return $s;
        };
        $keyOf = static function (string $a, string $b) use ($norm): string {
            $x = $norm($a);
            $y = $norm($b);
            // Ordre canonique pour accepter A→B et B→A comme le même accord.
            return ($x <= $y) ? ($x . '|' . $y) : ($y . '|' . $x);
        };

        $secKeys = [];
        foreach ($secondary as $f) {
            if (!is_array($f)) {
                continue;
            }
            $from = trim((string) ($f['from'] ?? ''));
            $to = trim((string) ($f['to'] ?? ''));
            if ($from === '' || $to === '') {
                continue;
            }
            $secKeys[$keyOf($from, $to)] = TRUE;
        }

        $kept = [];
        $dropped = [];
        foreach ($primary as $f) {
            if (!is_array($f)) {
                continue;
            }
            $from = trim((string) ($f['from'] ?? ''));
            $to = trim((string) ($f['to'] ?? ''));
            if ($from === '' || $to === '' || mb_strtolower($from) === mb_strtolower($to)) {
                continue;
            }
            $k = $keyOf($from, $to);
            if (!empty($secKeys[$k])) {
                $kept[] = ['from' => $from, 'to' => $to];
            }
            else {
                $dropped[] = $from . ' → ' . $to;
            }
        }

        if ($dropped) {
            $this->logger->notice('Cross-athlete fusions écartées (désaccord Grok) : @list | paire=@pair', [
                '@list' => implode(' ; ', $dropped),
                '@pair' => $pairLabel,
            ]);
        }
        if ($kept) {
            $this->logger->info('Cross-athlete fusions retenues (accord Gemini+Grok) : @list | paire=@pair', [
                '@list' => implode(' ; ', array_map(static fn($f) => $f['from'] . ' → ' . $f['to'], $kept)),
                '@pair' => $pairLabel,
            ]);
        }
        else {
            $this->logger->info('Cross-athlete aucune fusion retenue après double validation. Paire : @pair', [
                '@pair' => $pairLabel,
            ]);
        }

        return $kept;
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
