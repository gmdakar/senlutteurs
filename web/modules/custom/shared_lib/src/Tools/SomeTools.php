<?php

namespace Drupal\shared_lib\Tools;

use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\Language\LanguageInterface;
use Symfony\Component\Console\Style\StyleInterface;

/**
 * Façade de compatibilité ascendante.
 *
 * Toute la logique métier qui vivait ici a été déplacée vers des services
 * dédiés sous Drupal\shared_lib\Service\ (voir shared_lib.services.yml) :
 *
 *  - TextNormalizationHelper           (shared_lib.text_normalization)
 *  - YoutubeSearchService               (shared_lib.youtube_search)
 *  - AthleteDeduplicationService        (shared_lib.athlete_deduplication)
 *  - ConfrontationDeduplicationService  (shared_lib.confrontation_deduplication)
 *  - AthleteMergeService                (shared_lib.athlete_merge)
 *
 * Cette classe ne fait plus que déléguer, méthode par méthode, vers le
 * service correspondant, afin qu'aucun des appelants existants
 * (SomeTools::xxx(...) dans les autres modules) n'ait besoin d'être modifié.
 *
 * Nouveau code : préférez injecter directement le service concerné plutôt
 * que de passer par cette façade statique.
 */
class SomeTools
{
    /**
     * Vérifie si deux noms de lutteurs sont suffisamment proches pour être considérés identiques.
     * Utilise normalizeForDistance + Levenshtein ≤ 2, avec garde-fou sur les suffixes numériques.
     */
    public static function fuzzyMatchName(string $nameA, string $nameB): bool
    {
        return \Drupal::service('shared_lib.text_normalization')->fuzzyMatchName($nameA, $nameB);
    }

    /**
     * Extrait proprement et de manière robuste un JSON perdu au milieu de texte brut.
     *
     * @param string $text
     *   Le texte brut concaténé issu de l'API.
     * @return string
     *   La chaîne JSON pure extraite.
     */
    public static function extractPureJson(string $text): string
    {
        return \Drupal::service('shared_lib.text_normalization')->extractPureJson($text);
    }


    public static function cleanFakeVideoUrls(?StyleInterface $io = null): void
    {
        \Drupal::service('shared_lib.confrontation_deduplication')->cleanFakeVideoUrls($io);
    }

    /**
     * Nettoie les doublons d'athlètes - Version DEBUG (logs console + dblog)
     */
    public static function cleanDuplicateAthletes(?StyleInterface $io = null): void
    {
        \Drupal::service('shared_lib.athlete_deduplication')->cleanDuplicateAthletes($io);
    }

    /**
     * Nettoie les doublons d'écuries, migre les athlètes et dépublie proprement.
     */
    public static function cleanDuplicatedEcuries(array $submittedGroups, ?StyleInterface $io = null): void
    {
        \Drupal::service('shared_lib.athlete_deduplication')->cleanDuplicatedEcuries($submittedGroups, $io);
    }

    /**
     * Recherche, pour un lutteur donné, si la confrontation fournie a un doublon,
     * et le supprime selon les règles établies.
     *
     * @param NodeInterface $confrontation    Nœud "confrontation" à analyser.
     * @param NodeInterface $lutteur_principal Nœud "athlete" dont on cherche les doublons de combat.
     * @return bool  true si un doublon combat a été trouvé et supprimé.
     */
    public static function findAndDeleteDuplicateCombat(NodeInterface $confrontation,
        NodeInterface $lutteur_principal): bool
    {
        return \Drupal::service('shared_lib.confrontation_deduplication')->findAndDeleteDuplicateCombat($confrontation, $lutteur_principal);
    }

    /**
     * Nettoie une chaîne pour la comparaison de texte (conserve les espaces).
     */
    public static function cleanForMatch(string $string): string
    {
        return \Drupal::service('shared_lib.text_normalization')->cleanForMatch($string);
    }

    /**
     * Normalise pour le matching (garde les espaces, retire les accents et ponctuation).
     */
    public static function normalizeForMatch(string $string): string
    {
        return \Drupal::service('shared_lib.text_normalization')->normalizeForMatch($string);
    }


    public static function internalYoutubeRequest(string $date,
        string $wrestler1,
        string $wrestler2,
        bool $latest): array
    {
        return \Drupal::service('shared_lib.youtube_search')->internalYoutubeRequest($date, $wrestler1, $wrestler2, $latest);
    }

    /**
     * Récupère les titres YouTube spécifiques à un combat entre 2 lutteurs.
     * Utilisé pour deviner le vainqueur à partir du titre des vidéos.
     *
     * @throws \RuntimeException si AUCUN appel API n'a abouti (quota/clé/réseau)
     *   — à distinguer d'un résultat légitimement vide.
     */
    public static function fetchYoutubeTitlesForFight(string $wrestler1, string $wrestler2, string $date = ''): array
    {
        return \Drupal::service('shared_lib.youtube_search')->fetchYoutubeTitlesForFight($wrestler1, $wrestler2, $date);
    }

    /**
     * Recherche sur YouTube les titres de vidéos associées à un lutteur donné.
     * Version optimisée, appel unique.
     */
    public static function fetchYoutubeTitlesForWrestler(string $wrestlerName): string
    {
        return \Drupal::service('shared_lib.youtube_search')->fetchYoutubeTitlesForWrestler($wrestlerName);
    }

    /**
     * Vérifie si le quota YouTube est toujours expiré ou disponible.
     * Youtube api retinitialise à 07:00 heure sn tous les jours
     */
    public static function isYoutubeQuotaAvailable()
    {
        return \Drupal::service('shared_lib.youtube_search')->isYoutubeQuotaAvailable();
    }


    public static function buildYoutubeQuery($syn1,
        $syn2,
        string $fightYear = ""): array
    {
        return \Drupal::service('shared_lib.youtube_search')->buildYoutubeQuery($syn1, $syn2, $fightYear);
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

    public static function filterResultsRaw($items,
        $syn1,
        $syn2,
        string $fightYear): array
    {
        return \Drupal::service('shared_lib.youtube_search')->filterResultsRaw($items, $syn1, $syn2, $fightYear);
    }


    public static function normalizeForSearch(string $string): string
    {
        return \Drupal::service('shared_lib.text_normalization')->normalizeForSearch($string);
    }


    public static function normalizeForDistance(string $s): string
    {
        return \Drupal::service('shared_lib.text_normalization')->normalizeForDistance($s);
    }


    public static function strictMatch(string $titleNorm, string $synonym): bool
    {
        return \Drupal::service('shared_lib.text_normalization')->strictMatch($titleNorm, $synonym);
    }

    /**
     * Calcul la distance entre 2 noms de lutteurs dans un titre de video 
     * (ex. Coly Faye 2 vs Cartouche), distance 1 ici (vs)
     */
    public static function namesDistanceInWords(string $titleNorm,
        array $norms1,
        array $norms2): int
    {
        return \Drupal::service('shared_lib.youtube_search')->namesDistanceInWords($titleNorm, $norms1, $norms2);
    }


    public static function urlsToUriArrayBase(?array $urls): array
    {
        return \Drupal::service('shared_lib.text_normalization')->urlsToUriArrayBase($urls);
    }


    public static function technicalWrestlerName(string $name): string
    {
        return \Drupal::service('shared_lib.text_normalization')->technicalWrestlerName($name);
    }


    public static function correctNames(string $texte): string
    {
        return \Drupal::service('shared_lib.text_normalization')->correctNames($texte);
    }


    public static function getSynonyms(string $name): array
    {
        return \Drupal::service('shared_lib.youtube_search')->getSynonyms($name);
    }


    public static function getYoutubeSettings(): array
    {
        return \Drupal::service('shared_lib.youtube_search')->getYoutubeSettings();
    }


    public static function hasDirectSeparator(string $titleNorm,
        array $norms1,
        array $norms2,
        array $separators): bool
    {
        return \Drupal::service('shared_lib.youtube_search')->hasDirectSeparator($titleNorm, $norms1, $norms2, $separators);
    }


    public static function isListLike(string $titleRaw): bool
    {
        return \Drupal::service('shared_lib.text_normalization')->isListLike($titleRaw);
    }


    public static function getVideoDurations(array $videoIds,
        string $apiKey): array
    {
        return \Drupal::service('shared_lib.youtube_search')->getVideoDurations($videoIds, $apiKey);
    }


    public static function sortAndSlice(array &$results,
        int $maxF2f = 4,
        int $maxCombat = 2): void
    {
        \Drupal::service('shared_lib.youtube_search')->sortAndSlice($results, $maxF2f, $maxCombat);
    }


    public static function searchYoutubeVideosBase(string $date,
        string $wrestler1,
        string $wrestler2,
        int $maxVideoCombatAgeDays = 0)
    {
        return \Drupal::service('shared_lib.youtube_search')->searchYoutubeVideosBase($date, $wrestler1, $wrestler2, $maxVideoCombatAgeDays);
    }


    public static function searchYoutubeVideosLatestsBase(string $wrestler1,
        string $wrestler2)
    {
        return \Drupal::service('shared_lib.youtube_search')->searchYoutubeVideosLatestsBase($wrestler1, $wrestler2);
    }


    public static function combatExists($fightId,
        $type,
        EntityTypeManagerInterface $etm,
        LoggerInterface $logger): mixed
    {
        return \Drupal::service('shared_lib.athlete_deduplication')->combatExists($fightId, $type, $etm, $logger);
    }

    /**
     * Wrapper locké autour de getOrCreateAthlete avec wait+retry.
     * Empêche la création d'athlètes en double lors de traitements parallèles.
     */
    public static function getOrCreateAthleteLocked($lock, string $name, ?string $ecurie)
    {
        return \Drupal::service('shared_lib.athlete_deduplication')->getOrCreateAthleteLocked($lock, $name, $ecurie);
    }

    /**
     * Get or create new Athlete (and Get or Create new Ecurie) node 
     */
    public static function getOrCreateAthlete(string &$name,
        EntityTypeManagerInterface $etm,
        LoggerInterface $logger,
        mixed &$ecurie = null): ?Node
    {
        return \Drupal::service('shared_lib.athlete_deduplication')->getOrCreateAthlete($name, $etm, $logger, $ecurie);
    }

    /** Clean orphan Ecuries related to NO athletes */
    public static function cleanOrphanEcuries(): void
    {
        \Drupal::service('shared_lib.athlete_deduplication')->cleanOrphanEcuries();
    }


    public static function cleanPhantomEcuries(): void
    {
        \Drupal::service('shared_lib.athlete_deduplication')->cleanPhantomEcuries();
    }


    public static function updateKingStatus(NodeInterface $winner,
        NodeInterface $loser): void
    {
        \Drupal::service('shared_lib.confrontation_deduplication')->updateKingStatus($winner, $loser);
    }

    /**
     * Remove all doublons sure and log only suspect not sure.
     *
     * Règle 1 : Même date + adversaire identique sauf suffixe numérique
     *           → garder le suffixe le plus élevé.
     * Règle 2 : Dates ≤ 1 an + adversaire Levenshtein = 1 (vraie coquille)
     *           → garder le combat au NID le plus ancien.
     * Règle 3 : Même adversaire + même verdict + ≤ 6 jours d'écart
     *           + field_is_lutte_simple = false
     *           → supprimer le combat avec la date la plus récente.
     */
    public static function getOrCleanPotentialsDuplicatedCombats(): void
    {
        \Drupal::service('shared_lib.confrontation_deduplication')->getOrCleanPotentialsDuplicatedCombats();
    }

    /**
     * Détecte les groupes de combats ambigus (même pivot, adversaire au nom
     * très proche - Levenshtein <= 2 -, dates à 7 jours d'écart maximum) et
     * les met en queue pour résolution IA. Voir
     * ConfrontationDeduplicationService::queueAmbiguousNearDateCombats().
     *
     * @return int Nombre de groupes mis en queue.
     */
    public static function queueAmbiguousNearDateCombats(): int
    {
        return \Drupal::service('shared_lib.confrontation_deduplication')->queueAmbiguousNearDateCombats();
    }

    /**
     * Met en queue les paires de combats cross-athlètes suspects (≤ $days j,
     * aucun NID commun, noms proches des deux côtés).
     *
     * @see \Drupal\shared_lib\Service\ConfrontationDeduplicationService::queueCrossAthleteNearDateCombats()
     *
     * @param int $days Fenêtre max en jours (défaut 7).
     *
     * @return int Nombre de paires mises en queue.
     */
    public static function queueCrossAthleteNearDateCombats(int $days = 7): int
    {
        return \Drupal::service('shared_lib.confrontation_deduplication')->queueCrossAthleteNearDateCombats($days);
    }

    /**
     * Indique si le N-uplet de combats donné (field_id_text) a déjà été
     * vérifié par l'IA avec pour résultat "tout conserver". Voir
     * ConfrontationDeduplicationService::isDoublonGroupVerifiedKept().
     *
     * @param string[] $fieldIdTexts
     */
    public static function isDoublonGroupVerifiedKept(array $fieldIdTexts): bool
    {
        return \Drupal::service('shared_lib.confrontation_deduplication')->isDoublonGroupVerifiedKept($fieldIdTexts);
    }

    /**
     * Enregistre définitivement le N-uplet de combats donné (field_id_text)
     * comme "vérifié IA, tout conservé". Voir
     * ConfrontationDeduplicationService::markDoublonGroupVerifiedKept().
     *
     * @param string[] $fieldIdTexts
     */
    public static function markDoublonGroupVerifiedKept(array $fieldIdTexts): void
    {
        \Drupal::service('shared_lib.confrontation_deduplication')->markDoublonGroupVerifiedKept($fieldIdTexts);
    }

    /**
     * Retire le N-uplet de combats donné (field_id_text) de la liste des
     * groupes "en attente" de traitement IA dans
     * confrontations_doublons_ai_queue. À appeler UNIQUEMENT une fois le
     * traitement d'un item terminé sans levée de SuspendQueueException
     * (voir ConfrontationsDoublonsAiQueue::processItem() et
     * ConfrontationDeduplicationService::clearDoublonGroupPending() pour
     * le détail des garanties).
     *
     * @param string[] $fieldIdTexts
     */
    public static function clearDoublonGroupPending(array $fieldIdTexts): void
    {
        \Drupal::service('shared_lib.confrontation_deduplication')->clearDoublonGroupPending($fieldIdTexts);
    }

    /**
     * Remet à FALSE field_check_is_lutte_simple sur les combats existants
     * des lutteurs donnés où il valait TRUE. Voir
     * ConfrontationDeduplicationService::resetLutteSimpleCheckForAthletes().
     *
     * @param int[] $athleteIds
     */
    public static function resetLutteSimpleCheckForAthletes(array $athleteIds, int $excludeNid = 0): void
    {
        \Drupal::service('shared_lib.confrontation_deduplication')->resetLutteSimpleCheckForAthletes($athleteIds, $excludeNid);
    }

    /**
     * Détecte les lutteurs ayant au moins 50% de leurs combats déjà marqués
     * field_is_lutte_simple = TRUE, et met en queue (lots de 3 combats max)
     * leurs combats pas encore marqués comme tels, pour résolution IA. Voir
     * ConfrontationDeduplicationService::queueSuspectedLutteSimpleCombats().
     *
     * @return int Nombre de lots (queue items) mis en queue.
     */
    public static function queueSuspectedLutteSimpleCombats(int $queueLimit = 0): int
    {
        return \Drupal::service('shared_lib.confrontation_deduplication')->queueSuspectedLutteSimpleCombats($queueLimit);
    }

    /**
     * Enregistre un alias de nom de lutteur dans le dictionnaire de
     * synonymes de misc_divers.settings, utilisé pour la normalisation de
     * texte ("remplacements") et la recherche YouTube ("remplacementsYtb").
     *
     * - "remplacements" : tableau clé => valeur, clé = nom incorrect/à
     *   remplacer, valeur = nom correct. On y ajoute/écrase l'entrée
     *   [$deletedName => $keptName] uniquement si elle n'existe pas déjà à
     *   l'identique (pas de doublon exact).
     * - "remplacementsYtb" : tableau clé => valeur, clé = nom officiel,
     *   valeur = liste de synonymes séparés par virgule. On y ajoute
     *   $deletedName à la liste des synonymes de $keptName s'il n'y est pas
     *   déjà (recherche insensible à la casse, quelle que soit sa position
     *   dans la liste).
     *
     * Les deux tableaux sont triés par clé (ordre alphabétique) avant
     * sauvegarde.
     */
    public static function registerWrestlerNameReplacement(string $deletedName, string $keptName): void
    {
        $deletedName = trim($deletedName);
        $keptName    = trim($keptName);

        if ($deletedName === '' || $keptName === '' || mb_strtolower($deletedName) === mb_strtolower($keptName)) {
            return;
        }

        $config = \Drupal::configFactory()->getEditable('misc_divers.settings');

        // --- remplacements (clé = nom incorrect, valeur = nom correct) ---
        $remplacements = $config->get('remplacements');
        $remplacements = is_array($remplacements) ? $remplacements : [];

        if (!isset($remplacements[$deletedName]) || $remplacements[$deletedName] !== $keptName) {
            $remplacements[$deletedName] = $keptName;
        }
        ksort($remplacements, SORT_NATURAL | SORT_FLAG_CASE);

        // --- remplacementsYtb (clé = nom officiel, valeur = synonymes CSV) ---
        $remplacementsYtb = $config->get('remplacementsYtb');
        $remplacementsYtb = is_array($remplacementsYtb) ? $remplacementsYtb : [];

        $existingValue = isset($remplacementsYtb[$keptName]) ? (string) $remplacementsYtb[$keptName] : '';
        $existingList  = array_values(array_filter(array_map('trim', explode(',', $existingValue)), fn($v) => $v !== ''));
        $existingListLower = array_map('mb_strtolower', $existingList);

        if (!in_array(mb_strtolower($deletedName), $existingListLower, true)) {
            $existingList[] = $deletedName;
            $remplacementsYtb[$keptName] = implode(',', $existingList);
        }
        ksort($remplacementsYtb, SORT_NATURAL | SORT_FLAG_CASE);

        $config
            ->set('remplacements', $remplacements)
            ->set('remplacementsYtb', $remplacementsYtb)
            ->save();

        \Drupal::service('logger.factory')->get('shared_lib')->info(
            'Dictionnaire synonymes mis à jour : remplacements["@del"] = "@keep" ; remplacementsYtb["@keep"] += "@del".',
            ['@del' => $deletedName, '@keep' => $keptName]
        );
    }

    /**
     * Identifie et supprime les doublons basés sur le champ technique field_id_text.
     *
     * Cette méthode sécurise la base de données avant l'application de contraintes 
     * d'unicité SQL. Elle identifie les valeurs de 'field_id_text' présentes plusieurs 
     * fois, conserve le NID le plus ancien comme pivot et supprime les autres.
     *
     * @return array
     *   Un tableau de chaînes de caractères détaillant les pivots conservés et 
     *   les nœuds supprimés pour le reporting Drush.
     *
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     * @throws \Drupal\Core\Entity\EntityStorageException
     */
    public static function forceCleanTechnicalDoublons()
    {
        return \Drupal::service('shared_lib.confrontation_deduplication')->forceCleanTechnicalDoublons();
    }

    /**
     * Nettoie le markdown éventuel, décode le JSON et log en critical si invalide.
     *
     * @param string $raw     La chaîne brute retournée par l'IA.
     * @param string $context Nom de la méthode appelante pour identifier l'origine dans les logs.
     *
     * @return array|null     Tableau PHP décodé, ou null si JSON invalide.
     */
    public static function decodeJson(string $raw,
        string $context = "inconnu"): ?array
    {
        return \Drupal::service('shared_lib.text_normalization')->decodeJson($raw, $context);
    }

    /**
     * Tente de réparer un JSON array tronqué en supprimant l'objet incomplet final.
     * Ex: [...}, {"date":"2025-11-30","lutteur1":"Fils De   →  [...]
     */
    public static function repairTruncatedJson(string $raw): string
    {
        return \Drupal::service('shared_lib.text_normalization')->repairTruncatedJson($raw);
    }

    /**
     * Convertit le nouveau format compact json vers l'ancien format ddes combats d'un lutteur.
     */
    public static function reverseJsonFormatFightsAthlete(array $new_data, string $lutteur_name): array
    {
        return \Drupal::service('shared_lib.text_normalization')->reverseJsonFormatFightsAthlete($new_data, $lutteur_name);
    }

    /**
     * Point d'entrée principal.
     *
     * @param int                 $pivotNid  NID du nœud pivot.
     * @param StyleInterface|null $io        Console Drush (optionnel).
     *
     * @return array|null
     *   ['combats_transferred', 'avenir_transferred', 'deleted']
     *   ou NULL en cas d'erreur bloquante.
     */
    public static function mergeFromPivot(int $pivotNid, ?StyleInterface $io = NULL): ?array
    {
        return \Drupal::service('shared_lib.athlete_merge')->mergeFromPivot($pivotNid, $io);
    }

    /**
     * Nettoie les doublons de type "a_venir" : même paire de lutteurs, seule la date change.
     * Garde le node avec la date la PLUS RÉCENTE.
     * Transfère uniquement field_avenir_face2face et field_no_index.
     */
    public static function cleanDuplicateAvenir(?StyleInterface $io = null): void
    {
        \Drupal::service('shared_lib.confrontation_deduplication')->cleanDuplicateAvenir($io);
    }

    /**
     * Résout la valeur textuelle du verdict à partir d'une réponse brute IA.
     * Reprend la logique historique de ConfrontationsRecentesQueue::processItem().
     *
     * @return string|null
     *   '?' si toujours inconnu, le label du vainqueur, 'Nul', 'Litige (sans verdict)',
     *   ou NULL si la chaîne est ininterprétable (aucun lutteur reconnu dedans).
     */
    public static function resolveVerdictValue(string $verdictRaw, string $labelLutteur1, string $labelLutteur2): ?string
    {
        return \Drupal::service('shared_lib.text_normalization')->resolveVerdictValue($verdictRaw, $labelLutteur1, $labelLutteur2);
    }

    /**
     * Vérifie si une confrontation "quasi-identique" existe déjà (même paire
     * d'athlètes, PEU IMPORTE L'ORDRE dans field_athlete_1/field_athlete_2,
     * écart de date ≤ 1 an) et applique les règles de résolution de doublon
     * SANS jamais insérer la nouvelle confrontation.
     *
     * @return bool
     *   TRUE si un doublon a été détecté (la nouvelle confrontation ne doit
     *   PAS être créée), FALSE sinon (on peut insérer normalement).
     */
    /**
     * Calcule une base de recherche compacte (sans suffixe numérique/junior/jr,
     * sans espaces) utilisée pour repérer des variantes proches d'un nom.
     */
    public static function compactSearchBase(string $name): string
    {
        return \Drupal::service('shared_lib.text_normalization')->compactSearchBase($name);
    }

    /**
     * Suggère des variantes de noms de lutteurs proches déjà présents en base.
     */
    public static function findSimilarAthleteNames(string $name, int $limit = 6): array
    {
        return \Drupal::service('shared_lib.athlete_deduplication')->findSimilarAthleteNames($name, $limit);
    }

    /**
     * Détecte les lutteurs "d'origine" ayant au moins un homonyme
     * générationnel en base, avec un trou d'au moins 5 ans dans leur
     * carrière, et met en queue les combats suspects pour vérification IA.
     */
    public static function queueSuspectedGenerationalHomonyms(int $queueLimit = 0): int
    {
        return \Drupal::service('shared_lib.athlete_deduplication')->queueSuspectedGenerationalHomonyms($queueLimit);
    }

    /**
     * Récupère les derniers combats connus d'un lutteur (historique compact JSON-able).
     */
    public static function getRecentConfrontationsJson(string $wrestlerName, int $limit = 2): array
    {
        return \Drupal::service('shared_lib.confrontation_deduplication')->getRecentConfrontationsJson($wrestlerName, $limit);
    }

    public static function checkNearDuplicateConfrontation(int $athlete1Id,
        int $athlete2Id,
        string $newDateStr,
        string $newVerdict,
        $storage,
        $logger): bool
    {
        return \Drupal::service('shared_lib.confrontation_deduplication')->checkNearDuplicateConfrontation($athlete1Id, $athlete2Id, $newDateStr, $newVerdict, $storage, $logger);
    }
    /**
     * Met en queue les groupes a_venir ambigus (même date + protagoniste commun).
     *
     * @return int Nombre de groupes mis en queue.
     */
    public static function queueAmbiguousAvenirSameDateCombats(): int
    {
        return \Drupal::service('shared_lib.confrontation_deduplication')->queueAmbiguousAvenirSameDateCombats();
    }

    /**
     * @param string[] $fieldIdTexts
     */
    public static function clearAvenirDoublonGroupPending(array $fieldIdTexts): void
    {
        \Drupal::service('shared_lib.confrontation_deduplication')->clearAvenirDoublonGroupPending($fieldIdTexts);
    }

    /**
     * Garde-fou insertion a_venir : même date + un lutteur en commun.
     */
    public static function findExistingAvenirSameDateSharedWrestler(int $athlete1Id, int $athlete2Id, string $dateYmd): ?int
    {
        return \Drupal::service('shared_lib.confrontation_deduplication')->findExistingAvenirSameDateSharedWrestler($athlete1Id, $athlete2Id, $dateYmd);
    }


}
