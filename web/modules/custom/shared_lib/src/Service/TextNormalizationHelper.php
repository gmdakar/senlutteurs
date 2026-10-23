<?php

namespace Drupal\shared_lib\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Utilitaires purs de normalisation de texte / JSON pour le domaine lutte.
 *
 * Aucune dépendance vers les entités Drupal : ce service peut être appelé
 * depuis n'importe quel autre service du module sans risque de dépendance
 * circulaire.
 *
 * Issu du découpage de l'ancienne classe statique
 * \Drupal\shared_lib\Tools\SomeTools (voir SomeTools.php, conservée comme
 * façade de compatibilité — voir shared_lib.services.yml).
 */
class TextNormalizationHelper {

  protected ConfigFactoryInterface $configFactory;

  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->configFactory = $configFactory;
  }

    /**
     * Vérifie si deux noms de lutteurs sont suffisamment proches pour être considérés identiques.
     * Utilise normalizeForDistance + Levenshtein ≤ 2, avec garde-fou sur les suffixes numériques.
     */
    public function fuzzyMatchName(string $nameA, string $nameB): bool
    {
        if ($nameA === '' || $nameB === '') {
            return false;
        }

        // Comparaison exacte d'abord (après normalisation déjà appliquée par l'appelant)
        if ($nameA === $nameB) {
            return true;
        }

        // Normalisation pour la distance (retire accents, ponctuation, met en minuscule)
        $normA = $this->normalizeForDistance($nameA);
        $normB = $this->normalizeForDistance($nameB);

        if ($normA === $normB) {
            return true;
        }

        // Garde-fou : si les bases sont identiques mais les suffixes numériques différents
        // → ce sont deux lutteurs distincts (ex: "pape yade" vs "pape yade 2")
        $baseA = $this->stripTrailingNumber($normA);
        $baseB = $this->stripTrailingNumber($normB);
        $numA  = $this->extractTrailingNumber($normA);
        $numB  = $this->extractTrailingNumber($normB);

        if ($baseA === $baseB && $baseA !== '' && $numA !== $numB) {
            return false;
        }

        // Levenshtein ≤ 2 sur les noms normalisés
        return levenshtein($normA, $normB) <= 2;
    }

    /**
     * Extrait proprement et de manière robuste un JSON perdu au milieu de texte brut.
     *
     * @param string $text
     *   Le texte brut concaténé issu de l'API.
     * @return string
     *   La chaîne JSON pure extraite.
     */
    public function extractPureJson(string $text): string
    {
        $text = trim($text);

        // 1. Tenter d'isoler un bloc de code Markdown contenant le JSON
        // Le flag /u impose une interprétation UTF-8 des sous-groupes capturés,
        // et évite de couper un caractère multi-octets en plein milieu.
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/isu', $text, $matches)) {
            return trim($matches[1]);
        }

        // 2. Si pas de bloc Markdown, on cherche le premier [ ou {
        // et le dernier ] ou } pour isoler le JSON brut.
        // IMPORTANT : mb_strpos/mb_strrpos/mb_substr (et non les versions
        // octet-safe strpos/strrpos/substr) pour ne jamais couper un
        // caractère UTF-8 multi-octets (accents, apostrophes typographiques,
        // très fréquents dans les noms de lutteurs) — cause du "Malformed
        // UTF-8 characters" observé lors du json_decode final.
        $first_bracket = mb_strpos($text, '[');
        $first_brace   = mb_strpos($text, '{');

        $start_pos = false;
        $end_char  = '';

        if ($first_bracket !== false && ($first_brace === false || $first_bracket < $first_brace)) {
            $start_pos = $first_bracket;
            $end_char  = ']';
        } elseif ($first_brace !== false) {
            $start_pos = $first_brace;
            $end_char  = '}';
        }

        if ($start_pos !== false) {
            $end_pos = mb_strrpos($text, $end_char);
            if ($end_pos !== false && $end_pos > $start_pos) {
                return mb_substr($text, $start_pos, $end_pos - $start_pos + 1);
            }
        }

        return $text;
    }

    /**
     * Nettoie une chaîne pour la comparaison de texte (conserve les espaces).
     */
    public function cleanForMatch(string $string): string
    {
        $s = mb_strtolower(trim($string));
        // Supprime les accents
        $s = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $s) ?: $s;
        // Remplace la ponctuation et caractères spéciaux par des espaces
        $s = preg_replace("/[^a-z0-9]/", " ", $s);
        // Nettoie les espaces multiples
        return trim(preg_replace("/\s+/", " ", $s));
    }

    /**
     * Normalise pour le matching (garde les espaces, retire les accents et ponctuation).
     */
    public function normalizeForMatch(string $string): string
    {
        $s = mb_strtolower(trim($string));
        // Supprime les accents proprement
        $s = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $s) ?: $s;
        // Remplace tout ce qui n'est pas alphanumérique par un espace
        $s = preg_replace("/[^a-z0-9]/", " ", $s);
        // Nettoie les espaces multiples pour un matching propre
        return trim(preg_replace("/\s+/", " ", $s));
    }

    public function normalizeForSearch(string $string): string
    {
        $string = mb_strtolower($string ?? "");
        $string = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $string) ?: $string;
        $string = str_replace(["junior", "jr", "n2"], "2", $string);
        $string = preg_replace("/\bii\b/i", "2", $string);
        $string = preg_replace("/\bi\b/i", "1", $string);
        $string = preg_replace("/[-–—·•_]/u", " ", $string);
        $string = preg_replace("/[^a-z0-9\s]/", " ", $string);
        $string = preg_replace("/\s+/", " ", $string);
        return trim($string);
    }

    public function normalizeForDistance(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $s) ?: $s;
        $s = preg_replace("/[^a-z0-9\s]/", "", $s);
        return trim($s);
    }

    public function strictMatch(string $titleNorm, string $synonym): bool
    {
        $synonym = trim($synonym);
        if ($synonym === "") {
            return false;
        }
        $hasGeneration = preg_match('/\b(1|2|3)\b$/', $synonym);
        $escapedSyn = preg_quote($synonym, "/");
        $pattern = $hasGeneration
            ? "/\b" . $escapedSyn . "\b/i"
            : "/\b" . $escapedSyn . "\b(?!\s*[23]\b)/i";
        return (bool) preg_match($pattern, $titleNorm);
    }

    public function urlsToUriArrayBase(?array $urls): array
    {
        if (empty($urls) || !is_array($urls)) {
            return [];
        }

        $cleanUrls = [];
        foreach ($urls as $u) {
            // Si c'est déjà un tableau (ex: format Drupal ['uri' => 'val']), on extrait la string
            if (is_array($u) && isset($u["uri"])) {
                $u = $u["uri"];
            }

            // On ne traite que si c'est une chaîne non vide
            if (is_string($u) && trim($u) !== "") {
                $cleanUrls[] = ["uri" => trim($u)];
            }
        }

        return $cleanUrls;
    }

    public function technicalWrestlerName(string $name): string
    {
        $s = trim(mb_strtolower($name));
        $s = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $s) ?: $s;
        $s = preg_replace("/[^a-z0-9]/", "", $s);
        return $s;
    }

    public function correctNames(string $texte): string
    {
        // 1. Remplacer les underscores par des espaces pour séparer les mots
        $texte = str_replace("_", " ", $texte);

        // 2. Appliquer vos remplacements personnalisés (ex: "id" -> "identifiant")
        $remplacements = $this->getReplacements();
        $texte = str_ireplace(
            array_keys($remplacements),
            array_values($remplacements),
            $texte
        );

        // 3. Tout mettre en minuscule, puis capitaliser chaque mot
        // ucwords() met une majuscule à chaque début de mot après un espace
        return ucwords(strtolower($texte));
    }

    protected function getReplacements(): array
    {
        $config = $this->configFactory->get("misc_divers.settings");
        $replacements = $config->get("remplacements");
        return is_array($replacements) && !empty($replacements)
            ? $replacements
            : [];
    }

    public function isListLike(string $titleRaw): bool
    {
        $commaCount = substr_count($titleRaw, ",");
        return $commaCount >= 2;
    }

    /**
     * Extrait le suffixe numérique final d'un nom normalisé.
     * Ex: "papayade2" → 2,  "papayade" → 0,  "papayade12" → 12
     */
    public function extractTrailingNumber(string $normalizedName): int
    {
        if (preg_match('/(\d+)$/', $normalizedName, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * Extrait le suffixe numérique du segment "nom_lutteur" dans un field_id_text.
     * Pattern attendu : nomL1_nomL2_yyyymmdd
     * Ex: "bousiteu_papeyade2_20210403" → on isole "papeyade2" → suffix = 2
     *     "bousiteu_papeyade_20210403"  → on isole "papeyade"  → suffix = 0
     */
    public function parseIdTextParts(string $idText): ?array
    {
        // Pattern : seg1_seg2_date(8 chiffres)
        if (!preg_match('/^(.+)_(.+)_(\d{8})$/', $idText, $m)) {
            return null;
        }
        return [
            'seg1' => $m[1],
            'seg2' => $m[2],
            'date' => $m[3],
        ];
    }

    /**
     * Retourne la base sans suffixe numérique final.
     * Ex: "papayade2" → "papayade",  "papayade" → "papayade"
     */
    public function stripTrailingNumber(string $normalizedName): string
    {
        return rtrim(preg_replace('/\s*\d+$/', '', $normalizedName));
    }

    /**
     * Calcule une "base de recherche" compacte pour un nom de lutteur, utilisée
     * pour repérer des variantes proches en base (ex: suggestions avant insertion
     * d'une confrontation).
     *
     * - normalise (accents, casse, ponctuation)
     * - convertit les suffixes générationnels ("junior", "jr", "n2", "ii", "i") en chiffres
     * - retire le suffixe numérique final ("2", "3"...)
     * - supprime les espaces, pour comparer indépendamment de l'espacement
     *   (ex: "Sa Thies 3" et "Sathies2" donnent tous deux "sathies")
     *
     * Ex: "Sa thies 2" → "sathies", "Sathiers Junior" → "sathiers"
     */
    public function compactSearchBase(string $name): string
    {
        $base = $this->normalizeForSearch($name);
        $base = $this->stripTrailingNumber($base);
        return str_replace(' ', '', $base);
    }

    /**
     * Nettoie le markdown éventuel, décode le JSON et log en critical si invalide.
     *
     * @param string $raw     La chaîne brute retournée par l'IA.
     * @param string $context Nom de la méthode appelante pour identifier l'origine dans les logs.
     *
     * @return array|null     Tableau PHP décodé, ou null si JSON invalide.
     */
    public function decodeJson(
        string $raw,
        string $context = "inconnu"
    ): ?array {
        $logger = \Drupal::logger("shared_lib");

        // --- NETTOYAGE MARKDOWN ---
        // L'IA répond parfois : "Voici le json : ```json { ... } ```"
        $cleaned = preg_replace("/^```json\s*/i", "", trim($raw));
        $cleaned = preg_replace('/\s*```$/', "", trim($cleaned));

        $decoded = json_decode($cleaned, true);
        $logger->debug('decodeJson en cours...');
        if ($decoded === null) {
            $logger->warning(
                "JSON invalide [@context] (json_decode a échoué). " .
                    "Erreur JSON : @error. Contenu brut reçu : <pre>@raw</pre>",
                [
                    "@context" => $context,
                    "@error" => json_last_error_msg(),
                    "@raw" => $raw,
                ]
            );
            return null;
        }

        return $decoded;
    }

    /**
     * Tente de réparer un JSON array tronqué en supprimant l'objet incomplet final.
     * Ex: [...}, {"date":"2025-11-30","lutteur1":"Fils De   →  [...]
     */
    public function repairTruncatedJson(string $raw): string
    {
        $raw = trim($raw);

        // Déjà valide → rien à faire
        if (json_decode($raw) !== null) {
            return $raw;
        }

        // On cherche la position du dernier objet complet (dernière accolade fermante })
        // suivie uniquement d'espaces/virgules/crochets jusqu'à la fin.
        // Stratégie : on remonte depuis la fin pour trouver le dernier '}' valide.
        $lastClose = strrpos($raw, '}');
        if ($lastClose === false) {
            return $raw; // Rien à faire, pas récupérable
        }

        // On reconstruit : tout jusqu'au dernier '}' + fermeture du tableau
        $repaired = substr($raw, 0, $lastClose + 1) . ']';

        // Vérification que la réparation est valide
        if (json_decode($repaired) !== null) {
            \Drupal::logger('shared_lib')->warning(
                'repairTruncatedJson : JSON tronqué réparé (@lost chars perdus).',
                ['@lost' => strlen($raw) - strlen($repaired)]
            );
            return $repaired;
        }

        return $raw; // Réparation échouée, on laisse decodeJson gérer l'erreur
    }

    /**
     * Convertit le nouveau format compact json vers l'ancien format ddes combats d'un lutteur.
     */
    public function reverseJsonFormatFightsAthlete(array $new_data, string $lutteur_name): array
    {
        $result = [];

        foreach ($new_data as $item) {
            $res = $item['res'] ?? '';

            if ($res === 'piv') {
                $resultat = $lutteur_name;
            } elseif ($res === 'adv') {
                $parts    = explode(' / ', $item['adv'], 2);
                $resultat = trim($parts[0]);
            } else {
                $resultat = $res;
            }

            $result[] = [
                'date_precise' => $item['dte'],
                'lutteur1'     => $lutteur_name,
                'lutteur2'     => $item['adv'],
                'resultat'     => $resultat,
                'is_lutte_simple'    => $item['ls'],
                'face2face'    => '',
                'video_combat' => '',
            ];
        }

        return $result;
    }

    /**
     * Résout la valeur textuelle du verdict à partir d'une réponse brute IA.
     * Reprend la logique historique de ConfrontationsRecentesQueue::processItem().
     *
     * @return string|null
     *   '?' si toujours inconnu, le label du vainqueur, 'Nul', 'Litige (sans verdict)',
     *   ou NULL si la chaîne est ininterprétable (aucun lutteur reconnu dedans).
     */
    public function resolveVerdictValue(string $verdictRaw, string $labelLutteur1, string $labelLutteur2): ?string
    {
        $verdictRaw = trim($verdictRaw);
        if ($verdictRaw === '') {
            return null;
        }
        if ($verdictRaw === '?') {
            return '?';
        }

        $cleanVerdict = $this->cleanForMatch($verdictRaw);
        $cleanName1   = $this->cleanForMatch($labelLutteur1);
        $cleanName2   = $this->cleanForMatch($labelLutteur2);

        if ($cleanVerdict === 'nul' || str_contains($cleanVerdict, 'match nul')) {
            return 'Nul';
        }
        if (str_contains($cleanVerdict, 'litige') || str_contains($cleanVerdict, 'sans verdict')) {
            return 'Litige (sans verdict)';
        }
        if (stripos($cleanVerdict, $cleanName1) !== FALSE) {
            return $labelLutteur1;
        }
        if (stripos($cleanVerdict, $cleanName2) !== FALSE) {
            return $labelLutteur2;
        }

        return null;
    }

}
