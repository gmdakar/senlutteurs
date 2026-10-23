<?php

namespace Drupal\shared_lib\Service;

use Drupal\node\NodeInterface;
use Symfony\Component\Console\Style\StyleInterface;

/**
 * Détection et suppression des combats (confrontations / a_venir) en
 * doublon, ainsi que la mise à jour du statut "roi des arènes".
 *
 * Contient les règles 1 à 4 de dédoublonnage des confrontations et la
 * vérification anti-doublon utilisée à l'insertion
 * (checkNearDuplicateConfrontation), appelée aussi bien depuis les imports
 * que depuis AthleteMergeService lors d'une fusion de jumeaux.
 *
 * Issu du découpage de l'ancienne classe statique
 * \Drupal\shared_lib\Tools\SomeTools (voir SomeTools.php, conservée comme
 * façade de compatibilité — voir shared_lib.services.yml).
 */
class ConfrontationDeduplicationService
{

    /**
     * Nombre minimal de combats connus pour un lutteur pour que le ratio
     * "lutte simple" (cf. queueSuspectedLutteSimpleCombats()) soit jugé
     * significatif.
     */
    const LUTTE_SIMPLE_MIN_COMBATS = 2;

    /**
     * Nombre maximal de combats envoyés à l'IA en un seul lot (prompt), pour
     * un même lutteur, dans queueSuspectedLutteSimpleCombats().
     */
    const LUTTE_SIMPLE_BATCH_SIZE = 3;

    protected TextNormalizationHelper $textNormalizationHelper;

    public function __construct(TextNormalizationHelper $textNormalizationHelper)
    {
        $this->textNormalizationHelper = $textNormalizationHelper;
    }

    /**
     * Récupère les $limit dernières confrontations connues d'un lutteur
     * (identifié par son nom), sous forme de tableau simple exploitable
     * directement en JSON pour un prompt IA.
     *
     * Si le lutteur n'existe pas encore en base (nouveau lutteur), retourne
     * un tableau vide — ce n'est pas une erreur.
     *
     * @param string $wrestlerName Nom brut du lutteur (ex: "Sa thies 2").
     * @param int    $limit        Nombre max de combats à remonter (défaut 2).
     *
     * @return array<int, array{date: ?string, adversaire: string, resultat: ?string}>
     */
    public function getRecentConfrontationsJson(string $wrestlerName, int $limit = 2): array
    {
        $storage = \Drupal::entityTypeManager()->getStorage('node');

        $cleanName = $this->textNormalizationHelper->technicalWrestlerName(
            $this->textNormalizationHelper->correctNames($wrestlerName)
        );

        $anids = $storage->getQuery()->accessCheck(false)
            ->condition('type', 'athlete')
            ->condition('field_athlete_nom_technique', $cleanName)
            ->execute();

        if (empty($anids)) {
            // Lutteur inconnu en base : pas d'historique, ce n'est pas anormal.
            return [];
        }

        $athleteId = (int) reset($anids);

        $query = $storage->getQuery()->accessCheck(false)
            ->condition('type', 'confrontation');

        $orGroup = $query->orConditionGroup()
            ->condition('field_athlete_1', $athleteId)
            ->condition('field_athlete_2', $athleteId);

        $nids = $query->condition($orGroup)
            ->sort('field_confrontation_date', 'DESC')
            ->range(0, $limit)
            ->execute();

        if (empty($nids)) {
            return [];
        }

        $result = [];
        foreach ($storage->loadMultiple($nids) as $node) {
            $a1 = $node->hasField('field_athlete_1') ? $node->get('field_athlete_1')->entity : null;
            $a2 = $node->hasField('field_athlete_2') ? $node->get('field_athlete_2')->entity : null;

            $isA1Self  = $a1 && (int) $a1->id() === $athleteId;
            $opponent  = $isA1Self ? $a2 : $a1;

            $result[] = [
                'date'       => $node->hasField('field_confrontation_date') ? $node->get('field_confrontation_date')->value : null,
                'adversaire' => $opponent ? (string) $opponent->label() : '',
                'resultat'   => $node->hasField('field_vainqueur') ? $node->get('field_vainqueur')->value : null,
            ];
        }

        return $result;
    }

    /**
     * Extrait l'identifiant YouTube d'un item de champ vidéo (uri/url/value).
     * Retourne une chaîne vide si aucun ID exploitable n'est trouvé.
     *
     * Factorisé depuis cleanFakeVideoUrls() afin d'être réutilisé par
     * getValidVideoUrls() (exposition au prompt IA de dédoublonnage des
     * combats — voir ConfrontationsDoublonsAiQueue::buildPrompt()).
     */
    private function extractVideoId(array $item): string
    {
        $url = $item['uri'] ?? $item['url'] ?? $item['value'] ?? '';

        if (empty($url)) {
            return '';
        }

        $video_id = '';

        if (preg_match('/[?&]v=([^"&?\/ ]+)/i', $url, $matches)) {
            $video_id = $matches[1];
        } elseif (preg_match('/\/(?:shorts|embed)\/([^"&?\/ ]+)/i', $url, $matches)) {
            $video_id = $matches[1];
        } elseif (preg_match('/youtu\.be\/([^"&?\/ ]+)/i', $url, $matches)) {
            $video_id = $matches[1];
        } elseif (preg_match('/^youtube:\/\/([^"&?\/ ]+)/i', $url, $matches)) {
            $video_id = $matches[1];
        } elseif (preg_match('/^([A-Za-z0-9_-]{11})$/', trim($url), $matches)) {
            $video_id = $matches[1];
        }

        return $video_id;
    }

    /**
     * Détermine si un item de champ vidéo porte un ID YouTube plausible
     * (par opposition à un placeholder d'import : "ID_xxx", "eX_Y_Z_ID_N",
     * ID tout en majuscules/chiffres, ID répétitif, etc.).
     *
     * Factorisé depuis cleanFakeVideoUrls() — voir extractVideoId().
     */
    private function isValidVideoItem(array $item): bool
    {
        $video_id = $this->extractVideoId($item);

        if (empty($video_id)) {
            return false;
        }

        if (strpos($video_id, 'ID_') === 0) {
            return false;
        }

        // AJOUT : IDs tout en majuscules + chiffres sans minuscule ni _ ni - = suspect
        if (preg_match('/^[A-Z0-9]{11}$/', $video_id)) {
            return false;
        }

        // Placeholder d'import : format eX_Y_Z_ID_N ou variantes
        if (preg_match('/^[a-z][A-Z]_[A-Z]_[A-Z]_ID_\d+$/i', $video_id)) {
            return false;
        }

        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $video_id)) {
            return false;
        }

        if (preg_match('/^\d{11}$/', $video_id)) {
            return false;
        }

        if (preg_match('/^(.{2,4})\1{2,}/', $video_id)) {
            return false;
        }

        if (count(array_unique(str_split($video_id))) < 4) {
            return false;
        }

        return true;
    }

    /**
     * Retourne les URLs vidéo de field_video_du_combat jugées valides
     * (filtrage anti-placeholder via isValidVideoItem()), pour exposition
     * au prompt IA de résolution des doublons de combats ambigus via le
     * placeholder @videos_combat (map field_id_text => URLs, construite
     * dans queueAmbiguousNearDateCombats() au même niveau que
     * @ecuries_adversaires / @nb_combats_adversaires — voir
     * ConfrontationsDoublonsAiQueue::buildPrompt()).
     *
     * Une vidéo présente ici constitue, pour l'IA, une preuve forte
     * d'existence physique du combat (Principe cardinal 7 du prompt) :
     * on exclut donc volontairement les faux IDs de scraping pour ne pas
     * biaiser artificiellement la conservation d'un combat fictif.
     */
    public function getValidVideoUrls(NodeInterface $node): array
    {
        if (!$node->hasField('field_video_du_combat') || $node->get('field_video_du_combat')->isEmpty()) {
            return [];
        }

        $urls = [];
        foreach ($node->get('field_video_du_combat')->getValue() as $item) {
            if (!$this->isValidVideoItem($item)) {
                continue;
            }
            $url = $item['uri'] ?? $item['url'] ?? $item['value'] ?? '';
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    public function cleanFakeVideoUrls(?StyleInterface $io = null): void
    {
        $logger = \Drupal::logger('shared_lib');
        $storage = \Drupal::entityTypeManager()->getStorage('node');

        echo "cleanFakeVideoUrls : DÉMARRAGE\n";

        $nids = $storage->getQuery()
            ->condition('type', 'confrontation')
            ->exists('field_video_du_combat')
            ->accessCheck(false)
            ->execute();

        if (empty($nids)) {
            echo "Aucune confrontation avec un champ vidéo n'a été trouvée.\n";
            return;
        }

        echo "-> " . count($nids) . " nœud(s) analysé(s) pour vérification des URLs.\n";
        $nodes = $storage->loadMultiple($nids);
        $treated_count = 0;

        foreach ($nodes as $node) {
            /** @var \Drupal\node\NodeInterface $node */
            $field         = $node->get('field_video_du_combat');
            $values        = $field->getValue();
            $initial_count = count($values);

            $has_suspect = false;
            foreach ($values as $item) {
                if (!$this->isValidVideoItem($item)) {
                    $has_suspect = true;
                    break;
                }
            }

            if (!$has_suspect) {
                continue;
            }

            $new_values = array_filter($values, [$this, 'isValidVideoItem']);

            if (count($new_values) < $initial_count) {

                if ($io !== null) {
                    $node_info = "[" . $node->id() . "] " . $node->getTitle();
                    $confirm = $io->confirm("Voulez-vous nettoyer le champ vidéo de la confrontation {$node_info} ?", true);
                    if (!$confirm) {
                        echo "Ignoré (choix manuel) : [" . $node->id() . "]\n";
                        continue;
                    }
                }

                $removed_urls = [];
                foreach ($values as $item) {
                    if (!$this->isValidVideoItem($item)) {
                        $removed_urls[] = $item['uri'] ?? $item['url'] ?? $item['value'] ?? '(vide)';
                    }
                }

                $field->setValue(array_values($new_values));
                $node->save();

                $removed_str = implode(', ', $removed_urls);
                echo "SAUVEGARDE : Nœud [" . $node->id() . "] " . $node->getTitle() . " — URLs supprimées : " . $removed_str . "\n";
                $logger->notice(
                    "cleanFakeVideoUrls : Nœud sauvegardé — NID=@nid | Titre=@title | URLs supprimées=@urls",
                    [
                        '@nid'   => $node->id(),
                        '@title' => $node->getTitle(),
                        '@urls'  => $removed_str,
                    ]
                );
                $treated_count++;
            }
        }

        echo "cleanFakeVideoUrls : FIN DU TRAITEMENT ({$treated_count} nœuds modifiés).\n";
    }

    /**
     * Recherche, pour un lutteur donné, si la confrontation fournie a un doublon,
     * et le supprime selon les règles établies.
     *
     * @param NodeInterface $confrontation    Nœud "confrontation" à analyser.
     * @param NodeInterface $lutteur_principal Nœud "athlete" dont on cherche les doublons de combat.
     * @return bool  true si un doublon combat a été trouvé et supprimé.
     */
    public function findAndDeleteDuplicateCombat(
        NodeInterface $confrontation,
        NodeInterface $lutteur_principal
    ): bool {
        $logger  = \Drupal::logger('shared_lib');
        $storage = \Drupal::entityTypeManager()->getStorage('node');

        if ($confrontation->bundle() !== 'confrontation' || $lutteur_principal->bundle() !== 'athlete') {
            return false;
        }

        $dateA = $confrontation->get('field_confrontation_date')->value ?? '';
        if (empty($dateA)) {
            return false;
        }

        $lutteurId = (int) $lutteur_principal->id();
        $fieldIdA  = trim((string) ($confrontation->get('field_id_text')->value ?? ''));

        // 1. RECHERCHE PRIORITAIRE : Doublon Parfait par ID technique
        // C'est ici que 'ambulance_wouly2_20190413' est traité.
        if ($fieldIdA !== '') {
            $exactIds = $storage->getQuery()
                ->condition('type', 'confrontation')
                ->condition('field_id_text', $fieldIdA)
                ->condition('nid', $confrontation->id(), '!=')
                ->accessCheck(false)
                ->execute();

            if (!empty($exactIds)) {
                $confrontation2 = $storage->load(reset($exactIds));
                if ($confrontation2) {
                    if ((int) $confrontation->id() > (int) $confrontation2->id()) {
                        [$toDelete, $toKeep] = [$confrontation, $confrontation2];
                    } else {
                        [$toDelete, $toKeep] = [$confrontation2, $confrontation];
                    }

                    try {
                        $deleteNid = $toDelete->id();
                        $toDelete->delete();
                        $storage->resetCache([$deleteNid]);
                        //$logger->notice('findAndDeleteDuplicateCombat DOUBLON PARFAIT (ID_TEXT) SUPPRIMÉ | ID: @id', ['@id' => $fieldIdA]);
                        return true;
                    } catch (\Exception $e) {
                        $logger->error('Échec suppression ID_TEXT : @msg', ['@msg' => $e->getMessage()]);
                    }
                }
            }
        }

        // 2. LOGIQUE GLOBALE (Si pas trouvé par ID_TEXT ou si ID_TEXT vide)
        $idA1 = (int) ($confrontation->get('field_athlete_1')->target_id ?? 0);
        $idA2 = (int) ($confrontation->get('field_athlete_2')->target_id ?? 0);

        if ($idA1 === $lutteurId) {
            $opponentId = $idA2;
        } elseif ($idA2 === $lutteurId) {
            $opponentId = $idA1;
        } else {
            return false; // Lutteur non présent dans le combat
        }

        $or = $storage->getQuery()->orConditionGroup()
            ->condition('field_athlete_1', $lutteurId)
            ->condition('field_athlete_2', $lutteurId);

        $candidateIds = $storage->getQuery()
            ->condition('type', 'confrontation')
            ->condition($or)
            ->condition('nid', $confrontation->id(), '!=')
            ->accessCheck(false)
            ->execute();

        if (empty($candidateIds)) {
            return false;
        }

        $titleA     = trim($confrontation->getTitle());
        $vainqueurA = (string) ($confrontation->get('field_vainqueur')->value ?? '');
        $opponentNode = $storage->load($opponentId);
        if (!$opponentNode) return false;
        $opponentName = $this->textNormalizationHelper->normalizeForDistance($opponentNode->getTitle());

        foreach ($storage->loadMultiple($candidateIds) as $confrontation2) {
            $fieldIdB   = trim((string) ($confrontation2->get('field_id_text')->value ?? ''));
            $titleB     = trim($confrontation2->getTitle());
            $dateB      = $confrontation2->get('field_confrontation_date')->value ?? '';
            $vainqueurB = (string) ($confrontation2->get('field_vainqueur')->value ?? '');

            $isPerfectDuplicate = false;
            $perfectReason      = '';

            // Comparaison field_id_text alternative
            if ($fieldIdA !== '' && $fieldIdB !== '' && strtolower($fieldIdA) === strtolower($fieldIdB)) {
                $isPerfectDuplicate = true;
                $perfectReason      = 'field_id_text identique';
            }

            // Comparaison Titre + Date + Vainqueur
            if (!$isPerfectDuplicate && $titleA !== '' && strtolower($titleA) === strtolower($titleB) && $dateA === $dateB && $vainqueurA === $vainqueurB) {
                $isPerfectDuplicate = true;
                $perfectReason      = 'titre + date + vainqueur identiques';
            }

            if ($isPerfectDuplicate) {
                if ((int) $confrontation->id() > (int) $confrontation2->id()) {
                    [$toDelete, $toKeep] = [$confrontation, $confrontation2];
                } else {
                    [$toDelete, $toKeep] = [$confrontation2, $confrontation];
                }
                try {
                    $delId = $toDelete->id();
                    $toDelete->delete();
                    $storage->resetCache([$delId]);
                    //$logger->notice('findAndDeleteDuplicateCombat DOUBLON PARFAIT SUPPRIMÉ : @reason', ['@reason' => $perfectReason]);
                    return true;
                } catch (\Exception $e) {
                    continue;
                }
            }

            // --- Logique floue (Levenshtein + Distances temporelles) ---
            $idB1 = (int) ($confrontation2->get('field_athlete_1')->target_id ?? 0);
            $idB2 = (int) ($confrontation2->get('field_athlete_2')->target_id ?? 0);
            $opponent2Id = ($idB1 === $lutteurId) ? $idB2 : $idB1;

            $opponent2Node = $storage->load($opponent2Id);
            if (!$opponent2Node) continue;
            $opponent2Name = $this->textNormalizationHelper->normalizeForDistance($opponent2Node->getTitle());

            $tsA = strtotime($dateA);
            $tsB = strtotime($dateB);
            $diffDays = abs($tsA - $tsB) / 86400;
            $isJan1Match = (date('Y', $tsA) === date('Y', $tsB)) && (date('m-d', $tsA) === '01-01' || date('m-d', $tsB) === '01-01');

            if (!$isJan1Match && $diffDays >= 180) continue;

            $levOpponent = levenshtein($opponentName, $opponent2Name);
            if ($levOpponent === 0 || $levOpponent <= 2) {
                if ($levOpponent === 0) {
                    if (date('m-d', $tsA) === '01-01' && date('m-d', $tsB) !== '01-01') {
                        [$toDelete, $toKeep] = [$confrontation, $confrontation2];
                    } elseif (date('m-d', $tsB) === '01-01' && date('m-d', $tsA) !== '01-01') {
                        [$toDelete, $toKeep] = [$confrontation2, $confrontation];
                    } else {
                        if ((int)$confrontation->id() > (int)$confrontation2->id()) {
                            [$toDelete, $toKeep] = [$confrontation, $confrontation2];
                        } else {
                            [$toDelete, $toKeep] = [$confrontation2, $confrontation];
                        }
                    }
                } else {
                    // Pour lev 1 ou 2, on supprime systématiquement le paramètre fourni
                    [$toDelete, $toKeep] = [$confrontation, $confrontation2];
                }

                try {
                    $delId = $toDelete->id();
                    $toDelete->delete();
                    $storage->resetCache([$delId]);
                    //$logger->notice('findAndDeleteDuplicateCombat DOUBLON FLOU SUPPRIMÉ (lev=@lev)', ['@lev' => $levOpponent]);
                    return true;
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return false;
    }

    public function updateKingStatus(
        NodeInterface $winner,
        NodeInterface $loser
    ): void {
        if ((int) $loser->get("field_king")->value !== 1) {
            return;
        }

        $storage = \Drupal::entityTypeManager()->getStorage("node");
        $ids = \Drupal::entityQuery("node")
            ->condition("type", "athlete")
            ->condition("field_king", 1)
            ->accessCheck(false)
            ->execute();

        if (!empty($ids)) {
            $nodes = $storage->loadMultiple($ids);
            foreach ($nodes as $n) {
                $n->set("field_king", 0);
                $n->save();
            }
        }

        $winner->set("field_king", 1);
        $winner->save();

        \Drupal::logger("shared_lib")->notice(
            "👑 Nouveau roi des arènes : @winner (a battu @loser)",
            ["@winner" => $winner->label(), "@loser" => $loser->label()]
        );
    }

    /**
     * Nettoie le nom de l'athlète pour le calcul de distance Levenshtein
     * Levenshtein: car on cherche des noms suspectement trop proches (ex. Mor Diop / Mor Diop 2 / Diop mor)
     */
    private function getCleanAthleteNameForLevenshtein($athlete_id): string
    {
        static $cache = [];

        if (isset($cache[$athlete_id])) {
            return $cache[$athlete_id];
        }

        if (empty($athlete_id)) {
            return $cache[$athlete_id] = "";
        }

        $athlete = \Drupal::entityTypeManager()
            ->getStorage("node")
            ->load($athlete_id);

        if (!$athlete) {
            return $cache[$athlete_id] = "";
        }

        $name = $athlete->getTitle();
        $clean = $this->textNormalizationHelper->normalizeForDistance($name);

        // Supprime les " 2" à la fin (cas de doublons de noms genre "John Doe 2")
        $clean = preg_replace('/\s+2\s*$/i', "2", $clean);

        return $cache[$athlete_id] = $clean;
    }

    /**
     * Détermine si une date de combat est une date "01/01" antérieure à
     * l'an 2000. Ces dates sont très généralement des dates par défaut
     * saisies faute de mieux (date réelle du combat inconnue/imprécise) et
     * ne sont pas fiables pour juger d'une proximité temporelle entre deux
     * combats.
     */
    private function isUnreliableDefaultJanuaryFirstDate(?string $date): bool
    {
        if (empty($date)) {
            return false;
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return false;
        }
        return (int) date('n', $ts) === 1 && (int) date('j', $ts) === 1 && (int) date('Y', $ts) < 2000;
    }

    /**
     * Détermine si une date de combat tombe un 01/01, quelle que soit
     * l'année.
     */
    private function isJanuaryFirstDate(?string $date): bool
    {
        if (empty($date)) {
            return false;
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return false;
        }
        return (int) date('n', $ts) === 1 && (int) date('j', $ts) === 1;
    }

    /**
     * Détermine si deux dates de combat sont toutes les deux un 1er janvier
     * de la MÊME année, et que cette année est antérieure à 2026 (ex.
     * "2015-01-01" et "2015-01-01"). Les 1er janvier à partir de 2026 sont
     * considérés comme des dates potentiellement réelles et ne sont donc
     * PAS concernés par cet écart (le doute ne s'applique qu'aux dates
     * "par défaut" historiques, année connue mais jour/mois inconnus,
     * pratique qui a cessé à partir de 2026).
     * Utilisé pour ignorer les groupes de 2 combats suspects où la
     * proximité de date n'est probablement due qu'à une telle date "par
     * défaut" partagée par les deux combats.
     */
    private function isSameJanuaryFirstYearPair(?string $date1, ?string $date2): bool
    {
        if (!$this->isJanuaryFirstDate($date1) || !$this->isJanuaryFirstDate($date2)) {
            return false;
        }
        $ts1 = strtotime($date1);
        $ts2 = strtotime($date2);
        if ($ts1 === false || $ts2 === false) {
            return false;
        }
        $year1 = (int) date('Y', $ts1);
        $year2 = (int) date('Y', $ts2);

        return $year1 === $year2 && $year1 < 2026;
    }

    /**
     * Filtre la liste des combats d'un pivot pour en retirer ceux dont la
     * date n'est pas fiable pour la détection de doublons basée sur la
     * date (RÈGLE 1 à 5 + queue IA) :
     *
     * - tout combat au 01/01 antérieur à l'an 2000 (date par défaut quasi
     *   systématique) ;
     * - tout combat au 01/01 (n'importe quelle année) dès lors que ce même
     *   pivot compte 3 COMBATS OU PLUS à cette même date
     *   exacte : un tel volume sur une seule journée trahit presque
     *   toujours une date par défaut utilisée en masse (ex. import
     *   "Assurance" avec date inconnue) plutôt que des combats réellement
     *   disputés le même jour — on ne cherche donc pas à les dédoublonner
     *   entre eux.
     *
     * @param NodeInterface[] $fights
     * @return NodeInterface[]
     */
    private function filterUnreliableJanuaryFirstFights(array $fights): array
    {
        // Étape 1 : dates par défaut pré-2000, toujours écartées.
        $fights = array_filter(
            $fights,
            fn(NodeInterface $fight) => !$this->isUnreliableDefaultJanuaryFirstDate($fight->get('field_confrontation_date')->value ?? '')
        );

        // Étape 2 : parmi les combats restants, on regroupe les 01/01 par
        // date exacte ; tout groupe de 3 combats ou plus à la même date
        // 01/01 est écarté en bloc.
        $countsByExactDate = [];
        foreach ($fights as $fight) {
            $date = $fight->get('field_confrontation_date')->value ?? '';
            if ($this->isJanuaryFirstDate($date)) {
                $countsByExactDate[$date] = ($countsByExactDate[$date] ?? 0) + 1;
            }
        }

        return array_filter($fights, function (NodeInterface $fight) use ($countsByExactDate) {
            $date = $fight->get('field_confrontation_date')->value ?? '';
            return !($this->isJanuaryFirstDate($date) && ($countsByExactDate[$date] ?? 0) >= 3);
        });
    }

    /**
     * RÈGLE 1 — Même date, adversaire identique sauf suffixe numérique.
     *
     * Exemple : "bou siteu vs papa yade" ET "bou siteu vs papa yade 2" → même date
     * → on garde celui où l'adversaire a le suffixe le plus élevé (ici "papa yade 2")
     * → on supprime l'autre.
     *
     * @return bool true si un doublon a été détecté et supprimé.
     */
    private function checkAndDeleteNumericSuffixDuplicate(
        NodeInterface $fight1,
        NodeInterface $fight2,
        int $pivotAthleteId,
        $storage,
        $logger
    ): bool {
        $date1 = $fight1->get('field_confrontation_date')->value ?? '';
        $date2 = $fight2->get('field_confrontation_date')->value ?? '';

        if ($date1 !== $date2 || empty($date1)) {
            return false;
        }

        // ── DÉTECTION RAPIDE VIA field_id_text ───────────────────────────────────
        $idText1 = trim((string) ($fight1->get('field_id_text')->value ?? ''));
        $idText2 = trim((string) ($fight2->get('field_id_text')->value ?? ''));

        if ($idText1 !== '' && $idText2 !== '') {
            $parts1 = $this->textNormalizationHelper->parseIdTextParts($idText1);
            $parts2 = $this->textNormalizationHelper->parseIdTextParts($idText2);

            if ($parts1 && $parts2 && $parts1['date'] === $parts2['date']) {
                // Comparer les 2 segments (seg1 et seg2) croisés pour trouver le pair commun
                // et le segment qui diffère uniquement par un suffixe numérique
                $pairs = [
                    [$parts1['seg1'], $parts1['seg2'], $parts2['seg1'], $parts2['seg2']],
                    [$parts1['seg1'], $parts1['seg2'], $parts2['seg2'], $parts2['seg1']], // inversé
                ];

                foreach ($pairs as [$a1, $b1, $a2, $b2]) {
                    // a1 == a2 (pivot identique) et b1/b2 diffèrent par suffixe numérique
                    if ($a1 === $a2) {
                        $base1 = $this->textNormalizationHelper->stripTrailingNumber($b1);
                        $base2 = $this->textNormalizationHelper->stripTrailingNumber($b2);
                        $num1  = $this->textNormalizationHelper->extractTrailingNumber($b1);
                        $num2  = $this->textNormalizationHelper->extractTrailingNumber($b2);

                        if ($base1 === $base2 && $base1 !== '' && $num1 !== $num2) {
                            // On garde le combat dont le segment adversaire a le suffixe le plus élevé
                            if ($num1 > $num2) {
                                [$toDelete, $toKeep] = [$fight2, $fight1];
                            } else {
                                [$toDelete, $toKeep] = [$fight1, $fight2];
                            }

                            try {
                                $delNid = $toDelete->id();
                                $logger->notice(
                                    'RÈGLE 1 (ID_TEXT) - SUPPRESSION : NID @del (@id_del) supprimé, NID @keep (@id_keep) conservé',
                                    [
                                        '@del'    => $toDelete->id(),
                                        '@id_del' => $toDelete === $fight1 ? $idText1 : $idText2,
                                        '@keep'   => $toKeep->id(),
                                        '@id_keep' => $toKeep === $fight1 ? $idText1 : $idText2,
                                    ]
                                );
                                $toDelete->delete();
                                $storage->resetCache([$delNid]);
                                return true;
                            } catch (\Exception $e) {
                                $logger->error('RÈGLE 1 (ID_TEXT) - Échec : @msg', ['@msg' => $e->getMessage()]);
                                return false;
                            }
                        }
                    }
                }
            }
        }

        // ── DÉTECTION PAR NŒUDS ATHLÈTES (fallback si pas de field_id_text) ─────
        $getOpponentId = function (NodeInterface $fight) use ($pivotAthleteId): int {
            $id1 = (int) ($fight->get('field_athlete_1')->target_id ?? 0);
            $id2 = (int) ($fight->get('field_athlete_2')->target_id ?? 0);
            return $id1 === $pivotAthleteId ? $id2 : $id1;
        };

        $opp1Id = $getOpponentId($fight1);
        $opp2Id = $getOpponentId($fight2);

        if ($opp1Id === $opp2Id || $opp1Id === 0 || $opp2Id === 0) {
            return false;
        }

        $opp1Node = $storage->load($opp1Id);
        $opp2Node = $storage->load($opp2Id);
        if (!$opp1Node || !$opp2Node) {
            return false;
        }

        $norm1 = $this->textNormalizationHelper->normalizeForDistance($opp1Node->getTitle());
        $norm2 = $this->textNormalizationHelper->normalizeForDistance($opp2Node->getTitle());

        // Bug corrigé : trim sur la base après suppression du suffixe
        $base1 = $this->textNormalizationHelper->stripTrailingNumber($norm1); // "pape yade" ✅
        $base2 = $this->textNormalizationHelper->stripTrailingNumber($norm2); // "pape yade" ✅
        $num1  = $this->textNormalizationHelper->extractTrailingNumber($norm1);
        $num2  = $this->textNormalizationHelper->extractTrailingNumber($norm2);

        if ($base1 !== $base2 || $base1 === '' || $num1 === $num2) {
            return false;
        }

        if ($num1 > $num2) {
            [$toDelete, $toKeep] = [$fight2, $fight1];
        } else {
            [$toDelete, $toKeep] = [$fight1, $fight2];
        }

        try {
            $delNid = $toDelete->id();
            $logger->notice(
                'RÈGLE 1 (ATHLETE) - SUPPRESSION : NID @del (adversaire "@adv_del") supprimé, NID @keep (adversaire "@adv_keep") conservé, date @date',
                [
                    '@del'      => $toDelete->id(),
                    '@keep'     => $toKeep->id(),
                    '@adv_del'  => ($toDelete === $fight1 ? $opp1Node : $opp2Node)->getTitle(),
                    '@adv_keep' => ($toDelete === $fight1 ? $opp2Node : $opp1Node)->getTitle(),
                    '@date'     => $date1,
                ]
            );
            $toDelete->delete();
            $storage->resetCache([$delNid]);
            return true;
        } catch (\Exception $e) {
            $logger->error('RÈGLE 1 (ATHLETE) - Échec : @msg', ['@msg' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * RÈGLE 2 — Dates différentes (≤ 1 an), adversaire quasi-identique (Levenshtein = 1).
     *
     * Exemple : "bou siteu vs papa yade" (2022-03-10) ET "bou siteu vs pape yade" (2022-07-15)
     * → Levenshtein("papayade", "papeyade") = 1, écart < 365 jours
     * → on garde le combat avec le NID le plus ancien (trié ASC en amont), on supprime l'autre.
     *
     * @return bool true si un doublon a été détecté et supprimé.
     */
    private function checkAndDeleteLevenshteinDuplicate(
        NodeInterface $fight1,
        NodeInterface $fight2,
        int $pivotAthleteId,
        $storage,
        $logger
    ): bool {
        $date1 = $fight1->get('field_confrontation_date')->value ?? '';
        $date2 = $fight2->get('field_confrontation_date')->value ?? '';

        if (empty($date1) || empty($date2)) {
            return false;
        }

        // Écart maximal : 1 an (365 jours)
        $diffDays = abs(strtotime($date1) - strtotime($date2)) / 86400;
        if ($diffDays > 365) {
            return false;
        }

        // Identifier les adversaires
        $getOpponentId = function (NodeInterface $fight) use ($pivotAthleteId): int {
            $id1 = (int) ($fight->get('field_athlete_1')->target_id ?? 0);
            $id2 = (int) ($fight->get('field_athlete_2')->target_id ?? 0);
            return $id1 === $pivotAthleteId ? $id2 : $id1;
        };

        $opp1Id = $getOpponentId($fight1);
        $opp2Id = $getOpponentId($fight2);

        if ($opp1Id === $opp2Id || $opp1Id === 0 || $opp2Id === 0) {
            return false;
        }

        $opp1Node = $storage->load($opp1Id);
        $opp2Node = $storage->load($opp2Id);
        if (!$opp1Node || !$opp2Node) {
            return false;
        }

        $norm1 = $this->textNormalizationHelper->normalizeForDistance($opp1Node->getTitle());
        $norm2 = $this->textNormalizationHelper->normalizeForDistance($opp2Node->getTitle());

        // Garde-fou : si l'un des deux a un suffixe numérique différent → c'est la règle 1,
        // pas la règle 2. On laisse passer uniquement si les bases ET les suffixes
        // indiquent une vraie coquille de nom (pas une numérotation intentionnelle).
        $base1 = $this->textNormalizationHelper->stripTrailingNumber($norm1);
        $base2 = $this->textNormalizationHelper->stripTrailingNumber($norm2);
        $num1  = $this->textNormalizationHelper->extractTrailingNumber($norm1);
        $num2  = $this->textNormalizationHelper->extractTrailingNumber($norm2);

        // Si les bases sont identiques et les suffixes différents → règle 1, pas règle 2
        if ($base1 === $base2 && $num1 !== $num2) {
            return false;
        }

        // Levenshtein strictement égal à 1
        $lev = levenshtein($norm1, $norm2);
        if ($lev !== 1) {
            return false;
        }

        // Pivot = combat avec le NID le plus ancien (fight1 est déjà le plus ancien
        // car la requête trie par nid ASC et on itère en séquentiel)
        // → on supprime fight2 (le plus récent)
        $toDelete = $fight2;
        $toKeep   = $fight1;

        try {
            $delNid = $toDelete->id();
            $logger->notice(
                'RÈGLE 2 - SUPPRESSION doublon Levenshtein=1 : NID @del supprimé (adversaire "@adv_del", date @date_del), NID @keep conservé (adversaire "@adv_keep", date @date_keep), écart @days j.',
                [
                    '@del'       => $toDelete->id(),
                    '@keep'      => $toKeep->id(),
                    '@adv_del'   => $opp2Node->getTitle(),
                    '@adv_keep'  => $opp1Node->getTitle(),
                    '@date_del'  => $date2,
                    '@date_keep' => $date1,
                    '@days'      => round($diffDays, 1),
                ]
            );
            $toDelete->delete();
            $storage->resetCache([$delNid]);
            return true;
        } catch (\Exception $e) {
            $logger->error('RÈGLE 2 - Échec suppression NID @nid : @msg', [
                '@nid' => $toDelete->id(),
                '@msg' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * RÈGLE 5 — Même date exacte + un des 2 lutteurs identique (même pivot) +
     *           l'adversaire diffère (NID différent) MAIS le nom de l'un des 2
     *           adversaires est entièrement contenu dans le nom de l'autre.
     *
     * Exemple : le 20/07/2025, "Jacob Baldé vs Thiatou Yoff" ET "Jacob Baldé
     * vs Thiatou" (2 nœuds athlete distincts, doublon d'athlète non fusionné)
     * → "thiatou" est contenu dans "thiatou yoff" → on garde le combat dont
     * l'adversaire a le nom le plus long (plus précis), on supprime l'autre.
     *
     * @return bool true si un doublon a été détecté et supprimé.
     */
    private function checkAndDeleteNameContainmentSameDateDuplicate(
        NodeInterface $fight1,
        NodeInterface $fight2,
        int $pivotAthleteId,
        $storage,
        $logger
    ): bool {
        $date1 = $fight1->get('field_confrontation_date')->value ?? '';
        $date2 = $fight2->get('field_confrontation_date')->value ?? '';

        if ($date1 !== $date2 || empty($date1)) {
            return false;
        }

        $getOpponentId = function (NodeInterface $fight) use ($pivotAthleteId): int {
            $id1 = (int) ($fight->get('field_athlete_1')->target_id ?? 0);
            $id2 = (int) ($fight->get('field_athlete_2')->target_id ?? 0);
            return $id1 === $pivotAthleteId ? $id2 : $id1;
        };

        $opp1Id = $getOpponentId($fight1);
        $opp2Id = $getOpponentId($fight2);

        // Il faut bien 2 adversaires DIFFÉRENTS (sinon c'est un autre cas de doublon).
        if ($opp1Id === $opp2Id || $opp1Id === 0 || $opp2Id === 0) {
            return false;
        }

        $opp1Node = $storage->load($opp1Id);
        $opp2Node = $storage->load($opp2Id);
        if (!$opp1Node || !$opp2Node) {
            return false;
        }

        $norm1 = $this->textNormalizationHelper->normalizeForDistance($opp1Node->getTitle());
        $norm2 = $this->textNormalizationHelper->normalizeForDistance($opp2Node->getTitle());

        if ($norm1 === '' || $norm2 === '' || $norm1 === $norm2) {
            return false;
        }

        // L'un des 2 noms doit être entièrement contenu dans l'autre
        // (ex: "thiatou" ⊂ "thiatou yoff").
        $norm1ContainsNorm2 = str_contains($norm1, $norm2);
        $norm2ContainsNorm1 = str_contains($norm2, $norm1);

        if (!$norm1ContainsNorm2 && !$norm2ContainsNorm1) {
            return false;
        }

        // On garde le combat dont l'adversaire a le nom le plus long (plus précis).
        if (mb_strlen($norm1) >= mb_strlen($norm2)) {
            [$toDelete, $toKeep] = [$fight2, $fight1];
            [$advDel, $advKeep]  = [$opp2Node, $opp1Node];
        } else {
            [$toDelete, $toKeep] = [$fight1, $fight2];
            [$advDel, $advKeep]  = [$opp1Node, $opp2Node];
        }

        try {
            $delNid = $toDelete->id();
            $logger->notice(
                'RÈGLE 5 (NOM CONTENU, MÊME DATE) - SUPPRESSION : date @date, adversaire "@adv_del" (nom contenu dans/de "@adv_keep") → NID @del supprimé, NID @keep conservé (nom plus long/précis)',
                [
                    '@date'     => $date1,
                    '@adv_del'  => $advDel->getTitle(),
                    '@adv_keep' => $advKeep->getTitle(),
                    '@del'      => $toDelete->id(),
                    '@keep'     => $toKeep->id(),
                ]
            );
            $toDelete->delete();
            $storage->resetCache([$delNid]);
            return true;
        } catch (\Exception $e) {
            $logger->error('RÈGLE 5 (NOM CONTENU, MÊME DATE) - Échec : @msg', ['@msg' => $e->getMessage()]);
            return false;
        }
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
     * Règle 5 : Même date exacte + adversaires différents (NID) mais le nom
     *           de l'un est contenu dans le nom de l'autre
     *           → garder le combat dont l'adversaire a le nom le plus long.
     */
    public function getOrCleanPotentialsDuplicatedCombats(): void
    {
        // Fix contextuel base_url
        if (!empty($GLOBALS['base_url'])) {
            $request_context = \Drupal::service('router.request_context');
            $parsed = parse_url($GLOBALS['base_url']);
            if (!empty($parsed['path']) && $parsed['path'] !== '/') {
                $request_context->setBaseUrl(rtrim($parsed['path'], '/'));
            }
        }

        $logger  = \Drupal::service('logger.factory')->get('shared_lib');
        $storage = \Drupal::entityTypeManager()->getStorage('node');

        $athlete_ids = $storage->getQuery()
            ->condition('type', 'athlete')
            ->accessCheck(false)
            ->execute();

        foreach ($athlete_ids as $athlete_id) {
            $pivotId = (int) $athlete_id;
            $tech_ids_seen = [];

            $fight_ids = $storage->getQuery()
                ->condition('type', 'confrontation')
                ->condition(
                    $storage->getQuery()->orConditionGroup()
                        ->condition('field_athlete_1', $pivotId)
                        ->condition('field_athlete_2', $pivotId)
                )
                ->sort('field_confrontation_date', 'ASC')
                ->sort('nid', 'ASC')
                ->accessCheck(false)
                ->execute();

            if (count($fight_ids) < 2) continue;

            // On exclut les dates 01/01 non fiables (par défaut < an 2000,
            // ou 01/01 en surnombre pour ce pivot) de toutes les règles de
            // doublons basées sur la date.
            $fights = $this->filterUnreliableJanuaryFirstFights($storage->loadMultiple($fight_ids));

            if (count($fights) < 2) continue;

            // ── RÈGLE 4 : Groupe majoritaire (avant la boucle séquentielle) ──────────
            $deletedR4 = $this->checkAndDeleteMajorityVerdictDuplicates(
                array_values($fights),
                $pivotId,
                $storage,
                $logger
            );

            // Si la règle 4 a supprimé des nœuds, on recharge la liste pour éviter
            // d'itérer sur des entités supprimées dans les règles suivantes.
            if ($deletedR4 > 0) {
                $fight_ids = $storage->getQuery()
                    ->condition('type', 'confrontation')
                    ->condition(
                        $storage->getQuery()->orConditionGroup()
                            ->condition('field_athlete_1', $pivotId)
                            ->condition('field_athlete_2', $pivotId)
                    )
                    ->sort('field_confrontation_date', 'ASC')
                    ->sort('nid', 'ASC')
                    ->accessCheck(false)
                    ->execute();
                $fights = $this->filterUnreliableJanuaryFirstFights($storage->loadMultiple($fight_ids));
            }

            $previous_fight = null;
            foreach ($fights as $current_fight) {
                $is_deleted    = false;
                $current_tech_id = $current_fight->get('field_id_text')->value;

                // ── PHASE A : Doublon absolu par field_id_text ────────────────────
                if (!empty($current_tech_id) && isset($tech_ids_seen[$current_tech_id])) {
                    $logger->info('SUPPRESSION : Doublon field_id_text (@id) pour NID @nid', [
                        '@id'  => $current_tech_id,
                        '@nid' => $current_fight->id(),
                    ]);
                    $current_fight->delete();
                    $storage->resetCache([$current_fight->id()]);
                    $is_deleted = true;
                }
                // ── PHASE B : Règles floues (uniquement si on a un combat précédent) ─
                elseif ($previous_fight) {

                    // ── RÈGLE 1 : Même date + suffixe numérique sur l'adversaire ──
                    $deleted = $this->checkAndDeleteNumericSuffixDuplicate(
                        $previous_fight,
                        $current_fight,
                        $pivotId,
                        $storage,
                        $logger
                    );

                    if ($deleted) {
                        // On ne sait pas lequel a été supprimé depuis l'extérieur.
                        // Pour sécuriser l'itération : on vérifie si current_fight existe encore.
                        $still_exists = $storage->load($current_fight->id());
                        if (!$still_exists) {
                            $is_deleted = true;
                        } else {
                            // C'est previous_fight qui a été supprimé → on réinitialise le pivot
                            // en faisant pointer previous_fight vers current_fight ci-dessous.
                            $previous_fight = null;
                        }
                    }

                    // ── RÈGLE 5 : Même date exacte + nom adversaire contenu dans l'autre ──
                    if (!$deleted && $previous_fight) {
                        $deleted = $this->checkAndDeleteNameContainmentSameDateDuplicate(
                            $previous_fight,
                            $current_fight,
                            $pivotId,
                            $storage,
                            $logger
                        );

                        if ($deleted) {
                            $still_exists = $storage->load($current_fight->id());
                            if (!$still_exists) {
                                $is_deleted = true;
                            } else {
                                // C'est previous_fight qui a été supprimé.
                                $previous_fight = null;
                            }
                        }
                    }

                    // ── RÈGLE 2 : Dates ≤ 1 an + Levenshtein = 1 sur l'adversaire ─
                    if (!$deleted) {
                        $deleted = $this->checkAndDeleteLevenshteinDuplicate(
                            $previous_fight,
                            $current_fight,
                            $pivotId,
                            $storage,
                            $logger
                        );

                        if ($deleted) {
                            $is_deleted = true; // current_fight supprimé (NID le plus récent)
                        }
                    }

                    // ── RÈGLE 3 : Même adversaire + même verdict + ≤ 6 jours + field_is_lutte_simple = false ─
                    if (!$deleted && $previous_fight) {
                        $deleted = $this->checkAndDeleteSameOutcomeDateProximityDuplicate(
                            $previous_fight,
                            $current_fight,
                            $pivotId,
                            $storage,
                            $logger
                        );

                        if ($deleted) {
                            $is_deleted = true; // current_fight supprimé (date la plus récente)
                        }
                    }

                    // ── RÈGLE EXISTANTE : Doublon parfait (LEV 0, DAYS 0) ─────────
                    if (!$deleted && $previous_fight) {
                        $meta = $this->checkIfPotentialDuplicate(
                            $previous_fight,
                            $current_fight,
                            $pivotId,
                            'Athlete'
                        );

                        if ($meta && $meta['lev_distance'] === 0 && $meta['diff_days'] === 0) {
                            $logger->info('SUPPRESSION : Doublon parfait (LEV 0, DAYS 0) NID @nid', [
                                '@nid' => $current_fight->id(),
                            ]);
                            $current_fight->delete();
                            $storage->resetCache([$current_fight->id()]);
                            $is_deleted = true;
                        } elseif ($meta) {
                            /*$logger->warning('DOUBLON POTENTIEL (A VERIFIER) : @a vs @b (LEV: @lev, DAYS: @days)', [
                                '@a'    => $previous_fight->id(),
                                '@b'    => $current_fight->id(),
                                '@lev'  => $meta['lev_distance'],
                                '@days' => $meta['diff_days'],
                            ]);*/
                        }
                    }
                }

                // ── Mise à jour du curseur ────────────────────────────────────────
                if (!$is_deleted) {
                    if (!empty($current_tech_id)) {
                        $tech_ids_seen[$current_tech_id] = $current_fight;
                    }
                    $previous_fight = $current_fight;
                } elseif ($previous_fight === null) {
                    // previous_fight a été supprimé par règle 1 → current_fight devient le nouveau pivot
                    $previous_fight = $current_fight;
                    if (!empty($current_tech_id)) {
                        $tech_ids_seen[$current_tech_id] = $current_fight;
                    }
                }
            }

            $storage->resetCache($fight_ids);
        }
    }

    /**
     * Règle 3 : Même adversaire + même verdict + ≤ 6 jours d'écart
     *           + field_is_lutte_simple = false sur les deux combats
     * → Supprime le combat avec la date la plus récente (toujours current_fight,
     *   car le query est trié ASC par date + NID).
     *
     * @return bool TRUE si current_fight a été supprimé.
     */
    private function checkAndDeleteSameOutcomeDateProximityDuplicate(
        $previous_fight,
        $current_fight,
        int $pivotId,
        $storage,
        $logger
    ): bool {

        // ── 1. field_is_lutte_simple = false sur les deux ───────────────────────
        $prev_lutte_simple    = (bool) $previous_fight->get('field_is_lutte_simple')->value;
        $current_lutte_simple = (bool) $current_fight->get('field_is_lutte_simple')->value;

        if ($prev_lutte_simple || $current_lutte_simple) {
            return false;
        }

        // ── 2. Même adversaire ──────────────────────────────────────────────────
        $prev_opp_id    = $this->getOpponentId($previous_fight, $pivotId);
        $current_opp_id = $this->getOpponentId($current_fight, $pivotId);

        if ($prev_opp_id === null || $current_opp_id === null) {
            return false;
        }

        if ($prev_opp_id !== $current_opp_id) {
            return false;
        }

        // ── 3. Même verdict (field_vainqueur) ──────────────────────────────────────
        $prev_winner    = $previous_fight->get('field_vainqueur')->value ?? '';
        $current_winner = $current_fight->get('field_vainqueur')->value ?? '';

        if (strtolower($prev_winner) !== strtolower($current_winner)) {
            return false;
        }

        // ── 4. Écart de date ≤ 6 jours ──────────────────────────────────────────
        $prev_date_str    = $previous_fight->get('field_confrontation_date')->value;
        $current_date_str = $current_fight->get('field_confrontation_date')->value;

        if (empty($prev_date_str) || empty($current_date_str)) {
            return false;
        }

        try {
            $prev_date    = new \DateTime($prev_date_str);
            $current_date = new \DateTime($current_date_str);
        } catch (\Exception $e) {
            return false;
        }

        $diff_days = (int) $prev_date->diff($current_date)->days;

        if ($diff_days > 6) {
            return false;
        }

        // ── 5. Suppression du combat à la date la plus récente ──────────────────
        $deleted_nid   = $current_fight->id();
        $deleted_title = $current_fight->getTitle();
        $kept_nid      = $previous_fight->id();
        $kept_title    = $previous_fight->getTitle();

        $logger->info(
            'SUPPRESSION Règle 3 : Même adversaire (@opp), même verdict (@winner), écart @days j, field_is_lutte_simple=false → SUPPRIMÉ NID @del_nid "@del_title" | CONSERVÉ NID @kept_nid "@kept_title"',
            [
                '@opp'        => $current_opp_id,
                '@winner'     => $current_winner ?: 'nul/inconnu',
                '@days'       => $diff_days,
                '@del_nid'    => $deleted_nid,
                '@del_title'  => $deleted_title,
                '@kept_nid'   => $kept_nid,
                '@kept_title' => $kept_title,
            ]
        );

        $current_fight->delete();
        $storage->resetCache([$current_fight->id()]);

        return true;
    }

    /**
     * RÈGLE 4 — Groupe de 3+ combats (même pivot + même adversaire + ≤ 6 jours d'écart global)
     *           avec verdicts mixtes → vote majoritaire sur le verdict, date la plus ancienne.
     *
     * Conditions :
     * - Au moins 3 combats impliquant le même couple (pivot + adversaire)
     * - Écart entre la date la plus ancienne et la plus récente ≤ 6 jours
     * - field_is_lutte_simple = false sur tous
     * - Un verdict est majoritaire (> moitié des combats)
     *
     * Action :
     * - Conserver le combat dont le verdict correspond au majoritaire
     *   (si plusieurs ont le bon verdict, garder le NID le plus ancien)
     * - Lui assigner la date la plus ancienne du groupe si elle diffère
     * - Supprimer tous les autres
     *
     * @param NodeInterface[] $fights        Combats triés ASC par date+NID, déjà filtrés sur le pivot.
     * @param int             $pivotId
     * @param object          $storage
     * @param object          $logger
     * @return int  Nombre de nœuds supprimés (0 = règle non déclenchée).
     */
    private function checkAndDeleteMajorityVerdictDuplicates(
        array $fights,
        int $pivotId,
        $storage,
        $logger
    ): int {

        if (count($fights) < 3) {
            return 0;
        }

        // ── 1. Grouper par couple adversaire ────────────────────────────────────
        // Clé = opponent_id (int)
        $byOpponent = [];

        foreach ($fights as $fight) {
            if ((bool) $fight->get('field_is_lutte_simple')->value) {
                continue;
            }
            $oppId = $this->getOpponentId($fight, $pivotId);
            if ($oppId === null) {
                continue;
            }
            $byOpponent[$oppId][] = $fight;
        }

        $totalDeleted = 0;

        foreach ($byOpponent as $oppId => $group) {

            if (count($group) < 3) {
                continue;
            }

            // ── 2. Vérifier que tous les combats sont dans une fenêtre ≤ 6 jours ─
            $dates = array_map(
                fn($f) => $f->get('field_confrontation_date')->value,
                $group
            );
            $dates = array_filter($dates); // retirer les vides

            if (count($dates) !== count($group)) {
                continue; // date manquante sur l'un → on ne touche pas
            }

            $timestamps = array_map('strtotime', $dates);
            $minTs = min($timestamps);
            $maxTs = max($timestamps);
            $spanDays = ($maxTs - $minTs) / 86400;

            if ($spanDays > 6) {
                continue;
            }

            // ── 3. Vote majoritaire sur le verdict ───────────────────────────────
            $verdictCounts = [];
            foreach ($group as $fight) {
                $v = (string) ($fight->get('field_vainqueur')->value ?? '');
                $verdictCounts[$v] = ($verdictCounts[$v] ?? 0) + 1;
            }

            // Si tous les verdicts sont identiques → cas règle 3, on laisse la boucle
            // séquentielle le traiter combat par combat
            if (count($verdictCounts) === 1) {
                continue;
            }

            arsort($verdictCounts);
            $topVerdict = array_key_first($verdictCounts);
            $topCount   = $verdictCounts[$topVerdict];

            // Majorité stricte (> moitié)
            if ($topCount <= intdiv(count($group), 2)) {
                // Égalité parfaite → on ne tranche pas automatiquement
                $logger->warning(
                    'RÈGLE 4 — Égalité de verdicts, groupe ignoré (pivot=@piv, opp=@opp, nb=@nb)',
                    ['@piv' => $pivotId, '@opp' => $oppId, '@nb' => count($group)]
                );
                continue;
            }

            // ── 4. Choisir le combat à conserver ─────────────────────────────────
            // Parmi ceux qui ont le bon verdict, on prend le NID le plus ancien
            $winners = array_filter(
                $group,
                fn($f) => (string) ($f->get('field_vainqueur')->value ?? '') === $topVerdict
            );
            usort($winners, fn($a, $b) => (int) $a->id() <=> (int) $b->id());
            $keeper = reset($winners);

            // ── 5. Date la plus ancienne du groupe ───────────────────────────────
            $oldestDate = date('Y-m-d', $minTs);

            // ── 6. Mise à jour de la date si nécessaire ──────────────────────────
            // Recharger depuis la base pour avoir la vraie valeur (évite le cache mémoire)
            $storage->resetCache([$keeper->id()]);
            $keeper = $storage->load($keeper->id());

            $keeperDate = $keeper->get('field_confrontation_date')->value;

            if ($keeperDate !== $oldestDate) {
                $keeper->set('field_confrontation_date', $oldestDate);
                $keeper->setNewRevision(false);
                $keeper->save();
                $logger->notice(
                    'RÈGLE 4 — Date mise à jour NID @nid "@title" : @old → @new',
                    [
                        '@nid'   => $keeper->id(),
                        '@title' => $keeper->getTitle(),
                        '@old'   => $keeperDate,
                        '@new'   => $oldestDate,
                    ]
                );
            }
            // ── 7. Suppression des autres ────────────────────────────────────────
            foreach ($group as $fight) {
                if ($fight->id() === $keeper->id()) {
                    continue;
                }
                try {
                    $delNid    = $fight->id();
                    $delDate   = $fight->get('field_confrontation_date')->value;
                    $delVerdict = (string) ($fight->get('field_vainqueur')->value ?? '');
                    $fight->delete();
                    $storage->resetCache([$delNid]);
                    $totalDeleted++;
                    $logger->notice(
                        'RÈGLE 4 — SUPPRIMÉ NID @del "@del_title" (verdict=@v, date=@d) | CONSERVÉ NID @keep "@keep_title" (verdict majoritaire=@mv, date finale=@fd) | pivot=@piv opp=@opp',
                        [
                            '@del'        => $delNid,
                            '@del_title'  => $fight->getTitle(),
                            '@v'          => $delVerdict,
                            '@d'          => $delDate,
                            '@keep'       => $keeper->id(),
                            '@keep_title' => $keeper->getTitle(),
                            '@mv'         => $topVerdict,
                            '@fd'         => $oldestDate,
                            '@piv'        => $pivotId,
                            '@opp'        => $oppId,
                        ]
                    );
                } catch (\Exception $e) {
                    $logger->error(
                        'RÈGLE 4 — Échec suppression NID @nid : @msg',
                        ['@nid' => $fight->id(), '@msg' => $e->getMessage()]
                    );
                }
            }
        }

        return $totalDeleted;
    }

    /**
     * Détecte les groupes de combats potentiellement en doublon que les
     * règles déterministes (suffixe numérique, Levenshtein=1, containment...)
     * n'ont PAS pu résoudre : même lutteur pivot, adversaire dont le nom
     * commence par les 3 mêmes premiers caractères (normalisé) mais pas
     * forcément la même date exacte — à 7 jours d'écart maximum entre les
     * combats du groupe (abus de langage / abréviation / suffixe descriptif
     * / erreur de saisie de date, ex. "Arsa" le 1er juin vs "Arsenal 2" le
     * 2 juin vs "Arsa 2" le 6 juin — même adversaire, même combat réel).
     *
     * Les combats sont regroupés par clustering (composantes connexes) :
     * deux combats du même pivot sont reliés si leurs dates sont à <= 7
     * jours d'écart ET si les 3 premiers caractères du nom normalisé de
     * l'adversaire sont identiques. La transitivité permet de relier une
     * chaîne de combats même si les 2 extrêmes dépassent 7 jours entre eux.
     *
     * Chaque groupe suspect (composante >= 2 combats) est empilé UN PAR UN
     * dans la queue 'confrontations_doublons_ai_queue', qui interrogera
     * l'IA (via request_ai / sl_live_search) pour trancher lequel garder et
     * lesquels supprimer. Le choix du combat à garder est laissé libre à
     * l'IA, mais la date finale retenue est TOUJOURS la plus ancienne du
     * groupe (imposé programmatiquement dans le QueueWorker, indépendamment
     * de la réponse IA).
     *
     * À appeler APRÈS getOrCleanPotentialsDuplicatedCombats() : cette
     * dernière règle est volontairement la DERNIÈRE de la chaîne — les vrais
     * doublons évidents ayant déjà été purgés par toutes les règles
     * déterministes existantes, ce qui limite le nombre de groupes soumis à
     * l'IA (coût/quota) et ne laisse remonter que les cas réellement
     * ambigus.
     *
     * @return int Nombre de groupes suspects mis en queue.
     */
    /**
     * Détermine si deux noms d'adversaires (déjà normalisés via
     * TextNormalizationHelper::normalizeForDistance) désignent
     * potentiellement la même personne, pour le clustering de
     * queueAmbiguousNearDateCombats().
     *
     * Deux heuristiques, l'une suffit :
     * 1. Préfixe commun d'au moins $prefixLen caractères sur le nom
     *    complet (rapide, capte "Boy Bambara" vs "Boy Kaïré" au même
     *    prénom/surnom initial).
     * 2. Un token (mot) d'au moins $prefixLen caractères de l'un est
     *    identique OU à distance de Levenshtein <= 2 d'un token d'au
     *    moins $prefixLen caractères de l'autre (capte "Baleine Bou
     *    Ndaw" vs "Ndiambane 2 (Baleine)" via le token "baleine",
     *    même si ce n'est pas le premier mot du nom).
     *
     * Les tokens numériques seuls (ex. "2" pour "Ndiambane 2") sont
     * ignorés : pas assez discriminants et souvent juste un numéro de
     * génération.
     */
    protected function opponentNamesLookRelated(string $oppNormA, string $oppNormB, int $prefixLen): bool
    {
        if (mb_strlen($oppNormA) < $prefixLen || mb_strlen($oppNormB) < $prefixLen) {
            return false;
        }

        // 1. Préfixe commun sur le nom complet.
        if (mb_substr($oppNormA, 0, $prefixLen) === mb_substr($oppNormB, 0, $prefixLen)) {
            return true;
        }

        // 2. Comparaison token à token (Levenshtein).
        $tokensA = $this->significantNameTokens($oppNormA, $prefixLen);
        $tokensB = $this->significantNameTokens($oppNormB, $prefixLen);

        foreach ($tokensA as $tokenA) {
            foreach ($tokensB as $tokenB) {
                if ($tokenA === $tokenB) {
                    return true;
                }
                // Seuil de distance adapté à la longueur du token le plus
                // court, pour éviter les faux positifs sur des tokens
                // courts (ex. "boy" vs "bou" à distance 1 sur 3 lettres
                // resterait accepté, mais deux tokens sans rapport de
                // longueur proche ne matcheront pas au hasard).
                $shorterLen = min(mb_strlen($tokenA), mb_strlen($tokenB));
                $maxDistance = $shorterLen <= 4 ? 1 : 2;
                if (levenshtein($tokenA, $tokenB) <= $maxDistance) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Découpe un nom normalisé en tokens "significatifs" : mots d'au
     * moins $prefixLen caractères, en excluant les tokens purement
     * numériques (numéros de génération type "2", "3").
     *
     * @return string[]
     */
    protected function significantNameTokens(string $nameNorm, int $prefixLen): array
    {
        $tokens = preg_split('/\s+/', trim($nameNorm), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($tokens, function (string $token) use ($prefixLen) {
            return mb_strlen($token) >= $prefixLen && !ctype_digit($token);
        }));
    }

    public function queueAmbiguousNearDateCombats(): int
    {
        $storage = \Drupal::entityTypeManager()->getStorage('node');
        $logger  = \Drupal::service('logger.factory')->get('shared_lib');
        $queue   = \Drupal::queue('confrontations_doublons_ai_queue');

        // Purge immédiate et déterministe des marqueurs "pending" orphelins :
        // si la queue confrontations_doublons_ai_queue est VIDE, alors PAR
        // DÉFINITION aucun marqueur "pending" ne peut être légitime (un
        // marqueur légitime correspond toujours à un item réellement présent
        // en queue, en attente de traitement). On peut donc purger tout
        // l'état "pending" instantanément dans ce cas, sans attendre
        // l'expiration du TTL (voir DOUBLONS_AI_PENDING_TTL_SECONDS, qui
        // reste un filet de sécurité pour le cas où la queue n'est PAS vide
        // mais où le marqueur est quand même orphelin — item bloqué/expiré
        // différemment).
        if ($queue->numberOfItems() === 0) {
            $pendingBefore = \Drupal::state()->get(self::DOUBLONS_AI_PENDING_STATE_KEY, []);
            if (!empty($pendingBefore)) {
                \Drupal::state()->delete(self::DOUBLONS_AI_PENDING_STATE_KEY);
                $logger->warning('@count marqueur(s) "pending" purgé(s) instantanément (queue confrontations_doublons_ai_queue vide au démarrage de queueAmbiguousNearDateCombats — impossible que ces marqueurs soient légitimes).', [
                    '@count' => count($pendingBefore),
                ]);
            }
        }

        $sevenDays  = 7 * 86400;
        $prefixLen  = 3;

        // Debug : si une liste de lutteurs est configurée, on ne met en queue
        // QUE les groupes de combats dont le pivot est l'un de ces lutteurs —
        // tous les autres sont ignorés dès le remplissage de la queue
        // (pas seulement au traitement), pour éviter de générer et de
        // faire tourner inutilement les groupes non concernés. Voir
        // confrontations_recentes.settings:prompt_ai_doublons_combats_debug_pivot_athlete.
        $debugPivotIds = \Drupal::config('confrontations_recentes.settings')
            ->get('prompt_ai_doublons_combats_debug_pivot_athlete') ?? [];
        // Rétrocompatibilité : ancienne config stockait un seul NID (int).
        if (!is_array($debugPivotIds)) {
            $debugPivotIds = $debugPivotIds ? [(int) $debugPivotIds] : [];
        }
        $debugPivotIds = array_values(array_filter(array_map('intval', $debugPivotIds)));

        if (!empty($debugPivotIds)) {
            $athlete_ids = $debugPivotIds;
            $logger->notice('queueAmbiguousNearDateCombats : mode debug actif, seuls les lutteurs pivot NID @nids seront traités.', [
                '@nids' => implode(', ', $debugPivotIds),
            ]);
        } else {
            $athlete_ids = $storage->getQuery()
                ->condition('type', 'athlete')
                ->accessCheck(false)
                ->execute();
        }

        $queued = 0;

        // Cache local (durée du run) du nombre de combats déjà enregistrés en
        // base par lutteur, pour alimenter les placeholders IA @nb_combats_pivot
        // et @nb_combats_adversaires (règle du dilemme phonétique/orthographique,
        // voir countCombatsForAthlete() plus bas).
        $combatCountCache = [];

        foreach ($athlete_ids as $athlete_id) {
            $pivotId = (int) $athlete_id;

            $fight_ids = $storage->getQuery()
                ->condition('type', 'confrontation')
                // On ignore les combats déjà dépubliés (= déjà supprimés par
                // un passage précédent du pipeline doublons) : sans ce
                // filtre, un combat dépublié reste chargeable via
                // storage->load() et reforme indéfiniment le même cluster
                // avec le(s) combat(s) conservé(s), provoquant un
                // retraitement IA inutile à chaque cron (double appel
                // Gemini+Grok, nouveau log de suppression, tentative de
                // réindexation d'un noeud déjà retiré de l'index).
                ->condition('status', 1)
                ->condition(
                    $storage->getQuery()->orConditionGroup()
                        ->condition('field_athlete_1', $pivotId)
                        ->condition('field_athlete_2', $pivotId)
                )
                ->sort('field_confrontation_date', 'ASC')
                ->sort('nid', 'ASC')
                ->accessCheck(false)
                ->execute();

            if (count($fight_ids) < 2) {
                continue;
            }

            $pivotNode = $storage->load($pivotId);
            if (!$pivotNode) {
                continue;
            }

            // Préparation des noeuds candidats avec timestamp + nom
            // d'adversaire normalisé, en ignorant ceux sans date exploitable
            // ou sans field_id_text (identifiant indispensable pour la
            // réponse IA). On écarte aussi les dates 01/01 non fiables
            // (par défaut < an 2000, ou 01/01 en surnombre pour ce pivot) —
            // voir filterUnreliableJanuaryFirstFights().
            $fights   = $this->filterUnreliableJanuaryFirstFights($storage->loadMultiple($fight_ids));
            $nodes    = [];
            foreach ($fights as $fight) {
                $date = $fight->get('field_confrontation_date')->value;
                $techId = (string) ($fight->get('field_id_text')->value ?? '');
                if (empty($date) || $techId === '') {
                    continue;
                }
                $ts = strtotime($date);
                if ($ts === false) {
                    continue;
                }

                $oppId   = $this->getOpponentId($fight, $pivotId);
                $oppNode = $oppId ? $storage->load($oppId) : null;
                $oppName = $oppNode ? $oppNode->getTitle() : '';
                $oppEcurie = '';
                if ($oppNode && $oppNode->hasField('field_ecurie') && !$oppNode->get('field_ecurie')->isEmpty()) {
                    $oppEcurieNode = $oppNode->get('field_ecurie')->entity;
                    $oppEcurie = $oppEcurieNode ? $oppEcurieNode->getTitle() : '';
                }

                $nodes[] = [
                    'fight'          => $fight,
                    'ts'             => $ts,
                    'date'           => $date,
                    'techId'         => $techId,
                    'oppId'          => $oppId,
                    'oppName'        => $oppName,
                    'oppEcurie'      => $oppEcurie,
                    'oppNorm'        => $oppName !== '' ? $this->textNormalizationHelper->normalizeForDistance($oppName) : '',
                    'isLutteSimple'  => $fight->hasField('field_is_lutte_simple') ? (bool) $fight->get('field_is_lutte_simple')->value : false,
                ];
            }

            $n = count($nodes);
            if ($n < 2) {
                continue;
            }

            // Union-Find (composantes connexes).
            $parent = range(0, $n - 1);
            $find = function (int $x) use (&$parent, &$find) {
                while ($parent[$x] !== $x) {
                    $parent[$x] = $parent[$parent[$x]];
                    $x = $parent[$x];
                }
                return $x;
            };
            $union = function (int $a, int $b) use (&$parent, $find) {
                $ra = $find($a);
                $rb = $find($b);
                if ($ra !== $rb) {
                    $parent[$ra] = $rb;
                }
            };

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if (abs($nodes[$i]['ts'] - $nodes[$j]['ts']) > $sevenDays) {
                        continue;
                    }
                    // Règle "hors lutte simple" : en lutte avec frappe, un
                    // même lutteur ne peut normalement pas affronter 2
                    // adversaires réels différents à ≤7 jours d'écart — donc
                    // même sans nom d'adversaire proche (ou inconnu), la
                    // seule proximité de date + même pivot suffit à
                    // suspecter un doublon (mauvaise identification
                    // d'adversaire par l'IA lors de l'import, pas un vrai
                    // tournoi). Cette règle ne s'applique pas si l'un des
                    // deux combats est déjà marqué lutte simple (là,
                    // plusieurs adversaires le même jour est normal — cf.
                    // mbappats/tournois).
                    if (!$nodes[$i]['isLutteSimple'] && !$nodes[$j]['isLutteSimple']) {
                        $union($i, $j);
                        continue;
                    }
                    // Sinon (au moins un des deux en lutte simple) : on ne
                    // regroupe que si l'adversaire semble être la même
                    // personne — soit mêmes 3 premiers caractères (nom
                    // normalisé), soit un mot (token) de l'un est très
                    // proche (Levenshtein) d'un mot de l'autre (ex.
                    // "Baleine Bou Ndaw" vs "Ndiambane 2 (Baleine)" partagent
                    // le token "baleine" alors qu'il n'est pas en position
                    // de préfixe dans le second nom).
                    if ($nodes[$i]['oppNorm'] === '' || $nodes[$j]['oppNorm'] === '') {
                        continue;
                    }
                    if (!$this->opponentNamesLookRelated($nodes[$i]['oppNorm'], $nodes[$j]['oppNorm'], $prefixLen)) {
                        continue;
                    }
                    // Nom strictement identique : déjà traité en amont par
                    // les règles déterministes si même date exacte. On
                    // laisse quand même passer ici au cas où les dates
                    // diffèrent (ce que les règles amont ne couvrent pas),
                    // l'IA confirmera.
                    $union($i, $j);
                }
            }

            // Regroupement par composante.
            $clusters = [];
            for ($i = 0; $i < $n; $i++) {
                $root = $find($i);
                $clusters[$root][] = $nodes[$i];
            }

            foreach ($clusters as $cluster) {
                if (count($cluster) < 2) {
                    continue;
                }

                // Cas particulier : groupe de seulement 2 combats, tous les
                // deux datés du 1er janvier de la MÊME année. Ce sont très
                // souvent deux dates "par défaut" (année connue mais jour/mois
                // inconnus) plutôt qu'un vrai doublon détecté par proximité
                // de date ; on ignore ce groupe (pas de mise en queue IA).
                if (count($cluster) === 2 && $this->isSameJanuaryFirstYearPair($cluster[0]['date'], $cluster[1]['date'])) {
                    // $logger->info('Groupe suspect ignoré (2 combats, tous deux au 1er janvier @year) : @pivot (@ids).', [
                    //     '@year'  => date('Y', strtotime($cluster[0]['date'])),
                    //     '@pivot' => $pivotNode->getTitle(),
                    //     '@ids'   => implode(', ', array_column($cluster, 'techId')),
                    // ]);
                    continue;
                }

                // Si ce même N-uplet de combats (mêmes field_id_text, quel
                // que soit l'ordre) a déjà été soumis à l'IA lors d'un
                // précédent passage et qu'AUCUNE suppression n'en avait
                // résulté (verdict "tous conservés"), on ne le renvoie plus
                // jamais à l'IA : voir isDoublonGroupVerifiedKept() /
                // markDoublonGroupVerifiedKept() (table
                // confrontations_recentes_ai_doublons_verified).
                $techIds = array_column($cluster, 'techId');
                if ($this->isDoublonGroupVerifiedKept($techIds)) {
                    // $logger->info('Groupe suspect ignoré (déjà vérifié IA, aucune suppression lors du précédent passage) : @pivot (@ids).', [
                    //     '@pivot' => $pivotNode->getTitle(),
                    //     '@ids'   => implode(', ', $techIds),
                    // ]);
                    continue;
                }

                // Idem si ce même groupe a déjà été mis en queue lors d'un
                // passage précédent et n'a pas encore été traité (voir
                // isDoublonGroupPending() / markDoublonGroupPending()) :
                // sans ce contrôle, chaque exécution de drush cdbl / cron
                // recréait un item strictement identique tant que le
                // précédent n'avait pas été dépilé, d'où l'accumulation
                // massive constatée en queue.
                if ($this->isDoublonGroupPending($techIds)) {
                    // $logger->info('Groupe suspect ignoré (déjà en attente de traitement IA dans la queue, non encore dépilé) : @pivot (@ids).', [
                    //     '@pivot' => $pivotNode->getTitle(),
                    //     '@ids'   => implode(', ', $techIds),
                    // ]);
                    continue;
                }

                // Écurie du lutteur pivot (utile pour désambiguïsation dans le prompt IA).
                $pivotEcurie = '';
                if ($pivotNode->hasField('field_ecurie') && !$pivotNode->get('field_ecurie')->isEmpty()) {
                    $pivotEcurieNode = $pivotNode->get('field_ecurie')->entity;
                    $pivotEcurie = $pivotEcurieNode ? $pivotEcurieNode->getTitle() : '';
                }

                // Carte adversaire → écurie pour le placeholder @ecuries_adversaires
                // (ex: {"Modou lo": "Parcelles Mbollo", "Balla gaye 2": "Xam Sa Cossan"}).
                // Carte adversaire → nombre de combats en base, pour le placeholder
                // @nb_combats_adversaires (règle du dilemme phonétique/orthographique
                // - ex: {"Aladji Gaïndé": 14, "Elhadj Gaindé": 1} - voir
                // countCombatsForAthlete()).
                $ecuriesAdversaires = [];
                $nbCombatsAdversaires = [];
                // Carte field_id_text → URLs vidéo du combat physique
                // (hors faux IDs de scraping), pour le placeholder IA
                // @videos_combat, au même niveau que @ecuries_adversaires
                // / @nb_combats_adversaires : preuve d'existence forte
                // exploitée par le Principe cardinal 7 du prompt IA
                // (prompt_ai_doublons_combats).
                $videosCombat = [];
                foreach ($cluster as $c) {
                    $oppName = $c['oppName'] !== '' ? $c['oppName'] : '(inconnu)';
                    $oppEcurie = $c['oppEcurie'] ?? '';
                    // On n'écrase pas une écurie déjà connue par une valeur vide.
                    if ($oppEcurie !== '' || !isset($ecuriesAdversaires[$oppName])) {
                        $ecuriesAdversaires[$oppName] = $oppEcurie;
                    }
                    if (!isset($nbCombatsAdversaires[$oppName])) {
                        $nbCombatsAdversaires[$oppName] = !empty($c['oppId'])
                            ? $this->countCombatsForAthlete((int) $c['oppId'], $combatCountCache)
                            : 0;
                    }
                    $videosCombat[$c['techId']] = $this->getValidVideoUrls($c['fight']);
                }

                $payload = [
                    'pivot_nid'              => $pivotId,
                    'pivot_name'             => $pivotNode->getTitle(),
                    'ecurie_pivot'           => $pivotEcurie,
                    'nb_combats_pivot'       => $this->countCombatsForAthlete($pivotId, $combatCountCache),
                    'ecuries_adversaires'    => $ecuriesAdversaires,
                    'nb_combats_adversaires' => $nbCombatsAdversaires,
                    'videos_combat'          => $videosCombat,
                    'date'                   => $cluster[0]['date'],
                    'combats'                => [],
                ];

                foreach ($cluster as $c) {
                    $payload['combats'][] = [
                        'nid'           => (int) $c['fight']->id(),
                        'field_id_text' => $c['techId'],
                        'lutteur1'      => $pivotNode->getTitle(),
                        'lutteur2'      => $c['oppName'] !== '' ? $c['oppName'] : '(inconnu)',
                        'date'          => $c['date'],
                        'vainqueur'     => (string) ($c['fight']->get('field_vainqueur')->value ?? ''),
                    ];
                }

                $queue->createItem($payload);
                $queued++;
                $this->markDoublonGroupPending($techIds);

                $logger->info('Groupe suspect (doublon IA, dates proches) mis en queue : @pivot (@n combats, dates : @dates).', [
                    '@pivot' => $payload['pivot_name'],
                    '@n'     => count($cluster),
                    '@dates' => implode(', ', array_column($cluster, 'date')),
                ]);
            }
        }

        return $queued;
    }

    /**
     * Table custom mémorisant les N-uplets (2 ou +) de combats suspects de
     * doublons déjà soumis à l'IA (queueAmbiguousNearDateCombats /
     * ConfrontationsDoublonsAiQueue) et pour lesquels le verdict a été
     * "tout conserver" (aucun delete=1). Une fois enregistrés ici, ces
     * mêmes N-uplets ne sont plus jamais re-proposés à l'IA.
     */
    const DOUBLONS_AI_VERIFIED_TABLE = 'confrontations_recentes_ai_doublons_verified';

    /**
     * Calcule la clé unique (et son hash) d'un groupe de combats à partir
     * de la liste de leurs field_id_text, triée par ordre alphabétique
     * avant concaténation, afin que la clé soit indépendante de l'ordre
     * dans lequel les combats ont été détectés/fournis.
     *
     * @param string[] $fieldIdTexts
     *
     * @return array{key: string, hash: string}
     */
    protected function buildDoublonGroupKey(array $fieldIdTexts): array
    {
        $ids = array_values(array_unique(array_map('strval', $fieldIdTexts)));
        sort($ids, SORT_STRING);
        $key = implode('|', $ids);
        return [
            'key'  => $key,
            'hash' => hash('sha256', $key),
        ];
    }

    /**
     * Indique si le N-uplet de combats donné (identifié par la liste de
     * leurs field_id_text) a déjà été vérifié par l'IA avec pour résultat
     * "tout conserver" (aucune suppression). Dans ce cas, on ne doit plus
     * chercher à le retraiter (voir queueAmbiguousNearDateCombats()).
     *
     * @param string[] $fieldIdTexts
     */
    public function isDoublonGroupVerifiedKept(array $fieldIdTexts): bool
    {
        if (count($fieldIdTexts) < 2) {
            return false;
        }
        $hash = $this->buildDoublonGroupKey($fieldIdTexts)['hash'];

        return (bool) \Drupal::database()->select(self::DOUBLONS_AI_VERIFIED_TABLE, 't')
            ->fields('t', ['id'])
            ->condition('group_key_hash', $hash)
            ->range(0, 1)
            ->execute()
            ->fetchField();
    }

    /**
     * Enregistre définitivement le N-uplet de combats donné comme "vérifié,
     * tout conservé" : il ne sera plus jamais soumis à l'IA pour la
     * résolution des doublons ambigus. Idempotent (clé unique sur le hash).
     *
     * @param string[] $fieldIdTexts
     */
    public function markDoublonGroupVerifiedKept(array $fieldIdTexts): void
    {
        if (count($fieldIdTexts) < 2) {
            return;
        }
        $built = $this->buildDoublonGroupKey($fieldIdTexts);

        \Drupal::database()->merge(self::DOUBLONS_AI_VERIFIED_TABLE)
            ->key('group_key_hash', $built['hash'])
            ->fields([
                'group_key' => $built['key'],
                'created'   => \Drupal::time()->getRequestTime(),
            ])
            ->execute();

        \Drupal::service('logger.factory')->get('shared_lib')->info(
            'Groupe de combats marqué "vérifié IA, tout conservé" (ne sera plus jamais re-soumis) : @ids.',
            ['@ids' => $built['key']]
        );
    }

    /**
     * Clé State utilisée pour suivre les N-uplets de combats suspects de
     * doublons déjà mis en queue (confrontations_doublons_ai_queue) et pas
     * encore traités par l'IA (ni marqués "vérifié", ni retraités suite à
     * un verdict avec suppression(s)). Sert à éviter de recréer un item
     * strictement identique à chaque exécution de
     * queueAmbiguousNearDateCombats() (drush cdbl ou cron) tant que l'item
     * précédemment mis en queue n'a pas fini d'être traité — c'est cette
     * absence de vérification qui provoquait l'accumulation massive de
     * doublons en queue (même groupe recréé à chaque passage).
     */
    const DOUBLONS_AI_PENDING_STATE_KEY = 'confrontations_recentes.doublons_ai_pending_group_hashes';

    /**
     * Durée maximale (en secondes) pendant laquelle un marqueur "pending"
     * reste valable avant expiration automatique.
     *
     * RAISON : si l'item correspondant disparaît de la queue par un autre
     * chemin que le traitement normal de ConfrontationsDoublonsAiQueue
     * (nettoyage manuel de la queue, purge d'orphelins, reset admin...),
     * clearDoublonGroupPending() n'est alors jamais appelé et le verrou
     * reste bloqué À VIE — le groupe n'est alors plus jamais resoumis à
     * l'IA, silencieusement, sans qu'aucun item ne soit réellement en
     * attente dans la queue (bug observé : "Groupe suspect ignoré (déjà en
     * attente...)" alors que la queue est vide). Un item met au pire
     * ~380s à être traité (2 appels IA avec retry, voir
     * ConfrontationsDoublonsAiQueue::AI_CALL_MAX_ATTEMPTS/RETRY_DELAY) ;
     * 1h de marge est largement suffisant pour absorber tout traitement
     * normal, y compris un cron ralenti.
     */
    const DOUBLONS_AI_PENDING_TTL_SECONDS = 3600;

    /**
     * Indique si le N-uplet de combats donné (identifié par la liste de
     * leurs field_id_text) est déjà en attente de traitement dans
     * confrontations_doublons_ai_queue : mis en queue lors d'un passage
     * précédent de queueAmbiguousNearDateCombats(), pas encore traité par
     * ConfrontationsDoublonsAiQueue::processItem(). Dans ce cas, on ne doit
     * pas en recréer un doublon.
     *
     * @param string[] $fieldIdTexts
     */
    public function isDoublonGroupPending(array $fieldIdTexts): bool
    {
        if (count($fieldIdTexts) < 2) {
            return false;
        }
        $hash = $this->buildDoublonGroupKey($fieldIdTexts)['hash'];
        $pending = \Drupal::state()->get(self::DOUBLONS_AI_PENDING_STATE_KEY, []);

        if (empty($pending[$hash])) {
            return false;
        }

        $markedAt = is_int($pending[$hash]) ? $pending[$hash] : 0;
        if ($markedAt > 0 && (\Drupal::time()->getRequestTime() - $markedAt) > self::DOUBLONS_AI_PENDING_TTL_SECONDS) {
            // Marqueur orphelin expiré : on le purge et on n'en tient pas
            // compte, plutôt que de bloquer le groupe indéfiniment.
            unset($pending[$hash]);
            \Drupal::state()->set(self::DOUBLONS_AI_PENDING_STATE_KEY, $pending);

            \Drupal::logger('shared_lib')->warning('Marqueur "pending" expiré/orphelin purgé pour le groupe de hash @hash (aucun item correspondant traité depuis plus de @ttl s) : le groupe redevient éligible à une nouvelle mise en queue IA.', [
                '@hash' => $hash,
                '@ttl'  => self::DOUBLONS_AI_PENDING_TTL_SECONDS,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Marque le N-uplet de combats donné comme "en attente" dans la queue.
     * Appelé juste après $queue->createItem($payload) dans
     * queueAmbiguousNearDateCombats().
     *
     * @param string[] $fieldIdTexts
     */
    public function markDoublonGroupPending(array $fieldIdTexts): void
    {
        if (count($fieldIdTexts) < 2) {
            return;
        }
        $hash = $this->buildDoublonGroupKey($fieldIdTexts)['hash'];
        $pending = \Drupal::state()->get(self::DOUBLONS_AI_PENDING_STATE_KEY, []);
        // Timestamp (et non TRUE) : nécessaire pour l'expiration automatique
        // des marqueurs orphelins, voir isDoublonGroupPending().
        $pending[$hash] = \Drupal::time()->getRequestTime();
        \Drupal::state()->set(self::DOUBLONS_AI_PENDING_STATE_KEY, $pending);
    }

    /**
     * Retire le N-uplet de combats donné de la liste "en attente".
     *
     * IMPORTANT : à appeler UNIQUEMENT une fois que
     * ConfrontationsDoublonsAiQueue::processItem() a terminé de traiter
     * l'item SANS lever de SuspendQueueException (donc juste avant que
     * Drupal ne supprime l'item de la queue, qu'un verdict ait été
     * appliqué ou rejeté par un garde-fou). Si une SuspendQueueException
     * est levée (échec technique IA), cette méthode ne doit volontairement
     * PAS être appelée : l'item reste dans la queue pour être retraité au
     * prochain cron, et doit donc rester marqué "en attente" pour ne pas
     * être recréé en double entre-temps par queueAmbiguousNearDateCombats().
     *
     * @param string[] $fieldIdTexts
     */
    public function clearDoublonGroupPending(array $fieldIdTexts): void
    {
        if (count($fieldIdTexts) < 2) {
            return;
        }
        $hash = $this->buildDoublonGroupKey($fieldIdTexts)['hash'];
        $pending = \Drupal::state()->get(self::DOUBLONS_AI_PENDING_STATE_KEY, []);
        if (isset($pending[$hash])) {
            unset($pending[$hash]);
            \Drupal::state()->set(self::DOUBLONS_AI_PENDING_STATE_KEY, $pending);
        }
    }

    /**
     * Remet à FALSE field_check_is_lutte_simple sur les combats déjà
     * existants des lutteurs $athleteIds où ce champ valait TRUE, en réaction
     * à l'insertion du combat $excludeNid.
     *
     * Appelée à chaque insertion d'un nouveau combat (hook_ENTITY_TYPE_insert,
     * voir confrontations_recentes_node_insert()) : l'arrivée d'un nouveau
     * combat change l'historique/le contexte du lutteur, donc un verdict IA
     * "pas lutte simple" déjà posé sur un ancien combat doit pouvoir être
     * réexaminé (le combat redevient éligible à queueSuspectedLutteSimpleCombats()).
     *
     * Portée du reset (évaluée indépendamment pour CHAQUE lutteur de
     * $athleteIds — un même combat opposant deux lutteurs peut être "le plus
     * récent" pour l'un et pas pour l'autre) :
     *  - Si le combat inséré ($excludeNid) est le PLUS RÉCENT de tous les
     *    combats du lutteur (par field_confrontation_date) : on ne réexamine
     *    que les 2 combats précédents immédiats (les plus proches
     *    chronologiquement) — les N-2 combats plus anciens restants ne sont
     *    PAS touchés (N = nombre total de combats du lutteur, nouveau combat
     *    inclus).
     *  - Sinon (combat inséré ailleurs dans l'historique, ex. rattrapage
     *    d'un vieux combat) : comportement historique inchangé, tout
     *    l'historique du lutteur est réexaminé.
     *
     * @param int[] $athleteIds
     * @param int   $excludeNid Nid du combat à ne pas toucher (celui qu'on
     *                          vient d'insérer, sans effet de toute façon
     *                          puisqu'il vaut FALSE par défaut à la création).
     */
    public function resetLutteSimpleCheckForAthletes(array $athleteIds, int $excludeNid = 0): void
    {
        $athleteIds = array_values(array_unique(array_filter(array_map('intval', $athleteIds))));
        if (empty($athleteIds)) {
            return;
        }

        $storage = \Drupal::entityTypeManager()->getStorage('node');
        $logger  = \Drupal::service('logger.factory')->get('shared_lib');

        foreach ($athleteIds as $athleteId) {
            $this->resetLutteSimpleCheckForOneAthlete($athleteId, $excludeNid, $storage, $logger);
        }
    }

    /**
     * Logique de reset de field_check_is_lutte_simple pour UN lutteur (voir
     * resetLutteSimpleCheckForAthletes() pour la règle de portée détaillée).
     */
    private function resetLutteSimpleCheckForOneAthlete(int $athleteId, int $excludeNid, $storage, $logger): void
    {
        // ── Historique complet du lutteur, trié chronologiquement (plus ancien → plus récent) ─
        $query = $storage->getQuery()
            ->condition('type', 'confrontation')
            ->accessCheck(false);

        $orGroup = $query->orConditionGroup()
            ->condition('field_athlete_1', $athleteId)
            ->condition('field_athlete_2', $athleteId);
        $query->condition($orGroup);

        $query->sort('field_confrontation_date', 'ASC')
              ->sort('nid', 'ASC');

        $nids = array_values($query->execute());
        if (empty($nids)) {
            return;
        }

        $newCombatIsMostRecent = $excludeNid > 0 && ((int) end($nids) === $excludeNid);

        if ($newCombatIsMostRecent && count($nids) > 1) {
            // Combat inséré = le plus récent du lutteur → on ne réexamine que
            // les 2 combats précédents immédiats (les N-2 plus anciens restent intouchés).
            $previousNids  = array_slice($nids, 0, -1); // tous les combats sauf le nouveau (dernier de la liste)
            $candidateNids = array_slice($previousNids, -2); // les 2 plus proches chronologiquement du nouveau
        } else {
            // Comportement historique : tout l'historique du lutteur (hors le combat inséré) est réexaminé.
            $candidateNids = array_values(array_filter($nids, fn($nid) => (int) $nid !== $excludeNid));
        }

        if (empty($candidateNids)) {
            return;
        }

        $resetQuery = $storage->getQuery()
            ->condition('type', 'confrontation')
            ->condition('nid', $candidateNids, 'IN')
            ->condition('field_check_is_lutte_simple', 1)
            ->accessCheck(false);

        $toReset = $resetQuery->execute();
        if (empty($toReset)) {
            return;
        }

        foreach ($storage->loadMultiple($toReset) as $node) {
            $node->set('field_check_is_lutte_simple', FALSE);
            $node->setNewRevision(FALSE);
            $node->save();

            $logger->info('field_check_is_lutte_simple réinitialisé (nouveau combat ajouté pour le lutteur) : NID @nid "@title".', [
                '@nid'   => $node->id(),
                '@title' => $node->getTitle(),
            ]);
        }
    }

    /**
     * Détecte les lutteurs ayant au moins 50% de leurs combats déjà marqués
     * field_is_lutte_simple = TRUE, et met en queue (par lots de 3 max) les
     * combats de ces lutteurs qui ne sont PAS encore marqués comme tels,
     * afin qu'une IA (via request_ai / sl_live_search) tranche pour chacun
     * d'eux s'il s'agit bien de lutte simple.
     *
     * Seuil minimal : un lutteur doit avoir au moins
     * self::LUTTE_SIMPLE_MIN_COMBATS combats connus pour être éligible,
     * afin d'éviter un ratio 50% non significatif (ex. 1 combat sur 1).
     *
     * @return int Nombre de lots (queue items) mis en queue.
     */
    public function queueSuspectedLutteSimpleCombats(int $queueLimit = 0): int
    {
        $storage = \Drupal::entityTypeManager()->getStorage('node');
        $logger  = \Drupal::service('logger.factory')->get('shared_lib');
        $queue   = \Drupal::queue('confrontations_lutte_simple_ai_queue');

        $minCombats = self::LUTTE_SIMPLE_MIN_COMBATS;
        $batchSize  = self::LUTTE_SIMPLE_BATCH_SIZE;

        $athlete_ids = $storage->getQuery()
            ->condition('type', 'athlete')
            ->accessCheck(false)
            ->execute();

        $queued = 0;

        foreach ($athlete_ids as $athlete_id) {
            $pivotId = (int) $athlete_id;

            $fight_ids = $storage->getQuery()
                ->condition('type', 'confrontation')
                ->condition(
                    $storage->getQuery()->orConditionGroup()
                        ->condition('field_athlete_1', $pivotId)
                        ->condition('field_athlete_2', $pivotId)
                )
                ->sort('field_confrontation_date', 'ASC')
                ->sort('nid', 'ASC')
                ->accessCheck(false)
                ->execute();

            $total = count($fight_ids);
            if ($total < $minCombats) {
                continue;
            }

            $fights = $storage->loadMultiple($fight_ids);

            // Pré-propagation "même jour" AVANT la mise en queue : si, pour
            // ce lutteur, une date donnée compte déjà au moins 2 combats
            // marqués lutte simple, on marque tout de suite les autres
            // combats de cette même date (déductible sans IA). Cela réduit
            // d'autant le nombre de combats à envoyer à l'IA plus bas.
            $this->propagateSameDayLutteSimpleAmongFights($fights, $logger);

            $lutteSimpleCount = 0;
            $unmarked = [];
            foreach ($fights as $fight) {
                if (!$fight->hasField('field_is_lutte_simple')) {
                    continue;
                }
                if ((bool) $fight->get('field_is_lutte_simple')->value) {
                    $lutteSimpleCount++;
                    continue;
                }
                // Déjà tranché par l'IA comme n'étant PAS lutte simple : on
                // ne le remet plus jamais en queue. Ça n'empêche pas la
                // propagation "même jour" de le corriger plus tard si le
                // contexte le justifie (elle ne regarde pas ce champ).
                if ($fight->hasField('field_check_is_lutte_simple') && (bool) $fight->get('field_check_is_lutte_simple')->value) {
                    continue;
                }
                $unmarked[] = $fight;
            }

            if ($total === 0 || ($lutteSimpleCount / $total) < 0.2) {
                continue;
            }

            if (empty($unmarked)) {
                // Déjà 100% traité (ou aucun combat non marqué à vérifier).
                continue;
            }

            $pivotNode = $storage->load($pivotId);
            if (!$pivotNode) {
                continue;
            }

            // Nom de l'écurie du lutteur, si disponible (donnée utile au
            // prompt IA : les combats de lutte simple/lutte avec frappe
            // suivent souvent des tendances par écurie).
            $ecurieName = '';
            if ($pivotNode->hasField('field_ecurie') && !$pivotNode->get('field_ecurie')->isEmpty()) {
                $ecurieNode = $pivotNode->get('field_ecurie')->entity;
                $ecurieName = $ecurieNode ? $ecurieNode->getTitle() : '';
            }

            // Tri par date de combat croissante (déjà globalement trié par
            // la requête, mais on re-trie le sous-ensemble $unmarked par
            // sécurité — l'ordre importe pour l'analyse par lots de 3).
            usort($unmarked, function ($a, $b) {
                $da = strtotime($a->get('field_confrontation_date')->value ?? '');
                $db = strtotime($b->get('field_confrontation_date')->value ?? '');
                return $da <=> $db;
            });

            $combatsPayload = [];
            foreach ($unmarked as $fight) {
                $oppId   = $this->getOpponentId($fight, $pivotId);
                $oppNode = $oppId ? $storage->load($oppId) : null;

                $combatsPayload[] = [
                    'nid'           => (int) $fight->id(),
                    'field_id_text' => (string) ($fight->get('field_id_text')->value ?? ''),
                    'lutteur1'      => $pivotNode->getTitle(),
                    'lutteur2'      => $oppNode ? $oppNode->getTitle() : '(inconnu)',
                    'date'          => $fight->get('field_confrontation_date')->value ?? '',
                    'vainqueur'     => (string) ($fight->get('field_vainqueur')->value ?? ''),
                ];
            }

            $batches = array_chunk($combatsPayload, $batchSize);

            foreach ($batches as $batch) {
                $payload = [
                    'pivot_nid'   => $pivotId,
                    'pivot_name'  => $pivotNode->getTitle(),
                    'ecurie_name' => $ecurieName,
                    'ratio'       => round(($lutteSimpleCount / $total) * 100, 1),
                    'combats'     => $batch,
                ];

                $queue->createItem($payload);
                $queued++;

                $logger->info('Lot lutte simple suspecte mis en queue : @pivot (@ratio% déjà lutte simple sur @total combats, écurie : @ecurie) - @n combat(s) du lot.', [
                    '@pivot'  => $payload['pivot_name'],
                    '@ratio'  => $payload['ratio'],
                    '@total'  => $total,
                    '@ecurie' => $ecurieName !== '' ? $ecurieName : '(inconnue)',
                    '@n'      => count($batch),
                ]);

                // Limite optionnelle du nombre de LOTS mis en queue (option
                // --queue-limit de "drush clsr"). 0 = pas de limite (tout
                // mettre en queue, comportement historique).
                if ($queueLimit > 0 && $queued >= $queueLimit) {
                    $logger->info('Limite de mise en queue atteinte (@limit lot(s)) : détection interrompue avant la fin.', ['@limit' => $queueLimit]);
                    return $queued;
                }
            }
        }

        return $queued;
    }

    /**
     * Propage la règle "même jour" au sein d'un ensemble de combats DÉJÀ
     * CHARGÉS pour un même lutteur (typiquement : tous les combats connus
     * d'un pivot, avant mise en queue). Regroupe par date de combat ; pour
     * toute date comptant déjà au moins 2 combats field_is_lutte_simple =
     * TRUE, marque immédiatement les autres combats de cette même date qui
     * ne le sont pas encore (déductible sans passer par l'IA).
     *
     * Modifie les entités passées en paramètre (par référence, via l'objet)
     * et les sauvegarde ; ne retourne rien, l'appelant doit relire les
     * champs après appel s'il a besoin des valeurs à jour (déjà le cas
     * puisqu'il s'agit des mêmes objets en mémoire).
     *
     * @param \Drupal\node\NodeInterface[] $fights
     * @param \Psr\Log\LoggerInterface $logger
     */
    protected function propagateSameDayLutteSimpleAmongFights(array $fights, $logger): void
    {
        $byDate = [];
        foreach ($fights as $fight) {
            if (!$fight->hasField('field_is_lutte_simple') || !$fight->hasField('field_confrontation_date')) {
                continue;
            }
            $date = $fight->get('field_confrontation_date')->value;
            if (empty($date)) {
                continue;
            }
            $byDate[$date][] = $fight;
        }

        foreach ($byDate as $date => $dayFights) {
            if (count($dayFights) < 2) {
                continue;
            }

            $lutteSimpleCount = 0;
            $notYetMarked = [];
            foreach ($dayFights as $fight) {
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

                $logger->info('LUTTE SIMPLE (propagation même jour, pré-queue) : NID @nid "@title" le @date - au moins 2 combats du même lutteur déjà en lutte simple ce jour-là.', [
                    '@nid'   => $fight->id(),
                    '@title' => $fight->getTitle(),
                    '@date'  => $date,
                ]);
            }
        }
    }

    /**
     * Clé State utilisée pour suivre les field_id_text de combats déjà mis
     * en queue "lutte simple" et pas encore traités (ni marqués, ni
     * confirmés "non lutte simple" par l'IA). Sert à éviter de remettre en
     * queue les mêmes combats à chaque exécution de
     * queueSuspectedLutteSimpleCombats() tant que le cron n'a pas eu le
     * temps de vider la queue précédente.
     */
    const LUTTE_SIMPLE_PENDING_STATE_KEY = 'confrontations_recentes.lutte_simple_pending_ids';

    /**
     * Retourne l'ensemble (field_id_text => TRUE) des combats actuellement
     * en attente de traitement IA dans confrontations_lutte_simple_ai_queue.
     */
    public static function getPendingLutteSimpleIds(): array
    {
        return \Drupal::state()->get(self::LUTTE_SIMPLE_PENDING_STATE_KEY, []);
    }

    /**
     * Ajoute des field_id_text à la liste des combats en attente (appelé au
     * moment de la mise en queue).
     */
    public static function addPendingLutteSimpleIds(array $fieldIdTexts): void
    {
        if (empty($fieldIdTexts)) {
            return;
        }
        $pending = self::getPendingLutteSimpleIds();
        foreach ($fieldIdTexts as $id) {
            $pending[(string) $id] = TRUE;
        }
        \Drupal::state()->set(self::LUTTE_SIMPLE_PENDING_STATE_KEY, $pending);
    }

    /**
     * Retire des field_id_text de la liste des combats en attente (appelé
     * quand un item de queue est effectivement traité — marqué ou laissé
     * tel quel — PAS quand son traitement est suspendu/reporté).
     */
    public static function removePendingLutteSimpleIds(array $fieldIdTexts): void
    {
        if (empty($fieldIdTexts)) {
            return;
        }
        $pending = self::getPendingLutteSimpleIds();
        foreach ($fieldIdTexts as $id) {
            unset($pending[(string) $id]);
        }
        \Drupal::state()->set(self::LUTTE_SIMPLE_PENDING_STATE_KEY, $pending);
    }

    /**
     * Nombre total de combats (nœuds "confrontation", tous statuts confondus)
     * enregistrés en base pour un lutteur donné, où il apparaît en
     * field_athlete_1 ou field_athlete_2.
     *
     * Alimente les placeholders IA @nb_combats_pivot et
     * @nb_combats_adversaires du prompt prompt_ai_doublons_combats, utilisés
     * pour arbitrer le "dilemme phonétique/orthographique" entre deux
     * graphies du même lutteur (ex: "Aladji Gaïndé" vs "Elhadj Gaindé") : on
     * conserve la variante possédant le plus grand nombre de combats en base.
     *
     * Seuls les combats publiés (status = 1) sont comptés, par cohérence
     * avec le filtre déjà appliqué au pivot dans queueAmbiguousNearDateCombats()
     * (un combat dépublié = déjà retiré par un passage précédent du pipeline
     * doublons, il ne doit pas peser dans l'arbitrage).
     *
     * @param int   $athleteId
     * @param array &$cache Cache local (par ID) à passer par référence, pour
     *                       éviter de recompter le même lutteur plusieurs
     *                       fois lorsqu'il apparaît dans plusieurs groupes.
     */
    private function countCombatsForAthlete(int $athleteId, array &$cache = []): int
    {
        if ($athleteId <= 0) {
            return 0;
        }
        if (isset($cache[$athleteId])) {
            return $cache[$athleteId];
        }

        $storage = \Drupal::entityTypeManager()->getStorage('node');
        $count = (int) $storage->getQuery()
            ->condition('type', 'confrontation')
            ->condition('status', 1)
            ->condition(
                $storage->getQuery()->orConditionGroup()
                    ->condition('field_athlete_1', $athleteId)
                    ->condition('field_athlete_2', $athleteId)
            )
            ->accessCheck(false)
            ->count()
            ->execute();

        $cache[$athleteId] = $count;

        return $count;
    }

    /**
     * Retourne l'ID de l'adversaire du $pivotId dans un combat donné.
     *
     * @return int|null L'ID de l'adversaire, ou NULL si introuvable.
     */
    private function getOpponentId($fight, int $pivotId): ?int
    {
        $athlete1 = (int) $fight->get('field_athlete_1')->target_id;
        $athlete2 = (int) $fight->get('field_athlete_2')->target_id;

        if ($athlete1 === $pivotId) {
            return $athlete2 ?: null;
        }
        if ($athlete2 === $pivotId) {
            return $athlete1 ?: null;
        }

        return null;
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
    public function forceCleanTechnicalDoublons()
    {
        $database = \Drupal::database();
        $storage = \Drupal::entityTypeManager()->getStorage("node");
        $logger = \Drupal::service('logger.factory')->get('shared_lib');

        // 1. Recherche des valeurs de field_id_text en doublon via SQL
        $duplicate_values = $database->query("
            SELECT field_id_text_value 
            FROM {node__field_id_text} 
            GROUP BY field_id_text_value 
            HAVING COUNT(*) > 1
        ")->fetchCol();

        if (empty($duplicate_values)) {
            return [];
        }

        $report = [];

        foreach ($duplicate_values as $tech_id) {
            // 2. Pour chaque ID technique, on récupère tous les NIDs par ordre croissant
            $nids = $storage->getQuery()
                ->condition('type', 'confrontation')
                ->condition('field_id_text', $tech_id)
                ->sort('nid', 'ASC')
                ->accessCheck(FALSE)
                ->execute();

            if (count($nids) > 1) {
                $nid_list = array_values($nids);

                // On extrait le premier NID pour servir de Pivot
                $keep_nid = array_shift($nid_list);
                $pivot_node = $storage->load($keep_nid);
                $pivot_title = $pivot_node ? $pivot_node->getTitle() : "Titre inconnu";

                $report[] = "PIVOT CONSERVÉ : NID [$keep_nid] - $pivot_title";

                // 3. Suppression des autres occurrences (les doublons)
                foreach ($nid_list as $del_nid) {
                    $del_node = $storage->load($del_nid);
                    $del_title = $del_node ? $del_node->getTitle() : "Orphelin SQL";

                    if ($del_node) {
                        $del_node->delete();
                    } else {
                        // Nettoyage manuel de la table de champ si le nœud est déjà corrompu
                        $database->delete('node__field_id_text')
                            ->condition('entity_id', $del_nid)
                            ->execute();
                    }

                    $report[] = "   >> SUPPRIMÉ : NID [$del_nid] - $del_title";

                    // Log système pour la traçabilité
                    $logger->error("Force Clean : Pivot @keep conservé, @del supprimé (ID Tech: @id)", [
                        '@keep' => $keep_nid,
                        '@del' => $del_nid,
                        '@id' => $tech_id
                    ]);
                }
                $report[] = "------------------------------------------------";
            }
        }

        // 4. On vide les caches pour refléter les suppressions immédiatement
        drupal_flush_all_caches();

        return $report;
    }

    /**
     * Vérification resserrée : Levenshtein total <= 2 ET chaque nom <= 1
     * Si la distance est de 2, on réduit aussi la fenêtre de jours.
     */
    private function checkIfPotentialDuplicate(
        NodeInterface $fight1,
        NodeInterface $fight2,
        $athlete_id,
        $athlete_name
    ): ?array {

        $d1 = $fight1->get('field_confrontation_date')->value;
        $d2 = $fight2->get('field_confrontation_date')->value;
        $v1 = $fight1->get('field_vainqueur')->value ?? '';
        $v2 = $fight2->get('field_vainqueur')->value ?? '';

        if ($v1 !== $v2 || empty($d1) || empty($d2)) {
            return null;
        }

        $diff_days = abs(strtotime($d1) - strtotime($d2)) / 86400;

        $name1a = $this->getCleanAthleteNameForLevenshtein($fight1->get('field_athlete_1')->target_id);
        $name1b = $this->getCleanAthleteNameForLevenshtein($fight1->get('field_athlete_2')->target_id);
        $name2a = $this->getCleanAthleteNameForLevenshtein($fight2->get('field_athlete_1')->target_id);
        $name2b = $this->getCleanAthleteNameForLevenshtein($fight2->get('field_athlete_2')->target_id);

        // Calcul des distances individuelles
        $lev1 = min(levenshtein($name1a, $name2a), levenshtein($name1a, $name2b));
        $lev2 = min(levenshtein($name1b, $name2a), levenshtein($name1b, $name2b));
        $total_lev = $lev1 + $lev2;

        // --- SERRAGE DE BOULONS ---

        // 1. On refuse si l'un des deux athlètes a plus de 1 de distance (évite Armée vs Marley)
        if ($lev1 > 1 || $lev2 > 1) {
            return null;
        }

        // 2. Critères combinés Distance / Temps :
        // - Si noms parfaits (0) : on garde l'année (365j)
        // - Si petite coquille (1 ou 2) : on réduit à 3 mois (90j) pour limiter les erreurs
        $is_suspicious = false;

        if ($total_lev == 0 && $diff_days <= 365) {
            $is_suspicious = true;
        } elseif ($total_lev <= 2 && $diff_days <= 90) {
            $is_suspicious = true;
        }

        if ($is_suspicious) {
            return $this->buildDuplicateEntry($fight2, $fight1, $total_lev, $diff_days, $athlete_id, $athlete_name);
        }

        return null;
    }

    /**
     * Formate l'entrée pour le stockage des résultats
     */
    private function buildDuplicateEntry(
        $current,
        $previous,
        $lev,
        $days,
        $athlete_id,
        $athlete_name
    ): array {
        return [
            "athlete_id" => $athlete_id,
            "athlete_name" => $athlete_name,
            "nid" => $current->id(),
            "title" => $current->getTitle(),
            "date" => $current->get("field_confrontation_date")->value,
            "prev_nid" => $previous->id(),
            "prev_title" => $previous->getTitle(),
            "prev_date" => $previous->get("field_confrontation_date")->value,
            "lev_distance" => $lev,
            "diff_days" => round($days, 1),
        ];
    }

    /**
     * Affiche le tableau final formaté
     */
    private function renderDuplicateTableByAthlete(
        array $duplicates_by_athlete
    ) {
        if (empty($duplicates_by_athlete)) {
            echo "\n✅ Aucun doublon potentiel trouvé (Levenshtein ≤ 3 + max 90 jours).\n";
            return;
        }

        echo "\n🚨 DOUBLONS POTENTIELS TROUVÉS (" .
            count($duplicates_by_athlete) .
            " lutteurs concernés)\n";

        foreach ($duplicates_by_athlete as $athlete_id => $dups) {
            $first = $dups[0];
            echo "\n🏆 ATHLÈTE : {$first["athlete_name"]} (ID {$athlete_id})\n";

            // Masque de colonnes : NID | Titre Actuel | Date | Lev | Jours | P.NID | Titre Précédent
            $mask = "%-8s | %-40s | %-12s | %-4s | %-6s | %-8s | %-40s\n";

            echo str_repeat("-", 170) . "\n";
            printf(
                $mask,
                "NID",
                "Combat Actuel",
                "Date",
                "Lev",
                "Jours",
                "P.NID",
                "Combat Suspect (Précédent)"
            );
            echo str_repeat("-", 170) . "\n";

            foreach ($dups as $dup) {
                printf(
                    $mask,
                    $dup["nid"],
                    mb_strimwidth($dup["title"], 0, 39, "..."),
                    $dup["date"],
                    $dup["lev_distance"],
                    $dup["diff_days"],
                    $dup["prev_nid"],
                    mb_strimwidth($dup["prev_title"], 0, 39, "...")
                );
            }
        }
    }

    /**
     * Nettoie les doublons de type "a_venir" : même paire de lutteurs, seule la date change.
     * Garde le node avec la date la PLUS RÉCENTE.
     * Transfère uniquement field_avenir_face2face et field_no_index.
     */
    public function cleanDuplicateAvenir(?StyleInterface $io = null): void
    {
        $logger = \Drupal::logger('shared_lib');
        $storage = \Drupal::entityTypeManager()->getStorage('node');

        echo "cleanDuplicateAvenir : DÉMARRAGE\n";

        $avenir_nids = $storage->getQuery()
            ->condition('type', 'a_venir')
            ->accessCheck(false)
            ->execute();

        if (empty($avenir_nids)) {
            echo "Aucun a_venir trouvé.\n";
            return;
        }

        $avenirs = $storage->loadMultiple($avenir_nids);
        echo "→ " . count($avenirs) . " nœuds a_venir analysés.\n";

        // Grouper par paire de lutteurs (ordre indifférent)
        $groups = [];
        foreach ($avenirs as $avenir) {
            $l1 = (int) ($avenir->get('field_lutteur_1')->target_id ?? 0);
            $l2 = (int) ($avenir->get('field_lutteur_2')->target_id ?? 0);

            if ($l1 === 0 || $l2 === 0) continue;

            $key = $l1 < $l2 ? "{$l1}_{$l2}" : "{$l2}_{$l1}";
            $groups[$key][] = $avenir;
        }

        $treated = 0;

        foreach ($groups as $key => $nodes) {
            if (count($nodes) < 2) continue;

            // Trier par date DESC (plus récent en premier) → champ = field_date
            usort($nodes, function ($a, $b) {
                $da = $a->get('field_date')->value ?? '0000-00-00';
                $db = $b->get('field_date')->value ?? '0000-00-00';
                return strcmp($db, $da); // DESC
            });

            $keeper = $nodes[0];           // Le plus récent
            $duplicates = array_slice($nodes, 1);

            $keeperId = $keeper->id();
            $keeperDate = $keeper->get('field_date')->value ?? '???';

            foreach ($duplicates as $old) {
                $oldId = $old->id();
                $oldDate = $old->get('field_date')->value ?? '???';

                $transferred = [];

                // Transfert uniquement des deux champs demandés
                foreach (['field_avenir_face2face', 'field_no_index'] as $fieldName) {
                    if ($old->hasField($fieldName) && $keeper->hasField($fieldName)) {
                        $oldValue = $old->get($fieldName)->getValue();
                        $keeperValue = $keeper->get($fieldName)->getValue();

                        if (!empty($oldValue) && $oldValue !== $keeperValue) {
                            $keeper->set($fieldName, $oldValue);
                            $transferred[] = $fieldName;
                        }
                    }
                }

                if (!empty($transferred)) {
                    $keeper->setNewRevision(false);
                    $keeper->save();
                }

                // Suppression de l'ancien
                try {
                    $old->delete();
                    $storage->resetCache([$oldId]);

                    $fieldsStr = implode(', ', $transferred) ?: '(aucun)';
                    $msg = "DOUBLON A_VENIR → Gardé [{$keeperId}] (date {$keeperDate}) | Supprimé [{$oldId}] (date {$oldDate}) | Champs transférés : {$fieldsStr}";

                    echo $msg . "\n";
                    $logger->notice('cleanDuplicateAvenir : ' . $msg);

                    $treated++;
                } catch (\Exception $e) {
                    $logger->error('cleanDuplicateAvenir : Échec suppression [@old] : @msg', [
                        '@old' => $oldId,
                        '@msg' => $e->getMessage()
                    ]);
                }
            }
        }

        echo "cleanDuplicateAvenir : FIN — {$treated} doublons traités.\n";
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
    public function checkNearDuplicateConfrontation(
        int $athlete1Id,
        int $athlete2Id,
        string $newDateStr,
        string $newVerdict,
        $storage,
        $logger
    ): bool {
        if (empty($newDateStr) || $athlete1Id <= 0 || $athlete2Id <= 0) {
            $logger->debug('checkNearDuplicateConfrontation : ABORT (paramètres invalides) | a1=@a1 a2=@a2 date=@d', [
                '@a1' => $athlete1Id,
                '@a2' => $athlete2Id,
                '@d' => $newDateStr,
            ]);
            return false;
        }

        $newTs = strtotime($newDateStr);
        if ($newTs === false) {
            return false;
        }

        // ── Requête candidats : peu importe l'ordre, on cherche toute
        // confrontation où LA PAIRE {athlete1Id, athlete2Id} apparaît,
        // quel que soit celui stocké dans field_athlete_1 ou field_athlete_2.
        // On combine 2 groupes OR pour couvrir les 2 ordres possibles.
        $orderA = $storage->getQuery()->andConditionGroup()
            ->condition('field_athlete_1', $athlete1Id)
            ->condition('field_athlete_2', $athlete2Id);

        $orderB = $storage->getQuery()->andConditionGroup()
            ->condition('field_athlete_1', $athlete2Id)
            ->condition('field_athlete_2', $athlete1Id);

        $pairGroup = $storage->getQuery()->orConditionGroup()
            ->condition($orderA)
            ->condition($orderB);

        $candidateIds = $storage->getQuery()
            ->condition('type', 'confrontation')
            ->condition($pairGroup)
            ->accessCheck(FALSE)
            ->execute();

        $logger->debug(
            'checkNearDuplicateConfrontation : recherche paire a1=@a1 a2=@a2 (ordre indifférent) → @nb candidat(s) trouvé(s) : [@ids]',
            ['@a1' => $athlete1Id, '@a2' => $athlete2Id, '@nb' => count($candidateIds), '@ids' => implode(',', $candidateIds)]
        );

        if (empty($candidateIds)) {
            return false;
        }

        foreach ($storage->loadMultiple($candidateIds) as $existing) {
            $existingDateStr = $existing->get('field_confrontation_date')->value ?? '';
            if (empty($existingDateStr)) {
                continue;
            }

            $existingTs = strtotime($existingDateStr);
            if ($existingTs === false) {
                continue;
            }

            $diffDays = abs($newTs - $existingTs) / 86400;

            $logger->debug(
                'checkNearDuplicateConfrontation : candidat NID @nid | date_existante=@ed | date_nouvelle=@nd | ecart_jours=@diff',
                ['@nid' => $existing->id(), '@ed' => $existingDateStr, '@nd' => $newDateStr, '@diff' => round($diffDays, 1)]
            );

            if ($diffDays > 365) {
                continue; // Hors fenêtre de 1 an, pas un doublon.
            }

            // ── Doublon potentiel confirmé (même paire, peu importe l'ordre, < 1 an) ──
            $isNewJan1      = (date('m-d', $newTs) === '01-01');
            $isExistingJan1 = (date('m-d', $existingTs) === '01-01');

            $finalDateStr   = $existingDateStr;
            $needDateUpdate = false;

            if (!$isNewJan1 && !$isExistingJan1) {
                // Cas 1 : ni l'une ni l'autre n'est le 1er janvier.
                if ($newTs < $existingTs) {
                    $finalDateStr   = date('Y-m-d', $newTs);
                    $needDateUpdate = ($finalDateStr !== $existingDateStr);
                }
            } elseif ($isNewJan1 xor $isExistingJan1) {
                // Cas 2 : une seule des deux est le 1er janvier.
                if ($isExistingJan1 && !$isNewJan1) {
                    $finalDateStr   = date('Y-m-d', $newTs);
                    $needDateUpdate = ($finalDateStr !== $existingDateStr);
                }
            } else {
                // Cas 3 : les deux sont le 1er janvier.
                $yearNew      = (int) date('Y', $newTs);
                $yearExisting = (int) date('Y', $existingTs);

                if ($yearNew !== $yearExisting && $newTs < $existingTs) {
                    $finalDateStr   = date('Y-m-d', $newTs);
                    $needDateUpdate = ($finalDateStr !== $existingDateStr);
                }
            }

            // ── Verdict : toujours préférer un résultat renseigné ("!= ?") ────────
            $existingVerdict   = (string) ($existing->get('field_vainqueur')->value ?? '');
            $needVerdictUpdate = (
                trim($existingVerdict) === '?'
                && trim($newVerdict) !== ''
                && trim($newVerdict) !== '?'
            );

            $logger->debug(
                'checkNearDuplicateConfrontation : décision NID @nid | isNewJan1=@nj isExistingJan1=@ej | needDateUpdate=@ndu (final=@fd) | needVerdictUpdate=@nvu (existant=@ev, nouveau=@nv)',
                [
                    '@nid' => $existing->id(),
                    '@nj'  => $isNewJan1 ? 'oui' : 'non',
                    '@ej'  => $isExistingJan1 ? 'oui' : 'non',
                    '@ndu' => $needDateUpdate ? 'oui' : 'non',
                    '@fd'  => $finalDateStr,
                    '@nvu' => $needVerdictUpdate ? 'oui' : 'non',
                    '@ev'  => $existingVerdict,
                    '@nv'  => $newVerdict,
                ]
            );

            if ($needDateUpdate || $needVerdictUpdate) {
                if ($needDateUpdate) {
                    $existing->set('field_confrontation_date', $finalDateStr);
                }
                if ($needVerdictUpdate) {
                    $existing->set('field_vainqueur', $newVerdict);
                }

                try {
                    $existing->setNewRevision(FALSE);
                    $existing->save();
                    $logger->notice(
                        'checkNearDuplicateConfrontation : Doublon NID @nid mis à jour (date_maj=@du, verdict_maj=@vu)',
                        ['@nid' => $existing->id(), '@du' => $needDateUpdate ? 'oui' : 'non', '@vu' => $needVerdictUpdate ? 'oui' : 'non']
                    );
                } catch (\Exception $e) {
                    $logger->error(
                        'checkNearDuplicateConfrontation : échec MAJ NID @nid : @msg',
                        ['@nid' => $existing->id(), '@msg' => $e->getMessage()]
                    );
                }
            } else {
                $logger->info(
                    'checkNearDuplicateConfrontation : Doublon NID @nid détecté, aucune mise à jour nécessaire — nouvelle confrontation ignorée.',
                    ['@nid' => $existing->id()]
                );
            }

            return true; // Doublon confirmé : ne PAS insérer la nouvelle confrontation.
        }

        return false;
    }

    // =========================================================================
    // A_VENIR — doublons même date + un protagoniste commun (résolution IA)
    // =========================================================================

    /**
     * Clé State pour les groupes a_venir déjà mis en queue IA (pending).
     */
    const AVENIR_DOUBLONS_AI_PENDING_STATE_KEY = 'confrontations_recentes.avenir_doublons_ai_pending_group_hashes';

    /**
     * Détecte les groupes de nœuds a_venir ambigus selon la règle métier :
     *   même date (jour calendaire) + au moins un lutteur en commun (même NID)
     * ⇒ un lutteur ne peut pas avoir deux combats à venir distincts le même jour.
     * Les groupes (≥ 2 nœuds) sont mis en queue a_venir_doublons_ai_queue pour
     * que l'IA tranche quelle variante conserver.
     *
     * Aucun filtre de similarité de nom sur l'adversaire : la règle est purement
     * structurelle. L'IA reçoit le contexte (suggestions, historiques, nb combats)
     * pour choisir la bonne variante.
     *
     * @return int Nombre de groupes mis en queue.
     */
    public function queueAmbiguousAvenirSameDateCombats(): int
    {
        $storage = \Drupal::entityTypeManager()->getStorage('node');
        $logger  = \Drupal::service('logger.factory')->get('shared_lib');
        $queue   = \Drupal::queue('a_venir_doublons_ai_queue');

        // Purge marqueurs pending orphelins si la queue est vide.
        if ($queue->numberOfItems() === 0) {
            $pendingBefore = \Drupal::state()->get(self::AVENIR_DOUBLONS_AI_PENDING_STATE_KEY, []);
            if (!empty($pendingBefore)) {
                \Drupal::state()->delete(self::AVENIR_DOUBLONS_AI_PENDING_STATE_KEY);
                $logger->warning('@count marqueur(s) a_venir "pending" purgé(s) (queue a_venir_doublons_ai_queue vide).', [
                    '@count' => count($pendingBefore),
                ]);
            }
        }

        $nids = $storage->getQuery()
            ->condition('type', 'a_venir')
            ->condition('status', 1)
            ->accessCheck(FALSE)
            ->execute();

        if (empty($nids) || count($nids) < 2) {
            return 0;
        }

        $nodes = $storage->loadMultiple($nids);

        // Index : date (Y-m-d) => liste d'entrées {nid, techId, l1, l2, labels, face2face}
        $byDate = [];
        foreach ($nodes as $node) {
            $dateRaw = (string) ($node->get('field_date')->value ?? '');
            if ($dateRaw === '') {
                continue;
            }
            $day = substr($dateRaw, 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                continue;
            }
            $l1 = (int) ($node->get('field_lutteur_1')->target_id ?? 0);
            $l2 = (int) ($node->get('field_lutteur_2')->target_id ?? 0);
            if ($l1 === 0 || $l2 === 0) {
                continue;
            }
            $techId = trim((string) ($node->get('field_id_text')->value ?? ''));
            if ($techId === '') {
                $techId = 'nid_' . $node->id();
            }

            $face2face = [];
            if ($node->hasField('field_avenir_face2face') && !$node->get('field_avenir_face2face')->isEmpty()) {
                foreach ($node->get('field_avenir_face2face')->getValue() as $item) {
                    if (!empty($item['uri'])) {
                        $face2face[] = $item['uri'];
                    }
                }
            }

            $label1 = '';
            $label2 = '';
            if ($node->get('field_lutteur_1')->entity) {
                $label1 = $node->get('field_lutteur_1')->entity->label();
            }
            if ($node->get('field_lutteur_2')->entity) {
                $label2 = $node->get('field_lutteur_2')->entity->label();
            }

            $byDate[$day][] = [
                'nid'       => (int) $node->id(),
                'techId'    => $techId,
                'l1'        => $l1,
                'l2'        => $l2,
                'label1'    => $label1,
                'label2'    => $label2,
                'face2face' => $face2face,
                'node'      => $node,
            ];
        }

        $queued = 0;
        $combatCountCache = [];

        foreach ($byDate as $day => $entries) {
            if (count($entries) < 2) {
                continue;
            }

            $n = count($entries);
            // Union-find sur indices : relier si au moins un lutteur en commun.
            $parent = range(0, $n - 1);
            $find = function ($i) use (&$parent, &$find) {
                if ($parent[$i] !== $i) {
                    $parent[$i] = $find($parent[$i]);
                }
                return $parent[$i];
            };
            $union = function ($i, $j) use (&$parent, $find) {
                $ri = $find($i);
                $rj = $find($j);
                if ($ri !== $rj) {
                    $parent[$ri] = $rj;
                }
            };

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $entries[$i];
                    $b = $entries[$j];
                    $shared = ($a['l1'] === $b['l1'] || $a['l1'] === $b['l2']
                        || $a['l2'] === $b['l1'] || $a['l2'] === $b['l2']);
                    if ($shared) {
                        $union($i, $j);
                    }
                }
            }

            $clusters = [];
            for ($i = 0; $i < $n; $i++) {
                $root = $find($i);
                $clusters[$root][] = $entries[$i];
            }

            foreach ($clusters as $cluster) {
                if (count($cluster) < 2) {
                    continue;
                }

                $techIds = array_column($cluster, 'techId');
                if ($this->isAvenirDoublonGroupPending($techIds)) {
                    continue;
                }

                // Identifier un pivot = lutteur présent dans le plus de nœuds du cluster.
                $freq = [];
                foreach ($cluster as $c) {
                    $freq[$c['l1']] = ($freq[$c['l1']] ?? 0) + 1;
                    $freq[$c['l2']] = ($freq[$c['l2']] ?? 0) + 1;
                }
                arsort($freq);
                $pivotId = (int) array_key_first($freq);
                $pivotNode = $storage->load($pivotId);
                $pivotName = $pivotNode ? $pivotNode->getTitle() : ('nid:' . $pivotId);

                $pivotEcurie = '';
                if ($pivotNode && $pivotNode->hasField('field_ecurie') && !$pivotNode->get('field_ecurie')->isEmpty()) {
                    $ec = $pivotNode->get('field_ecurie')->entity;
                    $pivotEcurie = $ec ? $ec->label() : '';
                }

                $adversaires = [];
                $nbCombatsAdversaires = [];
                $historiqueAdversaires = [];
                $suggestionsAdversaires = [];

                $payloadCombats = [];
                foreach ($cluster as $c) {
                    $oppId = ($c['l1'] === $pivotId) ? $c['l2'] : $c['l1'];
                    $oppName = ($c['l1'] === $pivotId) ? $c['label2'] : $c['label1'];
                    if ($oppName === '') {
                        $oppName = '(inconnu)';
                    }
                    $adversaires[$oppName] = $oppId;
                    if (!isset($nbCombatsAdversaires[$oppName])) {
                        $nbCombatsAdversaires[$oppName] = $this->countCombatsForAthlete($oppId, $combatCountCache);
                    }
                    if (!isset($historiqueAdversaires[$oppName])) {
                        $historiqueAdversaires[$oppName] = \Drupal\shared_lib\Tools\SomeTools::getRecentConfrontationsJson($oppName, 2);
                    }
                    if (!isset($suggestionsAdversaires[$oppName])) {
                        $suggestionsAdversaires[$oppName] = \Drupal\shared_lib\Tools\SomeTools::findSimilarAthleteNames($oppName, 6);
                    }

                    $payloadCombats[] = [
                        'nid'           => $c['nid'],
                        'field_id_text' => $c['techId'],
                        'lutteur1'      => $c['label1'] !== '' ? $c['label1'] : ('nid:' . $c['l1']),
                        'lutteur2'      => $c['label2'] !== '' ? $c['label2'] : ('nid:' . $c['l2']),
                        'lutteur1_nid'  => $c['l1'],
                        'lutteur2_nid'  => $c['l2'],
                        'date'          => $day,
                        'face2face'     => $c['face2face'],
                    ];
                }

                $payload = [
                    'pivot_nid'                 => $pivotId,
                    'pivot_name'                => $pivotName,
                    'ecurie_pivot'              => $pivotEcurie,
                    'nb_combats_pivot'          => $this->countCombatsForAthlete($pivotId, $combatCountCache),
                    'date'                      => $day,
                    'combats'                   => $payloadCombats,
                    'nb_combats_adversaires'    => $nbCombatsAdversaires,
                    'historique_adversaires'    => $historiqueAdversaires,
                    'suggestions_adversaires'   => $suggestionsAdversaires,
                ];

                $queue->createItem($payload);
                $queued++;
                $this->markAvenirDoublonGroupPending($techIds);

                $logger->info('Groupe a_venir suspect (même date + protagoniste commun) mis en queue : @pivot le @date (@n combats).', [
                    '@pivot' => $pivotName,
                    '@date'  => $day,
                    '@n'     => count($cluster),
                ]);
            }
        }

        return $queued;
    }

    /**
     * @param string[] $fieldIdTexts
     */
    public function isAvenirDoublonGroupPending(array $fieldIdTexts): bool
    {
        if (count($fieldIdTexts) < 2) {
            return false;
        }
        $hash = $this->buildDoublonGroupKey($fieldIdTexts)['hash'];
        $pending = \Drupal::state()->get(self::AVENIR_DOUBLONS_AI_PENDING_STATE_KEY, []);
        if (empty($pending[$hash])) {
            return false;
        }
        $markedAt = is_int($pending[$hash]) ? $pending[$hash] : 0;
        if ($markedAt > 0 && (\Drupal::time()->getRequestTime() - $markedAt) > self::DOUBLONS_AI_PENDING_TTL_SECONDS) {
            unset($pending[$hash]);
            \Drupal::state()->set(self::AVENIR_DOUBLONS_AI_PENDING_STATE_KEY, $pending);
            return false;
        }
        return true;
    }

    /**
     * @param string[] $fieldIdTexts
     */
    public function markAvenirDoublonGroupPending(array $fieldIdTexts): void
    {
        if (count($fieldIdTexts) < 2) {
            return;
        }
        $hash = $this->buildDoublonGroupKey($fieldIdTexts)['hash'];
        $pending = \Drupal::state()->get(self::AVENIR_DOUBLONS_AI_PENDING_STATE_KEY, []);
        $pending[$hash] = \Drupal::time()->getRequestTime();
        \Drupal::state()->set(self::AVENIR_DOUBLONS_AI_PENDING_STATE_KEY, $pending);
    }

    /**
     * @param string[] $fieldIdTexts
     */
    public function clearAvenirDoublonGroupPending(array $fieldIdTexts): void
    {
        if (count($fieldIdTexts) < 2) {
            return;
        }
        $hash = $this->buildDoublonGroupKey($fieldIdTexts)['hash'];
        $pending = \Drupal::state()->get(self::AVENIR_DOUBLONS_AI_PENDING_STATE_KEY, []);
        if (isset($pending[$hash])) {
            unset($pending[$hash]);
            \Drupal::state()->set(self::AVENIR_DOUBLONS_AI_PENDING_STATE_KEY, $pending);
        }
    }

    /**
     * Garde-fou à l'insertion d'un a_venir : un lutteur ne peut pas avoir
     * deux combats à venir le même jour.
     *
     * Si un a_venir publié existe déjà à la même date (Y-m-d) avec au moins
     * un des deux lutteurs, retourne le NID du nœud existant (le nouvel
     * insert doit être ignoré / fusionné côté appelant). Sinon NULL.
     */
    public function findExistingAvenirSameDateSharedWrestler(int $athlete1Id, int $athlete2Id, string $dateYmd): ?int
    {
        if ($athlete1Id <= 0 || $athlete2Id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
            return null;
        }

        $storage = \Drupal::entityTypeManager()->getStorage('node');

        // field_date est stocké en datetime ; on matche le préfixe jour.
        $nids = $storage->getQuery()
            ->condition('type', 'a_venir')
            ->condition('status', 1)
            ->condition('field_date', $dateYmd, 'STARTS_WITH')
            ->accessCheck(FALSE)
            ->execute();

        if (empty($nids)) {
            return null;
        }

        foreach ($storage->loadMultiple($nids) as $node) {
            $l1 = (int) ($node->get('field_lutteur_1')->target_id ?? 0);
            $l2 = (int) ($node->get('field_lutteur_2')->target_id ?? 0);
            $pair = [$l1, $l2];
            if (in_array($athlete1Id, $pair, true) || in_array($athlete2Id, $pair, true)) {
                return (int) $node->id();
            }
        }

        return null;
    }

    /**
     * Détecte des paires de combats publiés à ≤ $days jours d'écart, sans NID
     * athlète en commun, mais dont les noms des deux côtés se ressemblent
     * (tokens / préfixe / Levenshtein) — typiquement "Confiance vs Fils De
     * Falaye" et "Confiance Tp vs Elou Fils De Falaye".
     *
     * Ces cas sont invisibles pour queueAmbiguousNearDateCombats() (pas de
     * pivot NID partagé). Chaque paire suspecte est mise en queue
     * confrontations_cross_athlete_doublons_ai_queue.
     *
     * @param int $days Fenêtre max en jours (défaut 7). Passée via
     *   drush cdbl --days=N.
     *
     * @return int Nombre de paires mises en queue.
     */
    public function queueCrossAthleteNearDateCombats(int $days = 7): int
    {
        $storage = \Drupal::entityTypeManager()->getStorage('node');
        $logger  = \Drupal::service('logger.factory')->get('shared_lib');
        $queue   = \Drupal::queue('confrontations_cross_athlete_doublons_ai_queue');

        $days = max(1, $days);
        $windowSeconds = $days * 86400;
        $prefixLen = 3;

        // Tous les combats publiés avec date + 2 athlètes (tri date ASC).
        $fight_ids = $storage->getQuery()
            ->condition('type', 'confrontation')
            ->condition('status', 1)
            ->exists('field_confrontation_date')
            ->exists('field_athlete_1')
            ->exists('field_athlete_2')
            ->sort('field_confrontation_date', 'ASC')
            ->sort('nid', 'ASC')
            ->accessCheck(FALSE)
            ->execute();

        if (count($fight_ids) < 2) {
            return 0;
        }

        $nodes = [];
        foreach ($storage->loadMultiple($fight_ids) as $fight) {
            $date = (string) ($fight->get('field_confrontation_date')->value ?? '');
            $techId = (string) ($fight->get('field_id_text')->value ?? '');
            if ($date === '' || $techId === '') {
                continue;
            }
            $ts = strtotime($date);
            if ($ts === false) {
                continue;
            }
            $a1 = (int) ($fight->get('field_athlete_1')->target_id ?? 0);
            $a2 = (int) ($fight->get('field_athlete_2')->target_id ?? 0);
            if ($a1 <= 0 || $a2 <= 0 || $a1 === $a2) {
                continue;
            }
            $n1 = $storage->load($a1);
            $n2 = $storage->load($a2);
            if (!$n1 || !$n2) {
                continue;
            }
            $name1 = (string) $n1->getTitle();
            $name2 = (string) $n2->getTitle();
            $nodes[] = [
                'nid'    => (int) $fight->id(),
                'techId' => $techId,
                'ts'     => $ts,
                'date'   => $date,
                'a1'     => $a1,
                'a2'     => $a2,
                'name1'  => $name1,
                'name2'  => $name2,
                'norm1'  => $this->textNormalizationHelper->normalizeForDistance($name1),
                'norm2'  => $this->textNormalizationHelper->normalizeForDistance($name2),
                'vainqueur' => $fight->hasField('field_vainqueur') && !$fight->get('field_vainqueur')->isEmpty()
                    ? (string) ($fight->get('field_vainqueur')->entity?->getTitle() ?? $fight->get('field_vainqueur')->value ?? '')
                    : '',
            ];
        }

        $n = count($nodes);
        if ($n < 2) {
            return 0;
        }

        $queued = 0;
        // Fenêtre glissante O(n * w) : pour chaque combat i, comparer aux
        // suivants j tant que date(j) - date(i) ≤ $days jours.
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $dt = $nodes[$j]['ts'] - $nodes[$i]['ts'];
                if ($dt > $windowSeconds) {
                    break;
                }
                if ($dt < 0) {
                    continue;
                }

                $A = $nodes[$i];
                $B = $nodes[$j];

                // Déjà un athlète en commun → pipeline pivot classique.
                $idsA = [$A['a1'], $A['a2']];
                $idsB = [$B['a1'], $B['a2']];
                if (array_intersect($idsA, $idsB)) {
                    continue;
                }

                // Similarité des deux côtés (ordre direct ou croisé).
                $direct = $this->opponentNamesLookRelated($A['norm1'], $B['norm1'], $prefixLen)
                    && $this->opponentNamesLookRelated($A['norm2'], $B['norm2'], $prefixLen);
                $crossed = $this->opponentNamesLookRelated($A['norm1'], $B['norm2'], $prefixLen)
                    && $this->opponentNamesLookRelated($A['norm2'], $B['norm1'], $prefixLen);
                if (!$direct && !$crossed) {
                    continue;
                }

                $techIds = [$A['techId'], $B['techId']];
                if ($this->isDoublonGroupVerifiedKept($techIds) || $this->isDoublonGroupPending($techIds)) {
                    continue;
                }

                $item = [
                    'combats' => [
                        [
                            'nid' => $A['nid'],
                            'field_id_text' => $A['techId'],
                            'lutteur1' => $A['name1'],
                            'lutteur2' => $A['name2'],
                            'lutteur1_nid' => $A['a1'],
                            'lutteur2_nid' => $A['a2'],
                            'date' => $A['date'],
                            'vainqueur' => $A['vainqueur'],
                        ],
                        [
                            'nid' => $B['nid'],
                            'field_id_text' => $B['techId'],
                            'lutteur1' => $B['name1'],
                            'lutteur2' => $B['name2'],
                            'lutteur1_nid' => $B['a1'],
                            'lutteur2_nid' => $B['a2'],
                            'date' => $B['date'],
                            'vainqueur' => $B['vainqueur'],
                        ],
                    ],
                    'date' => $A['date'],
                ];

                $queue->createItem($item);
                $this->markDoublonGroupPending($techIds);
                $queued++;

                $logger->notice('Cross-athlete doublon suspect mis en queue : @a (@d1) vs @b (@d2).', [
                    '@a' => $A['techId'],
                    '@b' => $B['techId'],
                    '@d1' => $A['date'],
                    '@d2' => $B['date'],
                ]);
            }
        }

        return $queued;
    }

}
