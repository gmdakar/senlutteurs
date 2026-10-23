<?php

namespace Drupal\confrontations_recentes\Service;

use Drupal\shared_lib\Service\BaseWrestlingService;
use Drupal\shared_lib\Tools\SomeTools;

/**
 * Service pour la gestion des confrontations récentes.
 */
class CombatService extends BaseWrestlingService
{
    /**
     * Récupère les combats récents via le service injecté dans le parent.
     */
    public function fetchCombats(): array
    {
        try {
            $responseContent = '';

            $prompt = $this->getPrompt();

            $this->logger->notice('Prompt envoyé : @prompt', [
                '@prompt' => $prompt,
            ]);

            $now = new \DateTime();
            $expirationThreshold    = (clone $now)->modify("-4 hours");
            $expirationThresholdISO = $expirationThreshold->format('Y-m-d\TH:i:s');

            $hoursLimit = 168;
            if (preg_match('/(\d+)\s*h/i', $prompt, $matches)) {
                $hoursLimit = (int) $matches[1];
            }

            $startDate    = (clone $now)->modify("-{$hoursLimit} hours");
            $startDateISO = $startDate->format('Y-m-d\TH:i:s');

            if (stripos($prompt, '__ignore__') !== false) {
                $responseContent = $this->getDefaultResponse();
                $responseContent = str_replace('__debug__', '', $responseContent);
            } else {
                $prompt = str_replace('__debug__', '', $prompt);
            }

            if (empty($responseContent)) {
                $response = $this->liveSearchService->liveSearch($prompt);

                $this->logger->debug('<pre> fetchCombats : @data</pre>', ['@data' => print_r($response, TRUE)]);

                if ($response['success'] && !empty($response['content'])) {
                    $responseContent = $response['content'];
                }
            }

            $data = $this->decodeJson($responseContent, __METHOD__);

            if (
                !empty($data) &&
                (
                    stripos(trim($this->getPrompt()), '__debug__') !== false ||
                    (
                        stripos(trim($this->getPrompt()), '__ignore__') !== false &&
                        stripos(trim($this->getDefaultResponse()), '__debug__') !== false
                    )
                )
            ) {
                $this->logger->notice('Reponse brut: @data', ['@data' => print_r($data, TRUE)]);
            }

            if (!is_array($data)) {
                $data = [];
            }

            // ── Normalisation multi-jours ──────────────────────────────────────
            // L'IA peut retourner un tableau de jours [{day1}, {day2}] au lieu
            // de l'objet mono-jour attendu {date_jour, combats_lutte_recents}.
            // Cause : prompt contradictoire (demande { } mais dit "commence par [").
            if (isset($data[0]['combats_lutte_recents'])) {
                $allCombats = [];
                foreach ($data as $dayData) {
                    if (is_array($dayData) && !empty($dayData['combats_lutte_recents'])) {
                        $allCombats = array_merge($allCombats, $dayData['combats_lutte_recents']);
                    }
                }
                $data = [
                    'date_jour'             => $now->format('Y-m-d'),
                    'combats_lutte_recents' => $allCombats,
                ];
            }
            // ── Fin normalisation multi-jours ──────────────────────────────────

            if (!isset($data['combats_lutte_recents'])) {
                $data['combats_lutte_recents'] = [];
            }
            if (!isset($data['date_jour'])) {
                $data['date_jour'] = $now->format('Y-m-d');
            }

            if (stripos(trim($this->getPrompt()), '__ignore__') === false) {

                // NOTE : on NE force plus 'resultat' => '?' ici.
                // Les combats issus de la recherche live ont déjà leur vrai
                // résultat ; seuls les combats injectés depuis "a_venir"
                // ci-dessous ont resultat = '?' (verdict inconnu).

                $storage = \Drupal::entityTypeManager()->getStorage('node');

                $query = $storage->getQuery()
                    ->condition('type', 'a_venir')
                    ->condition('status', 1)
                    ->condition('field_date', $startDateISO, '>=')
                    ->condition('field_date', $expirationThresholdISO, '<')
                    ->accessCheck(FALSE);

                $nids  = $query->execute();
                $nodes = $storage->loadMultiple($nids);

                foreach ($nodes as $node) {
                    $l1 = $node->get('field_lutteur_1')->entity ? $node->get('field_lutteur_1')->entity->label() : '';
                    $l2 = $node->get('field_lutteur_2')->entity ? $node->get('field_lutteur_2')->entity->label() : '';

                    if (empty($l1) || empty($l2)) continue;

                    $fighters = [strtolower(trim($l1)), strtolower(trim($l2))];
                    sort($fighters);
                    $combatSignature = implode(' vs ', $fighters);

                    $isDuplicate = false;
                    foreach ($data['combats_lutte_recents'] as $c) {
                        $c_l1      = strtolower(trim(explode('/', $c['lutteur1'])[0]));
                        $c_l2      = strtolower(trim(explode('/', $c['lutteur2'])[0]));
                        $c_fighters = [$c_l1, $c_l2];
                        sort($c_fighters);
                        if (implode(' vs ', $c_fighters) === $combatSignature) {
                            $isDuplicate = true;
                            break;
                        }
                    }

                    if (!$isDuplicate) {
                        $face2face = [];
                        foreach ($node->get('field_avenir_face2face')->getValue() as $link) {
                            if (!empty($link['uri'])) $face2face[] = $link['uri'];
                        }

                        $data['combats_lutte_recents'][] = [
                            'date_precise' => $node->get('field_date')->value,
                            'source'       => 'Base locale (Ex-A venir)',
                            'lutteur1'     => $l1,
                            'lutteur2'     => $l2,
                            'resultat'     => '?',
                            'face2face'    => $face2face,
                            'video_combat' => [],
                            'id_text'      => $node->get('field_id_text')->value ?? '',
                            'is_lutte_simple' => $node->hasField('field_is_lutte_simple') ? (int) $node->get('field_is_lutte_simple')->value : 0,
                            // Exige une confirmation IA (combat_a_eu_lieu) avant insertion.
                            'from_a_venir' => TRUE,
                        ];
                    }
                }

                $this->logger->notice('Reponse avant verdicts (a_venir injectés avec ?): @data', ['@data' => print_r($data, TRUE)]);

                // getAllResultsVerdicts() ne fait quelque chose que s'il existe
                // au moins un combat avec resultat = '?' (cas des a_venir injectés
                // ci-dessus). Sinon court-circuit immédiat et $data inchangé.
                $responseContentV = $this->getAllResultsVerdicts(json_encode($data));
                if (!empty($responseContentV)) {
                    $newData = $this->decodeJson($responseContentV, __METHOD__ . ' [verdicts]');
                    if (is_array($newData)) {
                        $data = $newData;
                    }
                    // Si $newData est null (échec IA) : $data reste inchangé,
                    // les combats live gardent leurs vrais résultats, et les
                    // a_venir injectés restent à '?' (seront retentés au run suivant).
                }

                // Filtre post-verdicts : n'envoyer en queue que les combats
                // confirmés comme ayant bien eu lieu (voir filterConfirmedPlayedCombats).
                $data = $this->filterConfirmedPlayedCombats($data);
                $this->logger->notice('New Reponse with each verdict confirmed: @data', ['@data' => print_r($data, TRUE)]);
                return $data;
            }

            if (!empty($data['combats_lutte_recents']) && is_array($data['combats_lutte_recents'])) {

                $responseContentV = $this->getAllResultsVerdicts(json_encode($data));
                if (!empty($responseContentV)) {
                    $newData = $this->decodeJson($responseContentV, __METHOD__ . ' [verdicts __ignore__]');
                    if (is_array($newData) && !empty($newData['combats_lutte_recents'])) {
                        $data['combats_lutte_recents'] = $newData['combats_lutte_recents'];
                    }
                }

                $data = $this->filterConfirmedPlayedCombats($data);
                $this->logger->notice('New Reponse with each verdict confirmed: @data', ['@data' => print_r($data, TRUE)]);
                return $data;
            }
        } catch (\Exception $e) {
            $this->logger->error('API error in fetchCombats: @msg', ['@msg' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Récupère le verdict d'un combat via le service injecté.
     */

    /**
     * Filtre les combats après l'étape IA des verdicts.
     *
     * Règle métier : avant d'insérer une confrontation, le combat doit avoir
     * été confirmé comme ayant BIEN EU LIEU.
     *
     * - resultat connu (≠ "?" et non vide) → conservé (combat clairement joué)
     * - resultat "?" + combat_a_eu_lieu === true → conservé (joué, verdict encore flou)
     * - sinon (dont ex-a_venir non confirmés, affiches YouTube non jouées) → exclu
     * - date future par rapport à date_jour → exclu
     *
     * @param array $data Wrapper {date_jour, combats_lutte_recents} ou liste plate.
     *
     * @return array Même structure, combats filtrés (réindexés).
     */
    protected function filterConfirmedPlayedCombats(array $data): array
    {
        $isWrapper = isset($data['combats_lutte_recents']) && is_array($data['combats_lutte_recents']);
        $list = $isWrapper ? $data['combats_lutte_recents'] : $data;

        if (!is_array($list)) {
            return $data;
        }

        $dateLimit = new \DateTime($isWrapper ? ($data['date_jour'] ?? 'now') : 'now');
        $kept = [];
        $dropped = [];

        foreach ($list as $combat) {
            if (!is_array($combat) || empty($combat['date_precise'])) {
                $dropped[] = '(entrée invalide / sans date)';
                continue;
            }

            try {
                $dateCombat = new \DateTime(substr((string) $combat['date_precise'], 0, 10));
                if ($dateCombat > $dateLimit) {
                    $dropped[] = sprintf(
                        '%s vs %s le %s (date future)',
                        $combat['lutteur1'] ?? '?',
                        $combat['lutteur2'] ?? '?',
                        $combat['date_precise']
                    );
                    continue;
                }
            }
            catch (\Exception $e) {
                $dropped[] = sprintf('%s vs %s (date invalide)', $combat['lutteur1'] ?? '?', $combat['lutteur2'] ?? '?');
                continue;
            }

            $res = trim((string) ($combat['resultat'] ?? ''));
            $playedFlag = $combat['combat_a_eu_lieu'] ?? null;
            // Accepte true / 1 / "true" / "1"
            $playedConfirmed = ($playedFlag === true || $playedFlag === 1 || $playedFlag === '1' || $playedFlag === 'true');

            if ($res !== '' && $res !== '?') {
                $kept[] = $combat;
                continue;
            }

            // resultat "?" ou vide : uniquement si l'IA a confirmé que le combat a eu lieu.
            if ($playedConfirmed) {
                // Normalise resultat à '?' si vide pour la suite du pipeline.
                if ($res === '') {
                    $combat['resultat'] = '?';
                }
                $kept[] = $combat;
                continue;
            }

            $dropped[] = sprintf(
                '%s vs %s le %s (resultat=%s, combat_a_eu_lieu non confirmé, source=%s)',
                $combat['lutteur1'] ?? '?',
                $combat['lutteur2'] ?? '?',
                $combat['date_precise'] ?? '?',
                $res === '' ? '(vide)' : $res,
                $combat['source'] ?? '?'
            );
        }

        if (!empty($dropped)) {
            $this->logger->notice(
                'filterConfirmedPlayedCombats : @n exclu(s) — @list',
                ['@n' => count($dropped), '@list' => implode(' | ', $dropped)]
            );
        }

        if ($isWrapper) {
            $data['combats_lutte_recents'] = array_values($kept);
            return $data;
        }
        return array_values($kept);
    }

    public function getResultVerdict(string $dateFight, string $wrestler1, string $wrestler2): string
    {
        try {
            $responseContent = '';

            $prompt = $this->getPromptVerdict($dateFight, $wrestler1, $wrestler2);
            $prompt = str_replace('__debug__', '', $prompt);

            /** @var \Drupal\sl_live_search\Service\LiveSearchService $this->liveSearchService */
            $response = $this->liveSearchService->liveSearch($prompt);

            if ($response['success'] && !empty($response['content'])) {
                $responseContent = $response['content'];
            }

            $data = $this->decodeJson($responseContent, __METHOD__);
            return $data['resultat'] ?? '';
        } catch (\Exception $e) {
            $this->logger->error('API error in getResultVerdict: @msg', ['@msg' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * New specialized request to ask about each result VERDICT.
     */
    public function getAllResultsVerdicts(string $resultBrutJson): string
    {
        try {
            // --- COURT-CIRCUIT : si aucun verdict inconnu, le JSON est déjà complet ---
            $decoded = $this->decodeJson($resultBrutJson, __METHOD__);
            $hasUnknownResult = !empty(array_filter(
                $decoded['combats_lutte_recents'] ?? [],
                fn($c) => ($c['resultat'] ?? '') === '?'
            ));

            if (!$hasUnknownResult) {
                $this->logger->debug('getAllResultsVerdicts NON NECESSAIRE car pas de resultat verdict inconnu');
                return $resultBrutJson;
            }

            $prompt = $this->getPromptAllVerdicts($resultBrutJson);
            $prompt = str_replace('__debug__', '', $prompt);

            /** @var \Drupal\sl_live_search\Service\LiveSearchService $this->liveSearchService */
            $response = $this->liveSearchService->liveSearch($prompt);

            if ($response['success'] && !empty($response['content'])) {

                $rawJson = $response['content'];

                // --- VALIDATION + NETTOYAGE via decodeJson ---
                $decoded = $this->decodeJson($rawJson, __METHOD__);

                // Si null, le log critical a déjà été posé par decodeJson(), on sort proprement
                if ($decoded === null) {
                    return '';
                }

                // --- LOG PRETTY ---
                $prettyJson = json_encode(
                    $decoded,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );

                $this->logger->notice(
                    'getAllResultsVerdicts JSON BRUT RÉPONSE GROK (pretty) : <pre>@json</pre>',
                    ['@json' => $prettyJson]
                );

                // On retourne le JSON re-encodé (propre, sans markdown résiduel)
                return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        } catch (\Exception $e) {
            $this->logger->error('API error in getAllResultsVerdicts: @msg', ['@msg' => $e->getMessage()]);
        }

        return '';
    }

    protected function getPromptVerdict(string $dateFight, string $wrestler1, string $wrestler2): string
    {
        $promptTemplate = $this->configFactory->get('confrontations_recentes.settings')->get('prompt_verdict') ?? '';
        return strtr($promptTemplate, [
            '@date'     => $dateFight,
            '@wrestler1' => $wrestler1,
            '@wrestler2' => $wrestler2,
        ]);
    }

    /**
     * get prompt about the specialized request to ask about each result VERDICT.
     *
     * Le JSON est automatiquement minifié pour réduire le coût tokens.
     */
    protected function getPromptAllVerdicts(string $resultBrutJson): string
    {
        // Minification du JSON (supprime tous les espaces, sauts de ligne et indentations inutiles)
        $jsonMinifie = $this->minifyJson($resultBrutJson);

        $promptTemplate = $this->configFactory->get('confrontations_recentes.settings')->get('prompt_all_verdicts') ?? '';

        return strtr($promptTemplate, [
            '@resultBrutJson' => $jsonMinifie,
        ]);
    }

    /**
     * Minifie un JSON string (version ultra-compacte).
     */
    private function minifyJson(string $json): string
    {
        $decoded = $this->decodeJson($json, __METHOD__);

        if ($decoded === null) {
            // Log critical déjà posé par decodeJson(), on retourne tel quel
            return $json;
        }

        return json_encode(
            $decoded,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * À appeler AVANT la création d'un node "confrontation" (donc avant
     * getOrCreateAthlete), pour :
     *  1. Récupérer les 2 derniers combats connus de chacun des 2 lutteurs.
     *  2. Interroger l'IA (liveSearch) avec ce contexte + une liste de
     *     suggestions de variantes de noms proches déjà en base, afin de
     *     corriger éventuellement le nom générationnel d'un des 2 lutteurs
     *     (ex: "Sa thies 2" → "Sa thies 3") avant insertion, de nettoyer un
     *     nom pollué par du texte parasite, de lever une éventuelle
     *     homonymie (ajout de parenthèse), de compléter l'écurie si elle
     *     était absente en entrée (format "Nom/Ecurie"), et de confirmer
     *     que l'année du combat est cohérente (± 1 an) avec les 2 profils.
     *
     * Les écuries d'entrée ($ecurie1/$ecurie2, si connues depuis la source
     * d'origine) sont transmises à l'IA pour l'aider à lever une éventuelle
     * homonymie. En retour, l'IA peut compléter l'écurie directement dans
     * le nom du lutteur sous la forme "Nom/Ecurie" (même convention que le
     * parsing du texte source à l'étape 3 du QueueWorker) — c'est ensuite
     * l'appelant qui se charge de re-splitter ce format.
     *
     * 'had_suggestions' indique si des suggestions de noms proches
     * existaient en base pour l'un des 2 lutteurs (risque potentiel
     * d'homonymie/fusion de profils) — utilisé par l'appelant pour décider
     * s'il doit bloquer l'insertion en cas d'échec technique de l'IA
     * (fail-closed) plutôt que de risquer un rattachement à un mauvais
     * athlète existant (fail-open).
     *
     * Ne bloque JAMAIS l'insertion en cas d'échec technique (prompt non
     * configuré, IA indisponible, JSON invalide) : dans ce cas les noms
     * d'origine sont conservés tels quels ('ai_valid' => null). C'est à
     * l'appelant de décider, via 'had_suggestions', s'il doit malgré tout
     * bloquer l'insertion par prudence.
     *
     * @return array{
     *   lutteur1: string,
     *   lutteur2: string,
     *   ai_valid: bool|null,
     *   ai_corrected: bool,
     *   ai_commentaire: string,
     *   had_suggestions: bool,
     * }
     */
    public function confirmWrestlersBeforeInsert(
        string $wrestler1,
        string $wrestler2,
        string $datePrecise,
        ?string $ecurie1 = null,
        ?string $ecurie2 = null
    ): array {
        $fallback = [
            'lutteur1'        => $wrestler1,
            'lutteur2'        => $wrestler2,
            'ai_valid'        => null,
            'ai_corrected'    => false,
            'ai_commentaire'  => '',
            'had_suggestions' => false,
        ];

        try {
            $historique1  = SomeTools::getRecentConfrontationsJson($wrestler1, 2);
            $historique2  = SomeTools::getRecentConfrontationsJson($wrestler2, 2);
            $suggestions1 = SomeTools::findSimilarAthleteNames($wrestler1, 6);
            $suggestions2 = SomeTools::findSimilarAthleteNames($wrestler2, 6);

            // Dès que des suggestions existent (même si l'IA échoue plus
            // bas), on retient qu'un risque d'homonymie/fusion de profils
            // était présent pour ce combat.
            $fallback['had_suggestions'] = !empty($suggestions1) || !empty($suggestions2);

            $prompt = $this->getPromptConfirmWrestlers(
                $wrestler1,
                $wrestler2,
                $datePrecise,
                json_encode($historique1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($historique2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($suggestions1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($suggestions2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $ecurie1,
                $ecurie2
            );
            $prompt = str_replace('__debug__', '', $prompt);

            if (trim($prompt) === '') {
                $this->logger->debug('confirmWrestlersBeforeInsert : prompt_confirm_wrestlers non configuré, étape ignorée.');
                return $fallback;
            }

            $this->logger->notice('confirmWrestlersBeforeInsert Prompt envoyé : @prompt', ['@prompt' => $prompt]);

            $response = $this->liveSearchService->liveSearch($prompt);

            if (empty($response['success']) || empty($response['content'])) {
                $this->logger->warning('confirmWrestlersBeforeInsert : liveSearch échec/vide pour @l1 vs @l2. Erreur : @err', [
                    '@l1'  => $wrestler1,
                    '@l2'  => $wrestler2,
                    '@err' => $response['error'] ?? 'inconnu',
                ]);
                return $fallback;
            }

            $data = $this->decodeJson($response['content'], __METHOD__);

            if (!is_array($data) || empty($data['lutteur1']) || empty($data['lutteur2'])) {
                $this->logger->warning('confirmWrestlersBeforeInsert : réponse IA invalide/incomplète pour @l1 vs @l2, noms conservés tels quels. Contenu brut : @raw', [
                    '@l1'  => $wrestler1,
                    '@l2'  => $wrestler2,
                    '@raw' => $response['content'],
                ]);
                return $fallback;
            }

            $correctedL1 = trim((string) $data['lutteur1']);
            $correctedL2 = trim((string) $data['lutteur2']);

            // Garde-fou anti-doublon : si l'historique (déjà interrogé ci-dessus,
            // AVANT la réponse IA) montre que ce lutteur a DÉJÀ un combat
            // enregistré à cette date précise contre cet adversaire, alors ce
            // combat existe déjà sous le nom brut fourni. On ignore la
            // correction IA pour ce côté, quelle que soit sa justification
            // (homonymie, écurie…) : l'historique est une preuve plus fiable
            // qu'une inférence IA, et le contredire créerait un doublon
            // (nouveau nom technique → nouveau fightId → nouveau node).
            if (SomeTools::normalizeForMatch($correctedL1) !== SomeTools::normalizeForMatch($wrestler1)
                && $this->historyAlreadyHasFight($historique1, $datePrecise, $wrestler2)) {
                $this->logger->warning(
                    'confirmWrestlersBeforeInsert : correction IA "@old" → "@new" annulée — l\'historique de "@old" contient déjà ce combat du @date contre "@opp".',
                    ['@old' => $wrestler1, '@new' => $correctedL1, '@date' => $datePrecise, '@opp' => $wrestler2]
                );
                $correctedL1 = $wrestler1;
            }
            if (SomeTools::normalizeForMatch($correctedL2) !== SomeTools::normalizeForMatch($wrestler2)
                && $this->historyAlreadyHasFight($historique2, $datePrecise, $wrestler1)) {
                $this->logger->warning(
                    'confirmWrestlersBeforeInsert : correction IA "@old" → "@new" annulée — l\'historique de "@old" contient déjà ce combat du @date contre "@opp".',
                    ['@old' => $wrestler2, '@new' => $correctedL2, '@date' => $datePrecise, '@opp' => $wrestler1]
                );
                $correctedL2 = $wrestler2;
            }

            $corrected =
                SomeTools::normalizeForMatch($correctedL1) !== SomeTools::normalizeForMatch($wrestler1)
                || SomeTools::normalizeForMatch($correctedL2) !== SomeTools::normalizeForMatch($wrestler2);

            if ($corrected) {
                $this->logger->notice('confirmWrestlersBeforeInsert : correction IA "@old1" vs "@old2" → "@new1" vs "@new2"', [
                    '@old1' => $wrestler1,
                    '@old2' => $wrestler2,
                    '@new1' => $correctedL1,
                    '@new2' => $correctedL2,
                ]);
            }

            return [
                'lutteur1'        => $correctedL1 !== '' ? $correctedL1 : $wrestler1,
                'lutteur2'        => $correctedL2 !== '' ? $correctedL2 : $wrestler2,
                'ai_valid'        => array_key_exists('correspond_aux_profils', $data) ? (bool) $data['correspond_aux_profils'] : true,
                'ai_corrected'    => $corrected,
                'ai_commentaire'  => (string) ($data['commentaire'] ?? ''),
                'had_suggestions' => !empty($suggestions1) || !empty($suggestions2),
            ];
        } catch (\Exception $e) {
            $this->logger->error('confirmWrestlersBeforeInsert erreur pour @l1 vs @l2 : @msg', [
                '@l1'  => $wrestler1,
                '@l2'  => $wrestler2,
                '@msg' => $e->getMessage(),
            ]);
            return $fallback;
        }
    }

    /**
     * Un combat à $datePrecise contre $opponentName figure-t-il déjà dans cet
     * historique (voir getRecentConfrontationsJson, entrées 'date'/'adversaire') ?
     *
     * Comparaison stricte sur la date (format YYYY-MM-DD identique des 2
     * côtés) et floue sur l'adversaire (normalizeForMatch), pour tolérer les
     * petites variations orthographiques déjà réconciliées en base.
     */
    protected function historyAlreadyHasFight(array $history, string $datePrecise, string $opponentName): bool
    {
        $normalizedOpponent = SomeTools::normalizeForMatch($opponentName);
        foreach ($history as $entry) {
            if (($entry['date'] ?? null) !== $datePrecise) {
                continue;
            }
            if (SomeTools::normalizeForMatch((string) ($entry['adversaire'] ?? '')) === $normalizedOpponent) {
                return true;
            }
        }
        return false;
    }

    protected function getPromptConfirmWrestlers(
        string $wrestler1,
        string $wrestler2,
        string $datePrecise,
        string $historique1Json,
        string $historique2Json,
        string $suggestions1Json,
        string $suggestions2Json,
        ?string $ecurie1 = null,
        ?string $ecurie2 = null
    ): string {
        $promptTemplate = $this->configFactory->get('confrontations_recentes.settings')->get('prompt_confirm_wrestlers') ?? '';

        // @date : uniquement l'année du combat — l'IA n'a besoin que de
        // situer l'époque approximative (± 1 an) pour juger de la cohérence
        // générationnelle des 2 profils, pas la date précise.
        try {
            $year = (new \DateTime($datePrecise))->format('Y');
        } catch (\Exception $e) {
            $year = $datePrecise;
        }

        return strtr($promptTemplate, [
            '@wrestler1'    => $wrestler1,
            '@wrestler2'    => $wrestler2,
            '@ecurie1'      => $ecurie1 ?: '(non fournie)',
            '@ecurie2'      => $ecurie2 ?: '(non fournie)',
            '@date'         => $year,
            '@historique1'  => $historique1Json,
            '@historique2'  => $historique2Json,
            '@suggestions1' => $suggestions1Json,
            '@suggestions2' => $suggestions2Json,
        ]);
    }

    public function enqueueCombat(array $combat): void
    {
        $queue = \Drupal::queue('confrontations_recentes_queue');
        $queue->createItem($combat);
    }

    protected function getPrompt(): string
    {
        return $this->configFactory->get('confrontations_recentes.settings')->get('prompt') ?? '';
    }

    protected function getDefaultResponse(): string
    {
        return $this->configFactory->get('confrontations_recentes.settings')->get('default_response') ?? '';
    }

    public function updateVideosConfrontations($N = 10)
    {
        $nids = $this->getLastContentsOf('confrontation', $N);
        $queue = \Drupal::queue('update_videos_confrontations_recentes_queue');

        foreach ($nids as $nid) {
            $queue->createItem($nid);
        }

        return $nids;
    }

    /**
     * Recherche tous les combats < N jours avec field_vainqueur = '?' et
     * interroge l'IA pour tenter de résoudre le verdict. Ne met à jour QUE
     * si le résultat est désormais connu (jamais de retour en arrière vers '?').
     *
     * @param int $days
     *   Fenêtre en nombre de jours pour filtrer les combats récents (défaut : 30).
     * @param string|null $engine_force
     *   Le moteur de recherche IA à forcer (grok, gemini, etc.). Si NULL,
     *   le comportement par défaut du service liveSearch s'applique.
     */
    public function updateUnknownResultsFights(int $days = 30, ?string $engine_force = NULL): array
    {
        $storage = \Drupal::entityTypeManager()->getStorage('node');

        $now       = new \DateTime('now', new \DateTimeZone('UTC'));
        $sinceDate = (clone $now)->modify("-{$days} days");

        $nids = $storage->getQuery()
            ->condition('type', 'confrontation')
            ->condition('field_vainqueur', '?')
            ->condition('created', $sinceDate->getTimestamp(), '>=')
            ->accessCheck(FALSE)
            ->execute();

        if (empty($nids)) {
            $this->logger->info('updateUnknownResultsFights : aucun combat "?" trouvé (< 1 mois).');
            return ['checked' => 0, 'updated' => 0];
        }

        $nodes     = $storage->loadMultiple($nids);
        $fights    = [];
        $nodesById = [];

        foreach ($nodes as $node) {
            $fieldIdText = trim((string) ($node->get('field_id_text')->value ?? ''));
            if ($fieldIdText === '') {
                continue; // pas d'identifiant fiable pour matcher la réponse IA
            }

            $l1 = $node->get('field_athlete_1')->entity;
            $l2 = $node->get('field_athlete_2')->entity;
            if (!$l1 || !$l2) {
                continue;
            }

            $isLutteSimple = 0;
            if ($node->hasField('field_is_lutte_simple') && !$node->get('field_is_lutte_simple')->isEmpty()) {
                $isLutteSimple = (int) $node->get('field_is_lutte_simple')->value;
            }

            $fights[] = [
                'field_id_text'   => $fieldIdText,
                'date_precise'    => $node->get('field_confrontation_date')->value,
                'lutteur1'        => $l1->label(),
                'lutteur2'        => $l2->label(),
                'is_lutte_simple' => $isLutteSimple,
            ];
            $nodesById[$fieldIdText] = $node;
        }

        if (empty($fights)) {
            return ['checked' => 0, 'updated' => 0];
        }

        $prompt = $this->getPromptUpdateUnknownResults(
            json_encode($fights, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $this->logger->notice('updateUnknownResultsFights : @nb combat(s) "?" envoyés à l\'IA.', [
            '@nb' => count($fights),
        ]);

        $response = $this->liveSearchService->liveSearch($prompt, $engine_force ?? 'gemini', 'normal', !empty($engine_force));

        if (empty($response['success']) || empty($response['content'])) {
            $this->logger->warning('updateUnknownResultsFights : liveSearch échec/vide. @err', [
                '@err' => $response['error'] ?? 'inconnu',
            ]);
            return ['checked' => count($fights), 'updated' => 0];
        }

        $results = $this->decodeJson($response['content'], __METHOD__);

        if (!is_array($results)) {
            $this->logger->warning('updateUnknownResultsFights : JSON invalide retourné par l\'IA.');
            return ['checked' => count($fights), 'updated' => 0];
        }

        // Tolère { "combats": [...] } en plus d'un tableau direct.
        if (isset($results['combats']) && is_array($results['combats'])) {
            $results = $results['combats'];
        }

        $updatedCount = 0;
        $dateUpdatedCount = 0;
        $lutteSimpleUpdatedCount = 0;

        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }

            $fieldIdText = trim((string) ($item['field_id_text'] ?? ''));
            if ($fieldIdText === '') {
                continue;
            }

            if (!isset($nodesById[$fieldIdText])) {
                $this->logger->warning('updateUnknownResultsFights : field_id_text "@id" inconnu localement, ignoré.', [
                    '@id' => $fieldIdText,
                ]);
                continue;
            }

            $node = $nodesById[$fieldIdText];
            $l1   = $node->get('field_athlete_1')->entity;
            $l2   = $node->get('field_athlete_2')->entity;
            if (!$l1 || !$l2) {
                continue;
            }

            $labelLutteur1 = (string) $l1->label();
            $labelLutteur2 = (string) $l2->label();
            $changed = FALSE;

            // ── update_date (optionnel, indépendant du verdict) ──────────────
            $dateCorrigee = $this->normalizeUpdateDate($item['update_date'] ?? NULL);
            if ($dateCorrigee !== NULL && $node->hasField('field_confrontation_date')) {
                $ancienne = (string) ($node->get('field_confrontation_date')->value ?? '');
                $ancienneJour = $ancienne !== '' ? substr($ancienne, 0, 10) : '';
                if ($ancienneJour !== $dateCorrigee) {
                    $node->set('field_confrontation_date', $dateCorrigee);
                    $changed = TRUE;
                    $dateUpdatedCount++;
                    $this->logger->notice('updateUnknownResultsFights : Date corrigée NID @nid (@id) @old → @new', [
                        '@nid' => $node->id(),
                        '@id'  => $fieldIdText,
                        '@old' => $ancienneJour !== '' ? $ancienneJour : '-',
                        '@new' => $dateCorrigee,
                    ]);
                }
            }

            // ── is_lutte_simple (optionnel, inversion / correction) ──────────
            if (array_key_exists('is_lutte_simple', $item) && $node->hasField('field_is_lutte_simple')) {
                $newLutteSimple = $this->normalizeIsLutteSimple($item['is_lutte_simple']);
                if ($newLutteSimple !== NULL) {
                    $currentLutteSimple = (int) ($node->get('field_is_lutte_simple')->value ?? 0);
                    if ($currentLutteSimple !== $newLutteSimple) {
                        $node->set('field_is_lutte_simple', $newLutteSimple);
                        $changed = TRUE;
                        $lutteSimpleUpdatedCount++;
                        $this->logger->notice('updateUnknownResultsFights : is_lutte_simple corrigé NID @nid (@id) @old → @new', [
                            '@nid' => $node->id(),
                            '@id'  => $fieldIdText,
                            '@old' => $currentLutteSimple,
                            '@new' => $newLutteSimple,
                        ]);
                    }
                }
            }

            // ── resultat (verdict) ───────────────────────────────────────────
            $verdictRaw = trim((string) ($item['resultat'] ?? ''));
            if ($verdictRaw !== '' && $verdictRaw !== '?') {
                $verdictValue = SomeTools::resolveVerdictValue($verdictRaw, $labelLutteur1, $labelLutteur2);

                if ($verdictValue === null) {
                    $this->logger->notice('updateUnknownResultsFights : verdict "@v" ininterprétable pour @id, ignoré.', [
                        '@v'  => $verdictRaw,
                        '@id' => $fieldIdText,
                    ]);
                }
                elseif ($verdictValue !== '?') {
                    // Re-vérifie que le node n'a pas déjà été résolu (concurrence).
                    $currentValue = $node->get('field_vainqueur')->value;
                    if ($currentValue === '?') {
                        $node->set('field_vainqueur', $verdictValue);
                        $changed = TRUE;
                        $updatedCount++;

                        $this->logger->notice('updateUnknownResultsFights : Résultat mis à jour NID @nid (@id) → @verdict', [
                            '@nid'     => $node->id(),
                            '@id'      => $fieldIdText,
                            '@verdict' => $verdictValue,
                        ]);

                        // King status, comme dans la queue.
                        $winner = $loser = NULL;
                        if ($verdictValue === $labelLutteur1) {
                            $winner = $l1;
                            $loser  = $l2;
                        }
                        elseif ($verdictValue === $labelLutteur2) {
                            $winner = $l2;
                            $loser  = $l1;
                        }
                        if ($winner && $loser) {
                            SomeTools::updateKingStatus($winner, $loser);
                        }
                    }
                }
            }

            if ($changed) {
                $node->setNewRevision(FALSE);
                $node->save();
            }
        }

        $this->logger->notice('updateUnknownResultsFights TERMINÉ : @checked vérifié(s), @updated résultat(s), @dates date(s), @ls is_lutte_simple.', [
            '@checked' => count($fights),
            '@updated' => $updatedCount,
            '@dates'   => $dateUpdatedCount,
            '@ls'      => $lutteSimpleUpdatedCount,
        ]);

        return [
            'checked' => count($fights),
            'updated' => $updatedCount,
            'dates'   => $dateUpdatedCount,
            'lutte_simple' => $lutteSimpleUpdatedCount,
        ];
    }

    /**
     * Normalise update_date → YYYY-MM-DD (année seule → YYYY-01-01).
     */
    protected function normalizeUpdateDate($raw): ?string
    {
        if ($raw === NULL || $raw === FALSE || $raw === '') {
            return NULL;
        }
        if (!is_string($raw) && !is_numeric($raw)) {
            return NULL;
        }
        $v = trim((string) $raw);
        if ($v === '') {
            return NULL;
        }
        if (preg_match('/^(\d{4})$/', $v, $m)) {
            $year = (int) $m[1];
            if ($year < 1900 || $year > 2100) {
                return NULL;
            }
            return sprintf('%04d-01-01', $year);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            $year  = (int) $m[1];
            $month = (int) $m[2];
            $day   = (int) $m[3];
            if ($year < 1900 || $year > 2100 || !checkdate($month, $day, $year)) {
                return NULL;
            }
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        return NULL;
    }

    /**
     * Normalise is_lutte_simple (0/1, true/false, "oui"/"non") → 0|1 ou NULL.
     */
    protected function normalizeIsLutteSimple($raw): ?int
    {
        if ($raw === NULL || $raw === '') {
            return NULL;
        }
        if (is_bool($raw)) {
            return $raw ? 1 : 0;
        }
        if (is_int($raw) || is_float($raw)) {
            return ((int) $raw) ? 1 : 0;
        }
        if (!is_string($raw)) {
            return NULL;
        }
        $v = mb_strtolower(trim($raw));
        if (in_array($v, ['1', 'true', 'oui', 'yes', 'o'], TRUE)) {
            return 1;
        }
        if (in_array($v, ['0', 'false', 'non', 'no', 'n'], TRUE)) {
            return 0;
        }
        return NULL;
    }

    protected function getPromptUpdateUnknownResults(string $fightsJson): string
    {
        $promptTemplate = $this->configFactory->get('confrontations_recentes.settings')->get('prompt_update_unknown_results') ?? '';
        return strtr($promptTemplate, ['@fightsJson' => $fightsJson]);
    }
}
