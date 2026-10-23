<?php

namespace Drupal\shared_lib\Service;

use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\Console\Style\StyleInterface;

/**
 * Dédoublonnage des athlètes et des écuries, et création/récupération
 * d'athlètes (get-or-create) utilisée par les imports.
 *
 * Issu du découpage de l'ancienne classe statique
 * \Drupal\shared_lib\Tools\SomeTools (voir SomeTools.php, conservée comme
 * façade de compatibilité — voir shared_lib.services.yml).
 */
class AthleteDeduplicationService
{

    /** Écart minimum (en années) entre 2 combats successifs d'un même
     * lutteur "d'origine" (sans suffixe générationnel) considéré comme un
     * "trou de carrière" suspect (probable rattachement erroné à un
     * homonyme générationnel plus jeune). */
    const HOMONYMES_GAP_YEARS = 5;

    /** Nombre maximum de combats suspects envoyés en un seul appel IA
     * (garde-fou anti-prompt géant si le trou est très ancien). */
    const HOMONYMES_MAX_COMBATS = 30;

    protected TextNormalizationHelper $textNormalizationHelper;

    public function __construct(TextNormalizationHelper $textNormalizationHelper)
    {
        $this->textNormalizationHelper = $textNormalizationHelper;
    }

    /**
     * Détecte, pour CHAQUE lutteur en base (aucune exclusion a priori par
     * suffixe générationnel), s'il a AU MOINS un "homonyme" — défini ici
     * comme tout autre lutteur dont le nom CONTIENT (par sous-chaîne
     * normalisée) le nom COMPLET du pivot, suffixe générationnel du pivot
     * INCLUS (ex: pivot "Robert 2" → homonymes "Feu Robert 2", "Pape Ba
     * Dit Robert 2", mais PAS "Robert Diouf"). Si au moins un homonyme est
     * trouvé, vérifie s'il existe un "trou" d'au moins HOMONYMES_GAP_YEARS
     * années entre 2 de ses propres combats successifs (probable signe que
     * certains de ses combats ont en réalité été mal rattachés à un
     * homonyme). Si un tel trou est détecté, TOUS les combats à partir de
     * celui qui suit le trou sont mis en queue (un seul item par pivot,
     * lot complet) pour vérification IA
     * (confrontations_homonymes_generationnels_ai_queue).
     *
     * Les pivots sans homonyme ou sans trou détecté sont simplement
     * ignorés (aucune action). Note : le champ field_check_homonymes_gen
     * n'existe pas / n'est plus utilisé sur le bundle athlete ; le
     * mécanisme de cache/flag associé a été retiré.
     *
     * @return int Nombre de pivots mis en queue.
     */
    public function queueSuspectedGenerationalHomonyms(int $queueLimit = 0): int
    {
        $storage = \Drupal::entityTypeManager()->getStorage('node');
        $logger  = \Drupal::logger('shared_lib');
        $queue   = \Drupal::queue('confrontations_homonymes_generationnels_ai_queue');

        $athlete_ids = $storage->getQuery()
            ->condition('type', 'athlete')
            ->accessCheck(false)
            ->execute();

        if (empty($athlete_ids)) {
            return 0;
        }

        $queued = 0;

        foreach ($storage->loadMultiple($athlete_ids) as $pivotNode) {
            if (!empty($queueLimit) && $queued >= $queueLimit) {
                return $queued;
            }

            $title = trim((string) $pivotNode->label());
            if ($title === '') {
                continue;
            }
            $nid = (int) $pivotNode->id();

            // Homonymes = tous les lutteurs dont le nom CONTIENT le nom
            // complet du pivot tel quel, suffixe générationnel inclus (ex:
            // pivot "Robert 2" → matche "Pape Ba Dit Robert 2", "Feu
            // Robert 2", mais PAS "Robert Diouf"). Aucune exclusion a
            // priori par suffixe générationnel : n'importe quel lutteur
            // peut être pivot, y compris un homonyme lui-même (ex: "Robert
            // 2" peut être pivot même sans "Robert" sans suffixe en base).
            $homonyms = $this->findAthleteNamesContainingPivot($title);
            if (empty($homonyms)) {
                // Aucun homonyme détecté : rien à vérifier pour ce lutteur.
                continue;
            }

            $fight_ids = $storage->getQuery()
                ->condition('type', 'confrontation')
                ->condition(
                    $storage->getQuery()->orConditionGroup()
                        ->condition('field_athlete_1', $nid)
                        ->condition('field_athlete_2', $nid)
                )
                ->accessCheck(false)
                ->execute();

            if (count($fight_ids) < 2) {
                // Pas assez de combats pour qu'un "trou" ait un sens.
                continue;
            }

            $sorted = [];
            foreach ($storage->loadMultiple($fight_ids) as $fight) {
                $dateVal = $fight->hasField('field_confrontation_date')
                    ? $fight->get('field_confrontation_date')->value
                    : null;
                if (empty($dateVal)) {
                    continue;
                }
                $sorted[] = ['node' => $fight, 'date' => (string) $dateVal];
            }

            if (count($sorted) < 2) {
                continue;
            }

            usort($sorted, fn($a, $b) => strcmp($a['date'], $b['date']));

            // Recherche du PREMIER trou (chronologique) ≥ HOMONYMES_GAP_YEARS.
            $gapIndex = null;
            for ($i = 1; $i < count($sorted); $i++) {
                $y1 = (int) substr($sorted[$i - 1]['date'], 0, 4);
                $y2 = (int) substr($sorted[$i]['date'], 0, 4);
                if (($y2 - $y1) == self::HOMONYMES_GAP_YEARS) {
                    $gapIndex = $i;
                    break;
                }
            }

            if ($gapIndex === null) {
                // Aucun trou : carrière cohérente, rien à vérifier.
                continue;
            }

            $suspects = array_slice($sorted, $gapIndex, self::HOMONYMES_MAX_COMBATS);

            $ecurieName = '';
            if ($pivotNode->hasField('field_ecurie') && !$pivotNode->get('field_ecurie')->isEmpty()) {
                $ecurieNode = $pivotNode->get('field_ecurie')->entity;
                $ecurieName = $ecurieNode ? $ecurieNode->getTitle() : '';
            }

            // $suspects (issu de $sorted) sert de base à la détection du
            // trou de carrière et reste inchangé quel que soit l'état de
            // field_check_homonymie. En revanche, SEULS les combats pas
            // encore vérifiés (field_check_homonymie = FALSE/absent)
            // sont réellement inclus dans $combatsData, donc envoyés à
            // l'IA : un combat déjà traité lors d'un passage précédent
            // (peu importe le verdict) ne doit plus jamais être
            // re-soumis, sans pour autant sortir du périmètre "N
            // derniers combats suspects" utilisé pour le trou.
            $combatsData = [];
            foreach ($suspects as $s) {
                /** @var \Drupal\node\NodeInterface $fight */
                $fight = $s['node'];

                if (
                    $fight->hasField('field_check_homonymie')
                    && (bool) $fight->get('field_check_homonymie')->value
                ) {
                    continue;
                }

                $a1 = $fight->hasField('field_athlete_1') ? $fight->get('field_athlete_1')->entity : null;
                $a2 = $fight->hasField('field_athlete_2') ? $fight->get('field_athlete_2')->entity : null;
                $isA1Self = $a1 && (int) $a1->id() === $nid;
                $opponent = $isA1Self ? $a2 : $a1;

                $combatsData[] = [
                    'nid'           => (int) $fight->id(),
                    'field_id_text' => (string) ($fight->get('field_id_text')->value ?? ''),
                    'pivot_field'   => $isA1Self ? 'field_athlete_1' : 'field_athlete_2',
                    'adversaire'    => $opponent ? (string) $opponent->label() : '',
                    'date'          => $s['date'],
                    'vainqueur'     => $fight->hasField('field_vainqueur') ? $fight->get('field_vainqueur')->value : null,
                ];
            }

            if (empty($combatsData)) {
                // Tous les combats du trou détecté ont déjà été
                // vérifiés par l'IA lors d'un passage précédent : rien
                // de nouveau à envoyer.
                continue;
            }

            $queue->createItem([
                'pivot_nid'        => $nid,
                'pivot_name'       => $title,
                'ecurie_name'      => $ecurieName,
                'homonymes_connus' => $homonyms,
                'suggestions'      => $homonyms,
                'gap_annees'       => (int) substr($sorted[$gapIndex]['date'], 0, 4) - (int) substr($sorted[$gapIndex - 1]['date'], 0, 4),
                'gap_date_avant'   => $sorted[$gapIndex - 1]['date'],
                'gap_date_apres'   => $sorted[$gapIndex]['date'],
                'combats'          => $combatsData,
            ]);

            $logger->notice('queueSuspectedGenerationalHomonyms : trou de @gap an(s) détecté pour "@pivot" (nid @nid) entre @avant et @apres — @n combat(s) mis en queue pour vérification IA. Homonymes connus : @homonymes', [
                '@gap'       => (int) substr($sorted[$gapIndex]['date'], 0, 4) - (int) substr($sorted[$gapIndex - 1]['date'], 0, 4),
                '@pivot'     => $title,
                '@nid'       => $nid,
                '@avant'     => $sorted[$gapIndex - 1]['date'],
                '@apres'     => $sorted[$gapIndex]['date'],
                '@n'         => count($combatsData),
                '@homonymes' => implode(', ', $homonyms),
            ]);

            $queued++;
        }

        return $queued;
    }

    /**
     * Détermine si un nom de lutteur porte un suffixe générationnel
     * (chiffre final, ex: "Boy Faye 2"/"Boy Faye2", ou terme "junior"/
     * "jr"/"n2" en fin de nom). Un nom SANS ce type de suffixe est
     * considéré comme le lutteur "d'origine" du groupe générationnel.
     */
    protected function hasGenerationalSuffix(string $title): bool
    {
        $t = mb_strtolower(trim($title));
        if ($t === '') {
            return false;
        }
        if (preg_match('/\d\s*$/u', $t)) {
            return true;
        }
        if (preg_match('/\b(junior|jr|n2)\s*$/u', $t)) {
            return true;
        }
        return false;
    }

    /**
     * Nettoie les doublons d'athlètes - Version DEBUG (logs console + dblog)
     */
    public function cleanDuplicateAthletes(?StyleInterface $io = null): void
    {
        $logger  = \Drupal::logger('shared_lib');
        $storage = \Drupal::entityTypeManager()->getStorage('node');
        $language = \Drupal::languageManager()->getLanguage('fr');
        $autoDelete = []; // clés à supprimer automatiquement en Phase 3 (fusion manuelle acceptée)

        echo "cleanDuplicateAthletes DÉMARRAGE DEBUG\n";
        //$logger->notice('cleanDuplicateAthletes DÉMARRAGE DEBUG');

        $athlete_nids = $storage->getQuery()
            ->condition('type', 'athlete')
            ->accessCheck(false)
            ->execute();

        echo "cleanDuplicateAthletes → " . count($athlete_nids) . " athlètes trouvés dans la base\n";
        //$logger->notice('cleanDuplicateAthletes → @nb athlètes trouvés dans la base', ['@nb' => count($athlete_nids)]);

        $athletes = $storage->loadMultiple($athlete_nids);

        // ── PHASE 1 : Groupement exact (nom technique ou titre) ──────────────────
        $groups = [];
        foreach ($athletes as $athlete) {
            $title = trim($athlete->getTitle());
            if ($title === '') continue;

            $tech = trim($athlete->get('field_athlete_nom_technique')->value ?? '');
            $key  = $tech !== ''
                ? mb_strtolower($tech)
                : mb_strtolower($title);

            $groups[$key][] = $athlete;
        }

        echo "cleanDuplicateAthletes → " . count($groups) . " groupes après groupement exact\n";
        //$logger->notice('cleanDuplicateAthletes → @nb groupes après groupement exact', ['@nb' => count($groups)]);

        // ── PHASE 2 : Fusion floue (Levenshtein ≤ 1 sur le nom technique) ────────
        $keys   = array_keys($groups);
        $merged = [];

        foreach ($keys as $i => $keyA) {
            if (isset($merged[$keyA])) continue;

            $techA = $this->textNormalizationHelper->technicalWrestlerName($keyA);

            foreach ($keys as $j => $keyB) {
                if ($j <= $i || isset($merged[$keyB])) continue;

                $techB = $this->textNormalizationHelper->technicalWrestlerName($keyB);

                // Normalisation : "n°2" / "no2" / "tyson 2" → "tyson2"
                $normalizeGeneration = fn(string $s): string
                => preg_replace('/(?:\s+|no|n°)(\d+)$/', '$1', $s);

                $normA = $normalizeGeneration($techA);
                $normB = $normalizeGeneration($techB);
                $lev   = levenshtein($normA, $normB);

                if ($lev === 0) {
                    $groups[$keyA] = array_merge($groups[$keyA], $groups[$keyB]);
                    unset($groups[$keyB]);
                    $merged[$keyB] = true;

                    $label = ($techA !== $techB) ? 'NOTATION DIFFÉRENTE' : 'FUSION EXACTE';
                    echo "cleanDuplicateAthletes {$label} : \"$keyA\" ← \"$keyB\"\n";
                    /*$logger->notice('cleanDuplicateAthletes @label : "@a" ← "@b"', [
                        '@label' => $label,
                        '@a'     => $keyA,
                        '@b'     => $keyB,
                    ]);*/
                } elseif ($lev === 1) {
                    // ── Détection différence de numérotation (trailing OU leading) ──
                    $baseA_t = rtrim($normA, '0123456789');
                    $baseB_t = rtrim($normB, '0123456789');
                    $suffA   = substr($normA, strlen($baseA_t));
                    $suffB   = substr($normB, strlen($baseB_t));

                    $isTrailingGen = ($baseA_t === $baseB_t)
                        && ($suffA !== $suffB)
                        && ($suffA !== '' || $suffB !== '');

                    $baseA_l = ltrim($normA, '0123456789');
                    $baseB_l = ltrim($normB, '0123456789');
                    $prefA   = substr($normA, 0, strlen($normA) - strlen($baseA_l));
                    $prefB   = substr($normB, 0, strlen($normB) - strlen($baseB_l));

                    $isLeadingGen = ($baseA_l === $baseB_l)
                        && ($prefA !== $prefB)
                        && ($prefA !== '' || $prefB !== '');

                    if ($isTrailingGen || $isLeadingGen) {
                        // Lutteurs distincts portant des numéros différents → silence total
                    } else {
                        $titlesA = implode(', ', array_map(
                            fn($n) => '[' . $n->id() . '] ' . $n->getTitle(),
                            $groups[$keyA]
                        ));
                        $titlesB = implode(', ', array_map(
                            fn($n) => '[' . $n->id() . '] ' . $n->getTitle(),
                            $groups[$keyB]
                        ));

                        $msg = "DOUBLON SUSPECT (lev=1, non supprimé) : \"$keyA\" ↔ \"$keyB\" | A=[{$titlesA}] B=[{$titlesB}]";
                        echo "cleanDuplicateAthletes $msg\n";
                        //$logger->warning('cleanDuplicateAthletes @msg', ['@msg' => $msg]);

                        // ── Mode interactif : proposer la fusion ──────────────────
                        if ($io !== null) {
                            $confirm = $io->confirm(
                                "  → Fusionner \"$keyB\" dans \"$keyA\" ? (pivot = NID le plus ancien)",
                                false
                            );

                            if ($confirm) {
                                $groups[$keyA] = array_merge($groups[$keyA], $groups[$keyB]);
                                unset($groups[$keyB]);
                                $merged[$keyB] = true;
                                $autoDelete[$keyA] = true; // ← marquer pour suppression auto en Phase 3

                                echo "cleanDuplicateAthletes FUSION MANUELLE : \"$keyA\" ← \"$keyB\"\n";
                                /*$logger->notice('cleanDuplicateAthletes FUSION MANUELLE : "@a" ← "@b"', [
                                    '@a' => $keyA,
                                    '@b' => $keyB,
                                ]);*/
                            }
                        }
                    }
                }
            }
        }

        // Re-trier chaque groupe par NID croissant après les fusions
        foreach ($groups as &$nodes) {
            usort($nodes, fn($a, $b) => (int)$a->id() <=> (int)$b->id());
        }
        unset($nodes);

        echo "cleanDuplicateAthletes → " . count($groups) . " groupes après fusion floue\n";
        /*  $logger->notice('cleanDuplicateAthletes → @nb groupes après fusion floue', ['@nb' => count($groups)]);*/

        // ── PHASE 3 : Traitement des doublons ─────────────────────────────────────
        $totalDeleted = 0;
        $nbDoublons   = 0;

        foreach ($groups as $normalizedTitle => $nodes) {
            echo "DEBUG Phase3 groupe \"$normalizedTitle\" → " . count($nodes) . " nœuds\n";
            if (count($nodes) <= 1) continue;

            $nbDoublons++;

            $keeper     = $nodes[0];
            $duplicates = array_slice($nodes, 1);

            $title        = trim($keeper->getTitle());
            $expectedTech = $this->textNormalizationHelper->technicalWrestlerName($this->textNormalizationHelper->correctNames($title));
            $currentTech  = $keeper->get('field_athlete_nom_technique')->value ?? '';

            if ($currentTech !== $expectedTech) {
                foreach ($duplicates as $dup) {
                    $dupTech = $dup->get('field_athlete_nom_technique')->value ?? '';
                    if ($dupTech === $expectedTech) {
                        $keeper->set('field_athlete_nom_technique', $expectedTech);
                        $keeper->save();

                        $keeperConsole = "[{$keeper->id()}] {$keeper->getTitle()}";
                        echo "cleanDuplicateAthletes NOM TECHNIQUE CORRIGÉ sur pivot {$keeperConsole} → {$expectedTech}\n";
                        /*$logger->notice('cleanDuplicateAthletes NOM TECHNIQUE CORRIGÉ sur pivot @keeper_link → @tech', [
                            '@keeper_link' => Markup::create($this->makeNodeLink($keeper, $language)),
                            '@tech'        => $expectedTech,
                        ]);*/
                        break;
                    }
                }
            }

            $keeperConsole = "[{$keeper->id()}] {$keeper->getTitle()}";
            $keeperLink    = $this->makeNodeLink($keeper, $language);

            // echo "cleanDuplicateAthletes DOUBLON TITRE : {$title} → pivot {$keeperConsole}\n";
            /*  $logger->notice('cleanDuplicateAthletes DOUBLON TITRE : @title → pivot @keeper_link', [
                '@title'       => $title,
                '@keeper_link' => Markup::create($keeperLink),
            ]);*/

            foreach ($duplicates as $bad) {
                $badConsole = "[{$bad->id()}] {$bad->getTitle()}";
                $badLink    = $this->makeNodeLink($bad, $language);

                // ── Mode interactif : confirmation avant suppression ──────────────
                if ($io !== null && !isset($autoDelete[$normalizedTitle])) {
                    $confirm = $io->confirm(
                        "  → Supprimer le doublon {$badConsole} et transférer ses combats vers [{$keeper->id()}] {$keeper->getTitle()} ?",
                        true
                    );
                    if (!$confirm) {
                        echo "cleanDuplicateAthletes IGNORÉ (manuel) : {$badConsole}\n";
                        /*$logger->notice('cleanDuplicateAthletes IGNORÉ (manuel) : [NID:@nid] @title', [
                            '@nid'   => $bad->id(),
                            '@title' => $bad->getTitle(),
                        ]);*/
                        continue;
                    }
                }

                // ── A. Transfert des confrontations ───────────────────────────────
                $or = $storage->getQuery()->orConditionGroup()
                    ->condition('field_athlete_1', $bad->id())
                    ->condition('field_athlete_2', $bad->id());

                $combat_nids = $storage->getQuery()
                    ->condition('type', 'confrontation')
                    ->condition($or)
                    ->accessCheck(false)
                    ->execute();

                $countUpdated = 0;
                foreach ($combat_nids as $cid) {
                    $combat = $storage->load($cid);
                    if (!$combat) continue;

                    $updated = false;

                    if ($combat->hasField('field_athlete_1') && $combat->get('field_athlete_1')->target_id == $bad->id()) {
                        $combat->set('field_athlete_1', ['target_id' => $keeper->id()]);
                        $updated = true;
                    }
                    if ($combat->hasField('field_athlete_2') && $combat->get('field_athlete_2')->target_id == $bad->id()) {
                        $combat->set('field_athlete_2', ['target_id' => $keeper->id()]);
                        $updated = true;
                    }
                    if ($combat->hasField('field_vainqueur')) {
                        $val = $combat->get('field_vainqueur')->value;
                        if ($val !== null && (int)$val == (int)$bad->id()) {
                            $combat->set('field_vainqueur', ['value' => $keeper->id()]);
                            $updated = true;
                        }
                    }

                    if ($updated) {
                        $combat->save();
                        $countUpdated++;
                        echo "cleanDuplicateAthletes COMBAT TRANSFÉRÉ : [{$combat->id()}] {$combat->getTitle()}\n";
                        $logger->info('cleanDuplicateAthletes COMBAT TRANSFÉRÉ : @link', [
                            '@link' => Markup::create($this->makeNodeLink($combat, $language)),
                        ]);
                    }
                }

                // ── B. Transfert des a_venir ──────────────────────────────────────
                $orAvenir = $storage->getQuery()->orConditionGroup()
                    ->condition('field_lutteur_1', $bad->id())
                    ->condition('field_lutteur_2', $bad->id());

                $avenir_nids = $storage->getQuery()
                    ->condition('type', 'a_venir')
                    ->condition($orAvenir)
                    ->accessCheck(false)
                    ->execute();

                $countAvenirUpdated = 0;
                foreach ($avenir_nids as $avenir_nid) {
                    $avenir = $storage->load($avenir_nid);
                    if (!$avenir) continue;

                    $updated = false;
                    if ($avenir->hasField('field_lutteur_1') && $avenir->get('field_lutteur_1')->target_id == $bad->id()) {
                        $avenir->set('field_lutteur_1', ['target_id' => $keeper->id()]);
                        $updated = true;
                    }
                    if ($avenir->hasField('field_lutteur_2') && $avenir->get('field_lutteur_2')->target_id == $bad->id()) {
                        $avenir->set('field_lutteur_2', ['target_id' => $keeper->id()]);
                        $updated = true;
                    }
                    if ($updated) {
                        $avenir->save();
                        $countAvenirUpdated++;
                    }
                }

                // ── C. Suppression du doublon ─────────────────────────────────────
                try {
                    $bad->delete();
                    $totalDeleted++;
                    echo "cleanDuplicateAthletes DOUBLON SUPPRIMÉ : {$badConsole}"
                        . " ({$countUpdated} combats, {$countAvenirUpdated} a_venir transférés)\n";
                    /*$logger->notice(
                        'cleanDuplicateAthletes DOUBLON SUPPRIMÉ : [NID:@nid] @title — @bad_link (@count combats, @count_avenir a_venir)',
                        [
                            '@nid'          => $bad->id(),
                            '@title'        => $bad->getTitle(),
                            '@bad_link'     => Markup::create($badLink),
                            '@count'        => $countUpdated,
                            '@count_avenir' => $countAvenirUpdated,
                        ]
                    );*/
                } catch (\Exception $e) {
                    echo "cleanDuplicateAthletes ÉCHEC SUPPRESSION : {$badConsole} - {$e->getMessage()}\n";
                    $logger->error('cleanDuplicateAthletes ÉCHEC SUPPRESSION : @bad_link - @msg', [
                        '@bad_link' => Markup::create($badLink),
                        '@msg'      => $e->getMessage(),
                    ]);
                }
            }
        }

        echo "cleanDuplicateAthletes TERMINÉ DEBUG — {$totalDeleted} doublons supprimés ({$nbDoublons} groupes avec doublons)\n";
        /*$logger->notice('cleanDuplicateAthletes TERMINÉ — @nb doublons supprimés (@nb_doublons groupes)', [
            '@nb'          => $totalDeleted,
            '@nb_doublons' => $nbDoublons,
        ]);*/
    }

    /**
     * Nettoie les doublons d'écuries, migre les athlètes et dépublie proprement.
     */
    public function cleanDuplicatedEcuries(array $submittedGroups, ?StyleInterface $io = null): void
    {
        $logger          = \Drupal::logger('shared_lib');
        $storage         = \Drupal::entityTypeManager()->getStorage('node');
        $languageManager = \Drupal::languageManager();
        $currentLanguage = $languageManager->getCurrentLanguage()->getId();

        echo "\n--- cleanDuplicatedEcuries : DÉMARRAGE ---\n";
        /*$logger->notice('cleanDuplicatedEcuries : DÉMARRAGE. @count sections reçues.', [
            '@count' => count($submittedGroups)
        ]);*/

        $totalUpdatedAthletes    = 0;
        $totalUnpublishedEcuries = 0;

        foreach ($submittedGroups as $index => $groupNids) {
            $rawNids = is_array($groupNids) && isset($groupNids['ecuries']) ? $groupNids['ecuries'] : $groupNids;
            $nids    = array_values(array_unique(array_filter((array) $rawNids)));

            if (count($nids) <= 1) {
                echo "Section {$index} : Ignorée (pas assez de NIDs).\n";
                continue;
            }

            $keeper_nid      = $nids[0];
            $duplicates_nids = array_slice($nids, 1);

            /** @var \Drupal\node\NodeInterface $keeper */
            $keeper = $storage->load($keeper_nid);
            if (!$keeper) {
                echo "Section {$index} : Pivot {$keeper_nid} introuvable !\n";
                $logger->error('cleanDuplicatedEcuries : Pivot @nid introuvable.', ['@nid' => $keeper_nid]);
                continue;
            }

            echo "Section {$index} : Pivot [{$keeper_nid}] " . $keeper->label() . "\n";
            $keeperLink = Markup::create($this->makeNodeLink($keeper, $languageManager->getLanguage($currentLanguage)));

            // ── Mode interactif : confirmation une fois pour tout le groupe ───────
            $groupConfirmed = true;
            if ($io !== null) {
                $dupLabels = implode(', ', array_map(
                    fn($nid) => "[$nid]",
                    $duplicates_nids
                ));
                $groupConfirmed = $io->confirm(
                    "  → Fusionner {$dupLabels} dans [{$keeper_nid}] \"{$keeper->label()}\" et supprimer les doublons ?",
                    true
                );
                if (!$groupConfirmed) {
                    echo "Section {$index} : Groupe ignoré (choix manuel).\n";
                    /*$logger->notice('cleanDuplicatedEcuries : IGNORÉ (manuel) - groupe pivot @nid', [
                        '@nid' => $keeper_nid,
                    ]);*/
                    continue;
                }
            }

            // 1. Migration des athlètes
            $athlete_nids = $storage->getQuery()
                ->condition('type', 'athlete')
                ->condition('field_ecurie', $duplicates_nids, 'IN')
                ->accessCheck(false)
                ->execute();

            if (!empty($athlete_nids)) {
                $athletes = $storage->loadMultiple($athlete_nids);
                foreach ($athletes as $athlete) {
                    $athlete->set('field_ecurie', ['target_id' => $keeper_nid]);
                    $athlete->save();
                    $totalUpdatedAthletes++;
                    $msg = "  -> Athlète [" . $athlete->label() . " (" . $athlete->id() . ")] migré.\n";
                    echo $msg;
                    $logger->info('cleanDuplicatedEcuries : @msg', ['@msg' => $msg]);
                }
            }

            // 2. Dépublication des doublons (on NE supprime plus les nodes,
            // pour que les NID restent valables si de futurs athlètes y font référence)
            $duplicates = $storage->loadMultiple($duplicates_nids);
            foreach ($duplicates as $duplicate) {
                $dupId   = $duplicate->id();
                $dupLink = Markup::create($this->makeNodeLink($duplicate, $languageManager->getLanguage($currentLanguage)));

                if ((int) $duplicate->get('status')->value !== 0) {
                    $duplicate->set('status', 0);
                    $duplicate->setNewRevision(false);
                    $duplicate->save();
                    $totalUnpublishedEcuries++;
                }

                \Drupal\Core\Cache\Cache::invalidateTags(['node:' . $dupId]);

                echo "  -> Doublon [{$dupId}] dépublié (conservé, non supprimé).\n";
                $logger->notice('cleanDuplicatedEcuries : DÉPUBLICATION - @dup (Pivot: @keeper)', [
                    '@dup'    => $dupLink,
                    '@keeper' => $keeperLink,
                ]);
            }
        }

        $finalMsg = "TERMINÉ : {$totalUpdatedAthletes} athlètes migrés, {$totalUnpublishedEcuries} écuries dépubliées.";
        echo "\n" . $finalMsg . "\n";
        $logger->notice("cleanDuplicatedEcuries : " . $finalMsg);
    }

    /**
     * Helper : lien cliquable
     */
    private function makeNodeLink(NodeInterface $node, $language): string
    {
        $url = Url::fromRoute('entity.node.canonical', ['node' => $node->id()], ['language' => $language])
            ->toString();

        return '<a href="' . $url . '" target="_blank">[' . $node->id() . '] ' . htmlspecialchars($node->getTitle()) . '</a>';
    }

    public function combatExists(
        $fightId,
        $type,
        EntityTypeManagerInterface $etm,
        LoggerInterface $logger
    ): mixed {
        $query = $etm
            ->getStorage("node")
            ->getQuery()
            ->accessCheck(false)
            ->condition("type", $type)
            ->condition("field_id_text", $fightId)
            ->condition("status", 1)
            ->execute();
        return empty($query) ? null : reset($query);
    }

    /**
     * Wrapper locké autour de getOrCreateAthlete avec wait+retry.
     * Empêche la création d'athlètes en double lors de traitements parallèles.
     */
    public function getOrCreateAthleteLocked($lock, string $name, ?string $ecurie)
    {
        $lockName = 'athlete_create_' . md5(strtolower(trim($name)));

        if (!$lock->acquire($lockName, 15)) {
            $lock->wait($lockName);

            if (!$lock->acquire($lockName, 15)) {
                \Drupal::logger('confrontations_recentes')->error(
                    'Lock athlète impossible après wait pour @name — tentative directe.',
                    ['@name' => $name]
                );
                return $this->getOrCreateAthlete(
                    $name,
                    \Drupal::entityTypeManager(),
                    \Drupal::logger('shared_lib'),
                    $ecurie
                );
            }
        }

        try {
            return $this->getOrCreateAthlete(
                $name,
                \Drupal::entityTypeManager(),
                \Drupal::logger('shared_lib'),
                $ecurie
            );
        } finally {
            $lock->release($lockName);
        }
    }

    /**
     * Détermine si l'écurie actuellement liée à l'athlète est absente,
     * vide ou de valeur "inconnue" (ex : "?", "Inconnu", "Inconnue", "").
     * Permet de savoir si on peut/doit écraser cette écurie par une
     * nouvelle valeur obtenue via l'IA (ex : lors d'un LiveSearch).
     */
    private function isEcurieUnknownOrEmpty(Node $athlete, $storage): bool
    {
        if ($athlete->get('field_ecurie')->isEmpty()) {
            return true;
        }

        $ecurieId = $athlete->get('field_ecurie')->target_id;
        $ecurieNode = $ecurieId ? $storage->load($ecurieId) : null;

        if (!$ecurieNode) {
            return true;
        }

        $title = mb_strtolower(trim((string) $ecurieNode->label()));

        return $title === ''
            || $title === '?'
            || str_contains($title, 'inconnu');
    }

    /**
     * Get or create new Athlete (and Get or Create new Ecurie) node
     */
    public function getOrCreateAthlete(
        string &$name,
        EntityTypeManagerInterface $etm,
        LoggerInterface $logger,
        mixed &$ecurie = null
    ): ?Node {
        static $cache = [];
        $cleanName = $this->textNormalizationHelper->technicalWrestlerName($this->textNormalizationHelper->correctNames($name));

        if (isset($cache[$cleanName])) {
            return $cache[$cleanName];
        }

        $storage = $etm->getStorage("node");

        // 1. Recherche d'une écurie EXISTANTE uniquement (pas de création
        // automatique).
        //
        // RAISON : l'écurie fournie ici provient souvent d'un texte brut
        // extrait automatiquement (ex. suffixe "Nom/xxx" ou parenthèse
        // "Nom (xxx)" issu du scraping d'une source externe). Ce texte
        // n'est PAS validé par l'IA comme l'est le nom du lutteur (voir
        // prompt_confirm_wrestlers, Étape A) : il peut donc s'agir d'un
        // simple surnom ou slogan promotionnel collé lors de l'extraction
        // (ex. "door Dooraat"), et non d'une véritable écurie. Créer un
        // nouveau nœud "ecurie" à partir d'une telle valeur non fiable
        // polluerait durablement la taxonomie des écuries.
        //
        // On se limite donc à RÉUTILISER une écurie déjà connue en base
        // (correspondance exacte de titre) ; si elle n'existe pas encore,
        // on l'ignore simplement (l'athlète est créé/mis à jour sans
        // écurie plutôt qu'avec une écurie inventée).
        $ecurieTargetId = null;
        if (!empty($ecurie)) {
            $ecurieName = trim($this->textNormalizationHelper->correctNames($ecurie));

            // Garde : on refuse un titre vide ou purement numérique
            if ($ecurieName === '' || is_numeric($ecurieName)) {
                $logger->warning('getOrCreateAthlete : écurie ignorée (titre invalide après normalisation) pour @name — valeur brute : "@raw"', [
                    '@name' => $name,
                    '@raw'  => $ecurie,
                ]);
                $ecurieName = null;
            }

            if ($ecurieName) {
                $eNids = $storage->getQuery()->accessCheck(false)
                    ->condition("type", "ecurie")
                    ->condition("title", $ecurieName)
                    ->execute();

                if (!empty($eNids)) {
                    $ecurieTargetId = reset($eNids);
                } else {
                    $logger->warning('getOrCreateAthlete : écurie "@ecurie" inconnue en base pour @name — AUCUNE écurie créée (texte brut potentiellement non fiable, ex. surnom/slogan de scraping). Athlète traité sans écurie.', [
                        '@name'   => $name,
                        '@ecurie' => $ecurieName,
                    ]);
                }
            }
        }

        // 1-bis. Normalisation écurie : remplace tout doublon par son pivot
        // Placé ici pour s'appliquer systématiquement (création ET mise à jour d'athlète)
        if ($ecurieTargetId) {
            $savedData = \Drupal::state()->get('misc_divers.ecuries_data', []);
            foreach ($savedData as $section) {
                $groupNids = array_values($section['ecuries'] ?? []);
                if (!empty($groupNids) && in_array($ecurieTargetId, $groupNids) && $ecurieTargetId != $groupNids[0]) {
                    $logger->info("getOrCreateAthlete : Écurie doublon [@old] remplacée par pivot [@pivot] pour l'athlète @name", [
                        '@name'  => $name,
                        '@pivot' => $groupNids[0],
                        '@old'   => $ecurieTargetId,
                    ]);
                    $ecurieTargetId = $groupNids[0];
                    break;
                }
            }
        }

        // 2. Recherche de l'athlète
        $lookup = function ($nameToSearch) use ($storage) {
            $nids = $storage->getQuery()->accessCheck(false)
                ->condition("type", "athlete")
                ->condition("field_athlete_nom_technique", $nameToSearch)
                ->execute();
            return !empty($nids) ? $storage->load(reset($nids)) : null;
        };

        $athlete = $lookup($cleanName);

        // 3. CAS : CRÉATION D'UN NOUVEL ATHLÈTE
        if (!$athlete) {
            try {
                $athlete = Node::create([
                    "type"                         => "athlete",
                    "title"                        => $this->textNormalizationHelper->correctNames($name),
                    "field_athlete_nom_technique"  => $cleanName,
                    "field_ecurie"                 => $ecurieTargetId ? ["target_id" => $ecurieTargetId] : [],
                    "status"                       => 1,
                ]);
                $athlete->save();
            } catch (\Drupal\Core\Database\IntegrityConstraintViolationException $e) {
                // Race condition : un autre worker a créé l'athlète entre le lookup et le save.
                // La UNIQUE KEY a intercepté la collision → on recharge l'entité existante.
                $athlete = $lookup($cleanName);
                $logger->notice(
                    'getOrCreateAthlete : collision SQL pour @name — athlète existant rechargé.',
                    ['@name' => $name]
                );
            } catch (\Exception $e) {
                // Autre erreur inattendue → dernier recours
                $athlete = $lookup($cleanName);
                $logger->error(
                    'getOrCreateAthlete : erreur inattendue pour @name — @msg',
                    ['@name' => $name, '@msg' => $e->getMessage()]
                );
            }
        }
        // CAS : MISE À JOUR (si pas d'écurie connue, ou écurie actuelle "inconnue"/vide/"?")
        elseif ($athlete && $ecurieTargetId && $this->isEcurieUnknownOrEmpty($athlete, $storage) && $ecurieTargetId != $athlete->get("field_ecurie")->target_id) {
            $oldEcurieId = $athlete->get("field_ecurie")->target_id;
            $athlete->set("field_ecurie", ["target_id" => $ecurieTargetId]);
            $athlete->save();
            $logger->notice(
                'getOrCreateAthlete : écurie mise à jour pour @name (ancienne écurie inconnue/vide [@old] → @new)',
                ['@name' => $name, '@old' => $oldEcurieId ?? 'vide', '@new' => $ecurieTargetId]
            );
        }

        if ($athlete) {
            $cache[$cleanName] = $athlete;
        }
        return $athlete;
    }

    /**
     * Suggère des variantes de noms de lutteurs déjà présents en base, proches
     * du nom donné, en ignorant les suffixes générationnels ("2", "3",
     * "junior", "jr") avant comparaison.
     *
     * Utilisé avant l'insertion d'une nouvelle confrontation : permet à l'IA
     * de repérer si le nom fourni ("Sa thies 2") correspond en fait à une
     * autre variante déjà connue ("Sathies", "Sa Thies 3", "Sathiers Junior"...).
     *
     * @param string $name  Nom brut (tel que reçu, ex: "Sa thies 2").
     * @param int    $limit Nombre maximum de suggestions retournées.
     *
     * @return string[] Liste de titres (labels) d'athlètes existants, proches
     *   du nom donné mais distincts (variantes potentielles).
     */
    public function findSimilarAthleteNames(string $name, int $limit = 6): array
    {
        $queryBase = $this->textNormalizationHelper->compactSearchBase($name);
        if ($queryBase === '') {
            return [];
        }

        $storage = \Drupal::entityTypeManager()->getStorage('node');
        $prefix  = mb_substr($queryBase, 0, min(5, mb_strlen($queryBase)));

        // Pré-filtrage SQL large (préfixe du nom technique), affinage ensuite en PHP.
        $nids = $storage->getQuery()->accessCheck(false)
            ->condition('type', 'athlete')
            ->condition('field_athlete_nom_technique', $prefix . '%', 'LIKE')
            ->range(0, 60)
            ->execute();

        if (empty($nids)) {
            return [];
        }

        $suggestions = [];
        foreach ($storage->loadMultiple($nids) as $athlete) {
            $title = trim((string) $athlete->label());
            if ($title === '') {
                continue;
            }

            // NB : on ne filtre PAS le cas où le titre est strictement
            // identique au nom demandé. Cette fonction sert notamment à
            // alimenter le prompt IA de confirmation avant insertion
            // (confirmWrestlersBeforeInsert) : le nom demandé n'est PAS
            // forcément déjà un nœud existant, donc une correspondance
            // exacte est justement l'information la plus précieuse à
            // transmettre à l'IA (elle prouve que ce nom, sans parenthèse,
            // existe déjà tel quel en base), et l'omettre revient à cacher
            // le meilleur candidat au profit d'homonymes moins pertinents.

            $candidateBase = $this->textNormalizationHelper->compactSearchBase($title);
            if ($candidateBase === '') {
                continue;
            }

            $isClose = $candidateBase === $queryBase
                || str_starts_with($candidateBase, $queryBase)
                || str_starts_with($queryBase, $candidateBase)
                || levenshtein($candidateBase, $queryBase) <= 2;

            if ($isClose && !isset($suggestions[$title])) {
                $suggestions[$title] = $title;
                if (count($suggestions) >= $limit) {
                    break;
                }
            }
        }

        return array_values($suggestions);
    }

    /**
     * Renvoie tous les noms de lutteurs (en base) dont le nom "technique"
     * normalisé CONTIENT le nom COMPLET du pivot tel quel — suffixe
     * générationnel du pivot INCLUS, pas retiré (ex: pivot "Robert 2" →
     * matche "Pape Ba Dit Robert 2", "Feu Robert 2", mais PAS "Robert
     * Diouf" ni "Robert" seul) —, en excluant le pivot lui-même.
     *
     * Utilisé par queueSuspectedGenerationalHomonyms() à la fois pour
     * déterminer les "homonymes_connus" (et donc l'éligibilité du pivot :
     * pas d'homonyme détecté = rien à vérifier) et pour peupler
     * "suggestions" avec tous les homonymes potentiels du pivot (ex:
     * pivot "Robert" → "Robert 2", "Robert Junior", "Petit Robert"...).
     *
     * @param string $pivotName Nom du lutteur pivot.
     * @param int    $limit     Nombre maximum de suggestions renvoyées.
     *
     * @return string[] Liste de noms (sans doublons, sans le pivot).
     */
    public function findAthleteNamesContainingPivot(string $pivotName, int $limit = 20): array
    {
        // Recherche du nom COMPLET du pivot tel quel (suffixe générationnel
        // inclus, ex. "Robert 2" reste "Robert 2" — on ne le tronque PAS en
        // "Robert"), normalisé au même format que field_athlete_nom_technique
        // (minuscules, accents translittérés, espaces/ponctuation retirés) :
        // ex. "Robert 2" → "robert2". Ce motif est recherché en
        // LIKE '%...%', ce qui matche "Pape Ba Dit Robert 2" (contient
        // "robert2") mais PAS "Robert Diouf" (ne contient pas "robert2").
        $searchBase = $this->textNormalizationHelper->technicalWrestlerName($pivotName);
        if ($searchBase === '') {
            return [];
        }

        $storage = \Drupal::entityTypeManager()->getStorage('node');

        $nids = $storage->getQuery()->accessCheck(false)
            ->condition('type', 'athlete')
            ->condition('field_athlete_nom_technique', '%' . $searchBase . '%', 'LIKE')
            ->range(0, 200)
            ->execute();

        if (empty($nids)) {
            return [];
        }

        $pivotNormalizedTitle = mb_strtolower(trim($pivotName));

        $names = [];
        foreach ($storage->loadMultiple($nids) as $athlete) {
            $title = trim((string) $athlete->label());
            if ($title === '' || mb_strtolower($title) === $pivotNormalizedTitle) {
                // On exclut le pivot lui-même (mais pas les autres
                // homonymes, même s'ils partagent la même base compacte).
                continue;
            }

            $names[$title] = $title;
            if (count($names) >= $limit) {
                break;
            }
        }

        return array_values($names);
    }

    /** Clean orphan Ecuries related to NO athletes */
    public function cleanOrphanEcuries(): void
    {
        $logger  = \Drupal::logger('shared_lib');
        $storage = \Drupal::entityTypeManager()->getStorage('node');

        // Toutes les écuries PUBLIÉES uniquement — les dépubliées sont volontairement
        // conservées (doublons fusionnés) et ne doivent plus être supprimées.
        $allEcurieNids = $storage->getQuery()
            ->accessCheck(false)
            ->condition('type', 'ecurie')
            ->condition('status', 1)
            ->execute();

        if (empty($allEcurieNids)) {
            echo "Aucune écurie trouvée.\n";
            return;
        }

        // Écuries qui ont AU MOINS un athlète rattaché
        $usedNids = $storage->getQuery()
            ->accessCheck(false)
            ->condition('type', 'athlete')
            ->condition('field_ecurie', array_values($allEcurieNids), 'IN')
            ->execute();

        // Récupère les valeurs de field_ecurie de ces athlètes
        $usedEcurieNids = [];
        foreach ($storage->loadMultiple($usedNids) as $athlete) {
            $ref = $athlete->get('field_ecurie')->target_id;
            if ($ref) {
                $usedEcurieNids[$ref] = $ref;
            }
        }

        $orphanNids = array_diff(array_values($allEcurieNids), array_keys($usedEcurieNids));

        if (empty($orphanNids)) {
            echo "Aucune écurie orpheline trouvée.\n";
            return;
        }

        echo count($orphanNids) . " écurie(s) orpheline(s) trouvée(s).\n";

        foreach ($orphanNids as $nid) {
            $node = $storage->load($nid);
            if ($node) {
                $title = $node->getTitle() ?: '(sans titre)';
                $node->delete();
                echo "  -> Écurie [{$nid}] \"{$title}\" supprimée.\n";
                $logger->notice(
                    'cleanOrphanEcuries : écurie orpheline [@nid] "@title" supprimée.',
                    ['@nid' => $nid, '@title' => $title]
                );
            }
        }

        echo "Nettoyage des écuries orphelines terminé.\n";
    }

    public function cleanPhantomEcuries(): void
    {
        $logger  = \Drupal::logger('shared_lib');
        $storage = \Drupal::entityTypeManager()->getStorage('node');

        $nids = $storage->getQuery()
            ->accessCheck(false)
            ->condition('type', 'ecurie')
            ->condition('title', '')
            ->execute();

        // Filet supplémentaire : titre whitespace-only via DB
        $connection = \Drupal\Core\Database\Database::getConnection();
        $extra = $connection->query(
            "SELECT nid FROM {node_field_data} WHERE type = 'ecurie' AND TRIM(title) = ''"
        )->fetchCol();

        $allNids = array_unique(array_merge(array_values($nids), $extra));

        if (empty($allNids)) {
            echo "Aucune écurie fantôme trouvée.\n";
            return;
        }

        foreach ($allNids as $nid) {
            $athleteNids = $storage->getQuery()
                ->accessCheck(false)
                ->condition('type', 'athlete')
                ->condition('field_ecurie', $nid)
                ->execute();

            // Détacher les athlètes rattachés à cette écurie fantôme
            if (!empty($athleteNids)) {
                $count = count($athleteNids); // ← fix : variable définie ici
                foreach ($athleteNids as $athleteNid) {
                    $athlete = $storage->load($athleteNid);
                    if ($athlete) {
                        $athlete->set('field_ecurie', null);
                        $athlete->save();
                    }
                }
                $logger->warning(
                    'cleanPhantomEcuries : @nb athlète(s) détaché(s) de l\'écurie fantôme [@nid]',
                    ['@nid' => $nid, '@nb' => $count]
                );
                echo "  -> {$count} athlète(s) détaché(s) de l'écurie fantôme [{$nid}].\n";
            }

            // Suppression maintenant sans obstacle
            $node = $storage->load($nid);
            if ($node) {
                $node->delete();
                echo "  -> Fantôme [{$nid}] supprimé.\n";
                $logger->notice(
                    'cleanPhantomEcuries : écurie fantôme [@nid] supprimée.',
                    ['@nid' => $nid]
                );
            }
        }
    }
}
