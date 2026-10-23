<?php

namespace Drupal\shared_lib\Service;

use Drupal\node\NodeInterface;
use Drupal\Core\Render\Markup;
use Symfony\Component\Console\Style\StyleInterface;

/**
 * Fusion de deux (ou plusieurs) fiches athlète identifiées comme
 * "jumelles" (field_same_athlete) vers un pivot unique : transfert des
 * confrontations et des a_venir, création des redirections 301, purge
 * des références résiduelles, puis suppression des jumeaux.
 *
 * Issu du découpage de l'ancienne classe statique
 * \Drupal\shared_lib\Tools\SomeTools (voir SomeTools.php, conservée comme
 * façade de compatibilité — voir shared_lib.services.yml).
 */
class AthleteMergeService {

  protected ConfrontationDeduplicationService $confrontationDeduplicationService;

  public function __construct(ConfrontationDeduplicationService $confrontationDeduplicationService) {
    $this->confrontationDeduplicationService = $confrontationDeduplicationService;
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
    public function mergeFromPivot(int $pivotNid, ?StyleInterface $io = NULL): ?array
    {
        $logger  = \Drupal::logger('misc_divers');
        $storage = \Drupal::entityTypeManager()->getStorage('node');

        // ── 1. Chargement et validation du pivot ─────────────────────────────────
        $pivot = $storage->load($pivotNid);

        if (!$pivot || $pivot->bundle() !== 'athlete') {
            $msg = sprintf('NID %d introuvable ou n\'est pas un athlete.', $pivotNid);
            $io ? $io->error($msg) : $logger->error($msg);
            return NULL;
        }

        if (!$pivot->hasField('field_same_athlete')) {
            $msg = 'Le champ field_same_athlete est absent du type athlete. Vérifiez la configuration.';
            $io ? $io->error($msg) : $logger->error($msg);
            return NULL;
        }

        // ── 2. Récupération des jumeaux ───────────────────────────────────────────
        // On snapshote les NID avant toute modification pour éviter les rechargements
        // surprises dus aux resets de cache en cours de traitement.
        $twinNids = [];
        foreach ($pivot->get('field_same_athlete') as $item) {
            $twinNid = (int) ($item->target_id ?? 0);
            if ($twinNid > 0 && $twinNid !== $pivotNid) {
                $twinNids[] = $twinNid;
            }
        }

        if (empty($twinNids)) {
            $msg = sprintf(
                'Le pivot [%d] %s n\'a aucun jumeau déclaré dans field_same_athlete.',
                $pivotNid,
                $pivot->getTitle()
            );
            $io ? $io->warning($msg) : $logger->warning($msg);
            return ['combats_transferred' => 0, 'avenir_transferred' => 0, 'deleted' => 0];
        }

        $twins = [];
        foreach ($twinNids as $twinNid) {
            $twin = $storage->load($twinNid);
            if ($twin && $twin->bundle() === 'athlete') {
                $twins[] = $twin;
            } else {
                $warn = sprintf('Jumeau NID %d introuvable ou type invalide — ignoré.', $twinNid);
                $io ? $io->warning($warn) : $logger->warning($warn);
            }
        }

        if (empty($twins)) {
            $io && $io->warning('Aucun jumeau valide chargé. Abandon.');
            return ['combats_transferred' => 0, 'avenir_transferred' => 0, 'deleted' => 0];
        }

        $io && $io->writeln(sprintf(
            '<info>Pivot : [%d] %s → %d jumeau(x) à absorber.</info>',
            $pivotNid,
            $pivot->getTitle(),
            count($twins)
        ));

        // ── 3. Traitement de chaque jumeau ────────────────────────────────────────
        $totalCombats   = 0;
        $totalAvenir    = 0;
        $totalDeleted   = 0;
        $totalRedirects = 0;
        $deletedTwins    = [];

        foreach ($twins as $twin) {
            $twinId    = (int) $twin->id();
            $twinTitle = $twin->getTitle();

            $io && $io->writeln(sprintf('  → Absorption de [%d] %s …', $twinId, $twinTitle));

            // ── 3a. Transfert des confrontations ─────────────────────────────────
            $combatsMoved = $this->transferConfrontations($twin, $pivot, $storage, $logger);
            $totalCombats += $combatsMoved;

            $avenirMoved = $this->transferAvenir($twin, $pivot, $storage, $logger);
            $totalAvenir += $avenirMoved;

            // ── 3c. Redirections 301 des alias du jumeau vers le pivot ────────────
            $redirectsCreated = $this->createTwinRedirects($twin, $pivot, $logger, $io);
            $totalRedirects  += $redirectsCreated; // ← ajouter
            $io && $redirectsCreated > 0 && $io->writeln(sprintf(
                '     <comment>%d redirection(s) 301 créée(s).</comment>',
                $redirectsCreated
            ));

            // ── 3d. Purge des back-references entity_reference vers le jumeau ─────
            $backRefsCleaned = $this->purgeBackReferences($twinId, $pivotNid, $storage, $logger, $io);

            // Reset du cache storage pour que Drupal voie les MAJ en base
            $storage->resetCache([$twinId]);

            // ── 3d. Suppression du jumeau ─────────────────────────────────────────
            try {
                // Recharger depuis la base pour avoir l'état post-nettoyage
                $twinFresh = $storage->load($twinId);
                if (!$twinFresh) {
                    $io && $io->writeln(sprintf('     <comment>Jumeau [%d] déjà absent — ignoré.</comment>', $twinId));
                    continue;
                }

                $twinFresh->delete();
                $storage->resetCache([$twinId]);
                $totalDeleted++;
                $deletedTwins[] = ['nid' => $twinId, 'title' => $twinTitle]; // ← ajouter

                $logger->notice(
                    'fusion-athlete : Jumeau [@tid] "@ttitle" supprimé (@combats combats, @avenir a_venir transférés, @redirects redirections 301, @refs back-refs purgées → pivot [@pid] "@ptitle")',
                    [
                        '@tid'       => $twinId,
                        '@ttitle'    => $twinTitle,
                        '@combats'   => $combatsMoved,
                        '@avenir'    => $avenirMoved,
                        '@redirects' => $redirectsCreated,
                        '@refs'      => $backRefsCleaned,
                        '@pid'       => $pivotNid,
                        '@ptitle'    => $pivot->getTitle(),
                    ]
                );

                $io && $io->writeln(sprintf(
                    '     <comment>✓ Supprimé. (%d combats, %d a_venir transférés, %d back-refs purgées)</comment>',
                    $combatsMoved,
                    $avenirMoved,
                    $backRefsCleaned
                ));
            } catch (\Exception $e) {
                $logger->error(
                    'fusion-athlete : Échec suppression jumeau [@tid] "@ttitle" : @msg',
                    ['@tid' => $twinId, '@ttitle' => $twinTitle, '@msg' => $e->getMessage()]
                );
                $io && $io->error(sprintf('Échec suppression [%d] %s : %s', $twinId, $twinTitle, $e->getMessage()));

                // Diagnostic : lister les entités qui bloquent encore
                $this->logRemainingReferences($twinId, $storage, $logger);
            }
        }

        // ── 4. Nettoyage de field_same_athlete sur le pivot ───────────────────────
        try {
            // Recharger le pivot pour avoir son état courant
            $storage->resetCache([$pivotNid]);
            $pivotFresh = $storage->load($pivotNid);
            if ($pivotFresh) {
                $pivotFresh->set('field_same_athlete', []);
                $pivotFresh->setNewRevision(FALSE);
                $pivotFresh->save();
                $io && $io->writeln('<info>  → field_same_athlete vidé sur le pivot.</info>');
            }
        } catch (\Exception $e) {
            $warn = sprintf('Impossible de vider field_same_athlete sur le pivot : %s', $e->getMessage());
            $io ? $io->warning($warn) : $logger->warning($warn);
        }

        // ── 5. Log de synthèse dans dblog ────────────────────────────────────────
        if (!empty($deletedTwins)) {
            $twinsSummary = implode(', ', array_map(
                fn($t) => sprintf('[%d] "%s"', $t['nid'], $t['title']),
                $deletedTwins
            ));

            $logger->notice(
                'fusion-athlete SYNTHÈSE — Pivot [@pid] "@ptitle" | Jumeaux supprimés (@nb) : @twins | @combats combats + @avenir a_venir transférés, @redirects redirections 301 créées.',
                [
                    '@pid'       => $pivotNid,
                    '@ptitle'    => $pivot->getTitle(),
                    '@nb'        => count($deletedTwins),
                    '@twins'     => $twinsSummary,
                    '@combats'   => $totalCombats,
                    '@avenir'    => $totalAvenir,
                    '@redirects' => $totalRedirects,
                ]
            );
        }

        // ── 6. Mise à jour de la config remplacementsYtb (pivot|jumeaux supprimés) ─
        if (!empty($deletedTwins)) {
            $this->updateRemplacementsYtbConfig(
                $pivot->getTitle(),
                array_column($deletedTwins, 'title')
            );
        }

        return [
            'combats_transferred' => $totalCombats,
            'avenir_transferred'  => $totalAvenir,
            'deleted'             => $totalDeleted,
        ];
    }

    /**
     * Met à jour la config misc_divers.settings:remplacementsYtb pour
     * conserver la trace du (ou des) nom(s) de nœud(s) jumeau(x) supprimés
     * lors d'une fusion, associés au nom du lutteur pivot de référence.
     *
     * Format de chaque ligne (stocké en tableau associatif) :
     *   "nom du pivot référence" => "jumeau1, jumeau2, ..."
     *
     * Règles :
     *  - Si une entrée existe déjà pour ce pivot (comparaison insensible à
     *    la casse), on ALTERNE / complète simplement la valeur après le
     *    "|" (i.e. on ajoute les nouveaux noms de jumeaux à la liste
     *    existante, sans doublons), la clé d'origine étant conservée telle
     *    quelle.
     *  - Sinon, on crée une nouvelle entrée "pivot|jumeaux".
     *  - L'ensemble des entrées est ensuite trié par ordre alphabétique
     *    (insensible à la casse) sur le nom du pivot avant sauvegarde.
     *
     * @param string   $pivotTitle       Nom du lutteur pivot de référence.
     * @param string[] $deletedTwinTitles Noms des nœuds jumeaux supprimés.
     */
    private function updateRemplacementsYtbConfig(string $pivotTitle, array $deletedTwinTitles): void
    {
        $deletedTwinTitles = array_values(array_filter(array_map('trim', $deletedTwinTitles), fn($t) => $t !== ''));
        if (empty($deletedTwinTitles)) {
            return;
        }

        $pivotTitle = trim($pivotTitle);
        if ($pivotTitle === '') {
            return;
        }

        $config       = \Drupal::configFactory()->getEditable('misc_divers.settings');
        $replacements = $config->get('remplacementsYtb') ?? [];

        if (!is_array($replacements)) {
            $replacements = [];
        }

        // ── Recherche d'une entrée existante pour ce pivot (insensible à la casse) ─
        $matchedKey = NULL;
        foreach ($replacements as $key => $value) {
            if (mb_strtolower(trim((string) $key)) === mb_strtolower($pivotTitle)) {
                $matchedKey = $key;
                break;
            }
        }

        $existingNames = [];
        if ($matchedKey !== NULL) {
            $existingNames = array_filter(array_map('trim', explode(',', (string) $replacements[$matchedKey])));
        }

        // ── Fusion sans doublons (insensible à la casse) ─────────────────────────
        $allNames = array_values($existingNames);
        foreach ($deletedTwinTitles as $twinTitle) {
            $alreadyPresent = FALSE;
            foreach ($allNames as $existing) {
                if (mb_strtolower($existing) === mb_strtolower($twinTitle)) {
                    $alreadyPresent = TRUE;
                    break;
                }
            }
            if (!$alreadyPresent) {
                $allNames[] = $twinTitle;
            }
        }

        $newValue = implode(', ', $allNames);

        if ($matchedKey !== NULL) {
            // La clé existe déjà (même si casse différente) → on modifie juste la valeur après le "|".
            $replacements[$matchedKey] = $newValue;
        } else {
            // Nouvelle entrée "pivot|jumeaux".
            $replacements[$pivotTitle] = $newValue;
        }

        // ── Tri alphabétique final (insensible à la casse) sur le nom du pivot ───
        uksort($replacements, fn($a, $b) => strcasecmp((string) $a, (string) $b));

        $config->set('remplacementsYtb', $replacements)->save();
    }

    /**
     * Transfère toutes les confrontations du jumeau vers le pivot.
     *
     * Champs mis à jour :
     *  - field_athlete_1   (entity_reference)
     *  - field_athlete_2   (entity_reference)
     *  - field_vainqueur   (integer = NID dans ce projet, non scanné par integrity_enforce)
     *
     * ANTI-DOUBLON POST-FUSION : après avoir remplacé le jumeau par le pivot,
     * le combat devient "pivot vs adversaire". Or le pivot peut déjà avoir un
     * combat contre ce même adversaire à une date proche (< 1 an) — ce qui
     * N'ÉTAIT PAS un doublon avant la fusion (jumeau ≠ pivot), mais LE
     * DEVIENT après. On applique donc la même règle que pour les nouveaux
     * combats (checkNearDuplicateConfrontation) avant de transférer :
     *  - Si un doublon proche est détecté chez le pivot → on NE transfère
     *    PAS ce combat (il est supprimé), la méthode ayant déjà mis à jour
     *    au besoin la date/le verdict du combat existant conservé.
     *  - Si l'adversaire == pivot lui-même (le jumeau avait combattu contre
     *    le pivot directement) → transférer créerait un non-sens "pivot vs
     *    pivot" ; le combat est alors supprimé avec un log dédié.
     */
    private function transferConfrontations(
        NodeInterface $twin,
        NodeInterface $pivot,
        $storage,
        $logger
    ): int {
        $twinId  = (int) $twin->id();
        $pivotId = (int) $pivot->id();
        $count   = 0;

        $orGroup = $storage->getQuery()->orConditionGroup()
            ->condition('field_athlete_1', $twinId)
            ->condition('field_athlete_2', $twinId);

        $storage->resetCache();

        $combatNids = $storage->getQuery()
            ->condition('type', 'confrontation')
            ->condition($orGroup)
            ->accessCheck(FALSE)
            ->execute();

        $logger->info(
            'fusion-athlete transferConfrontations : query résultat pour [@tid] = [@nids]',
            ['@tid' => $twinId, '@nids' => implode(', ', $combatNids)]
        );

        if (empty($combatNids)) {
            return 0;
        }

        foreach ($storage->loadMultiple($combatNids) as $combat) {

            $currentA1 = (int) ($combat->get('field_athlete_1')->target_id ?? 0);
            $currentA2 = (int) ($combat->get('field_athlete_2')->target_id ?? 0);
            $opponentId = ($currentA1 === $twinId) ? $currentA2 : $currentA1;

            // ── CAS A : le jumeau avait combattu directement contre le pivot ──────
            // Après remplacement, ça donnerait "pivot vs pivot" — non-sens.
            if ($opponentId === $pivotId) {
                $delNid = $combat->id();
                $delTitle = $combat->getTitle();
                try {
                    $combat->delete();
                    $storage->resetCache([$delNid]);
                    $logger->warning(
                        'fusion-athlete transferConfrontations : combat [@nid] "@title" SUPPRIMÉ — le jumeau [@tid] avait combattu contre le pivot [@pid] lui-même (transfert aurait créé pivot vs pivot).',
                        ['@nid' => $delNid, '@title' => $delTitle, '@tid' => $twinId, '@pid' => $pivotId]
                    );
                } catch (\Exception $e) {
                    $logger->error(
                        'fusion-athlete transferConfrontations : échec suppression combat pivot-vs-pivot [@nid] : @msg',
                        ['@nid' => $delNid, '@msg' => $e->getMessage()]
                    );
                }
                continue; // Combat traité (supprimé), pas de transfert.
            }

            // ── CAS B : vérification anti-doublon post-fusion ────────────────────
            // Le pivot a-t-il déjà un combat "proche" (< 1 an) contre ce même
            // adversaire ? Si oui, on laisse checkNearDuplicateConfrontation
            // mettre à jour l'existant (date/verdict) et on NE transfère PAS
            // ce combat — on le supprime pour éviter le doublon.
            $combatDate    = (string) ($combat->get('field_confrontation_date')->value ?? '');
            $combatVerdict = (string) ($combat->get('field_vainqueur')->value ?? '');

            if ($opponentId > 0 && $combatDate !== '') {
                $isNearDuplicate = $this->confrontationDeduplicationService->checkNearDuplicateConfrontation(
                    $pivotId,
                    $opponentId,
                    $combatDate,
                    $combatVerdict,
                    $storage,
                    $logger
                );

                if ($isNearDuplicate) {
                    $delNid = $combat->id();
                    $delTitle = $combat->getTitle();
                    try {
                        $combat->delete();
                        $storage->resetCache([$delNid]);
                        $logger->notice(
                            'fusion-athlete transferConfrontations : combat [@nid] "@title" (jumeau [@tid] vs adversaire [@opp], @date) SUPPRIMÉ — doublon post-fusion détecté avec un combat existant du pivot [@pid] (déjà mis à jour si nécessaire par checkNearDuplicateConfrontation).',
                            [
                                '@nid'   => $delNid,
                                '@title' => $delTitle,
                                '@tid'   => $twinId,
                                '@opp'   => $opponentId,
                                '@date'  => $combatDate,
                                '@pid'   => $pivotId,
                            ]
                        );
                    } catch (\Exception $e) {
                        $logger->error(
                            'fusion-athlete transferConfrontations : échec suppression combat doublon post-fusion [@nid] : @msg',
                            ['@nid' => $delNid, '@msg' => $e->getMessage()]
                        );
                    }
                    continue; // Combat traité (supprimé), pas de transfert.
                }
            }

            // ── CAS C : pas de doublon détecté → transfert normal ─────────────────
            $updated = FALSE;

            if (
                $combat->hasField('field_athlete_1')
                && (int) $combat->get('field_athlete_1')->target_id === $twinId
            ) {
                $combat->set('field_athlete_1', ['target_id' => $pivotId]);
                $updated = TRUE;
            }

            if (
                $combat->hasField('field_athlete_2')
                && (int) $combat->get('field_athlete_2')->target_id === $twinId
            ) {
                $combat->set('field_athlete_2', ['target_id' => $pivotId]);
                $updated = TRUE;
            }

            // field_vainqueur = integer (NID) dans ce projet, pas une entity_reference.
            // Non scanné par entity_reference_integrity_enforce, mais on le met à jour
            // pour la cohérence des données.
            if ($combat->hasField('field_vainqueur')) {
                $val = $combat->get('field_vainqueur')->value;
                if ($val !== NULL && (int) $val === $twinId) {
                    $combat->set('field_vainqueur', $pivotId);
                    $updated = TRUE;
                }
            }

            if ($updated) {
                try {
                    $combat->setNewRevision(FALSE);
                    $combat->save();
                    $count++;
                    $logger->info(
                        'fusion-athlete : Combat [@cid] "@ctitle" → athletes mis à jour vers pivot [@pid]',
                        [
                            '@cid'    => $combat->id(),
                            '@ctitle' => $combat->getTitle(),
                            '@pid'    => $pivotId,
                        ]
                    );
                } catch (\Exception $e) {
                    $logger->error(
                        'fusion-athlete : Échec MAJ combat [@cid] : @msg',
                        ['@cid' => $combat->id(), '@msg' => $e->getMessage()]
                    );
                }
            }
        }

        return $count;
    }

    /**
     * Transfère toutes les a_venir du jumeau vers le pivot.
     *
     * Champs mis à jour :
     *  - field_lutteur_1
     *  - field_lutteur_2
     *
     * ANTI-DOUBLON POST-FUSION : mêmes précautions que pour
     * transferConfrontations() —
     *  - Si l'adversaire est le pivot lui-même (jumeau vs pivot) →
     *    transférer créerait un "pivot vs pivot" absurde → suppression.
     *  - Si le pivot a déjà un a_venir contre ce même adversaire à la
     *    même date (ou très proche) → on ne transfère pas, on supprime
     *    le a_venir du jumeau pour éviter le doublon.
     */
    private function transferAvenir(
        NodeInterface $twin,
        NodeInterface $pivot,
        $storage,
        $logger
    ): int {
        $twinId  = (int) $twin->id();
        $pivotId = (int) $pivot->id();
        $count   = 0;

        $storage->resetCache();

        $orGroup = $storage->getQuery()->orConditionGroup()
            ->condition('field_lutteur_1', $twinId)
            ->condition('field_lutteur_2', $twinId);

        $avenirNids = $storage->getQuery()
            ->condition('type', 'a_venir')
            ->condition($orGroup)
            ->accessCheck(FALSE)
            ->execute();

        if (empty($avenirNids)) {
            return 0;
        }

        foreach ($storage->loadMultiple($avenirNids) as $avenir) {

            $currentL1 = (int) ($avenir->get('field_lutteur_1')->target_id ?? 0);
            $currentL2 = (int) ($avenir->get('field_lutteur_2')->target_id ?? 0);
            $opponentId = ($currentL1 === $twinId) ? $currentL2 : $currentL1;

            // ── CAS A : jumeau vs pivot directement → non-sens après transfert ────
            if ($opponentId === $pivotId) {
                $delNid   = $avenir->id();
                $delTitle = $avenir->getTitle();
                try {
                    $avenir->delete();
                    $storage->resetCache([$delNid]);
                    $logger->warning(
                        'fusion-athlete transferAvenir : a_venir [@nid] "@title" SUPPRIMÉ — le jumeau [@tid] avait un combat à venir contre le pivot [@pid] lui-même (transfert aurait créé pivot vs pivot).',
                        ['@nid' => $delNid, '@title' => $delTitle, '@tid' => $twinId, '@pid' => $pivotId]
                    );
                } catch (\Exception $e) {
                    $logger->error(
                        'fusion-athlete transferAvenir : échec suppression a_venir pivot-vs-pivot [@nid] : @msg',
                        ['@nid' => $delNid, '@msg' => $e->getMessage()]
                    );
                }
                continue;
            }

            // ── CAS B : le pivot a-t-il déjà un a_venir contre ce même adversaire,
            // à la même date (ou très proche) ? Si oui, doublon post-fusion.
            $avenirDate = (string) ($avenir->get('field_confrontation_date')->value ?? '');

            if ($opponentId > 0 && $avenirDate !== '') {
                $orderA = $storage->getQuery()->andConditionGroup()
                    ->condition('field_lutteur_1', $pivotId)
                    ->condition('field_lutteur_2', $opponentId);
                $orderB = $storage->getQuery()->andConditionGroup()
                    ->condition('field_lutteur_1', $opponentId)
                    ->condition('field_lutteur_2', $pivotId);
                $pairGroup = $storage->getQuery()->orConditionGroup()
                    ->condition($orderA)
                    ->condition($orderB);

                $existingAvenirIds = $storage->getQuery()
                    ->condition('type', 'a_venir')
                    ->condition($pairGroup)
                    ->accessCheck(FALSE)
                    ->execute();

                $isDuplicate = false;
                if (!empty($existingAvenirIds)) {
                    $newTs = strtotime($avenirDate);
                    foreach ($storage->loadMultiple($existingAvenirIds) as $existingAvenir) {
                        $existingDateStr = (string) ($existingAvenir->get('field_confrontation_date')->value ?? '');
                        if ($existingDateStr === '') {
                            continue;
                        }
                        $existingTs = strtotime($existingDateStr);
                        if ($existingTs === false) {
                            continue;
                        }
                        // Fenêtre resserrée (≤ 30 jours) : un a_venir n'a pas de notion
                        // de "1er janvier générique" ni de verdict à préférer, donc on
                        // reste conservateur pour ne fusionner que les cas évidents.
                        if (abs($newTs - $existingTs) / 86400 <= 30) {
                            $isDuplicate = true;
                            $logger->info(
                                'fusion-athlete transferAvenir : doublon post-fusion détecté avec a_venir existant [@enid] du pivot (adversaire [@opp], écart ≤ 30j).',
                                ['@enid' => $existingAvenir->id(), '@opp' => $opponentId]
                            );
                            break;
                        }
                    }
                }

                if ($isDuplicate) {
                    $delNid   = $avenir->id();
                    $delTitle = $avenir->getTitle();
                    try {
                        $avenir->delete();
                        $storage->resetCache([$delNid]);
                        $logger->notice(
                            'fusion-athlete transferAvenir : a_venir [@nid] "@title" (jumeau [@tid] vs adversaire [@opp], @date) SUPPRIMÉ — doublon post-fusion avec un a_venir existant du pivot [@pid].',
                            [
                                '@nid'   => $delNid,
                                '@title' => $delTitle,
                                '@tid'   => $twinId,
                                '@opp'   => $opponentId,
                                '@date'  => $avenirDate,
                                '@pid'   => $pivotId,
                            ]
                        );
                    } catch (\Exception $e) {
                        $logger->error(
                            'fusion-athlete transferAvenir : échec suppression a_venir doublon post-fusion [@nid] : @msg',
                            ['@nid' => $delNid, '@msg' => $e->getMessage()]
                        );
                    }
                    continue;
                }
            }

            // ── CAS C : pas de doublon détecté → transfert normal ─────────────────
            $updated = FALSE;

            if (
                $avenir->hasField('field_lutteur_1')
                && (int) $avenir->get('field_lutteur_1')->target_id === $twinId
            ) {
                $avenir->set('field_lutteur_1', ['target_id' => $pivotId]);
                $updated = TRUE;
            }

            if (
                $avenir->hasField('field_lutteur_2')
                && (int) $avenir->get('field_lutteur_2')->target_id === $twinId
            ) {
                $avenir->set('field_lutteur_2', ['target_id' => $pivotId]);
                $updated = TRUE;
            }

            if ($updated) {
                try {
                    $avenir->setNewRevision(FALSE);
                    $avenir->save();
                    $count++;
                } catch (\Exception $e) {
                    $logger->error(
                        'fusion-athlete : Échec MAJ a_venir [@nid] : @msg',
                        ['@nid' => $avenir->id(), '@msg' => $e->getMessage()]
                    );
                }
            }
        }

        return $count;
    }

    /**
     * Supprime toutes les références entity_reference pointant vers $twinId,
     * sur des champs autres que ceux déjà gérés (confrontations / a_venir).
     *
     * Champs ciblés connus dans ce projet :
     *  - athlete.field_same_athlete  → retire la valeur $twinId de la liste
     *
     * Si d'autres content types ont des entity_reference vers athlete, les ajouter ici.
     *
     * @param int $twinId  NID du jumeau à purger.
     * @return int         Nombre de nœuds modifiés.
     */
    private function purgeBackReferences(
        int $twinId,
        int $pivotId,
        $storage,
        $logger,
        ?StyleInterface $io = NULL
    ): int {
        $count = 0;

        // ── athlete.field_same_athlete (entité courante) ─────────────────────────
        $athleteNids = $storage->getQuery()
            ->condition('type', 'athlete')
            ->condition('field_same_athlete', $twinId)
            ->accessCheck(FALSE)
            ->execute();

        foreach ($storage->loadMultiple($athleteNids) as $athlete) {
            $currentValues = $athlete->get('field_same_athlete')->getValue();
            $newValues = array_values(array_filter(
                $currentValues,
                fn($item) => (int) ($item['target_id'] ?? 0) !== $twinId
            ));

            if (count($newValues) === count($currentValues)) {
                continue;
            }

            try {
                $athlete->set('field_same_athlete', $newValues);
                $athlete->setNewRevision(FALSE);
                $athlete->save();
                $count++;

                $logger->info(
                    'fusion-athlete : Back-ref purgée — [@aid] "@atitle".field_same_athlete ne pointe plus vers [@tid]',
                    ['@aid' => $athlete->id(), '@atitle' => $athlete->getTitle(), '@tid' => $twinId]
                );
                $io && $io->writeln(sprintf(
                    '     <comment>Back-ref purgée : [%d] %s → field_same_athlete nettoyé</comment>',
                    $athlete->id(),
                    $athlete->getTitle()
                ));
            } catch (\Exception $e) {
                $logger->error(
                    'fusion-athlete : Échec purge back-ref [@aid] : @msg',
                    ['@aid' => $athlete->id(), '@msg' => $e->getMessage()]
                );
            }
        }

        // ── SQL : tables courantes + révisions (entity_reference_integrity) ──────
        // entity_reference_integrity_enforce inspecte aussi node_revision__field_*.
        // Les transferts entity API ne touchent que la révision / langue courante.
        $count += $this->forceReplaceSqlReferences($twinId, $pivotId, $logger, $io);

        return $count;
    }

    /**
     * Remplace $twinId par $pivotId dans toutes les tables field ER connues
     * (node__* et node_revision__*), toutes langues / deltas.
     */
    private function forceReplaceSqlReferences(
        int $twinId,
        int $pivotId,
        $logger,
        ?StyleInterface $io = NULL
    ): int {
        $db = \Drupal::database();
        $schema = $db->schema();
        $total = 0;

        // Colonnes entity_reference typiques vers un athlete.
        $candidates = [
            'node__field_athlete_1' => 'field_athlete_1_target_id',
            'node_revision__field_athlete_1' => 'field_athlete_1_target_id',
            'node__field_athlete_2' => 'field_athlete_2_target_id',
            'node_revision__field_athlete_2' => 'field_athlete_2_target_id',
            'node__field_lutteur_1' => 'field_lutteur_1_target_id',
            'node_revision__field_lutteur_1' => 'field_lutteur_1_target_id',
            'node__field_lutteur_2' => 'field_lutteur_2_target_id',
            'node_revision__field_lutteur_2' => 'field_lutteur_2_target_id',
            'node__field_athlete' => 'field_athlete_target_id',
            'node_revision__field_athlete' => 'field_athlete_target_id',
            'node__field_same_athlete' => 'field_same_athlete_target_id',
            'node_revision__field_same_athlete' => 'field_same_athlete_target_id',
        ];

        foreach ($candidates as $table => $column) {
            if (!$schema->tableExists($table) || !$schema->fieldExists($table, $column)) {
                continue;
            }
            try {
                $updated = $db->update($table)
                    ->fields([$column => $pivotId])
                    ->condition($column, $twinId)
                    ->execute();
                if ($updated) {
                    $total += (int) $updated;
                    $logger->notice(
                        'fusion-athlete SQL ref: @table.@col : @n ligne(s) [@tid] → [@pid]',
                        [
                            '@table' => $table,
                            '@col' => $column,
                            '@n' => $updated,
                            '@tid' => $twinId,
                            '@pid' => $pivotId,
                        ]
                    );
                    $io && $io->writeln(sprintf(
                        '     <comment>SQL %s.%s : %d ref(s) %d → %d</comment>',
                        $table,
                        $column,
                        $updated,
                        $twinId,
                        $pivotId
                    ));
                }
            } catch (\Exception $e) {
                $logger->error(
                    'fusion-athlete SQL ref échec @table : @msg',
                    ['@table' => $table, '@msg' => $e->getMessage()]
                );
            }
        }

        // Sécurité anti pivot-vs-pivot sur combats / a_venir (révisions incluses).
        foreach (['node__field_athlete_1', 'node_revision__field_athlete_1'] as $t1) {
            $t2 = str_replace('athlete_1', 'athlete_2', $t1);
            if (!$schema->tableExists($t1) || !$schema->tableExists($t2)) {
                continue;
            }
            // Lignes où les deux côtés pointent vers le pivot après remplacement :
            // on ne peut pas les « corriger » ici facilement ; le transfer a déjà
            // tenté de supprimer les nœuds pivot-vs-pivot en entity API.
        }

        return $total;
    }

    /**
     * Appelé après un échec de delete pour loguer ce qui bloque encore.
     * Permet un diagnostic rapide sans fouiller manuellement la base.
     */
    private function logRemainingReferences(int $twinId, $storage, $logger): void
    {
        // Confrontations résiduelles
        $orGroup = $storage->getQuery()->orConditionGroup()
            ->condition('field_athlete_1', $twinId)
            ->condition('field_athlete_2', $twinId);
        $combats = $storage->getQuery()
            ->condition('type', 'confrontation')
            ->condition($orGroup)
            ->accessCheck(FALSE)
            ->execute();

        // a_venir résiduels
        $orGroup2 = $storage->getQuery()->orConditionGroup()
            ->condition('field_lutteur_1', $twinId)
            ->condition('field_lutteur_2', $twinId);
        $avenirs = $storage->getQuery()
            ->condition('type', 'a_venir')
            ->condition($orGroup2)
            ->accessCheck(FALSE)
            ->execute();

        // field_same_athlete résiduels
        $sameAthletes = $storage->getQuery()
            ->condition('type', 'athlete')
            ->condition('field_same_athlete', $twinId)
            ->accessCheck(FALSE)
            ->execute();

        $logger->error(
            'fusion-athlete DIAGNOSTIC — Références résiduelles vers [@tid] : @nc confrontation(s), @na a_venir, @ns athlete.field_same_athlete. NIDs confrontations=[@c] | NIDs athletes=[@s]',
            [
                '@tid' => $twinId,
                '@nc'  => count($combats),
                '@na'  => count($avenirs),
                '@ns'  => count($sameAthletes),
                '@c'   => implode(', ', $combats),
                '@s'   => implode(', ', $sameAthletes),
            ]
        );

        // Scan SQL tables (révisions incluses) pour entity_reference_integrity.
        $db = \Drupal::database();
        $schema = $db->schema();
        $scan = [
            'node__field_athlete_1' => 'field_athlete_1_target_id',
            'node_revision__field_athlete_1' => 'field_athlete_1_target_id',
            'node__field_athlete_2' => 'field_athlete_2_target_id',
            'node_revision__field_athlete_2' => 'field_athlete_2_target_id',
            'node__field_lutteur_1' => 'field_lutteur_1_target_id',
            'node_revision__field_lutteur_1' => 'field_lutteur_1_target_id',
            'node__field_lutteur_2' => 'field_lutteur_2_target_id',
            'node_revision__field_lutteur_2' => 'field_lutteur_2_target_id',
            'node__field_athlete' => 'field_athlete_target_id',
            'node_revision__field_athlete' => 'field_athlete_target_id',
            'node__field_same_athlete' => 'field_same_athlete_target_id',
            'node_revision__field_same_athlete' => 'field_same_athlete_target_id',
        ];
        foreach ($scan as $table => $col) {
            if (!$schema->tableExists($table) || !$schema->fieldExists($table, $col)) {
                continue;
            }
            $n = (int) $db->select($table, 't')
                ->condition($col, $twinId)
                ->countQuery()
                ->execute()
                ->fetchField();
            if ($n > 0) {
                $logger->error(
                    'fusion-athlete DIAGNOSTIC SQL — @table.@col contient encore @n référence(s) vers [@tid]',
                    ['@table' => $table, '@col' => $col, '@n' => $n, '@tid' => $twinId]
                );
            }
        }
    }

    /**
     * Crée les redirections 301 depuis tous les alias du jumeau vers le pivot.
     *
     * Récupère tous les alias path_alias du jumeau (toutes langues),
     * trouve le meilleur alias du pivot (même langue en priorité, sinon premier trouvé),
     * et crée une Redirect 301 via le module redirect pour chaque alias source.
     *
     * @param NodeInterface   $twin   Nœud jumeau (avant suppression).
     * @param NodeInterface   $pivot  Nœud pivot (destination).
     * @param object          $logger
     * @param StyleInterface|null $io Console Drush (optionnel).
     *
     * @return int Nombre de redirections créées.
     */
    private function createTwinRedirects(
        NodeInterface $twin,
        NodeInterface $pivot,
        $logger,
        ?StyleInterface $io = null
    ): int {
        if (!\Drupal::moduleHandler()->moduleExists('redirect')) {
            $logger->warning('createTwinRedirects : module redirect absent, redirections ignorées.');
            return 0;
        }

        $aliasStorage    = \Drupal::entityTypeManager()->getStorage('path_alias');
        $redirectStorage = \Drupal::entityTypeManager()->getStorage('redirect');

        $twinPath  = '/node/' . $twin->id();
        $pivotPath = '/node/' . $pivot->id();

        // ── Récupérer tous les alias du jumeau (toutes langues) ─────────────────
        $twinAliases = $aliasStorage->loadByProperties(['path' => $twinPath]);

        if (empty($twinAliases)) {
            $logger->info(
                'createTwinRedirects : aucun alias trouvé pour le jumeau [@tid].',
                ['@tid' => $twin->id()]
            );
            return 0;
        }

        // ── Récupérer les alias du pivot, indexés par langue ─────────────────────
        $pivotAliases = $aliasStorage->loadByProperties(['path' => $pivotPath]);
        $pivotByLang  = [];
        foreach ($pivotAliases as $pa) {
            $lang = $pa->get('langcode')->value;
            // Garder le plus récent si plusieurs alias pour la même langue
            if (!isset($pivotByLang[$lang])) {
                $pivotByLang[$lang] = ltrim($pa->getAlias(), '/');
            }
        }

        if (empty($pivotByLang)) {
            $logger->warning(
                'createTwinRedirects : aucun alias pivot trouvé pour [@pid], redirections ignorées.',
                ['@pid' => $pivot->id()]
            );
            return 0;
        }

        // Alias pivot de secours (première langue disponible)
        $fallbackPivotAlias = reset($pivotByLang);

        $created = 0;

        foreach ($twinAliases as $twinAlias) {
            $lang         = $twinAlias->get('langcode')->value;
            $sourceAlias  = ltrim($twinAlias->getAlias(), '/');

            // Choisir la destination dans la même langue que la source, sinon fallback
            $destinationAlias = $pivotByLang[$lang] ?? $fallbackPivotAlias;
            $destinationUri   = 'internal:/' . $destinationAlias;

            // ── Skip si la redirection existe déjà ───────────────────────────────
            $existing = $redirectStorage->loadByProperties([
                'redirect_source__path' => $sourceAlias,
            ]);
            if (!empty($existing)) {
                $logger->info(
                    'createTwinRedirects : redirection déjà existante pour "@src" — ignorée.',
                    ['@src' => $sourceAlias]
                );
                continue;
            }

            // ── Skip si source == destination (sécurité boucle infinie) ──────────
            if ($sourceAlias === $destinationAlias) {
                $logger->info(
                    'createTwinRedirects : source == destination pour "@src" — ignorée.',
                    ['@src' => $sourceAlias]
                );
                continue;
            }

            try {
                /** @var \Drupal\redirect\Entity\Redirect $redirect */
                $redirect = \Drupal\redirect\Entity\Redirect::create([
                    'redirect_source'   => $sourceAlias, // Ex: lutteurs/ancien-nom
                    'redirect_redirect' => $destinationUri,
                    'language'          => 'und', // <--- Indépendant de la langue pour intercepter globalement
                    'status_code'       => 301,
                ]);
                $redirect->save();
                $created++;

                $logger->notice(
                    'createTwinRedirects : 301 créé — "/@src" → "/@dst" [lang=@lang] (jumeau [@tid] → pivot [@pid])',
                    [
                        '@src'  => $sourceAlias,
                        '@dst'  => $destinationAlias,
                        '@lang' => $lang,
                        '@tid'  => $twin->id(),
                        '@pid'  => $pivot->id(),
                    ]
                );
                $io && $io->writeln(sprintf(
                    '     <comment>↪ 301 /%s → /%s [%s]</comment>',
                    $sourceAlias,
                    $destinationAlias,
                    $lang
                ));
            } catch (\Exception $e) {
                $logger->error(
                    'createTwinRedirects : échec création redirection "/@src" → "/@dst" : @msg',
                    [
                        '@src' => $sourceAlias,
                        '@dst' => $destinationAlias,
                        '@msg' => $e->getMessage(),
                    ]
                );
            }
        }

        return $created;
    }

}
