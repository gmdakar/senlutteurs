<?php

namespace Drupal\shared_lib\Service;

/**
 * Recherche et filtrage de vidéos YouTube pour les combats de lutte.
 *
 * Regroupe la construction des requêtes, l'appel à l'API YouTube Data v3
 * (avec rotation de clés), et le scoring/filtrage des résultats.
 *
 * Issu du découpage de l'ancienne classe statique
 * \Drupal\shared_lib\Tools\SomeTools (voir SomeTools.php, conservée comme
 * façade de compatibilité — voir shared_lib.services.yml).
 */
class YoutubeSearchService {

  protected TextNormalizationHelper $textNormalizationHelper;

  public function __construct(TextNormalizationHelper $textNormalizationHelper) {
    $this->textNormalizationHelper = $textNormalizationHelper;
  }

    private function youtubeBaseName(string $name): string
    {
        $name = trim($name);

        $name = preg_replace('/\s+(junior|jr|n2|ii|\d+)$/i', '', $name);
        $name = preg_replace('/(?<=[a-z])2$/i', '', $name);

        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /**
     * @param int $maxVideoCombatAgeDays
     *   Si > 0 : les video_combat de plus de N jours sont exclues
     *   (API publishedAfter + filtre publishedAt). Utilisé par drush cri (= 5).
     */
    public function internalYoutubeRequest(
        string $date,
        string $wrestler1,
        string $wrestler2,
        bool $latest,
        int $maxVideoCombatAgeDays = 0
    ): array {
        $all_combats = [];
        $all_f2f = [];
        $logger = \Drupal::logger("shared_lib");

        // Récupérer l'ID de la clé choisi dans votre formulaire request_ai.settings
        $config = \Drupal::config('request_ai.settings');
        $key_id = $config->get('api_key_ytb');

        $keyRepository = \Drupal::service('key.repository');
        $keyEntity = $keyRepository->getKey($key_id); // On utilise l'ID stocké en config

        if (!$keyEntity) {
            $logger->error("La clé API '@id' est introuvable.", ['@id' => $key_id]);
            return ["face2face" => [], "video_combat" => []];
        }

        $rawKeys = $keyEntity->getKeyValue();
        $apiKeys = array_filter(
            array_map("trim", explode("|", $rawKeys ?? ""))
        );

        if (empty($apiKeys)) {
            $logger->error("La clé API '@id' a une valeur vide.", ['@id' => $key_id]);
            return ["face2face" => [], "video_combat" => []];
        }

        $syn1 = (array) $this->getSynonyms($wrestler1);
        $syn2 = (array) $this->getSynonyms($wrestler2);
        $year = strtotime($date) ? date("Y", strtotime($date)) : "";

        // Mode latest sans plafond explicite → 5 jours par défaut pour video_combat.
        if ($latest && $maxVideoCombatAgeDays <= 0) {
            $maxVideoCombatAgeDays = 5;
        }

        $queries = $this->buildYoutubeQuery($syn1, $syn2, $year);
        $cpt = 0;
        foreach ($apiKeys as $key) {
            $keyIsFunctional = false;
            $cpt++;
            foreach ($queries as $q) {
                $params = [
                    "key" => $key,
                    "q" => $q,
                    "part" => "snippet",
                    "type" => "video",
                    "maxResults" => 50,
                    'regionCode' => 'SN', // pour filtrer par Sénégal
                ];

                // Fenêtre temporelle : mode latest explicite OU combat de l'année en cours
                // → order=date pour remonter les vrais combats récents avant les analyses/touss.
                $currentYear = (int) date('Y');
                $fightYearInt = is_numeric($year) ? (int) $year : 0;
                $ageDays = $maxVideoCombatAgeDays;
                if ($ageDays <= 0 && ($fightYearInt === $currentYear || $fightYearInt === 0)) {
                    // Combat récent / année courante : 10 jours max
                    $ageDays = 10;
                }
                if ($ageDays > 0) {
                    $params['publishedAfter'] = gmdate(
                        'Y-m-d\TH:i:s\Z',
                        time() - ($ageDays * 24 * 60 * 60)
                    );
                    $params['order'] = 'date';
                }

                $url =
                    "https://www.googleapis.com/youtube/v3/search?" .
                    http_build_query($params);

                // Log cliquable
                $logger->warning(
                    'YOUTUBE REQUÊTE : <a href=":url" target="_blank">:label</a> | year=' .
                        $year .
                        " | latest=" .
                        ($latest ? "true" : "false"),
                    [
                        ":url" => $url,
                        ":label" => urldecode($url),
                    ]
                );

                $res = @file_get_contents(
                    $url,
                    false,
                    stream_context_create([
                        "http" => ["ignore_errors" => true],
                    ])
                );

                $headerLine = $http_response_header[0] ?? '';
                $isHttpQuota = (
                    str_contains($headerLine, '403') ||
                    str_contains($headerLine, '429')
                );

                $data = $res ? json_decode($res, true) : null;
                $errorCode = is_array($data) ? ($data['error']['code'] ?? null) : null;
                $errorReason = is_array($data)
                    ? ($data['error']['errors'][0]['reason'] ?? ($data['error']['status'] ?? null))
                    : null;
                $errorMessage = is_array($data) ? ($data['error']['message'] ?? '') : '';

                // Quota / rate limit YouTube (403 ou 429)
                if (
                    $isHttpQuota ||
                    $errorCode === 403 ||
                    $errorCode === 429 ||
                    in_array($errorReason, ['quotaExceeded', 'rateLimitExceeded', 'RESOURCE_EXHAUSTED'], true)
                ) {
                    $addLastMsg = count($apiKeys) == $cpt ? '_LAST' : '';
                    $logger->info(
                        'YOUTUBE QUOTA / RATE LIMIT@last (HTTP @http | code=@code | reason=@reason | token=@token) : @msg',
                        [
                            '@last' => $addLastMsg,
                            '@http' => trim($headerLine),
                            '@code' => (string) ($errorCode ?? ''),
                            '@reason' => (string) ($errorReason ?? ''),
                            '@token' => substr((string) $key, 0, 12) . '...',
                            '@msg' => mb_substr((string) $errorMessage, 0, 300),
                        ]
                    );
                    $keyIsFunctional = false;

                    if (!empty($addLastMsg)) {
                        // Plus d'autres clés YouTube valides → timestamp de blocage
                        \Drupal::state()->set(
                            'last_expired_youtube_key',
                            \Drupal::time()->getRequestTime()
                        );
                    }

                    break;
                }

                $keyIsFunctional = true;

                if (!empty($data['items'])) {
                    $filtered = $this->filterResultsRaw(
                        $data['items'],
                        $syn1,
                        $syn2,
                        (string) $year,
                        $date,
                        $maxVideoCombatAgeDays
                    );
                    $all_combats = array_merge(
                        $all_combats,
                        $filtered['video_combat']
                    );
                    $all_f2f = array_merge(
                        $all_f2f,
                        $filtered['face2face']
                    );
                }
            }

            if ($keyIsFunctional) {
                break;
            }
        }

        $final = ["face2face" => $all_f2f, "video_combat" => $all_combats];
        $this->sortAndSlice($final);

        return $final;
    }

    /**
     * Détecte si un titre de vidéo YouTube contient déjà une notion de date
     * (date complète jour+mois+année, format numérique, ou simple année isolée).
     */
    private function titleHasDateInfo(string $title): bool
    {
        // 1. Jour + mois en toutes lettres (français) + année
        //    Ex: "28 mars 2026", "2 novembre 2024", "12 juillet 2025"
        $months = 'janvier|février|fevrier|mars|avril|mai|juin|juillet|août|aout'
            . '|septembre|octobre|novembre|décembre|decembre';
        if (preg_match('/\b\d{1,2}\s+(?:' . $months . ')\s+\d{4}\b/i', $title)) {
            return true;
        }

        // 2. Format numérique jj/mm/aaaa, jj-mm-aaaa, jj.mm.aaaa, jj mm aaaa
        if (preg_match('/\b\d{1,2}[\/\-.\s]\d{1,2}[\/\-.\s]\d{2,4}\b/', $title)) {
            return true;
        }

        // 3. Format aaaa-mm-jj (ISO)
        if (preg_match('/\b(19|20)\d{2}-\d{2}-\d{2}\b/', $title)) {
            return true;
        }

        // 4. Année isolée (1900-2099) — suffisant comme "notion de date" au sens de la demande
        if (preg_match('/\b(19|20)\d{2}\b/', $title)) {
            return true;
        }

        return false;
    }

    /**
     * Récupère les titres YouTube spécifiques à un combat entre 2 lutteurs.
     * Utilisé pour deviner le vainqueur à partir du titre des vidéos.
     *
     * @throws \RuntimeException si AUCUN appel API n'a abouti (quota/clé/réseau)
     *   — à distinguer d'un résultat légitimement vide.
     */
    public function fetchYoutubeTitlesForFight(string $wrestler1, string $wrestler2, string $date = ''): array
    {
        $logger = \Drupal::logger('shared_lib');

        try {
            $config    = \Drupal::config('request_ai.settings');
            $key_id    = $config->get('api_key_ytb');
            $keyEntity = \Drupal::service('key.repository')->getKey($key_id);

            if (!$keyEntity) {
                $logger->warning('fetchYoutubeTitlesForFight : clé YouTube introuvable');
                throw new \RuntimeException('youtube_key_missing');
            }

            $apiKeys = array_values(array_filter(array_map('trim', explode('|', $keyEntity->getKeyValue() ?? ''))));
            if (empty($apiKeys)) {
                throw new \RuntimeException('youtube_no_api_keys');
            }

            $syn1 = $this->getSynonyms($wrestler1);
            $syn2 = $this->getSynonyms($wrestler2);
            $year = ($date && strtotime($date)) ? date('Y', strtotime($date)) : '';

            $queries = $this->buildYoutubeQuery($syn1, $syn2, $year);

            if (empty($queries)) {
                $queries = [
                    '"' . $wrestler1 . '" "' . $wrestler2 . '"',
                    '"' . $wrestler2 . '" "' . $wrestler1 . '"',
                ];
            }

            $titles = [];
            $seen   = [];
            $total  = count($apiKeys);

            // ── Tracking : au moins un appel API a-t-il réellement abouti (sans erreur) ? ──
            $hadSuccessfulCall = false;

            foreach (array_slice($queries, 0, 6) as $q) {
                for ($cpt = 0; $cpt < $total; $cpt++) {
                    $key = $apiKeys[$cpt];
                    $params = [
                        'key'        => $key,
                        'q'          => $q,
                        'part'       => 'snippet',
                        'type'       => 'video',
                        'maxResults' => 50,
                        'regionCode' => 'SN',
                    ];
                    $url = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query($params);
                    $res = @file_get_contents($url, false, stream_context_create(['http' => ['ignore_errors' => true]]));
                    if (!$res) continue; // échec réseau sur cette clé → clé suivante

                    $data = json_decode($res, true);
                    $errorCode = $data['error']['code'] ?? null;
                    $errorReason = $data['error']['errors'][0]['reason'] ?? ($data['error']['status'] ?? null);
                    $errorMessage = $data['error']['message'] ?? '';
                    if ($errorCode || $errorReason) {
                        $logger->info(
                            'YOUTUBE QUOTA / RATE LIMIT (fetchYoutubeTitlesForFight | code=@code | reason=@reason | token=@token) : @msg',
                            [
                                '@code' => (string) ($errorCode ?? ''),
                                '@reason' => (string) ($errorReason ?? ''),
                                '@token' => substr((string) $key, 0, 12) . '...',
                                '@msg' => mb_substr((string) $errorMessage, 0, 300),
                            ]
                        );
                        continue;
                    }

                    // ── Cet appel a abouti (même si 0 item) : c'est une vraie réponse ──
                    $hadSuccessfulCall = true;

                    foreach ($data['items'] ?? [] as $item) {
                        $title = $item['snippet']['title'] ?? '';
                        if ($title === '') continue;
                        $normalized = mb_strtolower(trim($title));
                        if (!isset($seen[$normalized])) {
                            $seen[$normalized] = true;
                            $titles[] = $title;
                        }
                    }
                    break; // clé fonctionnelle → requête suivante
                }
                if (count($titles) >= 20) break;
            }

            // ── Aucun appel n'a abouti sur TOUTES les clés/requêtes → échec technique, pas un vrai zéro ──
            if (!$hadSuccessfulCall) {
                $logger->info('fetchYoutubeTitlesForFight : AUCUN appel YouTube n\'a abouti (quota/clés épuisées ou réseau) pour @l1 vs @l2', [
                    '@l1' => $wrestler1,
                    '@l2' => $wrestler2,
                ]);
                throw new \RuntimeException('youtube_api_all_keys_failed');
            }

            $logger->info('fetchYoutubeTitlesForFight : @count titre(s) trouvé(s) pour @l1 vs @l2', [
                '@count' => count($titles),
                '@l1'    => $wrestler1,
                '@l2'    => $wrestler2,
            ]);

            return array_slice($titles, 0, 20);
        } catch (\RuntimeException $e) {
            // On relance : c'est un signal volontaire, pas une vraie exception "imprévue".
            throw $e;
        } catch (\Exception $e) {
            $logger->error('fetchYoutubeTitlesForFight exception : @msg', ['@msg' => $e->getMessage()]);
            throw $e; // idem : on ne masque plus l'échec en retournant []
        }
    }

    /**
     * Recherche sur YouTube les titres de vidéos associées à un lutteur donné.
     * Version optimisée, appel unique.
     */
    public function fetchYoutubeTitlesForWrestler(string $wrestlerName): string
    {
        $logger = \Drupal::logger('shared_lib');

        try {
            $config    = \Drupal::config('request_ai.settings');
            $key_id    = $config->get('api_key_ytb');
            $keyEntity = \Drupal::service('key.repository')->getKey($key_id);

            if (!$keyEntity) {
                $logger->warning('fetchYoutubeTitlesForWrestler : clé YouTube introuvable');
                return '[]';
            }

            $apiKeys = array_filter(array_map('trim', explode('|', $keyEntity->getKeyValue() ?? '')));
            if (empty($apiKeys)) return '[]';

            $apiKeys = array_values($apiKeys);
            $total   = count($apiKeys);

            // ── Préparation des noms ──────────────────────────────────────────────────
            $allSynonyms   = $this->getSynonyms($wrestlerName);
            $extraSynonyms = array_values(array_filter(
                $allSynonyms,
                fn($s) => mb_strtolower(trim($s)) !== mb_strtolower(trim($wrestlerName))
            ));
            $namesToSearch = array_merge([$wrestlerName], array_slice($extraSynonyms, 0, 2));

            $allTitles = [];
            $seen      = [];

            foreach ($namesToSearch as $currentName) {
                // On échappe les guillemets internes éventuels puis on quote le nom entier
                $quotedName = '"' . str_replace('"', '', trim($currentName)) . '"';

                $queries = [
                    $quotedName . ' "vs"',
                    '"vs" ' . $quotedName,
                    $quotedName . ' victoire',
                    'victoire ' . $quotedName,
                    $quotedName . ' k.o',
                    'k.o ' . $quotedName,
                    $quotedName . ' ko',
                    'ko ' . $quotedName,
                    $quotedName . ' bat',
                    'bat ' . $quotedName,
                    $quotedName . ' battu',
                    'battu ' . $quotedName,
                    $quotedName . ' terrasse',
                    'terrasse ' . $quotedName,
                    $quotedName . ' combat',
                    $quotedName,
                ];

                foreach ($queries as $query) {
                    $params = [
                        'q'          => $query,
                        'part'       => 'snippet',
                        'type'       => 'video',
                        'maxResults' => 10,
                    ];

                    // Rotation des clés API
                    for ($cpt = 0; $cpt < $total; $cpt++) {
                        $key = $apiKeys[$cpt];
                        $url = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query(array_merge($params, ['key' => $key]));

                        $res = @file_get_contents($url, false, stream_context_create(['http' => ['ignore_errors' => true]]));
                        if (!$res) continue;

                        $data = json_decode($res, true);

                        // Gestion Quota / rate limit (403 ou 429)
                        $errorCode   = $data['error']['code'] ?? null;
                        $errorReason = $data['error']['errors'][0]['reason'] ?? ($data['error']['status'] ?? null);
                        $errorMessage = $data['error']['message'] ?? '';
                        if (
                            $errorCode === 403 ||
                            $errorCode === 429 ||
                            in_array($errorReason, ['quotaExceeded', 'rateLimitExceeded', 'RESOURCE_EXHAUSTED'], true)
                        ) {
                            $logger->info(
                                'YOUTUBE QUOTA / RATE LIMIT (code=@code | reason=@reason | token=@token) : @msg',
                                [
                                    '@code' => (string) ($errorCode ?? ''),
                                    '@reason' => (string) ($errorReason ?? ''),
                                    '@token' => substr((string) $key, 0, 12) . '...',
                                    '@msg' => mb_substr((string) $errorMessage, 0, 300),
                                ]
                            );
                            if ($cpt === $total - 1) {
                                \Drupal::state()->set('last_expired_youtube_key', \Drupal::time()->getRequestTime());
                            }
                            continue;
                        }

                        $items = $data['items'] ?? [];
                        if (empty($items)) continue;

                        // Traitement des résultats
                        /* foreach ($items as $item) {
                            $title = $item['snippet']['title'] ?? '';
                            if ($title === '') continue;

                            $normalized = mb_strtolower(trim($title));

                            // Vérification robuste si le nom est présent dans le titre
                            $titleContainsName = false;
                            foreach ($namesToSearch as $n) {
                                if (mb_stripos($normalized, mb_strtolower($n)) !== false) {
                                    $titleContainsName = true;
                                    break;
                                }
                            }

                            if ($titleContainsName && !isset($seen[$normalized])) {
                                $seen[$normalized] = true;
                                $allTitles[] = $title;
                            }
                        }
*/
                        // Traitement des résultats
                        foreach ($items as $item) {
                            $title = $item['snippet']['title'] ?? '';
                            if ($title === '') continue;

                            $normalized = mb_strtolower(trim($title));

                            // Vérification robuste si le nom est présent dans le titre
                            $titleContainsName = false;
                            foreach ($namesToSearch as $n) {
                                if (mb_stripos($normalized, mb_strtolower($n)) !== false) {
                                    $titleContainsName = true;
                                    break;
                                }
                            }

                            if ($titleContainsName && !isset($seen[$normalized])) {
                                $seen[$normalized] = true;

                                // ── Si le titre ne contient AUCUNE notion de date, on ajoute
                                // la date de publication de la vidéo comme repère temporel.
                                $finalTitle = $title;
                                if (!$this->titleHasDateInfo($title)) {
                                    $pubDate = $item['snippet']['publishedAt'] ?? '';
                                    if ($pubDate !== '') {
                                        $pubDateFormatted = substr($pubDate, 0, 10); // "YYYY-MM-DD"
                                        $finalTitle = $title . ' (date du combat: ' . $pubDateFormatted . ')';
                                    }
                                }

                                $allTitles[] = $finalTitle;
                            }
                        }

                        // On passe à la requête suivante sans bloquer le reste
                        break;
                    }
                }
            }

            return json_encode($allTitles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Exception $e) {
            $logger->error('fetchYoutubeTitlesForWrestler exception : @msg', ['@msg' => $e->getMessage()]);
            return '[]';
        }
    }

    /**
     * Vérifie si le quota YouTube est toujours expiré ou disponible.
     * Youtube api retinitialise à 07:00 heure sn tous les jours
     */
    public function isYoutubeQuotaAvailable()
    {
        $last_expiry_timestamp = \Drupal::state()->get(
            "last_expired_youtube_key"
        );

        // Si on n'a jamais enregistré d'expiration, c'est que c'est libre
        if (!$last_expiry_timestamp) {
            return true;
        }

        // On calcule le timestamp du prochain 07:00 du matin après l'expiration
        // Si l'expiration a eu lieu à 04:00, on veut le 07:00 du même jour.
        // Si l'expiration a eu lieu à 10:00, on veut le 07:00 du lendemain.
        $reset_time = strtotime("today 07:00:00");

        // Si l'heure actuelle a dépassé 07:00 ce matin...
        if (time() >= $reset_time) {
            // ...et que la dernière erreur était AVANT ce matin 07:00, c'est bon !
            if ($last_expiry_timestamp < $reset_time) {
                return true;
            }
        }

        \Drupal::logger("shared_lib")->warning(
            "YOUTUBE Quota est toujours expiré donc recherche indisponible"
        );

        return false;
    }

    private function namesAreInTitle(
        string $titleNorm,
        array $norms
    ): bool {
        $logger = \Drupal::logger("shared_lib");

        foreach ($norms as $name) {
            $name = trim($name);
            if ($name === "") {
                continue;
            }

            $logger->debug('namesAreInTitle - Teste : "{name}"', [
                "name" => $name,
            ]);

            // 1. Match direct (stripos) - le plus fiable
            if (stripos($titleNorm, $name) !== false) {
                /*$logger->debug('=== MATCH DIRECT pour "{name}" ===', [
                    "name" => $name,
                ]);*/
                return true;
            }

            // 2. Tolérance Levenshtein (pour madione/mandione)
            $nameWords = explode(" ", strtolower($name));
            $titleWords = explode(" ", $titleNorm);

            foreach ($titleWords as $tw) {
                foreach ($nameWords as $nw) {
                    if (strlen($nw) >= 3 && levenshtein($tw, $nw) <= 1) {
                        /*$logger->debug(
                            '=== MATCH TOLÉRANT (levenshtein) pour "{name}" ===',
                            ["name" => $name]
                        );*/
                        return true;
                    }
                }
            }

            // 3. NOUVEAU : Match très permissif pour les noms avec "2" (Less 2, Double Less 2, etc.)
            if (preg_match("/\d/", $name)) {
                $baseName = preg_replace("/\s*2\s*/", "", $name); // "less" ou "double less"
                $noSpace = preg_replace("/\s+/", "", $name); // "less2"
                $withSpace = preg_replace('/(\d)$/', ' $1', $name); // "less 2"

                if (
                    stripos($titleNorm, $baseName) !== false ||
                    stripos($titleNorm, $noSpace) !== false ||
                    stripos($titleNorm, $withSpace) !== false
                ) {
                    /*$logger->debug(
                        '=== MATCH SPÉCIAL CHIFFRE pour "{name}" ===',
                        ["name" => $name]
                    );*/
                    return true;
                }
            }
        }

        //$logger->debug("AUCUN MATCH pour ce lutteur (toutes variantes)");
        return false;
    }

    private function expandYoutubeNames(array $names): array
    {
        $expanded = [];

        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') continue;

            // Conserver la forme brute si elle contient un tiret
            $lower = mb_strtolower($name);
            if (preg_match('/[-–—]/', $lower)) {
                $expanded[] = $lower;
            }

            $norm = $this->textNormalizationHelper->normalizeForMatch($name);
            if ($norm === '') continue;
            $expanded[] = $norm;

            // ── NOUVEAU : variante bidirectionnelle "bebe" <-> "bb" ──────
            // "bebe laurent" → "bb laurent"
            if (preg_match('/^bebe\b/i', $norm)) {
                $expanded[] = preg_replace('/^bebe\b/i', 'bb', $norm);
            }
            // "bb laurent" → "bebe laurent"
            elseif (preg_match('/^bb\b/i', $norm)) {
                $expanded[] = preg_replace('/^bb\b/i', 'bebe', $norm);
            }

            $base = preg_replace('/\s+(junior|jr|n2|ii|i|\d+)$/i', '', $norm);
            $base = preg_replace('/(?<=[a-z])2$/i', '', $base);
            $base = trim(preg_replace('/\s+/', ' ', $base));

            if ($base !== '' && $base !== $norm) {
                $expanded[] = $base;
            }
        }

        return array_values(array_unique(array_filter($expanded)));
    }

    public function buildYoutubeQuery(
        $syn1,
        $syn2,
        string $fightYear = ""
    ): array {

        $norm1 = $this->expandYoutubeNames((array) $syn1);
        $norm2 = $this->expandYoutubeNames((array) $syn2);

        if (empty($norm1) || empty($norm2)) {
            return [];
        }

        $queries = [];
        $fallbackQueries = []; // requêtes de secours (sans chiffre)

        foreach ($norm1 as $s1) {
            foreach ($norm2 as $s2) {
                if (empty($s1) || empty($s2)) {
                    continue;
                }

                $hasNumber1 = preg_match('/\d+$/', $s1);
                $hasNumber2 = preg_match('/\d+$/', $s2);

                $baseQuery =
                    '(vs OR versus OR "face to face" OR face OR f2f OR archive OR full)';

                // === Requêtes prioritaires exactes (remontent le vrai combat) ===
                $queries[] = '"' . $s1 . ' vs ' . $s2 . '"';
                $queries[] = '"' . $s2 . ' vs ' . $s1 . '"';
                $queries[] = '"' . $s1 . '" vs "' . $s2 . '"';
                $queries[] = '"' . $s2 . '" vs "' . $s1 . '"';

                // === Requêtes prioritaires (avec chiffres) ===
                $queries[] = '"' . $s1 . '" "' . $s2 . '" ' . $baseQuery;
                $queries[] = $s1 . " " . $s2 . " " . $baseQuery;

                // === Requêtes de fallback (sans chiffres) - priorité basse ===
                $s1_clean = $this->youtubeBaseName($s1);
                $s2_clean = $this->youtubeBaseName($s2);

                if (
                    ($s1_clean !== $s1 || $s2_clean !== $s2) &&
                    ($hasNumber1 || $hasNumber2)
                ) {
                    $fallbackQueries[] =
                        '"' . $s1_clean . '" "' . $s2 . '" ' . $baseQuery;
                    $fallbackQueries[] =
                        $s1_clean . " " . $s2 . " " . $baseQuery;
                    $fallbackQueries[] =
                        '"' . $s1 . '" "' . $s2_clean . '" ' . $baseQuery;
                    $fallbackQueries[] =
                        $s1 . " " . $s2_clean . " " . $baseQuery;
                }
            }
        }

        // On met les requêtes prioritaires en premier, puis les fallbacks
        return array_unique(array_merge($queries, $fallbackQueries));
    }

    /**
     * CORRECTION : filterResultsRaw
     *
     * PROBLÈMES IDENTIFIÉS ET CORRIGÉS :
     *
     * 1. strictMatch() échoue sur les noms répétés comme "reug reug"
     *    → Le \b final après un nom multi-mots avec espace fonctionne mal dans certains contextes.
     *    → CORRECTION : fallback strpos simple si strictMatch retourne false sur les noms multi-mots.
     *
     * 2. Aucun log intermédiaire → impossible de savoir à quelle étape la vidéo est filtrée.
     *    → CORRECTION : log DEBUG à chaque étape d'exclusion avec raison explicite.
     *
     * 3. expandYoutubeNames produit des variantes qui peuvent ne pas matcher "reug reug"
     *    car la normalisation retire les espaces consécutifs mais le pattern \b peut
     *    échouer si le mot suivant dans le titre commence immédiatement.
     *    → CORRECTION : ajout d'un match strpos en fallback dans hasName1/hasName2.
     */

    /**
     * @param array $items
     * @param array|string $syn1
     * @param array|string $syn2
     * @param string $fightYear
     * @param string $fightDate
     *   Date du combat YYYY-MM-DD (optionnel).
     * @param int $maxVideoCombatAgeDays
     *   Si > 0 : exclut les video_combat dont publishedAt est plus vieux
     *   que N jours (ex: 5 pour les combats récents via drush cri).
     */
    public function filterResultsRaw(
        $items,
        $syn1,
        $syn2,
        string $fightYear,
        string $fightDate = '',
        int $maxVideoCombatAgeDays = 0
    ): array {
        $logger   = \Drupal::logger('shared_lib');
        $results  = ["face2face" => [], "video_combat" => []];
        $settings = $this->getYoutubeSettings();

        $norms1 = $this->expandYoutubeNames((array) $syn1);
        $norms2 = $this->expandYoutubeNames((array) $syn2);

        /*$logger->debug('filterResultsRaw START | syn1=[@s1] syn2=[@s2] year=@y | norms1=[@n1] norms2=[@n2]', [
            '@s1' => implode(', ', (array) $syn1),
            '@s2' => implode(', ', (array) $syn2),
            '@y'  => $fightYear,
            '@n1' => implode(', ', $norms1),
            '@n2' => implode(', ', $norms2),
        ]);*/

        if (empty($norms1) || empty($norms2)) {
            //$logger->warning('filterResultsRaw ABORT : norms1 ou norms2 vide.');
            return $results;
        }

        $separators    = (array) $settings["separators"];
        $blockedChannels = (array) ($settings["excluded_channels_ytb"] ?? []);

        foreach ($items as $item) {
            $titleRaw  = $item["snippet"]["title"]     ?? "";
            $channelId = $item["snippet"]["channelId"] ?? "";
            $titleNorm = $this->textNormalizationHelper->normalizeForMatch($titleRaw);
            $pubDate   = $item["snippet"]["publishedAt"] ?? "";

            if (empty($titleRaw)) {
                continue;
            }

            $logPrefix = 'filterResultsRaw | "' . $titleRaw . '"';

            // ── 0. CHAÎNE BLOQUÉE ────────────────────────────────────────────────
            foreach ($blockedChannels as $blocked) {
                $blocked = trim($blocked);
                if ($blocked !== "" && $channelId === $blocked) {
                    /*$logger->info('@prefix → EXCLUS (chaîne bloquée : @ch)', [
                        '@prefix' => $logPrefix,
                        '@ch'     => $channelId,
                    ]);*/
                    continue 2;
                }
            }

            // ── 1. MOT-CLÉ EXCLU ────────────────────────────────────────────────
            foreach ($settings["excluded"] as $word) {
                $cleanWord = $this->textNormalizationHelper->normalizeForMatch($word);
                if ($cleanWord !== "" && $this->hasKeyword($titleNorm, $cleanWord)) {
                    /*$logger->info('@prefix → EXCLUS (mot-clé exclu : "@w")', [
                        '@prefix' => $logPrefix,
                        '@w'      => $word,
                    ]);*/
                    continue 2;
                }
            }

            // ── Variante i→1, ii→2 ──────────────────────────────────────────────
            $titleNormAlt = preg_replace("/\bii\b/i", "2", $titleNorm);
            $titleNormAlt = preg_replace("/\bi\b/i",  "1", $titleNormAlt);

            // ── 2. DÉTECTION NOMS ────────────────────────────────────────────────
            // CORRECTION : ajout fallback strpos pour les noms multi-mots (ex: "reug reug")
            // car \b peut échouer selon le contexte de ponctuation/chiffres adjacents.
            $hasName1      = false;
            $matchedNorm1  = '';

            foreach ($norms1 as $n1) {
                $clean     = preg_replace('/\s+\d+$/', '', $n1);
                $n1_with_i = preg_replace('/(\s+)1$/', '$1i', $n1);

                $matched =
                    $this->textNormalizationHelper->strictMatch($titleNorm,    $n1)       ||
                    $this->textNormalizationHelper->strictMatch($titleNorm,    $n1_with_i) ||
                    $this->textNormalizationHelper->strictMatch($titleNormAlt, $n1)       ||
                    $this->textNormalizationHelper->strictMatch($titleNorm,    $clean);

                // FALLBACK : strpos simple pour les noms multi-mots
                // (couvre les cas où \b échoue sur "reug reug", "bebe diene", etc.)
                if (!$matched && str_contains($n1, ' ')) {
                    $matched = str_contains($titleNorm, $n1)
                        || str_contains($titleNorm, $clean);
                }

                if ($matched) {
                    $hasName1     = true;
                    $matchedNorm1 = $n1;
                    break;
                }
            }

            $hasName2     = false;
            $matchedNorm2 = '';

            foreach ($norms2 as $n2) {
                $clean     = preg_replace('/\s+\d+$/', '', $n2);
                $n2_with_i = preg_replace('/(\s+)1$/', '$1i', $n2);

                $matched =
                    $this->textNormalizationHelper->strictMatch($titleNorm,    $n2)       ||
                    $this->textNormalizationHelper->strictMatch($titleNorm,    $n2_with_i) ||
                    $this->textNormalizationHelper->strictMatch($titleNormAlt, $n2)       ||
                    $this->textNormalizationHelper->strictMatch($titleNorm,    $clean);

                // FALLBACK : strpos simple pour les noms multi-mots
                if (!$matched && str_contains($n2, ' ')) {
                    $matched = str_contains($titleNorm, $n2)
                        || str_contains($titleNorm, $clean);
                }

                if ($matched) {
                    $hasName2     = true;
                    $matchedNorm2 = $n2;
                    break;
                }
            }

            /*$logger->debug('@prefix → Détection noms : name1=@n1 (@m1) | name2=@n2 (@m2)', [
                '@prefix' => $logPrefix,
                '@n1'     => $hasName1 ? 'OUI' : 'NON',
                '@m1'     => $matchedNorm1 ?: '—',
                '@n2'     => $hasName2 ? 'OUI' : 'NON',
                '@m2'     => $matchedNorm2 ?: '—',
            ]);*/

            if (!$hasName1 || !$hasName2) {
                /*$logger->info('@prefix → EXCLUS (nom manquant : name1=@n1 name2=@n2) | norms1=[@a] norms2=[@b]', [
                    '@prefix' => $logPrefix,
                    '@n1'     => $hasName1 ? 'trouvé' : 'ABSENT',
                    '@n2'     => $hasName2 ? 'trouvé' : 'ABSENT',
                    '@a'      => implode(', ', $norms1),
                    '@b'      => implode(', ', $norms2),
                ]);*/
                continue;
            }

            // ── 2bis. STRUCTURE MULTI-COMBAT (/, |) ─────────────────────────────
            if (preg_match('/[\/|]/', $titleRaw)) {
                $segments = preg_split('/\s*[\/|]\s*/', $titleRaw);
                $seg1 = [];
                $seg2 = [];
                foreach ($segments as $idx => $seg) {
                    $segNorm = $this->textNormalizationHelper->normalizeForMatch($seg);
                    foreach ($norms1 as $n1) {
                        if ($this->textNormalizationHelper->strictMatch($segNorm, $n1)) {
                            $seg1[] = $idx;
                            break;
                        }
                    }
                    foreach ($norms2 as $n2) {
                        if ($this->textNormalizationHelper->strictMatch($segNorm, $n2)) {
                            $seg2[] = $idx;
                            break;
                        }
                    }
                }
                if (!empty($seg1) && !empty($seg2) && empty(array_intersect($seg1, $seg2))) {
                    /*$logger->info('@prefix → EXCLUS (multi-combat : noms dans segments différents)', [
                        '@prefix' => $logPrefix,
                    ]);*/
                    continue;
                }
            }

            // ── 3. DISTANCE ──────────────────────────────────────────────────────
            $distance = $this->namesDistanceInWords($titleNorm, $norms1, $norms2);

            $isF2f = false;
            foreach ($settings["f2f"] as $w) {
                if ($this->hasKeyword($titleNorm, $w)) {
                    $isF2f = true;
                    break;
                }
            }

            /*$logger->debug('@prefix → distance=@d | isF2f=@f', [
                '@prefix' => $logPrefix,
                '@d'      => $distance,
                '@f'      => $isF2f ? 'OUI' : 'NON',
            ]);*/

            // ── Bloquage listes / palmarès ───────────────────────────────────────
            if (!$isF2f && $this->textNormalizationHelper->isListLike($titleRaw)) {
                $logger->info('@prefix → EXCLUS (isListLike : >= 2 virgules)', [
                    '@prefix' => $logPrefix,
                ]);
                continue;
            }

            // ── 4. VALIDATION STRUCTURELLE ───────────────────────────────────────
            $norms1_clean = array_unique(
                array_map(fn($n) => preg_replace('/\s+\d+$/', "", $n), $norms1)
            );
            $norms2_clean = array_unique(
                array_map(fn($n) => preg_replace('/\s+\d+$/', "", $n), $norms2)
            );

            $hasValidSeparator =
                $this->hasDirectSeparator($titleNorm,    $norms1,       $norms2,       $separators) ||
                $this->hasDirectSeparator($titleNormAlt, $norms1,       $norms2,       $separators) ||
                $this->hasDirectSeparator($titleNorm,    $norms1_clean, $norms2,       $separators) ||
                $this->hasDirectSeparator($titleNorm,    $norms1,       $norms2_clean, $separators) ||
                $this->hasDirectSeparator($titleNorm,    $norms1_clean, $norms2_clean, $separators);

            $hasAnySep = $this->hasAnySeparator($titleNorm, $separators);

            $logger->debug('@prefix → hasValidSeparator=@vs | hasAnySep=@as | distance=@d', [
                '@prefix' => $logPrefix,
                '@vs'     => $hasValidSeparator ? 'OUI' : 'NON',
                '@as'     => $hasAnySep ? 'OUI' : 'NON',
                '@d'      => $distance,
            ]);

            if (!$isF2f) {
                if ($hasAnySep && !$hasValidSeparator) {
                    $logger->info('@prefix → EXCLUS (séparateur présent mais invalide, video_combat)', [
                        '@prefix' => $logPrefix,
                    ]);
                    continue;
                }
                if ($distance > 2) {
                    $logger->info('@prefix → EXCLUS (distance trop grande : @d > 2, video_combat)', [
                        '@prefix' => $logPrefix,
                        '@d'      => $distance,
                    ]);
                    continue;
                }
            } else {
                if ($hasAnySep && !$hasValidSeparator) {
                    $logger->info('@prefix → EXCLUS (séparateur présent mais invalide, f2f)', [
                        '@prefix' => $logPrefix,
                    ]);
                    continue;
                }
                if ($distance > 2) {
                    $logger->info('@prefix → EXCLUS (distance trop grande : @d > 2, f2f)', [
                        '@prefix' => $logPrefix,
                        '@d'      => $distance,
                    ]);
                    continue;
                }
            }

            // ── 5. TEMPOREL ──────────────────────────────────────────────────────
            $yearInTitle = false;
            $isOldVideo  = false;
            $pubYear = $pubDate ? (int) substr($pubDate, 0, 4) : 0;
            $fightY  = (int) $fightYear;

            // Combats récents (cri) : video_combat > 5 jours → exclus.
            // face2face non concerné (peut rester plus ancien).
            if (!$isF2f && $maxVideoCombatAgeDays > 0 && $pubDate !== '') {
                $pubTs = strtotime($pubDate);
                if ($pubTs && (time() - $pubTs) > ($maxVideoCombatAgeDays * 24 * 60 * 60)) {
                    $logger->info('@prefix → EXCLUS (video_combat trop ancienne : publishedAt=@pub > @days j)', [
                        '@prefix' => $logPrefix,
                        '@pub'    => $pubDate,
                        '@days'   => $maxVideoCombatAgeDays,
                    ]);
                    continue;
                }
            }

            if ($fightYear !== "" && $fightY > 0) {
                $y4 = $fightYear;

                if (str_contains($titleRaw, $y4)) {
                    $yearInTitle = true;
                }

                if (
                    $yearInTitle &&
                    preg_match(
                        "/\b(0[1-9]|[12][0-9]|3[01])\s+" . substr($y4, 2, 2) . "/i",
                        $titleNorm
                    )
                ) {
                    if (!str_contains($titleNorm, $y4)) {
                        $yearInTitle = false;
                    }
                }

                if ($pubYear >= $fightY - 1 && $pubYear <= $fightY + 1) {
                    $isOldVideo = true;
                }
            }

            $isArchiveOld =
                $fightYear !== "" &&
                !$isF2f &&
                (($pubYear - $fightY > 4 &&
                    $this->hasAnyKeyword($titleNorm, $settings["archive"])) ||
                    $fightY < 2010);

            $logger->debug('@prefix → yearInTitle=@yi | isOldVideo=@ov | isArchiveOld=@ao | pubYear=@py | fightY=@fy', [
                '@prefix'  => $logPrefix,
                '@yi'      => $yearInTitle ? 'OUI' : 'NON',
                '@ov'      => $isOldVideo  ? 'OUI' : 'NON',
                '@ao'      => $isArchiveOld ? 'OUI' : 'NON',
                '@py'      => $pubYear,
                '@fy'      => $fightY,
            ]);

            if ($fightYear !== "") {
                if ($isF2f) {
                    $isOldVideoF2f = $pubYear >= $fightY - 1 && $pubYear <= $fightY + 2;
                    if (!$yearInTitle && !$isOldVideoF2f) {
                        $logger->info('@prefix → EXCLUS (f2f : année hors fenêtre, pubYear=@py fightY=@fy)', [
                            '@prefix' => $logPrefix,
                            '@py'     => $pubYear,
                            '@fy'     => $fightY,
                        ]);
                        continue;
                    }
                } else {
                    if (!$yearInTitle && !$isOldVideo && !$isArchiveOld) {
                        $logger->info('@prefix → EXCLUS (video_combat : année hors fenêtre, pubYear=@py fightY=@fy)', [
                            '@prefix' => $logPrefix,
                            '@py'     => $pubYear,
                            '@fy'     => $fightY,
                        ]);
                        continue;
                    }
                }
            }

            // ── 6. SCORING ───────────────────────────────────────────────────────
            $score      = 800;
            $hasVictory = $this->hasAnyKeyword($titleNorm, $settings["victory"]);
            $hasKO      = $this->hasAnyKeyword($titleNorm, $settings["ko"]);
            $hasFull    = $this->hasAnyKeyword($titleNorm, $settings["full"]);
            $hasCombat  = $this->hasAnyKeyword($titleNorm, $settings["combat"]);

            $effectiveSeparator = $hasValidSeparator || $isF2f;

            if ($effectiveSeparator) {
                $score += 35000;
            }
            if ($hasVictory) {
                $score += 30000;
            }
            if ($hasKO) {
                $score += 9000;
                if (!$isF2f) {
                    $score += 25000;
                }
            }
            if ($hasFull) {
                $score += 4000;
            }
            if ($hasCombat) {
                $score += 30000;
            }
            if ($yearInTitle) {
                $score += 15000;
            } elseif ($isOldVideo) {
                $score += 8000;
            }
            if ($isF2f) {
                $score += 60000;
            }

            $category = $isF2f ? "face2face" : "video_combat";

            /*$logger->notice('filterResultsRaw AJOUTÉE [@cat] → "@title" | score=@score | dist=@dist | sep=@sep | victory=@v | ko=@ko | yearInTitle=@y', [
                '@cat'   => $category,
                '@title' => $titleRaw,
                '@score' => $score,
                '@dist'  => $distance,
                '@sep'   => $hasValidSeparator ? 'OUI' : 'NON',
                '@v'     => $hasVictory ? 'OUI' : 'NON',
                '@ko'    => $hasKO ? 'OUI' : 'NON',
                '@y'     => $yearInTitle ? 'OUI' : 'NON',
            ]);*/

            $results[$category][] = [
                "url"       => "https://www.youtube.com/watch?v=" . ($item["id"]["videoId"] ?? ""),
                "score"     => $score,
                "videoDate" => $pubDate,
                "title"     => $titleRaw,
            ];
        }

        return $results;
    }

    private function hasAnySeparator(
        string $titleNorm,
        array $separators
    ): bool {
        if (empty($separators)) {
            return false;
        }
        $sepPattern = implode(
            "|",
            array_map(fn($s) => preg_quote(trim($s), "/"), $separators)
        );
        return preg_match("/\b(" . $sepPattern . ")\b/i", $titleNorm) === 1;
    }

    private function hasAnyKeyword(
        string $titleNorm,
        array $keywords
    ): bool {
        foreach ($keywords as $w) {
            if (!empty($w) && $this->hasKeyword($titleNorm, $w)) {
                return true;
            }
        }
        return false;
    }

    private function wordBelongsToTarget(
        string $word,
        array $norms1,
        array $norms2
    ): bool {
        if (empty($word)) {
            return false;
        }

        foreach (array_merge($norms1, $norms2) as $name) {
            $nameParts = explode(" ", $name);
            if (in_array($word, $nameParts)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Calcul la distance entre 2 noms de lutteurs dans un titre de video 
     * (ex. Coly Faye 2 vs Cartouche), distance 1 ici (vs)
     */
    public function namesDistanceInWords(
        string $titleNorm,
        array $norms1,
        array $norms2
    ): int {
        $words = explode(' ', $titleNorm);

        $isAmbiguousToken = fn(string $w): bool => ctype_digit($w);

        // Retourne des paires [start_index, end_index] pour chaque occurrence du synonyme
        $findRanges = function (string $syn) use ($words, $titleNorm, $isAmbiguousToken): array {
            $syn = trim($syn);
            if ($syn === '') return [];

            $synWords = explode(' ', $syn);
            $synLen   = count($synWords);
            $allDigits = count(array_filter($synWords, fn($w) => !ctype_digit($w))) === 0;

            // Synonyme mono-mot non-ambigu : matching classique
            if ($synLen === 1 && !$allDigits) {
                $ranges = [];
                foreach ($words as $i => $w) {
                    if (!$isAmbiguousToken($w) && $w === $syn) {
                        $ranges[] = [$i, $i];
                    }
                }
                return $ranges;
            }

            // Multi-mots ou tout-numérique : recherche dans la chaîne normalisée
            $ranges = [];
            $offset = 0;
            while (($pos = strpos($titleNorm, $syn, $offset)) !== false) {
                $before    = substr($titleNorm, 0, $pos);
                $wordStart = $before === '' ? 0 : count(explode(' ', $before)) - 1;
                $ranges[]  = [$wordStart, $wordStart + $synLen - 1];
                $offset    = $pos + 1;
            }

            // Fallback sliding window multi-mots
            if (empty($ranges) && !$allDigits) {
                for ($i = 0; $i <= count($words) - $synLen; $i++) {
                    if (array_slice($words, $i, $synLen) === $synWords) {
                        $ranges[] = [$i, $i + $synLen - 1];
                    }
                }
            }

            return $ranges;
        };

        $ranges1 = [];
        $ranges2 = [];

        foreach ($norms1 as $syn) {
            $ranges1 = array_merge($ranges1, $findRanges($syn));
        }
        foreach ($norms2 as $syn) {
            $ranges2 = array_merge($ranges2, $findRanges($syn));
        }

        if (empty($ranges1) || empty($ranges2)) {
            return PHP_INT_MAX;
        }

        $minDist = PHP_INT_MAX;
        foreach ($ranges1 as [$s1, $e1]) {
            foreach ($ranges2 as [$s2, $e2]) {
                if ($e1 < $s2) {
                    // nom1 avant nom2 : gap = s2 - e1 - 1
                    $dist = $s2 - $e1 - 1;
                } elseif ($e2 < $s1) {
                    // nom2 avant nom1 : gap = s1 - e2 - 1
                    $dist = $s1 - $e2 - 1;
                } else {
                    // chevauchement
                    $dist = 0;
                }
                if ($dist < $minDist) {
                    $minDist = $dist;
                }
            }
        }

        return max(0, $minDist);
    }

    public function getSynonyms(string $name): array
    {
        $config = \Drupal::config("misc_divers.settings");
        $replacements = $config->get("remplacementsYtb") ?? [];

        $official = mb_strtolower(trim($name));
        $synonyms = [$official]; // On commence avec le nom propre (en minuscule)

        if (empty($replacements)) {
            return $synonyms;
        }

        // Si $replacements est une chaîne de caractères (textarea), on la parse
        if (is_string($replacements)) {
            $lines = explode("\n", $replacements);
            foreach ($lines as $line) {
                if (str_contains($line, "|")) {
                    [$key, $values] = explode("|", $line);
                    if (mb_strtolower(trim($key)) === $official) {
                        $parts = explode(",", $values);
                        foreach ($parts as $p) {
                            $synonyms[] = mb_strtolower(trim($p));
                        }
                    }
                }
            }
        }
        // Si c'est déjà un tableau associatif (clé => valeur)
        elseif (is_array($replacements)) {
            foreach ($replacements as $key => $value) {
                if (mb_strtolower(trim($key)) === $official) {
                    $parts = explode(",", $value);
                    foreach ($parts as $part) {
                        $synonyms[] = mb_strtolower(trim($part));
                    }
                }
            }
        }

        return array_unique(array_filter($synonyms));
    }

    public function getYoutubeSettings(): array
    {
        static $settings = null;
        if ($settings !== null) {
            return $settings;
        }

        $config = \Drupal::config("misc_divers.settings");
        $parse = fn($key) => array_values(
            array_filter(
                array_map(
                    "trim",
                    preg_split('/[,\n\r|]+/', $config->get($key) ?? "")
                )
            )
        );

        $config_req_ai = \Drupal::config("request_ai.settings");
        $parse_req_ai = fn($key) => array_values(
            array_filter(
                array_map(
                    "trim",
                    preg_split('/[,\n\r|]+/', $config_req_ai->get($key) ?? "")
                )
            )
        );

        $settings = [
            "archive"          => $parse("archive_words_ytb"),
            "excluded"         => $parse("exclude_words_ytb"),
            "excluded_channels_ytb" => $parse_req_ai("excluded_channels_ytb"),
            "f2f"              => $parse("face2face_patterns_ytb"),
            "combat"           => $parse("combat_patterns_ytb"),
            "full"             => $parse("integral_patterns_ytb"),
            "victory"          => $parse("victory_patterns_ytb"),
            "ko"               => $parse("ko_patterns_ytb"),
            "separators"       => $parse("separators_patterns_ytb") ?: [
                "vs",
                "contre",
            ],
        ];
        return $settings;
    }

    protected function hasKeyword(
        string $titleNorm,
        string $keyword
    ): bool {
        if (empty($keyword)) {
            return false;
        }
        $wordNorm = $this->textNormalizationHelper->normalizeForSearch($keyword);
        if ($wordNorm === "") {
            return false;
        }
        if (str_contains($wordNorm, " ")) {
            return str_contains($titleNorm, $wordNorm);
        }
        return preg_match(
            "/\b" . preg_quote($wordNorm, "/") . "\b/",
            $titleNorm
        ) === 1;
    }

    public function hasDirectSeparator(
        string $titleNorm,
        array $norms1,
        array $norms2,
        array $separators
    ): bool {
        if (empty($separators) || empty($norms1) || empty($norms2)) {
            return false;
        }

        $sepPattern = implode(
            "|",
            array_map(fn($s) => preg_quote(trim($s), "/"), $separators)
        );

        foreach ($norms1 as $s1) {
            $s1 = trim($s1);
            if ($s1 === "") {
                continue;
            }
            // \b final seulement si le nom ne se termine pas par un chiffre ou un espace
            $s1End = str_contains($s1, " ") ? "" : "\b";

            foreach ($norms2 as $s2) {
                $s2 = trim($s2);
                if ($s2 === "") {
                    continue;
                }
                $s2End = str_contains($s2, " ") ? "" : "\b";

                $pattern =
                    "/\b" . preg_quote($s1, "/") . $s1End .
                    "(?:\s+\w{1,4})?\s*(?:" . $sepPattern . ")\s*(?:\w{1,4}\s+)?\b" .
                    preg_quote($s2, "/") . $s2End . "/i";
                $patternRev =
                    "/\b" . preg_quote($s2, "/") . $s2End .
                    "(?:\s+\w{1,4})?\s*(?:" . $sepPattern . ")\s*(?:\w{1,4}\s+)?\b" .
                    preg_quote($s1, "/") . $s1End . "/i";

                if (
                    preg_match($pattern, $titleNorm) ||
                    preg_match($patternRev, $titleNorm)
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    private function namesAreClose(
        string $titleNorm,
        array $norms1,
        array $norms2,
        array $separators,
        int $maxWordsApart = 3
    ): bool {
        $positions1 = $positions2 = [];

        foreach ($norms1 as $s) {
            $s = trim($s);
            if ($s) {
                preg_match_all(
                    "/\b" . preg_quote($s, "/") . "\b/u",
                    $titleNorm,
                    $m,
                    PREG_OFFSET_CAPTURE
                ) &&
                    ($positions1 = array_merge(
                        $positions1,
                        array_column($m[0], 1)
                    ));
            }
        }

        foreach ($norms2 as $s) {
            $s = trim($s);
            if ($s) {
                preg_match_all(
                    "/\b" . preg_quote($s, "/") . "\b/u",
                    $titleNorm,
                    $m,
                    PREG_OFFSET_CAPTURE
                ) &&
                    ($positions2 = array_merge(
                        $positions2,
                        array_column($m[0], 1)
                    ));
            }
        }

        if (empty($positions1) || empty($positions2)) {
            return false;
        }

        $sepPattern = implode(
            "|",
            array_map(fn($s) => preg_quote(trim($s), "/"), $separators)
        );
        foreach ($positions1 as $p1) {
            foreach ($positions2 as $p2) {
                $segment = substr(
                    $titleNorm,
                    min($p1, $p2),
                    abs($p2 - $p1) + strlen($titleNorm)
                );
                if (preg_match("/\b(" . $sepPattern . ")\b/i", $segment)) {
                    return true;
                }

                if (abs($p2 - $p1) / 6 <= $maxWordsApart) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getVideoDurations(
        array $videoIds,
        string $apiKey
    ): array {
        if (empty($videoIds) || empty($apiKey)) {
            return [];
        }
        $ids = implode(",", array_slice(array_unique($videoIds), 0, 50));

        $url =
            "https://www.googleapis.com/youtube/v3/videos?" .
            http_build_query([
                "key" => $apiKey,
                "id" => $ids,
                "part" => "contentDetails",
            ]);

        $res = @file_get_contents(
            $url,
            false,
            stream_context_create(["http" => ["ignore_errors" => true]])
        );
        if (!$res) {
            return [];
        }

        $data = json_decode($res, true);
        $durations = [];

        foreach ($data["items"] ?? [] as $item) {
            $vid = $item["id"];
            $iso = $item["contentDetails"]["duration"] ?? "PT0S";
            preg_match("/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/", $iso, $m);
            $seconds = ($m[1] ?? 0) * 3600 + ($m[2] ?? 0) * 60 + ($m[3] ?? 0);
            $durations[$vid] = $seconds;
        }
        return $durations;
    }

    public function sortAndSlice(
        array &$results,
        int $maxF2f = 4,
        int $maxCombat = 2
    ): void {
        $sortFn = function ($a, $b) {
            if ($a["score"] !== $b["score"]) {
                return $b["score"] <=> $a["score"];
            }
            return strcmp($b["videoDate"], $a["videoDate"]);
        };

        $dedup = function (array $list): array {
            $unique = [];
            foreach ($list as $item) {
                $url = $item["url"] ?? "";
                if ($url && !isset($unique[$url])) {
                    $unique[$url] = $item;
                }
            }
            return array_values($unique);
        };

        $results["face2face"] = $dedup($results["face2face"] ?? []);
        $results["video_combat"] = $dedup($results["video_combat"] ?? []);

        usort($results["face2face"], $sortFn);
        usort($results["video_combat"], $sortFn);

        $results["face2face"] = array_slice(
            array_map(fn($v) => $v["url"], $results["face2face"]),
            0,
            $maxF2f
        );
        $results["video_combat"] = array_slice(
            array_map(fn($v) => $v["url"], $results["video_combat"]),
            0,
            $maxCombat
        );
    }

    public function searchYoutubeVideosBase(
        string $date,
        string $wrestler1,
        string $wrestler2,
        int $maxVideoCombatAgeDays = 0
    ) {
        return $this->internalYoutubeRequest(
            $date,
            $wrestler1,
            $wrestler2,
            false,
            $maxVideoCombatAgeDays
        );
    }

    public function searchYoutubeVideosLatestsBase(
        string $wrestler1,
        string $wrestler2
    ) {
        // Mode latest : video_combat limitées à 5 jours.
        return $this->internalYoutubeRequest("", $wrestler1, $wrestler2, true, 5);
    }

}
