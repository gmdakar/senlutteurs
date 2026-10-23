<?php

namespace Drupal\confrontations_recentes\Plugin\QueueWorker;

use Drupal\shared_lib\Plugin\QueueWorker\BaseCombatQueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\shared_lib\Tools\SomeTools;
use Drupal\Core\Queue\SuspendQueueException;

/**
 * Traite un par un les groupes de combats suspectés de doublons mis en
 * queue par ConfrontationDeduplicationService::queueAmbiguousNearDateCombats().
 *
 * Pour chaque groupe (même lutteur pivot, adversaires dont le nom partage
 * les 3 mêmes premiers caractères normalisés, dates à 7 jours d'écart max
 * — ex. "Arsa" / "Arsa 2" / "Arsenal 2"), on interroge l'IA via le service
 * centralisé sl_live_search (module request_ai) avec un prompt configurable,
 * et on applique son verdict avec des garde-fous stricts : aucune
 * suppression n'est appliquée si la réponse IA est incomplète, invalide, ou
 * propose de tout supprimer.
 *
 * DOUBLE VALIDATION CROISÉE avant toute suppression réelle : si le premier
 * verdict (moteur par défaut, généralement Gemini) propose une ou plusieurs
 * suppressions, le MÊME prompt (prompt_ai_doublons_combats) est rejoué EN
 * AVEUGLE sur Grok (aucune info sur le premier verdict ne lui est
 * communiquée). Un combat n'est réellement supprimé que si les deux
 * moteurs sont d'accord (delete=1 des deux côtés sur le même
 * field_id_text) ; sinon la suppression est annulée et le combat est
 * conservé. Chaque lot traité produit un log de bilan avec NID + titre des
 * combats conservés et supprimés.
 *
 * @QueueWorker(
 *   id = "confrontations_doublons_ai_queue",
 *   title = @Translation("Résolution IA des doublons de combats ambigus"),
 *   cron = {"time" = 300}
 * )
 */
class ConfrontationsDoublonsAiQueue extends BaseCombatQueueWorker implements \Drupal\Core\Plugin\ContainerFactoryPluginInterface
{

    /**
     * Nombre max de tentatives pour un appel IA (sl_live_search) — que ce
     * soit le prompt principal (moteur par défaut, ex. Gemini) ou la
     * double validation Grok : 1 appel initial + 1 nouvelle tentative
     * après pause en cas d'échec technique (timeout, panne réseau/API,
     * réponse vide ou invalide).
     */
    protected const AI_CALL_MAX_ATTEMPTS = 2;

    /**
     * Pause (en secondes) avant de rejouer un appel IA après un échec
     * technique du premier essai.
     */
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
     *   ['pivot_nid', 'pivot_name', 'ecurie_pivot', 'nb_combats_pivot',
     *    'ecuries_adversaires' => ['Nom adversaire' => 'Écurie', ...],
     *    'nb_combats_adversaires' => ['Nom adversaire' => (int) nb, ...],
     *    'videos_combat' => ['field_id_text' => ['url', ...], ...],
     *    'date', 'combats' => [
     *      ['nid', 'field_id_text', 'lutteur1', 'lutteur2', 'date', 'vainqueur'], ...
     *   ]]
     */
    public function processItem($data)
    {
        $techIds = array_map('strval', array_column($data['combats'] ?? [], 'field_id_text'));

        $this->doProcessItem($data);

        // Atteint uniquement si doProcessItem() est allé jusqu'au bout sans
        // lever d'exception (une SuspendQueueException, levée par
        // callLiveSearchWithRetry() en cas d'échec technique IA, se
        // propage directement hors de processItem() sans jamais atteindre
        // cette ligne). Le traitement du groupe est donc terminé — verdict
        // appliqué ou suppression rejetée par un garde-fou — et l'item va
        // être retiré de la queue par Drupal : on libère le marqueur
        // "en attente" posé par queueAmbiguousNearDateCombats() lors de la
        // mise en queue, afin qu'un futur passage puisse à nouveau
        // détecter/re-proposer ce groupe si besoin (ex. suppression
        // partielle ayant modifié sa composition).
        SomeTools::clearDoublonGroupPending($techIds);
    }

    /**
     * Corps du traitement d'un item, séparé de processItem() afin que ce
     * dernier puisse libérer le marqueur "en attente" (voir plus haut)
     * uniquement lorsque le traitement va bien jusqu'au bout.
     *
     * @param array $data Voir le docblock de processItem().
     */
    protected function doProcessItem($data)
    {
        if (empty($data['combats']) || count($data['combats']) < 2) {
            return;
        }

        // Debug : si une liste de lutteurs est configurée, on ignore
        // silencieusement tout item dont le pivot n'est pas dans la liste
        // (pas de log de warning pour ne pas polluer pendant une session de
        // debug ciblée). Filet de sécurité complémentaire au filtre déjà
        // appliqué en amont dans queueAmbiguousNearDateCombats() : couvre
        // aussi les items déjà en queue avant l'activation du mode debug.
        $debugPivotIds = $this->configFactory
            ->get('confrontations_recentes.settings')
            ->get('prompt_ai_doublons_combats_debug_pivot_athlete') ?? [];
        // Rétrocompatibilité : ancienne config stockait un seul NID (int).
        if (!is_array($debugPivotIds)) {
            $debugPivotIds = $debugPivotIds ? [(int) $debugPivotIds] : [];
        }
        $debugPivotIds = array_values(array_filter(array_map('intval', $debugPivotIds)));
        if (!empty($debugPivotIds) && !in_array((int) ($data['pivot_nid'] ?? 0), $debugPivotIds, true)) {
            return;
        }

        $storage = $this->entityTypeManager->getStorage('node');

        // Rechargement défensif : le groupe a pu être partiellement modifié
        // (autre commande, cron, action manuelle) entre la mise en queue et
        // le traitement de cet item. On vérifie ici le statut publié et pas
        // seulement l'existence du noeud : un combat "supprimé" par un
        // passage précédent du pipeline est en réalité dépublié (pas
        // supprimé en base), donc storage->load() continuerait sinon à le
        // compter comme existant et referait tourner l'IA sur un groupe
        // déjà tranché (cf. queueAmbiguousNearDateCombats() qui filtre
        // désormais aussi sur status=1 en amont — ce contrôle ici reste un
        // filet de sécurité pour les items déjà en queue avant ce filtre).
        $stillExisting = 0;
        foreach ($data['combats'] as $c) {
            $node = $storage->load($c['nid']);
            if ($node && $node->isPublished()) {
                $stillExisting++;
            }
        }
        if ($stillExisting < 2) {
            $this->logger->info('Groupe IA ignoré (plus assez de combats existants) - @pivot le @date.', [
                '@pivot' => $data['pivot_name'] ?? '?',
                '@date'  => $data['date'] ?? '?',
            ]);
            return;
        }

        $prompt = $this->buildPrompt($data);
        if (trim($prompt) === '') {
            $this->logger->debug('ConfrontationsDoublonsAiQueue : prompt_ai_doublons_combats non configuré, groupe ignoré.');
            return;
        }

        $this->logger->notice('Prompt doublons IA envoyé (@pivot le @date) : @prompt', [
            '@pivot'  => $data['pivot_name'] ?? '?',
            '@date'   => $data['date'] ?? '?',
            '@prompt' => $prompt,
        ]);

        // $data['engine'] / $data['force_engine'] : injectés par
        // ConfrontationsDoublonsCommands::drainAiQueue() lorsque l'option
        // CLI --enginef a été passée (voir drush cdbl --enginef=grok),
        // afin de forcer le moteur du prompt principal pour ce run.
        $response = $this->callLiveSearchWithRetry(
            $prompt,
            $data,
            'principal (moteur par défaut)',
            $data['engine'] ?? null,
            !empty($data['force_engine'])
        );

        $verdicts = SomeTools::decodeJson($response['content'], __METHOD__);

        if (!is_array($verdicts) || empty($verdicts)) {
            $this->logger->warning('ConfrontationsDoublonsAiQueue : réponse IA invalide/vide (@pivot le @date). Contenu brut : @raw. Lot : @lot', [
                '@pivot' => $data['pivot_name'] ?? '?',
                '@date'  => $data['date'] ?? '?',
                '@raw'   => $response['content'],
                '@lot'   => $this->formatCombatList($data['combats'], $storage),
            ]);
            return;
        }

        $this->applyVerdicts($data, $verdicts, $storage);
    }

    /**
     * Appelle sl_live_search avec rattrapage automatique en cas d'échec
     * technique (timeout, panne réseau/API, réponse vide) : 1 appel
     * initial puis, en cas d'échec, 1 seule nouvelle tentative après une
     * pause de self::AI_CALL_RETRY_DELAY_SECONDS. Utilisé aussi bien pour
     * le prompt principal (moteur par défaut) que pour la double
     * validation Grok.
     *
     * @param string $prompt
     * @param array  $groupData
     *   Utilisé uniquement pour enrichir les logs (@pivot, @date).
     * @param string $context
     *   Libellé utilisé dans les logs/l'exception pour identifier l'appel
     *   (ex. "principal (moteur par défaut)" ou "confirmation Grok
     *   (doublons)").
     * @param string|null $engine
     *   Moteur à forcer (ex. "grok"), ou null pour laisser le moteur par
     *   défaut configuré dans request_ai.settings.
     * @param bool $forceEngine
     *   À true pour imposer réellement $engine (voir
     *   LiveSearchService::liveSearch()).
     *
     * @return array La réponse liveSearch (['success' => true, 'content' => ...]).
     *
     * @throws \Drupal\Core\Queue\SuspendQueueException
     *   Si l'appel échoue techniquement sur TOUTES les tentatives. Il ne
     *   s'agit alors PAS d'un désaccord entre IA ou d'un verdict "tout
     *   conserver", mais d'une panne technique : l'appelant ne doit donc
     *   ni appliquer/annuler de suppression sur cette base, ni marquer le
     *   groupe "vérifié" en BDD, ni libérer/supprimer l'item de la queue —
     *   il sera automatiquement retraité au prochain passage de cron (ou
     *   au prochain "drush cdbl --task=combats"), cette exception se
     *   propageant volontairement sans être interceptée.
     */
    protected function callLiveSearchWithRetry(string $prompt, array $groupData, string $context, ?string $engine = null, bool $forceEngine = false): array
    {
        $lastError = 'inconnue';

        for ($attempt = 1; $attempt <= self::AI_CALL_MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                $this->logger->warning('Appel IA (@context) : nouvelle tentative @attempt/@max après une pause de @delay s (@pivot le @date). Erreur précédente : @err', [
                    '@context' => $context,
                    '@attempt' => $attempt,
                    '@max'     => self::AI_CALL_MAX_ATTEMPTS,
                    '@delay'   => self::AI_CALL_RETRY_DELAY_SECONDS,
                    '@pivot'   => $groupData['pivot_name'] ?? '?',
                    '@date'    => $groupData['date'] ?? '?',
                    '@err'     => $lastError,
                ]);
                sleep(self::AI_CALL_RETRY_DELAY_SECONDS);
            }

            /** @var \Drupal\sl_live_search\Service\LiveSearchService $liveSearch */
            $liveSearch = \Drupal::service('sl_live_search.live_search_service');
            $response = ($engine !== null)
                ? $liveSearch->liveSearch($prompt, $engine, 'normal', $forceEngine)
                : $liveSearch->liveSearch($prompt);

            if (!empty($response['success']) && !empty($response['content'])) {
                if ($attempt > 1) {
                    // Log de CONFIRMATION dédié : prouve que le mécanisme
                    // de rattrapage fonctionne réellement en prod (à
                    // rechercher dans les logs pour valider le fix) — le
                    // 1er appel avait échoué techniquement, le 2e (après
                    // pause) a réussi, donc AUCUNE suppression n'a été
                    // annulée à tort ni aucun groupe verrouillé en BDD.
                    $this->logger->notice('✅ Appel IA (@context) RÉUSSI après rattrapage (tentative @attempt/@max) - @pivot le @date. Le premier essai avait échoué (@err) mais le second a abouti : traitement normal repris, aucun verrouillage BDD à tort.', [
                        '@context' => $context,
                        '@attempt' => $attempt,
                        '@max'     => self::AI_CALL_MAX_ATTEMPTS,
                        '@pivot'   => $groupData['pivot_name'] ?? '?',
                        '@date'    => $groupData['date'] ?? '?',
                        '@err'     => $lastError,
                    ]);
                }
                return $response;
            }

            $lastError = $response['error'] ?? 'inconnue';
            $this->logger->warning('Appel IA (@context) échec/vide (tentative @attempt/@max) - @pivot le @date. Erreur : @err', [
                '@context' => $context,
                '@attempt' => $attempt,
                '@max'     => self::AI_CALL_MAX_ATTEMPTS,
                '@pivot'   => $groupData['pivot_name'] ?? '?',
                '@date'    => $groupData['date'] ?? '?',
                '@err'     => $lastError,
            ]);
        }

        // Échec technique persistant après rattrapage : le plus souvent
        // quota épuisé, clé API absente, panne réseau ou timeout. Inutile
        // de continuer à vider le reste de la queue pour ne produire que
        // le même échec sur chaque item : on suspend le traitement de
        // CETTE queue pour le passage de cron en cours — l'item reste en
        // queue et sera retraité automatiquement au prochain cron, une
        // fois le problème résolu.
        $this->logger->warning('Appel IA (@context) en échec après @max tentative(s) (@pivot le @date) - traitement suspendu, item retraité au prochain cron. Dernière erreur : @err', [
            '@context' => $context,
            '@max'     => self::AI_CALL_MAX_ATTEMPTS,
            '@pivot'   => $groupData['pivot_name'] ?? '?',
            '@date'    => $groupData['date'] ?? '?',
            '@err'     => $lastError,
        ]);

        throw new SuspendQueueException(
            'Appel IA (' . $context . ') en échec après ' . self::AI_CALL_MAX_ATTEMPTS . ' tentative(s) : ' . $lastError
        );
    }

    /**
     * Applique les verdicts IA avec garde-fous stricts : on ne supprime
     * JAMAIS si la réponse est incohérente (moins de verdicts que de
     * combats fournis, field_id_text inconnu, ou tous marqués delete=1).
     */
    protected function applyVerdicts(array $groupData, array $verdicts, $storage): void
    {
        // Indexation des verdicts par field_id_text (seul identifiant fiable
        // fourni à l'IA — les noms peuvent être reformulés dans sa réponse).
        $byTechId = [];
        foreach ($verdicts as $v) {
            if (!empty($v['field_id_text'])) {
                $byTechId[(string) $v['field_id_text']] = $v;
            }
        }

        // Garde-fou 1 : il faut un verdict pour CHAQUE combat du groupe.
        if (count($byTechId) < count($groupData['combats'])) {
            $this->logger->warning('ConfrontationsDoublonsAiQueue : réponse IA incomplète (@got/@expected verdicts) - @pivot le @date, AUCUNE suppression appliquée. Lot : @lot', [
                '@got'      => count($byTechId),
                '@expected' => count($groupData['combats']),
                '@pivot'    => $groupData['pivot_name'] ?? '?',
                '@date'     => $groupData['date'] ?? '?',
                '@lot'      => $this->formatCombatList($groupData['combats'], $storage),
            ]);
            return;
        }

        // Garde-fou 2 : au moins un combat conservé (delete=0).
        $keepCount = 0;
        foreach ($groupData['combats'] as $c) {
            $v = $byTechId[$c['field_id_text']] ?? null;
            if ($v !== null && empty($v['delete'])) {
                $keepCount++;
            }
        }
        if ($keepCount < 1) {
            $this->logger->warning('ConfrontationsDoublonsAiQueue : IA propose de tout supprimer - @pivot le @date, AUCUNE suppression appliquée (garde-fou). Lot : @lot', [
                '@pivot' => $groupData['pivot_name'] ?? '?',
                '@date'  => $groupData['date'] ?? '?',
                '@lot'   => $this->formatCombatList($groupData['combats'], $storage),
            ]);
            return;
        }

        // Double validation OBLIGATOIRE avant toute suppression réelle :
        // opération sensible et irréversible, donc on rejoue EXACTEMENT le
        // même prompt (prompt_ai_doublons_combats) sur un second moteur
        // (Grok), EN AVEUGLE — sans lui communiquer le verdict ni la
        // raison du premier appel — pour obtenir un second avis vraiment
        // indépendant plutôt qu'une confirmation biaisée par ancrage.
        // Un seul appel Grok pour tout le groupe (pas un par combat).
        // Un combat n'est réellement supprimé que si les DEUX moteurs sont
        // d'accord (delete=1 chez Gemini ET chez Grok sur le même
        // field_id_text). En cas de désaccord, d'échec technique, ou
        // d'absence de verdict Grok sur ce combat précis : suppression
        // ANNULÉE par précaution, le combat repasse "conservé".
        $hasPendingDeletions = false;
        foreach ($byTechId as $v) {
            if (!empty($v['delete'])) {
                $hasPendingDeletions = true;
                break;
            }
        }

        // Déclaré ici (portée englobante) car réutilisé après le bloc de
        // double validation pour décider si le groupe peut être verrouillé
        // "vérifié, tout conservé" en BDD (voir markDoublonGroupVerifiedKept
        // plus bas) : reste à false si aucune double validation n'a été
        // nécessaire (aucune suppression proposée par le 1er moteur).
        $hasIncompleteGrokVerdict = false;

        if ($hasPendingDeletions) {
            $grokByTechId = $this->getGrokVerdicts($groupData);

            foreach ($groupData['combats'] as $c) {
                $v = $byTechId[$c['field_id_text']] ?? null;
                if ($v === null || empty($v['delete'])) {
                    continue;
                }

                $grokVerdict = $grokByTechId[$c['field_id_text']] ?? null;
                $grokAgrees  = $grokVerdict !== null && !empty($grokVerdict['delete']);

                if (!$grokAgrees) {
                    $byTechId[$c['field_id_text']]['delete'] = 0;

                    if ($grokVerdict !== null) {
                        // Cas spécifique : Grok a bien répondu sur ce combat,
                        // mais son verdict est explicitement en désaccord
                        // (delete=0 côté Grok alors que Gemini disait
                        // delete=1). C'est le cas de désaccord "pur", à
                        // distinguer d'une simple absence de réponse.
                        $byTechId[$c['field_id_text']]['raison'] = trim(
                            ($v['raison'] ?? ($v['commentaire'] ?? '')) . ' [Suppression ANNULÉE — DÉSACCORD Grok]'
                        );

                        $this->logger->warning('livesearch DÉSACCORD IA (Gemini=delete, Grok=conserve) - Suppression ANNULÉE : NID @nid [@id] "@l1 vs @l2" le @date. Raison Gemini (delete) : @reason_gemini | Raison Grok (conserve) : @reason_grok', [
                            '@nid'           => $c['nid'],
                            '@id'            => $c['field_id_text'],
                            '@l1'            => $c['lutteur1'] ?? '?',
                            '@l2'            => $c['lutteur2'] ?? '?',
                            '@date'          => $c['date'] ?? '?',
                            '@reason_gemini' => $v['raison'] ?? ($v['commentaire'] ?? ''),
                            '@reason_grok'   => $grokVerdict['raison'] ?? ($grokVerdict['commentaire'] ?? ''),
                        ]);
                    } else {
                        // Cas distinct : pas de désaccord à proprement parler,
                        // Grok n'a simplement rien dit sur ce field_id_text
                        // précis (réponse incomplète, échec technique, JSON
                        // invalide...). Prudence par défaut : on annule aussi,
                        // mais ce n'est PAS la même situation que ci-dessus —
                        // ce n'est pas un verdict "conserver" de Grok, donc le
                        // groupe ne doit PAS être verrouillé en BDD (voir la
                        // condition sur markDoublonGroupVerifiedKept()
                        // plus bas).
                        $hasIncompleteGrokVerdict = true;

                        $byTechId[$c['field_id_text']]['raison'] = trim(
                            ($v['raison'] ?? ($v['commentaire'] ?? '')) . ' [Suppression ANNULÉE — aucun verdict Grok reçu]'
                        );

                        $this->logger->warning('livesearch AUCUN VERDICT GROK reçu pour ce combat - Suppression ANNULÉE par précaution (groupe NON verrouillé, sera resoumis au prochain passage) : NID @nid [@id] "@l1 vs @l2" le @date. Raison Gemini (delete) : @reason_gemini', [
                            '@nid'           => $c['nid'],
                            '@id'            => $c['field_id_text'],
                            '@l1'            => $c['lutteur1'] ?? '?',
                            '@l2'            => $c['lutteur2'] ?? '?',
                            '@date'          => $c['date'] ?? '?',
                            '@reason_gemini' => $v['raison'] ?? ($v['commentaire'] ?? ''),
                        ]);
                    }
                } else {
                    $this->logger->info('livesearch SUPPRESSION CONFIRMÉE par Grok (accord des 2 moteurs) : NID @nid [@id] - @l1 vs @l2 le @date. Raison Gemini : @reason_gemini | Raison Grok : @reason_grok', [
                        '@nid'           => $c['nid'],
                        '@id'            => $c['field_id_text'],
                        '@l1'            => $c['lutteur1'] ?? '?',
                        '@l2'            => $c['lutteur2'] ?? '?',
                        '@date'          => $c['date'] ?? '?',
                        '@reason_gemini' => $v['raison'] ?? ($v['commentaire'] ?? ''),
                        '@reason_grok'   => $grokVerdict['raison'] ?? ($grokVerdict['commentaire'] ?? ''),
                    ]);
                }
            }
        }

        // Application des suppressions validées + collecte des survivants.
        // Collecte également les corrections de date explicites (CRITÈRE C
        // du prompt : "update_date" non vide/valide = combat réel mais date
        // à rectifier en base). Ces nids sont exclus du garde-fou n°3
        // (date la plus ancienne du groupe) plus bas : une correction de
        // date explicite et justifiée par l'IA prime sur cette heuristique
        // programmatique, qui suppose à tort que tous les combats du
        // groupe représentent le même évènement physique.
        $keptNids = [];
        $deletedSummary = [];
        $keptSummary = [];
        $explicitlyDatedNids = [];
        foreach ($groupData['combats'] as $c) {
            $v = $byTechId[$c['field_id_text']] ?? null;

            if ($v !== null && !empty($v['delete'])) {
                $node = $storage->load($c['nid']);
                if ($node) {
                    $nodeTitle = $node->getTitle();

                    $this->logger->info('SUPPRESSION (verdict IA) : NID @nid "@title" [@id] - @l1 vs @l2 le @date. Raison IA : @reason', [
                        '@nid'    => $c['nid'],
                        '@title'  => $nodeTitle,
                        '@id'     => $c['field_id_text'],
                        '@l1'     => $c['lutteur1'] ?? '?',
                        '@l2'     => $c['lutteur2'] ?? '?',
                        '@date'   => $groupData['date'] ?? '?',
                        '@reason' => $v['raison'] ?? ($v['commentaire'] ?? ''),
                    ]);

                    $deletedSummary[] = "NID {$c['nid']} \"{$nodeTitle}\"";

                    // $node->delete();
                    $node->setUnpublished();
                    $node->setNewRevision(FALSE);
                    $node->save();
                    $storage->resetCache([$c['nid']]);
                }
                continue;
            }

            $keptNids[] = $c['nid'];
            $keepNode = $storage->load($c['nid']);
            if ($keepNode) {
                $keptSummary[] = "NID {$c['nid']} \"{$keepNode->getTitle()}\"";

                // CRITÈRE C — combat réel conservé mais date à rectifier :
                // l'IA renseigne "update_date" (format AAAA-MM-JJ) quand
                // elle a retrouvé la date exacte du combat (P.E.R.L.,
                // presse, vidéo) alors que la date en base est décalée.
                $updateDate = trim((string) ($v['update_date'] ?? ''));
                if ($updateDate !== '' && $this->isValidYmdDate($updateDate)) {
                    if ($keepNode->hasField('field_confrontation_date')) {
                        $currentDate = $keepNode->get('field_confrontation_date')->value;

                        if ($currentDate !== $updateDate) {
                            $keepNode->set('field_confrontation_date', $updateDate);
                            $keepNode->setNewRevision(FALSE);
                            $keepNode->save();
                            $storage->resetCache([$c['nid']]);

                            $this->logger->info('DATE RECTIFIÉE (CRITÈRE C, verdict IA) : NID @nid "@title" [@id] - @old → @new. Raison IA : @reason', [
                                '@nid'    => $c['nid'],
                                '@title'  => $keepNode->getTitle(),
                                '@id'     => $c['field_id_text'],
                                '@old'    => $currentDate,
                                '@new'    => $updateDate,
                                '@reason' => $v['raison'] ?? ($v['commentaire'] ?? ''),
                            ]);
                        }

                        // Exclu du garde-fou n°3 : cette date vient d'être
                        // explicitement validée/rectifiée par l'IA (source
                        // P.E.R.L./presse/vidéo), on ne doit pas l'écraser
                        // ensuite avec la date la plus ancienne du groupe.
                        $explicitlyDatedNids[] = $c['nid'];
                    } else {
                        $this->logger->warning('update_date fourni par l\'IA mais field_confrontation_date absent du node : NID @nid [@id] - update_date=@ud ignoré.', [
                            '@nid' => $c['nid'],
                            '@id'  => $c['field_id_text'],
                            '@ud'  => $updateDate,
                        ]);
                    }
                } elseif ($updateDate !== '') {
                    $this->logger->warning('update_date fourni par l\'IA mais invalide/mal formaté (attendu AAAA-MM-JJ) : NID @nid [@id] - valeur reçue : "@ud". Ignoré, date inchangée.', [
                        '@nid' => $c['nid'],
                        '@id'  => $c['field_id_text'],
                        '@ud'  => $updateDate,
                    ]);
                }
            }
        }

        // Garde-fou 3 (a posteriori, indépendant de ce que l'IA a répondu
        // dans son propre champ "date") : quel que soit le nœud conservé,
        // il doit porter la date la PLUS ANCIENNE parmi tous les combats
        // du groupe (doublons supprimés inclus). On ne fait jamais confiance
        // à l'IA pour ce point précis, on l'impose programmatiquement —
        // SAUF pour les nids déjà rectifiés explicitement via "update_date"
        // (CRITÈRE C) juste au-dessus, où l'on fait au contraire confiance
        // à la date fournie par l'IA (recherchée et justifiée), plutôt qu'à
        // cette heuristique qui suppose à tort que tous les combats du lot
        // représentent le même évènement physique.
        $earliestDate = null;
        foreach ($groupData['combats'] as $c) {
            if (empty($c['date'])) {
                continue;
            }
            if ($earliestDate === null || strtotime($c['date']) < strtotime($earliestDate)) {
                $earliestDate = $c['date'];
            }
        }

        if ($earliestDate !== null) {
            foreach ($keptNids as $nid) {
                if (in_array($nid, $explicitlyDatedNids, true)) {
                    continue;
                }

                $node = $storage->load($nid);
                if (!$node || !$node->hasField('field_confrontation_date')) {
                    continue;
                }

                $currentDate = $node->get('field_confrontation_date')->value;
                if ($currentDate === $earliestDate) {
                    continue;
                }

                $node->set('field_confrontation_date', $earliestDate);
                $node->setNewRevision(FALSE);
                $node->save();

                $this->logger->info('DATE CORRIGÉE (a posteriori) : NID @nid - @old → @new (date la plus ancienne du groupe conservée).', [
                    '@nid' => $nid,
                    '@old' => $currentDate,
                    '@new' => $earliestDate,
                ]);
            }
        }

        // Log de synthèse du lot : ce qui a été considéré comme doublon et
        // supprimé, et le(s) combat(s) pivot finalement conservé(s), avec
        // NID + titre à chaque fois.
        $this->logger->notice('BILAN LOT (@pivot, dates du groupe : @dates) — Conservé(s) : @kept | Supprimé(s) : @deleted', [
            '@pivot'   => $groupData['pivot_name'] ?? '?',
            '@dates'   => implode(', ', array_unique(array_column($groupData['combats'], 'date'))),
            '@kept'    => !empty($keptSummary) ? implode(' ; ', $keptSummary) : '(aucun)',
            '@deleted' => !empty($deletedSummary) ? implode(' ; ', $deletedSummary) : '(aucun)',
        ]);

        // Si l'IA a tranché "tout conserver" (aucune suppression dans ce
        // lot), on mémorise définitivement ce N-uplet de combats (clé
        // dérivée de leurs field_id_text triés) afin de ne plus jamais le
        // resoumettre à l'IA lors des prochains "drush cdbl --task=combats".
        //
        // IMPORTANT : on ne verrouille PAS si ce "tout conservé" résulte
        // uniquement d'une ABSENCE de verdict Grok sur un ou plusieurs
        // combats (réponse Grok globalement valide mais incomplète pour cet
        // item précis) — ce n'est pas une décision éditoriale des deux IA,
        // juste une précaution technique ponctuelle. Verrouiller ici
        // reproduirait exactement le bug de verrouillage à tort déjà corrigé
        // pour l'échec technique total de Grok : le groupe reste donc
        // "ouvert" et sera naturellement resoumis à l'IA au prochain
        // passage (queueAmbiguousNearDateCombats() le redétectera tant que
        // isDoublonGroupVerifiedKept() renvoie false).
        if (empty($deletedSummary) && !$hasIncompleteGrokVerdict) {
            SomeTools::markDoublonGroupVerifiedKept(array_column($groupData['combats'], 'field_id_text'));
        } elseif (empty($deletedSummary) && $hasIncompleteGrokVerdict) {
            $this->logger->notice('Groupe NON verrouillé en BDD (@pivot le @date) : suppression annulée par précaution faute de verdict Grok complet, pas par décision éditoriale — sera resoumis à l\'IA au prochain passage.', [
                '@pivot' => $groupData['pivot_name'] ?? '?',
                '@date'  => $groupData['date'] ?? '?',
            ]);
        }
    }

    /**
     * Valide strictement qu'une chaîne est une date réelle au format
     * AAAA-MM-JJ (ex. "2024-12-23") — rejette les dates syntaxiquement
     * plausibles mais calendairement invalides (ex. "2024-02-30"), les
     * formats différents (ex. "23/12/2024"), et les chaînes vides ou
     * farfelues. Utilisé pour valider le champ "update_date" (CRITÈRE C)
     * fourni par l'IA avant toute écriture en base.
     */
    protected function isValidYmdDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year);
    }

    /**
     * Rejoue le prompt de résolution de doublons (prompt_ai_doublons_combats)
     * EN AVEUGLE sur le moteur Grok, forcé indépendamment du moteur
     * configuré par défaut dans request_ai.settings (généralement Gemini).
     * Aucune information sur le premier verdict n'est communiquée à Grok :
     * c'est un second avis vraiment indépendant, pas une simple
     * confirmation biaisée par ancrage.
     *
     * @return array Verdicts Grok indexés par field_id_text.
     *
     * @throws \Drupal\Core\Queue\SuspendQueueException
     *   Propagée par callLiveSearchWithRetry() si l'appel Grok échoue
     *   techniquement sur toutes les tentatives — voir sa documentation :
     *   aucune suppression n'est alors appliquée/annulée, aucun
     *   marquage "vérifié" en BDD, et l'item n'est pas libéré/supprimé de
     *   la queue (retraité au prochain cron).
     */
    protected function getGrokVerdicts(array $groupData): array
    {
        $prompt = $this->buildPrompt($groupData);
        if (trim($prompt) === '') {
            // Pas de prompt configuré : impossible de solliciter Grok. Ce
            // cas ne devrait jamais survenir ici, buildPrompt() ayant déjà
            // été validé non vide plus haut dans processItem().
            return [];
        }

        $this->logger->notice('Confirmation doublons via Grok envoyée (même prompt, en aveugle) : @pivot le @date.', [
            '@pivot' => $groupData['pivot_name'] ?? '?',
            '@date'  => $groupData['date'] ?? '?',
        ]);

        // force_engine = true : impose Grok quel que soit le moteur par
        // défaut — la double validation n'a de sens que sur un moteur
        // différent de celui ayant produit le premier verdict.
        $response = $this->callLiveSearchWithRetry($prompt, $groupData, 'confirmation Grok (doublons)', 'grok', true);

        $grokVerdicts = SomeTools::decodeJson($response['content'], __METHOD__);
        if (!is_array($grokVerdicts) || empty($grokVerdicts)) {
            $this->logger->warning('Confirmation Grok réponse invalide/vide (@pivot le @date) : AUCUNE suppression du lot ne sera confirmée. Contenu brut : @raw', [
                '@pivot' => $groupData['pivot_name'] ?? '?',
                '@date'  => $groupData['date'] ?? '?',
                '@raw'   => $response['content'],
            ]);
            return [];
        }

        $byTechId = [];
        foreach ($grokVerdicts as $v) {
            if (!empty($v['field_id_text'])) {
                $byTechId[(string) $v['field_id_text']] = $v;
            }
        }

        return $byTechId;
    }

    /**
     * Construit le prompt à partir du template configurable
     * confrontations_recentes.settings:prompt_ai_doublons_combats.
     * Placeholders : @pivot, @ecurie_pivot, @nb_combats_pivot,
     * @ecuries_adversaires, @nb_combats_adversaires, @videos_combat, @date,
     * @combats_json, @suggestions.
     * - @ecuries_adversaires : JSON objet {"Nom adversaire": "Écurie", ...}
     *   (ex: {"Modou lo": "Parcelles Mbollo", "Balla gaye 2": "Xam Sa Cossan"}).
     * - @nb_combats_pivot : nombre de combats déjà enregistrés en base pour
     *   le lutteur pivot (entier).
     * - @nb_combats_adversaires : JSON objet {"Nom adversaire": nb, ...}
     *   (ex: {"Aladji Gaïndé": 14, "Elhadj Gaindé": 1}), utilisé par le
     *   prompt pour arbitrer le dilemme phonétique/orthographique entre deux
     *   graphies du même lutteur (voir
     *   ConfrontationDeduplicationService::countCombatsForAthlete()).
     * - @videos_combat : JSON objet {"field_id_text": ["url1", ...], ...}
     *   (une entrée par combat de @combats_json, tableau vide si aucune
     *   vidéo) — voir ConfrontationDeduplicationService::getValidVideoUrls()
     *   et le Principe cardinal 7 du prompt IA.
     * - @suggestions : synonymes / variantes du pivot issus de
     *   misc_divers.settings:remplacementsYtb (via SomeTools::getSynonyms /
     *   YoutubeSearchService::getSynonyms). Le nom du pivot lui-même est
     *   exclu.
     */
    protected function buildPrompt(array $data): string
    {
        $promptTemplate = $this->configFactory
            ->get('confrontations_recentes.settings')
            ->get('prompt_ai_doublons_combats') ?? '';

        if (trim($promptTemplate) === '') {
            return '';
        }

        $combatsJson = json_encode($data['combats'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $pivotName = $data['pivot_name'] ?? '';
        $ecuriePivot = !empty($data['ecurie_pivot']) ? $data['ecurie_pivot'] : 'inconnue';
        $nbCombatsPivot = (int) ($data['nb_combats_pivot'] ?? 0);
        $ecuriesAdversaires = $data['ecuries_adversaires'] ?? [];
        $ecuriesAdversairesJson = json_encode(
            $ecuriesAdversaires,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $nbCombatsAdversaires = $data['nb_combats_adversaires'] ?? [];
        $nbCombatsAdversairesJson = json_encode(
            $nbCombatsAdversaires,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $videosCombat = $data['videos_combat'] ?? [];
        $videosCombatJson = json_encode(
            $videosCombat,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $suggestions = [];
        if ($pivotName !== '') {
            $pivotLower = mb_strtolower(trim($pivotName));

            // 1) Synonymes officiels depuis remplacementsYtb (config form
            //    misc_divers.settings), via la fonction existante getSynonyms.
            //    getSynonyms renvoie aussi le nom officiel en minuscules :
            //    on l'exclut et on conserve le reste.
            $fromYtb = \Drupal\shared_lib\Tools\SomeTools::getSynonyms($pivotName);
            foreach ($fromYtb as $syn) {
                $syn = trim((string) $syn);
                if ($syn === '' || mb_strtolower($syn) === $pivotLower) {
                    continue;
                }
                $suggestions[mb_strtolower($syn)] = $syn;
            }

            $suggestions = array_values($suggestions);
        }
        $suggestionsJson = json_encode($suggestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return strtr($promptTemplate, [
            '@pivot'                  => $pivotName,
            '@ecurie_pivot'           => $ecuriePivot,
            '@nb_combats_pivot'       => (string) $nbCombatsPivot,
            '@ecuries_adversaires'    => $ecuriesAdversairesJson,
            '@nb_combats_adversaires' => $nbCombatsAdversairesJson,
            '@videos_combat'          => $videosCombatJson,
            '@date'                   => $data['date'] ?? '',
            '@combats_json'           => $combatsJson,
            '@suggestions'            => $suggestionsJson,
        ]);
    }

    /**
     * Formate la liste des combats d'un lot pour les logs (garde-fous en
     * échec notamment) : "NID @nid "@title" [@field_id_text] (@l1 vs @l2, @date)".
     */
    protected function formatCombatList(array $combats, $storage): string
    {
        $parts = [];
        foreach ($combats as $c) {
            $node  = $storage->load($c['nid']);
            $title = $node ? $node->getTitle() : '(introuvable)';
            $parts[] = sprintf(
                'NID %d "%s" [%s] (%s vs %s, %s)',
                $c['nid'],
                $title,
                $c['field_id_text'] ?? '?',
                $c['lutteur1'] ?? '?',
                $c['lutteur2'] ?? '?',
                $c['date'] ?? '?'
            );
        }
        return implode(' ; ', $parts);
    }

}
