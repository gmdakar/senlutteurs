<?php

namespace Drupal\gemini_nextcombats_cron\Service;

use Drupal\shared_lib\Service\BaseWrestlingService;
use Drupal\shared_lib\Tools\SomeTools;
use Drupal\node\Entity\Node;

/**
 * Service pour la gestion des prochains combats via LiveSearchService.
 */
class CombatService extends BaseWrestlingService
{
    /**
     * Récupère les combats depuis l'API via le service injecté dans le parent.
     */
    public function fetchCombats(): array
    {
        try {
            $response_content = null;

            try {
                $prompt = $this->getPrompt();

                $this->logger->notice('Prompt envoyé : @prompt', [
                    '@prompt' => $prompt,
                ]);

                if (stripos($prompt, '__ignore__') !== false) {
                    $response_content = $this->getDefaultResponse();
                    $response_content = str_replace('__debug__', '', $response_content);
                } else {
                    $prompt = str_replace('__debug__', '', $prompt);

                    $response = $this->liveSearchService->liveSearch($prompt);

                    $this->logger->debug('<pre>fetchCombats : @data</pre>', ['@data' => print_r($response, TRUE)]);

                    if (!empty($response['error'])) {
                        $this->logger->warning('Livesearch [fetchCombats] Interruption du process car error:'. $response['error']);
                        return [];
                    }

                    if ($response['success'] && !empty($response['content'])) {
                        $response_content = $response['content'];
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
            }

            $data = $this->decodeJson(trim($response_content ?? ''), __METHOD__);

            if (!empty($data) && (
                stripos(trim($this->getPrompt()), '__debug__') !== false ||
                (
                    stripos(trim($this->getPrompt()), '__ignore__') !== false &&
                    stripos(trim($this->getDefaultResponse()), '__debug__') !== false
                )
            )) {
                $this->logger->notice('Reponse: @data', ['@data' => print_r($data, TRUE)]);
            }

            // Normalisation : l'IA peut wrapper dans {"combats": [...]}
            if (!empty($data['combats']) && is_array($data['combats'])) {
                $data = $data['combats'];
            }

            if (!is_array($data)) {
                $this->logger->warning('[fetchCombats] Données invalides après decodeJson.');
                return [];
            }

            return $data;

        } catch (\Exception $e) {
            $this->logger->error('API error: @msg', ['@msg' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * À appeler AVANT la création d'un node "a_venir" (donc avant
     * getOrCreateAthlete), pour :
     *  1. Récupérer les 2 derniers combats connus de chacun des 2 lutteurs.
     *  2. Interroger l'IA (liveSearch) avec ce contexte + une liste de
     *     suggestions de variantes de noms proches déjà en base, afin de
     *     confirmer/corriger la bonne variante de chaque lutteur (ex :
     *     "Pape thiam" → "Pape thiam 2", "Alboury" → "Alboury (kay bakh)")
     *     avant insertion du combat à venir.
     *
     * Ne bloque JAMAIS l'insertion en cas d'échec technique (prompt non
     * configuré, IA indisponible, JSON invalide) : dans ce cas les noms
     * d'origine sont conservés tels quels ('ai_valid' => null).
     *
     * @return array{
     *   lutteur1: string,
     *   lutteur2: string,
     *   date_precise: string,
     *   ai_valid: bool|null,
     *   ai_corrected: bool,
     *   ai_commentaire: string,
     * }
     */
    public function confirmWrestlersBeforeInsert(string $wrestler1, string $wrestler2, string $datePrecise): array
    {
        $fallback = [
            'lutteur1'       => $wrestler1,
            'lutteur2'       => $wrestler2,
            'date_precise'   => $datePrecise,
            'ai_valid'       => null,
            'ai_corrected'   => false,
            'ai_commentaire' => '',
        ];

        try {
            $historique1  = SomeTools::getRecentConfrontationsJson($wrestler1, 2);
            $historique2  = SomeTools::getRecentConfrontationsJson($wrestler2, 2);
            $suggestions1 = SomeTools::findSimilarAthleteNames($wrestler1, 6);
            $suggestions2 = SomeTools::findSimilarAthleteNames($wrestler2, 6);

            $prompt = $this->getPromptConfirmWrestlers(
                $wrestler1,
                $wrestler2,
                $datePrecise,
                json_encode($historique1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($historique2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($suggestions1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($suggestions2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $prompt = str_replace('__debug__', '', $prompt);

            if (trim($prompt) === '') {
                $this->logger->debug('confirmWrestlersBeforeInsert : prompt_confirm_wrestlers non configuré, étape ignorée.');
                return $fallback;
            }

            $this->logger->notice('confirmWrestlersBeforeInsert Prompt envoyé : @prompt', ['@prompt' => $prompt]);

            $response = $this->liveSearchService->liveSearch($prompt);

            if (empty($response['success']) || empty($response['content'])) {
                $this->logger->warning('confirmWrestlersBeforeInsert : liveSearch échec/vide pour @l1 vs @l2.', [
                    '@l1' => $wrestler1,
                    '@l2' => $wrestler2,
                ]);
                return $fallback;
            }

            $data = $this->decodeJson($response['content'], __METHOD__);

            if (!is_array($data) || empty($data['lutteur1']) || empty($data['lutteur2'])) {
                $this->logger->warning('confirmWrestlersBeforeInsert : réponse IA invalide/incomplète pour @l1 vs @l2, noms conservés tels quels.', [
                    '@l1' => $wrestler1,
                    '@l2' => $wrestler2,
                ]);
                return $fallback;
            }

            $correctedL1 = trim((string) $data['lutteur1']);
            $correctedL2 = trim((string) $data['lutteur2']);

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
                'lutteur1'       => $correctedL1 !== '' ? $correctedL1 : $wrestler1,
                'lutteur2'       => $correctedL2 !== '' ? $correctedL2 : $wrestler2,
                'date_precise'   => !empty($data['date_precise']) ? $data['date_precise'] : $datePrecise,
                'ai_valid'       => array_key_exists('correspond_aux_profils', $data) ? (bool) $data['correspond_aux_profils'] : true,
                'ai_corrected'   => $corrected,
                'ai_commentaire' => (string) ($data['commentaire'] ?? ''),
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

    protected function getPromptConfirmWrestlers(
        string $wrestler1,
        string $wrestler2,
        string $datePrecise,
        string $historique1Json,
        string $historique2Json,
        string $suggestions1Json,
        string $suggestions2Json
    ): string {
        $promptTemplate = $this->configFactory->get('gemini_nextcombats_cron.settings')->get('prompt_confirm_wrestlers') ?? '';

        return strtr($promptTemplate, [
            '@wrestler1'    => $wrestler1,
            '@wrestler2'    => $wrestler2,
            '@date'         => $datePrecise,
            '@historique1'  => $historique1Json,
            '@historique2'  => $historique2Json,
            '@suggestions1' => $suggestions1Json,
            '@suggestions2' => $suggestions2Json,
        ]);
    }

    public function enqueueCombat($combat): void
    {
        $queue = \Drupal::queue('gemini_nextcombats_queue');
        $queue->createItem($combat);
    }

    protected function getPrompt(string $wrestlerName = ''): string
    {
        $prompt = $this->configFactory->get('gemini_nextcombats_cron.settings')->get('prompt');
        return $prompt ? strtr($prompt, ['@wrestler' => $wrestlerName]) : '';
    }

    protected function getDefaultResponse(): string
    {
        return $this->configFactory->get('gemini_nextcombats_cron.settings')->get('default_response') ?? '';
    }

    /**
     * Force update videos (face 2 face and combats/fights) for all next coming fights (not yet occurred).
     */
    public function updateVideosCombatsNext() {
        $nids  = $this->getNotExpiredFights();
        $queue = \Drupal::queue('update_videos_combats_next_queue');

        foreach ($nids as $nid) {
            $queue->createItem($nid);
        }

        return $nids;
    }
    /**
     * Arbitrage IA à l'insertion : un a_venir existe déjà le même jour avec
     * un protagoniste commun, mais l'adversaire n'est pas le même NID.
     * L'IA décide si on GARDE l'existant ou si on le REMPLACE par la
     * variante candidate (mauvais "premier arrivé" possible).
     *
     * Réutilise prompt_ai_doublons_avenir (gemini_nextcombats_cron.settings — /admin/config/gemini-nextcombats-cron).
     * En cas d'échec technique / prompt vide : retourne 'existing' (conservateur).
     *
     * @param array $existing
     *   nid, field_id_text, lutteur1, lutteur2, lutteur1_nid, lutteur2_nid, date, face2face?
     * @param array $candidate
     *   même structure (nid=0 pour le candidat non encore créé, field_id_text du candidat)
     *
     * @return array{choice: 'existing'|'candidate', raison: string, ai_ok: bool}
     */
    public function resolveAvenirVariantConflict(array $existing, array $candidate, string $pivotName = ''): array
    {
        $fallback = [
            'choice' => 'existing',
            'raison' => 'fallback (IA indisponible ou réponse invalide)',
            'ai_ok'  => false,
        ];

        try {
            $promptTemplate = \Drupal::config('gemini_nextcombats_cron.settings')
                ->get('prompt_ai_doublons_avenir') ?? '';
            if (trim($promptTemplate) === '') {
                $this->logger->debug('resolveAvenirVariantConflict : prompt_ai_doublons_avenir non configuré, conservation de l\'existant.');
                return $fallback;
            }

            // Contexte minimal pour les placeholders du prompt a_venir.
            $combats = [
                array_merge($existing, ['nid' => (int) ($existing['nid'] ?? 0)]),
                array_merge($candidate, ['nid' => 0]),
            ];

            $oppNames = [];
            foreach ([$existing, $candidate] as $c) {
                // Adversaire = celui qui n'est pas le pivot si pivot connu, sinon lutteur2.
                $oppNames[] = $c['lutteur2'] ?? '';
                $oppNames[] = $c['lutteur1'] ?? '';
            }
            $oppNames = array_values(array_unique(array_filter($oppNames)));

            $nbCombatsAdversaires = [];
            $historiqueAdversaires = [];
            $suggestionsAdversaires = [];
            foreach ($oppNames as $name) {
                if ($name === '' || $name === $pivotName) {
                    continue;
                }
                $nbCombatsAdversaires[$name] = 0;
                try {
                    $historiqueAdversaires[$name] = SomeTools::getRecentConfrontationsJson($name, 2);
                    $suggestionsAdversaires[$name] = SomeTools::findSimilarAthleteNames($name, 6);
                }
                catch (\Throwable $e) {
                    // non bloquant
                }
            }

            $prompt = strtr($promptTemplate, [
                '@pivot'                   => $pivotName !== '' ? $pivotName : ($existing['lutteur1'] ?? ''),
                '@ecurie_pivot'            => 'inconnue',
                '@nb_combats_pivot'        => '0',
                '@date'                    => $existing['date'] ?? ($candidate['date'] ?? ''),
                '@combats_json'            => json_encode($combats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                '@nb_combats_adversaires'  => json_encode($nbCombatsAdversaires, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                '@historique_adversaires'  => json_encode($historiqueAdversaires, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                '@suggestions_adversaires' => json_encode($suggestionsAdversaires, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $prompt = str_replace('__debug__', '', $prompt);

            $this->logger->notice('resolveAvenirVariantConflict Prompt : @prompt', ['@prompt' => $prompt]);

            $response = $this->liveSearchService->liveSearch($prompt);
            if (empty($response['success']) || empty($response['content'])) {
                $this->logger->warning('resolveAvenirVariantConflict : liveSearch échec/vide.');
                return $fallback;
            }

            $data = $this->decodeJson($response['content'], __METHOD__);
            $verdicts = null;
            if (is_array($data)) {
                if (isset($data['verdicts']) && is_array($data['verdicts'])) {
                    $verdicts = $data['verdicts'];
                }
                elseif (isset($data[0]) && is_array($data[0])) {
                    $verdicts = $data;
                }
            }
            if (!is_array($verdicts) || count($verdicts) < 1) {
                $this->logger->warning('resolveAvenirVariantConflict : verdicts invalides. Brut : @raw', [
                    '@raw' => $response['content'],
                ]);
                return $fallback;
            }

            $byTech = [];
            foreach ($verdicts as $v) {
                if (!empty($v['field_id_text'])) {
                    $byTech[(string) $v['field_id_text']] = $v;
                }
            }

            $exId = (string) ($existing['field_id_text'] ?? '');
            $caId = (string) ($candidate['field_id_text'] ?? '');
            $vEx = $byTech[$exId] ?? null;
            $vCa = $byTech[$caId] ?? null;

            // Interprétation : on préfère le candidat si l'IA met delete=1
            // sur l'existant ET delete=0 sur le candidat.
            $deleteExisting = $vEx !== null && !empty($vEx['delete']);
            $keepCandidate  = $vCa !== null && empty($vCa['delete']);
            // Si un seul verdict (ou IA n'a répondu que partiellement) :
            // delete=1 sur existant ⇒ candidate ; sinon existing.
            if ($deleteExisting && ($vCa === null || $keepCandidate)) {
                $raison = (string) ($vEx['raison'] ?? $vCa['raison'] ?? ($data['commentaire'] ?? 'IA préfère candidat'));
                $this->logger->notice('resolveAvenirVariantConflict : choix=candidate — @raison', ['@raison' => $raison]);
                return ['choice' => 'candidate', 'raison' => $raison, 'ai_ok' => true];
            }

            $raison = (string) ($vEx['raison'] ?? $vCa['raison'] ?? ($data['commentaire'] ?? 'IA conserve existant'));
            $this->logger->notice('resolveAvenirVariantConflict : choix=existing — @raison', ['@raison' => $raison]);
            return ['choice' => 'existing', 'raison' => $raison, 'ai_ok' => true];
        }
        catch (\Exception $e) {
            $this->logger->error('resolveAvenirVariantConflict erreur : @msg', ['@msg' => $e->getMessage()]);
            return $fallback;
        }
    }


}