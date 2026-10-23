<?php

namespace Drupal\gemini_wrestling_importer\Service;

use Drupal\shared_lib\Tools\SomeTools;

/**
 * Service for fetching fights and videos from Gemini API.
 */
class GeminiWrestlingService extends GeminiBaseService
{

  protected function getPrompt(string $wrestlerName = ''): string
  {
    $prompt = $this->configFactory->get('gemini_wrestling_importer.settings')->get('prompt');
    return $prompt ? strtr($prompt, ['@wrestler' => $wrestlerName]) : '';
  }

  protected function getPromptDiff(string $wrestlerName = ''): string
  {
    $promptDiff = $this->configFactory->get('gemini_wrestling_importer.settings')->get('prompt_diff');
    return $promptDiff ? strtr($promptDiff, ['@wrestler' => $wrestlerName]) : '';
  }

  protected function getPromptDiffJsonFightsPart1(string $wrestlerName = ''): string
  {
    $promptJsonFightsPart1 = $this->configFactory->get('gemini_wrestling_importer.settings')->get('prompt_json_fights_part1');
    return $promptJsonFightsPart1 ? strtr($promptJsonFightsPart1, ['@wrestler' => $wrestlerName]) : '';
  }

  protected function getPromptDiffJsonFightsPart2(string $wrestlerName = ''): string
  {
    $promptJsonFightsPart2 = $this->configFactory->get('gemini_wrestling_importer.settings')->get('prompt_json_fights_part2');
    return $promptJsonFightsPart2 ? strtr($promptJsonFightsPart2, ['@wrestler' => $wrestlerName]) : '';
  }

  protected function getDefaultResponseByAthlete(string $wrestlerName = ''): string
  {
    if (empty($this->defaultResponses)) {
      $input = $this->configFactory->get('gemini_wrestling_importer.settings')->get('default_response');
      $lines = explode("\n", $input);
      foreach ($lines as $line) {
        $parts = explode("|", $line, 2);
        if (count($parts) === 2) {
          $key = $this->technicalWrestlerName($parts[0]);
          $this->defaultResponses[$key] = $parts[1];
        }
      }
    }
    $key = $this->technicalWrestlerName($wrestlerName);
    return $this->defaultResponses[$key] ?? '';
  }

  /**
   * Retourne directement la ligne des mots-clés de victoire depuis la config.
   */
  public function getVictoryKeywordsLine(): string
  {
    $config = \Drupal::config("misc_divers.settings");
    $victoryPatterns = array_values(
      array_filter(
        array_map(
          "trim",
          preg_split('/[,\n\r|]+/', $config->get("victory_patterns_ytb") ?? "")
        )
      )
    );

    if (empty($victoryPatterns)) {
      return '- Mots-clés puissants : "bat", "victoire", "gagné", "chute", "défaite"';
    }

    $keywords = array_map(fn($kw) => '"' . $kw . '"', $victoryPatterns);
    return '- Mots-clés puissants : ' . implode(', ', $keywords);
  }

  /**
   * Recupere les combats depuis Gemini.
   *
   * Retourne toujours un tableau structure :
   *   ['success' => true,  'fights' => [...]]
   *   ['success' => false, 'error'  => 'gemini_keys_expired'|'gemini_quota_exhausted'|
   *                                     'gemini_service_unavailable'|'exception', ...]
   */
  public function getFightsFromAiRequest(string $wrestlerName, $engine = 'grok', $mode = 'normal'): array
  {
    try {
      $prompt = $this->getPrompt($wrestlerName);

      // --- CAS DEFAUT (prompt marque __ignore__) ---
      if (stripos($prompt, '__ignore__') !== false) {
        $responseContent = $this->getDefaultResponseByAthlete($wrestlerName);
        $responseContent = str_replace('__debug__', '', $responseContent);

        if (empty($responseContent) || $responseContent === '[]') {
          return ['success' => true, 'fights' => []];
        }

        $fights = $this->decodeJson($responseContent, __METHOD__) ?? [];
        return ['success' => true, 'fights' => is_array($fights) ? $fights : []];
      }

      $synonyms = SomeTools::getSynonyms($wrestlerName);
      // pour ne garder que les variantes supplémentaires
      $synonymsExtra = array_values(array_filter(
        $synonyms,
        fn($s) => mb_strtolower(trim($s)) !== mb_strtolower(trim($wrestlerName))
      ));
      $synonymsLine = '';
      if (!empty($synonymsExtra)) {
        $synonymsLine = "- VARIANTES : Ce lutteur est aussi connu sous les appellations suivantes : "
          . implode(', ', $synonymsExtra)
          . ". Utilise ces variantes dans tes recherches si nécessaire.\n";
      }

      if ($mode == 'diff') {

        // ── Get titres potentiels combats depuis recherche direct YouTube ─────────────────
        $jsonTitlesYtbe = SomeTools::fetchYoutubeTitlesForWrestler($wrestlerName);
        $this->logger->info('fetchYoutubeTitlesForWrestler pour @name : @json', [
          '@name' => $wrestlerName,
          '@json' => $jsonTitlesYtbe,
        ]);

        // ── STOP immédiat si aucun titre YouTube trouvé : probable token expiré / quota —
        // on traite ça comme un ÉCHEC (pas un succès sans combat) pour permettre le retry demain.
        $jsonTitlesYtbeTrim = trim((string) $jsonTitlesYtbe);
        if ($jsonTitlesYtbeTrim === '' || $jsonTitlesYtbeTrim === '[]') {
          $this->logger->warning('getFightsFromAiRequest (diff) : aucun titre YouTube pour @name — probable token expiré, échec pour retry.', [
            '@name' => $wrestlerName,
          ]);
          return [
            'success' => false,
            'fights'  => [],
            'error'   => 'youtube_titles_empty',
            'message' => 'fetchYoutubeTitlesForWrestler a renvoyé un résultat vide (token expiré probable) — sera retenté.',
          ];
        }

        // ── Injection titres potentiels combats depuis recherche dans le prompt Part 1 ───
        $promptJsonFightsPart1 = $this->getPromptDiffJsonFightsPart1($wrestlerName);
        $replacements = [
          '@jsonTitlesYtbe'   => $jsonTitlesYtbe,
          '@synonyms'         => $synonymsLine,
          '@victory_keywords' => $this->getVictoryKeywordsLine(),
        ];
        $promptJsonFightsPart1 = strtr($promptJsonFightsPart1, $replacements);
        $this->logger->debug('DEBUG REPLACEMENT: Template contient @jsonTitlesYtbe : ' . (str_contains($promptJsonFightsPart1, '@jsonTitlesYtbe') ? 'OUI' : 'NON'));
        $this->logger->debug('DEBUG REPLACEMENT: Longueur jsonTitles : ' . strlen($jsonTitlesYtbe));

        $this->logger->info("LiveSearch promptJsonFightsPart1 : " . $promptJsonFightsPart1);
        $jsonFightsPart1 = $this->liveSearchService->liveSearch($promptJsonFightsPart1, $engine, $mode);
        $jsonFightsPart1 = ($jsonFightsPart1['success'] ?? false)
          ? (string) ($jsonFightsPart1['content'] ?? '[]')
          : '[]';
        $this->logger->info('liveSearch json Titles Youtube pour @name : @json', [
          '@name' => $wrestlerName,
          '@json' => $jsonFightsPart1,
        ]);

        // ── Injection des potentiels combats depuis recherche dans le prompt Part 2 ──────
        $promptJsonFightsPart2 = $this->getPromptDiffJsonFightsPart2($wrestlerName);
        $jsonFightsPart1 = str_replace('@wrestler', $wrestlerName, $jsonFightsPart1);

        $promptJsonFightsPart2 = str_replace(['@jsonFightsPart1', '@synonyms'], [$jsonFightsPart1, $synonymsLine], $promptJsonFightsPart2);
        $this->logger->info("LiveSearch promptJsonFightsPart2 : " . $promptJsonFightsPart2);
        $jsonFightsPart2 = $this->liveSearchService->liveSearch($promptJsonFightsPart2, $engine, $mode);
        $jsonFightsPart2 = ($jsonFightsPart2['success'] ?? false)
          ? (string) ($jsonFightsPart2['content'] ?? '[]')
          : '[]';
        $this->logger->info('liveSearch promptJsonFightsPart2 @name : @json', [
          '@name' => $wrestlerName,
          '@json' => $jsonFightsPart2,
        ]);

        // ── Recherche json final combats depuis recherche via prompt diff final ───────────
        $promptDiff = $this->getPromptDiff($wrestlerName);
        $jsonFightsPart2 = str_replace('@wrestler', $wrestlerName, $jsonFightsPart2);

        $promptDiff = str_replace(['@jsonFightsPart2', '@synonyms'], [$jsonFightsPart2, $synonymsLine], $promptDiff);
        $jsonFightsWrestler = $this->getFightsJsonWrestler($wrestlerName);
        $prompt = str_replace(
          ['__debug__', '@wrestler', '@jsonFightsWrestler'],
          ['', $wrestlerName, $jsonFightsWrestler],
          $promptDiff
        );
        $this->logger->info("LiveSearch getPromptDiff completed : " . $prompt);
      }

      // --- CAS NORMAL ---
      $prompt   = str_replace(['__debug__', '@wrestler'], ['', $wrestlerName], $prompt);
      $response = $this->liveSearchService->liveSearch($prompt, $engine, $mode);

      $this->logger->info('liveSearch getFightsFromAiRequest en cours pour @name...', [
        '@name' => $wrestlerName,
      ]);

      if (!$response['success']) {
        $error = $response['error'] ?? 'unknown';

        // invalid_json = le modèle a répondu mais le contenu brut n'est pas du JSON valide.
        // On tente de récupérer le contenu brut et de le nettoyer nous-mêmes.
        if ($error === 'invalid_json') {
          $rawContent = trim($response['content'] ?? '');

          $this->logger->warning('getFightsFromAiRequest : invalid_json pour @name — len=@len : @raw', [
            '@name' => $wrestlerName,
            '@len'  => strlen($rawContent),
            '@raw'  => substr($rawContent, 0, 500),
          ]);

          // Réponse vide ou [] : résultat légitime sans combats, pas de requeue.
          if ($rawContent === '' || $rawContent === '[]') {
            $this->logger->info('getFightsFromAiRequest : réponse vide/[] pour @name — succès sans combats.', [
              '@name' => $wrestlerName,
            ]);
            return ['success' => true, 'fights' => []];
          }

          // Contenu non vide mais JSON malformé : on tente la récupération.
          $response = ['success' => true, 'content' => $rawContent, 'invalid_json_tolerate' => true];
        } else {
          $this->logger->warning('getFightsFromAiRequest KO pour @name : @error', [
            '@name'  => $wrestlerName,
            '@error' => $error,
          ]);
          return $response;
        }
      }

      // --- CAS DEJA TRAITE ---
      if (!empty($response['skipped'])) {
        $this->logger->info('getFightsFromAiRequest ignore pour @name : deja en BDD.', [
          '@name' => $wrestlerName,
        ]);
        return [
          'success' => false,
          'fights'  => [],
          'skipped' => true,
          'error'   => 'skipped',
          'message' => 'Déjà traité y a moins de 24h — vider le state via : drush sl:live-search-reset',
        ];
      }

      $responseContent = $response['content'] ?? '';

      $this->logger->info('getFightsFromAiRequest : contenu brut avant nettoyage pour @name (@len chars) : @raw', [
        '@name' => $wrestlerName,
        '@len'  => strlen($responseContent),
        '@raw'  => substr($responseContent, 0, 500),
      ]);

      if (empty($responseContent) || $responseContent === '[]') {

        // On avait certes toléré pour voir..., mais si vide alors echec.
        if (!empty($response['invalid_json_tolerate'])) {
          return ['success' => false, 'fights' => [], 'invalid_json_empty' => true];
        }

        return ['success' => true, 'fights' => []];
      }

      // --- NETTOYAGE DU CONTENU BRUT ---
      // Grok peut encapsuler les URLs en syntaxe Markdown [label](url) → on extrait l'url.
      $responseContent = preg_replace('/\[([^\]]*)\]\(([^)]+)\)/', '$2', $responseContent);

      // Supprimer les blocs ```json ... ``` ou ``` ... ``` que certains modèles émettent.
      $responseContent = preg_replace('/```(?:json)?(.*?)```/s', '$1', $responseContent);

      // Supprimer tout ce qui précède le premier '[' (texte introductif parasite).
      $start = strpos($responseContent, '[');
      $end   = strrpos($responseContent, ']');
      if ($start !== false && $end !== false && $end > $start) {
        $responseContent = substr($responseContent, $start, $end - $start + 1);
      }

      $responseContent = trim($responseContent);

      if (empty($responseContent) || $responseContent === '[]') {

        // On avait certes toléré pour voir..., mais si post récupération vide alors echec.
        if (!empty($response['invalid_json_tolerate'])) {
          return ['success' => false, 'fights' => [], 'invalid_json_empty' => true];
        }

        return ['success' => true, 'fights' => []];
      }

      // --- ENRICHISSEMENT GROK pour les résultats non reconnus (si engine != grok) ---
      if ($engine !== 'grok') {

        $fightsPrelim = $this->decodeJson($responseContent, __METHOD__ . '::prelim');
        if (is_array($fightsPrelim) && !empty($fightsPrelim)) {
          $fightsToEnrich = array_filter($fightsPrelim, function ($fight) use ($wrestlerName) {
            if (!is_array($fight) || empty($fight)) return false;

            $resultat = mb_strtolower(trim((string) ($fight['resultat'] ?? '')));

            // On veut UNIQUEMENT les litiges et résultats vides/inconnus
            if ($resultat === '' || $resultat === '?') return true;
            if (str_contains($resultat, 'litige')) return true;

            return false;
          });

          if (!empty($fightsToEnrich)) {

            // ── PRÉ-ENRICHISSEMENT via titres YouTube (avant l'appel Grok classique) ─────
            foreach ($fightsToEnrich as $idx => $fight) {
              $l1   = $fight['lutteur1'] ?? '';
              $l2   = $fight['lutteur2'] ?? '';
              $date = $fight['date_precise'] ?? '';

              if ($l1 === '' || $l2 === '') {
                continue;
              }

              try {
                $titles = SomeTools::fetchYoutubeTitlesForFight($l1, $l2, $date);
              } catch (\Exception $e) {
                $this->logger->warning('getFightsFromAiRequest : échec YouTube (quota/token) pendant enrichissement @l1 vs @l2 pour @name — arrêt, retry demain.', [
                  '@l1'   => $l1,
                  '@l2'   => $l2,
                  '@name' => $wrestlerName,
                  '@msg'  => $e->getMessage(),
                ]);
                return [
                  'success' => false,
                  'fights'  => [],
                  'error'   => 'youtube_titles_empty',
                  'message' => 'Échec API YouTube pendant enrichissement (token/quota) — sera retenté.',
                ];
              }

              if (empty($titles)) {
                continue;
              }

              $titlesJson = json_encode($titles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

              $promptJsonYoutube =
                "Voici une liste de titres de vidéos YouTube trouvées pour le combat de lutte sénégalaise entre "
                . '"' . $l1 . '" et "' . $l2 . '"'
                . ($date ? " (date approximative : {$date})" : '') . " :\n\n"
                . $titlesJson . "\n\n"
                . "En te basant UNIQUEMENT sur ces titres (les titres YouTube annoncent souvent explicitement le "
                . "vainqueur : victoire, vs, défaite, bat, K.O, \"interview après sa victoire sur...\", etc.), déduis le "
                . "nom exact du vainqueur de ce combat.\n"
                . "Retourne UNIQUEMENT un objet JSON valide, sans texte ni markdown, au format :\n"
                . '{"lutteur1":"' . $l1 . '","lutteur2":"' . $l2 . '","vainqueur":"NOM_EXACT_OU_INCONNU"}' . "\n"
                . "Si aucun titre ne permet de déterminer clairement le vainqueur, mets \"vainqueur\":\"INCONNU\".";

              $responseYoutube = $this->liveSearchService->liveSearch($promptJsonYoutube, $engine, $mode);

              if ($responseYoutube['success'] ?? false) {
                $rawYt = trim($responseYoutube['content'] ?? '');
                $rawYt = preg_replace('/```(?:json)?(.*?)```/s', '$1', $rawYt);
                $startY = strpos($rawYt, '{');
                $endY   = strrpos($rawYt, '}');
                if ($startY !== false && $endY !== false && $endY > $startY) {
                  $rawYt = substr($rawYt, $startY, $endY - $startY + 1);
                }

                $decodedYt = $this->decodeJson(trim($rawYt), __METHOD__ . '::youtube_guess');
                $vainqueur = trim((string) ($decodedYt['vainqueur'] ?? ''));

                if ($vainqueur !== '' && mb_strtolower($vainqueur) !== 'inconnu') {
                  $fightsToEnrich[$idx]['resultat'] = $vainqueur;
                  $fightsPrelim[$idx]['resultat']   = $vainqueur; // répercuté sur le tableau source

                  $this->logger->info(
                    'Pré-enrichissement YouTube : vainqueur déduit pour @l1 vs @l2 → @v',
                    ['@l1' => $l1, '@l2' => $l2, '@v' => $vainqueur]
                  );
                }
              }
            } // ← fin foreach ($fightsToEnrich as $idx => $fight)

            // On ne garde pour l'enrichissement Grok que les combats encore non résolus
            $fightsToEnrich = array_filter($fightsToEnrich, function ($fight) {
              $resultat = mb_strtolower(trim((string) ($fight['resultat'] ?? '')));
              return $resultat === '' || $resultat === '?' || str_contains($resultat, 'litige');
            });

            if (!empty($fightsToEnrich)) {
              $fightsToEnrichJson = json_encode(
                array_values($fightsToEnrich),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
              );

              $this->logger->info(
                'getFightsFromAiRequest : @count combat(s) résultat invalide pour @name — enrichissement Grok via liveSearch.',
                ['@name' => $wrestlerName, '@count' => count($fightsToEnrich)]
              );

              $promptCompleted =
                "En tant qu'expert de la lutte sénégalaise, avec accès aux sites spécialisés "
                . "(luttetv.com, fr.allafrica.com, CNG Presse, YouTube Lutte TV / Kewal Info, archives Google et Youtube, commentaires spectateurs), "
                . "tu dois rechercher le verdict réel de chaque combat listé ci-dessous pour le lutteur @wrestler"
                . $synonymsLine
                . "Pour chaque combat, cherche ACTIVEMENT avec plusieurs stratégies :\n"
                . "- Toutes les variantes de nom du lutteur + nom de l'adversaire\n"
                . $this->getVictoryKeywordsLine() . "\n"
                . "- Titres de vidéos YouTube contenant \"Combat\", \"journée\", \"vainqueur\", \"victoire\", \"vs\", \"bat\"\n"
                . "- Vérifier les 15-20 premiers commentaires pour confirmation majoritaire\n"
                . "- Ignorer la date précise si besoin (chercher sur une fenêtre de ±3 mois autour de la date indiquée)\n\n"

                . "RÈGLE SPÉCIALE POUR LES COMBATS AVEC RÉSULTAT \"?\" :\n"
                . "Ces combats sont prioritaires et HYPER importants. Tu dois faire des recherches approfondies sur Google et YouTube pour déterminer le vainqueur réel. "
                . "Utilise toutes les combinaisons possibles (variantes de noms, dates approchantes, noms d’événements). "
                . "Ne laisse \"?\" que si vraiment aucune source fiable n'existe après recherches intensives.\n\n"

                . "Règles strictes :\n"
                . "- Retourne UNIQUEMENT un tableau JSON valide, sans aucun texte avant ou après, sans balises markdown.\n"
                . "- Le tableau doit contenir EXACTEMENT le même nombre d'objets dans le même ordre.\n"
                . "- Seul le champ \"resultat\" peut être modifié (nom exact du vainqueur comme \"@wrestler\", \"Nul\", ou \"Litige (sans verdict)\").\n"
                . "- Si tu trouves une source fiable (vidéo + titre + confirmation majoritaire dans les commentaires), mets le nom du vainqueur exact.\n"
                . "- Si le combat est contesté suite à un litige officiel (avec source), alors mets \"Litige (sans verdict)\".\n"
                . "- Pour les \"?\", fais des recherches poussées sur internet : si tu trouves un vainqueur clair → remplace la valeur du champ résultat par le nom du vainqueur. Sinon, laisse la valeur du champ résultat à :\"?\" exceptionnellement.\n"
                . "- Tous les autres champs restent inchangés.\n\n"
                . "Combats à enrichir :\n"
                . $fightsToEnrichJson;

              $promptCompleted = str_replace('@wrestler', $wrestlerName, $promptCompleted);
              $responseGrok = $this->liveSearchService->liveSearch($promptCompleted, 'grok', $mode, true);
              $fightsCompleted = [];
              if ($responseGrok['success'] ?? false) {
                $rawGrok = trim($responseGrok['content'] ?? '');
                $rawGrok = preg_replace('/```(?:json)?(.*?)```/s', '$1', $rawGrok);
                $startG  = strpos($rawGrok, '[');
                $endG    = strrpos($rawGrok, ']');
                if ($startG !== false && $endG !== false && $endG > $startG) {
                  $rawGrok = substr($rawGrok, $startG, $endG - $startG + 1);
                }
                $fightsCompleted = $this->decodeJson(trim($rawGrok), __METHOD__ . '::grok_enrich') ?? [];
              }

              if (!empty($fightsCompleted) && is_array($fightsCompleted)) {
                $originalKeys = array_keys($fightsToEnrich);

                foreach ($originalKeys as $i => $origKey) {
                  if (isset($fightsCompleted[$i]) && is_array($fightsCompleted[$i])) {
                    $newResultat = trim((string) ($fightsCompleted[$i]['resultat'] ?? ''));
                    if ($newResultat !== '') {
                      $fightsPrelim[$origKey]['resultat'] = $newResultat;
                    }
                  }
                }
                $this->logger->info(
                  'getFightsFromAiRequest : résultats Grok mergés pour @count combat(s) de @name.',
                  ['@name' => $wrestlerName, '@count' => count($fightsCompleted)]
                );
              }
            }

            // Réencodage avec les résultats enrichis (YouTube et/ou Grok) pour le decodeJson principal ci-dessous.
            $responseContent = json_encode(
              array_values($fightsPrelim),
              JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
          }
        }
      }

      // --- DECODAGE FINAL ---
      $this->logger->info('getFightsFromAiRequest decodeJson en cours pour @name...', ['@name' => $wrestlerName]);

      $fights = $this->decodeJson($responseContent, __METHOD__);

      $hasNewFormat = isset($fights[0]['dte'])
        && !isset($fights[0]['new'])
        && !isset($fights[0]['del'])
        && !isset($fights[0]['alter']);

      if ($hasNewFormat) {
        $fights = SomeTools::reverseJsonFormatFightsAthlete($fights, $wrestlerName);
      }

      if (!is_array($fights)) {
        $this->logger->warning('getFightsFromAiRequest decodeJson résultat non-array pour @name, valeur: @val', [
          '@name' => $wrestlerName,
          '@val'  => substr((string) $fights, 0, 300),
        ]);
        return ['success' => true, 'fights' => []];
      }

      // --- FILTRE DE SÉCURITÉ : rejette les combats qui ne concernent PAS le lutteur demandé ---
      // Empêche l'import de combats d'un autre lutteur (ex: homonymie/confusion IA lors
      // de la recherche web, cas observé avec "Aigle" → réponse contenant "Ada Fass").
      // S'applique quel que soit le format d'origine (compact reconverti ou complet natif).
      $wrestlerSynonyms   = SomeTools::getSynonyms($wrestlerName);
      $wrestlerSynonyms[] = $wrestlerName;
      $cleanTargets       = array_map(fn($s) => SomeTools::cleanForMatch($s), $wrestlerSynonyms);

      $fightsBeforeFilter = count($fights);
      $fights = array_values(array_filter($fights, function ($fight) use ($cleanTargets) {
        if (!is_array($fight)) return false;

        $l1 = SomeTools::cleanForMatch((string) ($fight['lutteur1'] ?? ''));
        $l2 = SomeTools::cleanForMatch((string) ($fight['lutteur2'] ?? ''));

        foreach ($cleanTargets as $target) {
          if ($target === '') continue;
          if (stripos($l1, $target) !== false || stripos($target, $l1) !== false) return true;
          if (stripos($l2, $target) !== false || stripos($target, $l2) !== false) return true;
        }
        return false;
      }));

      if (count($fights) < $fightsBeforeFilter) {
        $this->logger->warning('getFightsFromAiRequest : @count combat(s) rejeté(s) pour @name — aucun des deux lutteurs ne correspond au nom demandé (probable confusion IA/homonymie).', [
          '@count' => $fightsBeforeFilter - count($fights),
          '@name'  => $wrestlerName,
        ]);
      }
      // --- FIN FILTRE DE SÉCURITÉ ---

      // Filtrer les éléments parasites (true, null, non-array).
      $fights = array_values(array_filter($fights, fn($item) => is_array($item) && !empty($item)));

      $this->logger->info('getFightsFromAiRequest : @count combat(s) valides extraits pour @name.', [
        '@name'  => $wrestlerName,
        '@count' => count($fights),
      ]);

      return ['success' => true, 'fights' => $fights];
    } catch (\Exception $e) {
      $this->logger->error('getFightsFromAiRequest Erreur critique pour @name : @msg', [
        '@name' => $wrestlerName,
        '@msg'  => $e->getMessage(),
      ]);
      return [
        'success' => false,
        'error'   => 'exception',
        'message' => $e->getMessage(),
        'content' => '[]',
      ];
    }
  }

  public function getVideosFightsFromGemini(string $dateFight, string $wrestler1, string $wrestler2): array
  {
    static $staticCache = [];
    $cid = md5($dateFight . '|' . $wrestler1 . '|' . $wrestler2);
    if (isset($staticCache[$cid])) return $staticCache[$cid];

    try {
      $prompt   = "Find youtube videos for combat $wrestler1 vs $wrestler2 around $dateFight."
        . " But each video found SHOULD be tested and actually existing (not 404).";
      $response = $this->liveSearchService->liveSearch($prompt, 'gemini');

      $data = [];
      if ($response['success'] && !empty($response['content'])) {
        $data = $this->decodeJson($response['content'], __METHOD__) ?? [];
      }

      $staticCache[$cid] = $data;
      return $data;
    } catch (\Exception $e) {
      $this->logger->error('API error in getVideosFights: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }
  }
}
