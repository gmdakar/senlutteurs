<?php

namespace Drupal\gemini_wrestling_importer\Service;

use Drupal\node\Entity\Node;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\shared_lib\Service\BaseWrestlingService;
use Drupal\shared_lib\Tools\SomeTools;

/**
 * Gemini Wrestling import service.
 * Maintenant héritée de BaseWrestlingService.
 */
class GeminiBaseService extends BaseWrestlingService
{

  protected function correctNames(string $texte): string
  {
    return SomeTools::correctNames($texte);
  }

  /**
   * Récupère l'historique des combats d'un lutteur au format JSON.
   */
  public function getFightsJsonWrestler(string $wrestlerName): string
  {
    // 1. Recherche du NID du lutteur
    $athleteNids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'athlete')
      ->condition('title', $wrestlerName)
      ->condition('status', 1)
      ->accessCheck(false)
      ->range(0, 1)
      ->execute();

    if (empty($athleteNids)) {
      return '[]';
    }

    $athleteNid = reset($athleteNids);
    $storage    = $this->entityTypeManager->getStorage('node');

    $query = $storage->getQuery();

    $orGroup = $query->orConditionGroup()
      ->condition('field_athlete_1', $athleteNid)
      ->condition('field_athlete_2', $athleteNid);

    $allNids = $query->condition('type', 'confrontation')
      ->condition('status', 1)
      ->condition($orGroup)
      ->accessCheck(false)
      ->execute();

    if (empty($allNids)) {
      $this->logger->info('LiveSearch : Aucun combat trouvé pour l\'id @id', ['@id' => $athleteNid]);
      return '[]';
    }

    $this->logger->info('LiveSearch : getFightsJsonWrestler — @count nids trouvés.', ['@count' => count($allNids)]);

    /** @var \Drupal\node\Entity\Node[] $confrontations */
    $confrontations = $storage->loadMultiple($allNids);
    $result         = [];

    foreach ($confrontations as $node) {

      $athlete1 = $node->get('field_athlete_1')->entity;
      $athlete2 = $node->get('field_athlete_2')->entity;

      if (!$athlete1 || !$athlete2) {
        continue;
      }

      $isPivot    = ((int) $athlete1->id() === (int) $athleteNid);
      $adversaire = $isPivot ? $athlete2 : $athlete1;

      $advName   = $adversaire->label();
      $advEcurie = '';

      if ($adversaire->hasField('field_ecurie') && !$adversaire->get('field_ecurie')->isEmpty()) {
        $ecurieNode = $adversaire->get('field_ecurie')->entity;
        if ($ecurieNode) {
          $advEcurie = $ecurieNode->label();
        }
      }
      $advValue = $advEcurie ? $advName . ' / ' . $advEcurie : $advName;

      $verdict      = (string) ($node->get('field_vainqueur')->value ?? '');
      $cleanVerdict = SomeTools::cleanForMatch($verdict);
      $cleanSelf    = SomeTools::cleanForMatch($wrestlerName);
      $cleanAdv     = SomeTools::cleanForMatch($adversaire->label());

      if ($cleanVerdict === 'nul' || str_contains($cleanVerdict, 'match nul')) {
        $res = 'Nul';
      } elseif (str_contains($cleanVerdict, 'litige') || str_contains($cleanVerdict, 'sans verdict')) {
        $res = 'Litige (sans verdict)';
      } elseif (trim($verdict) === '?') {
        $res = '?';
      } elseif (stripos($cleanVerdict, $cleanSelf) !== false) {
        $res = 'piv';
      } elseif (stripos($cleanVerdict, $cleanAdv) !== false) {
        $res = $adversaire->label();
      } else {
        $res = $verdict;
      }

      $dateRaw = $node->get('field_confrontation_date')->value ?? '';
      try {
        $dte = (new \DateTime($dateRaw))->format('Y-m-d');
      } catch (\Exception $e) {
        $dte = $dateRaw;
      }

      $ls = (string) ((int) ($node->get('field_is_lutte_simple')->value ?? 0));
      $id = (string) ($node->get('field_id_text')->value ?? '');

      $result[] = [
        'id'  => $id,
        'dte' => $dte,
        'adv' => $advValue,
        'res' => $res,
        'ls'  => $ls,
      ];
    }

    usort($result, fn($a, $b) => strcmp($b['dte'], $a['dte']));

    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $this->logger->info('LiveSearch : getFightsJsonWrestler Lutteur "@name" — @count combat(s) trouvé(s). JSON: @json', [
      '@name'  => $wrestlerName,
      '@count' => count($result),
      '@json'  => $json,
    ]);

    return $json;
  }

  /**
   * Applique un diff JSON sur les confrontations d'un lutteur.
   */
  public function updateFightWrestler(string $wrestler, array $diffData): void
  {

    $storage  = $this->entityTypeManager->getStorage('node');

    $selfNids = $storage->getQuery()
      ->condition('type', 'athlete')
      ->condition('title', $wrestler)
      ->condition('status', 1)
      ->accessCheck(false)
      ->range(0, 1)
      ->execute();

    $selfNid  = !empty($selfNids) ? (int) reset($selfNids) : null;
    $selfNode = $selfNid ? $storage->load($selfNid) : null;

    if (!$selfNode) {
      $this->logger->error('[updateFightWrestler] Lutteur "@wrestler" introuvable en base.', [
        '@wrestler' => $wrestler,
      ]);
      return;
    }

    foreach ($diffData as $item) {

      $id = $item['id'] ?? null;
      if (!$id) {
        $this->logger->warning('[updateFightWrestler] Item sans "id", ignoré.', []);
        continue;
      }

      $nids = $storage->getQuery()
        ->condition('type', 'confrontation')
        ->condition('field_id_text', $id)
        ->condition('status', 1)
        ->accessCheck(false)
        ->range(0, 1)
        ->execute();

      if (empty($nids)) {
        $this->logger->warning('[updateFightWrestler] Confrontation id="@id" introuvable, ignorée.', [
          '@id' => $id,
        ]);
        continue;
      }

      $nid = (int) reset($nids);

      if (!empty($item['del']) && (string) $item['del'] === '1') {
        $node = $storage->load($nid);
        if ($node) {
          $node->delete();
          $this->logger->warning('Livesearch Suppression de node [updateFightWrestler] Confrontation supprimée : id=@id, nid=@nid.', [
            '@id'  => $id,
            '@nid' => $nid,
          ]);
        }
        continue;
      }

      if (empty($item['alter']) || !is_array($item['alter'])) {
        continue;
      }

      /** @var \Drupal\node\Entity\Node $node */
      $node  = $storage->load($nid);
      $alter = $item['alter'];

      if (!$node) {
        continue;
      }

      $athlete1Entity = $node->get('field_athlete_1')->entity;
      $isPivot        = ($athlete1Entity && (int) $athlete1Entity->id() === $selfNid);

      if (isset($alter['dte'])) {
        $node->set('field_confrontation_date', $alter['dte']);
      }

      if (isset($alter['ls'])) {
        $node->set('field_is_lutte_simple', (int) $alter['ls']);
      }

      $adversaireNode = null;
      if (isset($alter['adv'])) {
        $advParts  = explode('/', $alter['adv']);
        $advName   = $this->correctNames(trim($advParts[0]));
        $advEcurie = isset($advParts[1]) ? trim($advParts[1]) : null;

        $lock           = \Drupal::lock();
        $adversaireNode = SomeTools::getOrCreateAthleteLocked($lock, $advName, $advEcurie);

        if ($adversaireNode) {
          if ($isPivot) {
            $node->set('field_athlete_2', ['target_id' => $adversaireNode->id()]);
          } else {
            $node->set('field_athlete_1', ['target_id' => $adversaireNode->id()]);
          }
          $node->set('title', $this->t('@wrestler1 vs @wrestler2', [
            '@wrestler1' => $isPivot ? $selfNode->label() : $adversaireNode->label(),
            '@wrestler2' => $isPivot ? $adversaireNode->label() : $selfNode->label(),
          ]));
        } else {
          $this->logger->error('[updateFightWrestler] Impossible de résoudre l\'adversaire "@adv" pour id=@id.', [
            '@adv' => $alter['adv'],
            '@id'  => $id,
          ]);
        }
      }

      if (isset($alter['res'])) {
        $res = $alter['res'];

        if ($res === 'piv') {
          $verdictValue = $selfNode->label();
        } elseif ($res === 'Nul') {
          $verdictValue = (string) $this->t('Nul');
        } elseif ($res === 'Litige (sans verdict)') {
          $verdictValue = (string) $this->t('Litige (sans verdict)');
        } elseif ($res === '?') {
          $verdictValue = '?';
        } else {
          if ($adversaireNode) {
            $verdictValue = $adversaireNode->label();
          } else {
            $advNids = $storage->getQuery()
              ->condition('type', 'athlete')
              ->condition('title', $res)
              ->condition('status', 1)
              ->accessCheck(false)
              ->range(0, 1)
              ->execute();

            if (!empty($advNids)) {
              $resolvedAdv  = $storage->load(reset($advNids));
              $verdictValue = $resolvedAdv ? $resolvedAdv->label() : $res;
            } else {
              $verdictValue = $res;
            }
          }
        }

        $node->set('field_vainqueur', $verdictValue);
      }

      $node->save();

      $this->logger->info('[updateFightWrestler] Confrontation mise à jour : id=@id, nid=@nid. Champs modifiés : @fields', [
        '@id'     => $id,
        '@nid'    => $nid,
        '@fields' => implode(', ', array_keys($alter)),
      ]);
    }

    $this->logger->info('[updateFightWrestler] Traitement terminé pour "@wrestler" — @count item(s) traité(s).', [
      '@wrestler' => $wrestler,
      '@count'    => count($diffData),
    ]);
  }

  /**
   * Import des combats depuis les données Gemini.
   *
   * @param array  $fightsData   Tableau de combats (tous formats acceptés).
   * @param string $wrestlerName Nom du lutteur pivot, requis pour le mode diff.
   */
  public function importFights(array $fightsData, string $wrestlerName = ''): bool
  {
    $ret = false;

    if (isset($fightsData['fights']) && is_array($fightsData['fights'])) {
      $this->logger->error('[importFights] Reçu la structure wrapper complète au lieu du tableau de combats — extraction automatique. Vérifier l\'appelant (processItem ou importAllFightsAthleteLess).', []);
      $fightsData = $fightsData['fights'];
    }

    if (isset($fightsData[0]['combats_lutte'])) {
      $fightsData = $fightsData[0]['combats_lutte'];
    } elseif (isset($fightsData['combats_lutte'])) {
      $fightsData = $fightsData['combats_lutte'];
    } elseif (isset($fightsData['combats'])) {
      $fightsData = $fightsData['combats'];
    }

    if (empty($fightsData)) {
      $this->logger->info('[importFights] fightsData vide après normalisation, rien à importer.');
      return false;
    }

    if ($wrestlerName !== '') {
      SomeTools::getOrCreateAthlete(
        $wrestlerName,
        \Drupal::entityTypeManager(),
        \Drupal::logger('shared_lib'),
      );
    }

    $isDiff = isset($fightsData[0])
      && (isset($fightsData[0]['id']) || isset($fightsData[0]['del']) || isset($fightsData[0]['alter']) || isset($fightsData[0]['new']));

    if ($isDiff) {
      if ($wrestlerName === '') {
        $this->logger->error('[importFights] Mode diff détecté mais $wrestlerName vide — impossible de traiter. Vérifier l\'appelant.');
        return false;
      }

      $toUpdate = array_values(array_filter(
        $fightsData,
        fn($f) => isset($f['del']) || isset($f['alter'])
      ));
      $toAdd = array_values(array_filter(
        $fightsData,
        fn($f) => !empty($f['new'])
      ));

      if (!empty($toUpdate)) {
        $this->updateFightWrestler($wrestlerName, $toUpdate);
      }

      if (!empty($toAdd)) {
        $toAddConverted = SomeTools::reverseJsonFormatFightsAthlete($toAdd, $wrestlerName);
        return $this->importFights($toAddConverted);
      }

      return true;
    }

    $this->logger->info('[importFights] Début traitement de @count item(s).', [
      '@count' => count($fightsData),
    ]);

    foreach ($fightsData as $index => $fight) {

      if (!is_array($fight)) {
        $this->logger->error('[importFights] Item #@i ignoré : type inattendu "@type" (valeur: @val). Le tableau de combats est corrompu — vérifier getFightsFromAiRequest et processItem.', [
          '@i'    => $index,
          '@type' => gettype($fight),
          '@val'  => substr(json_encode($fight), 0, 200),
        ]);
        continue;
      }

      if (isset($fight['date_precise'])) {
        $fight['date'] = $fight['date_precise'];
      }

      $field_is_lutte_simple = 0;
      if (isset($fight['is_lutte_simple']) && !empty($fight['is_lutte_simple']))
        $field_is_lutte_simple = (int) (trim($fight['is_lutte_simple']));

      if (empty($fight['lutteur1']) || empty($fight['lutteur2']) || empty($fight['date'])) {
        $this->logger->warning('[importFights] Item #@i ignoré — clés manquantes (lutteur1/lutteur2/date). Contenu : @keys', [
          '@i'    => $index,
          '@keys' => json_encode($fight),
        ]);
        continue;
      }

      $names1       = explode('/', $fight['lutteur1']);
      $fightName1   = $this->correctNames($names1[0]);
      $fightEcurie1 = isset($names1[1]) ? trim($names1[1]) : null;

      $names2       = explode('/', $fight['lutteur2']);
      $fightName2   = $this->correctNames($names2[0]);
      $fightEcurie2 = isset($names2[1]) ? trim($names2[1]) : null;

      $sortedNames = [$this->technicalWrestlerName($fightName1), $this->technicalWrestlerName($fightName2)];
      sort($sortedNames);

      try {
        $fightDate = (new \DateTime($fight['date']))->format('Ymd');
      } catch (\Exception $e) {
        $this->logger->warning('[importFights] Item #@i ignoré — date invalide "@date" pour @n1 vs @n2.', [
          '@i'    => $index,
          '@date' => $fight['date'],
          '@n1'   => $fightName1,
          '@n2'   => $fightName2,
        ]);
        continue;
      }

      $fightId = $sortedNames[0] . '_' . $sortedNames[1] . '_' . $fightDate;

      $this->logger->info('[importFights] Item #@i : @n1 vs @n2 (@date) — fightId=@id', [
        '@i'    => $index,
        '@n1'   => $fightName1,
        '@n2'   => $fightName2,
        '@date' => $fight['date'],
        '@id'   => $fightId,
      ]);

      $nids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'confrontation')
        ->condition('field_id_text', $fightId)
        ->condition('status', 1)
        ->accessCheck(false)
        ->execute();

      if (!empty($nids)) {
        $this->logger->info('[importFights] Item #@i ignoré : confrontation déjà en BDD (nid=@nid).', [
          '@i'   => $index,
          '@nid' => reset($nids),
        ]);
        continue;
      }

      $lock         = \Drupal::lock();
      $athlete1Node = SomeTools::getOrCreateAthleteLocked($lock, $fightName1, $fightEcurie1);
      $athlete2Node = SomeTools::getOrCreateAthleteLocked($lock, $fightName2, $fightEcurie2);

      if (!$athlete1Node || !$athlete2Node) {
        $this->logger->error('[importFights] Item #@i ignoré : impossible de créer/récupérer un athlète (@n1 ou @n2).', [
          '@i'  => $index,
          '@n1' => $fightName1,
          '@n2' => $fightName2,
        ]);
        continue;
      }

      $this->logger->info('[importFights] Recherche vidéos YouTube pour @n1 vs @n2...', [
        '@n1' => $fightName1,
        '@n2' => $fightName2,
      ]);

      $confrontWithVideos = $this->searchYoutubeVideos($fight['date'], $fightName1, $fightName2);
      $face2face          = $this->urlsToUriArray($confrontWithVideos['face2face'] ?? []);
      $video_combat       = $this->urlsToUriArray($confrontWithVideos['video_combat'] ?? []);

      if (empty($face2face)) {
        $face2faceRaw = is_array($fight['face2face'] ?? null)
          ? urldecode(implode(',', $fight['face2face']))
          : urldecode($fight['face2face'] ?? '');
        $face2face = $this->normalizeVideoField(explode(',', $face2faceRaw));
      }

      if (empty($video_combat)) {
        $videoCombatRaw = is_array($fight['video_combat'] ?? null)
          ? urldecode(implode(',', $fight['video_combat']))
          : urldecode($fight['video_combat'] ?? '');
        $video_combat = $this->normalizeVideoField(explode(',', $videoCombatRaw));
      }

      // --- LOGIQUE VERDICT ---
      $verdictRaw   = $fight['resultat'] ?? '';
      $verdictValue = $this->correctNames($verdictRaw);

      $cleanVerdict = SomeTools::cleanForMatch($verdictRaw);
      $cleanName1   = SomeTools::cleanForMatch($fightName1);
      $cleanName2   = SomeTools::cleanForMatch($fightName2);

      if ($cleanVerdict === 'nul' || str_contains($cleanVerdict, 'match nul')) {
        $verdictValue = (string) $this->t('Nul');
      } elseif (str_contains($cleanVerdict, 'litige') || str_contains($cleanVerdict, 'sans verdict')) {
        $verdictValue = (string) $this->t('Litige (sans verdict)');
      } elseif (trim($verdictRaw) === '?') {
        $verdictValue = '?';
      } else {
        if (stripos($cleanVerdict, $cleanName1) !== FALSE) {
          $verdictValue = $athlete1Node->label();
        } elseif (stripos($cleanVerdict, $cleanName2) !== FALSE) {
          $verdictValue = $athlete2Node->label();
        } else {
          $threshold1 = max(1, (int) floor(strlen($cleanName1) * 0.08));
          $threshold2 = max(1, (int) floor(strlen($cleanName2) * 0.08));
          $dist1      = levenshtein(strtolower($cleanVerdict), strtolower($cleanName1));
          $dist2      = levenshtein(strtolower($cleanVerdict), strtolower($cleanName2));

          if ($dist1 <= $threshold1 && $dist1 <= $dist2) {
            $verdictValue = $athlete1Node->label();
          } elseif ($dist2 <= $threshold2) {
            $verdictValue = $athlete2Node->label();
          } else {
            $tokenScore = function (string $a, string $b): float {
              $aTokens = array_values(array_filter(preg_split('/\s+/', trim($a)), fn($t) => strlen($t) >= 2));
              $bTokens = array_values(array_filter(preg_split('/\s+/', trim($b)), fn($t) => strlen($t) >= 2));
              if (empty($aTokens) || empty($bTokens)) return 0.0;

              $countMatches = function (array $source, array $target): int {
                $matches = 0;
                foreach ($source as $st) {
                  foreach ($target as $tt) {
                    if (
                      $st === $tt
                      || str_contains($tt, $st)
                      || str_contains($st, $tt)
                      || levenshtein($st, $tt) <= 1
                    ) {
                      $matches++;
                      break;
                    }
                  }
                }
                return $matches;
              };

              $scoreAB = $countMatches($aTokens, $bTokens) / count($aTokens);
              $scoreBA = $countMatches($bTokens, $aTokens) / count($bTokens);

              return max($scoreAB, $scoreBA);
            };

            $v      = strtolower($cleanVerdict);
            $score1 = $tokenScore($v, strtolower($cleanName1));
            $score2 = $tokenScore($v, strtolower($cleanName2));

            if ($score1 >= 0.5 && $score1 >= $score2) {
              $verdictValue = $athlete1Node->label();
            } elseif ($score2 >= 0.5) {
              $verdictValue = $athlete2Node->label();
            } else {
              $this->logger->notice('[importFights] Item #@i ignoré : verdict "@verdict" non reconnu pour @n1 vs @n2.', [
                '@i'       => $index,
                '@verdict' => $verdictRaw,
                '@n1'      => $fightName1,
                '@n2'      => $fightName2,
              ]);
              continue;
            }
          }
        }
      }
      // --- FIN LOGIQUE VERDICT ---

      // --- VÉRIFICATION ANTI-DOUBLON (même paire d'athlètes, < 1 an d'écart) ---
      // Ne s'applique qu'aux combats passés : un combat futur ne peut pas
      // être un doublon d'un combat "confrontation" déjà joué.
      $todayCheck = new \DateTime('today');
      try {
        $fightDateTimeCheck = new \DateTime($fight['date']);
      } catch (\Exception $e) {
        $fightDateTimeCheck = null;
      }
      $isFutureCheck = $fightDateTimeCheck && $fightDateTimeCheck >= $todayCheck;

      if (!$isFutureCheck) {
        $this->logger->debug(
          '[importFights] DEBUG anti-doublon : athlete1_id=@a1 (@n1) | athlete2_id=@a2 (@n2) | date=@date | verdict=@verdict',
          [
            '@a1'      => $athlete1Node->id(),
            '@n1'      => $athlete1Node->label(),
            '@a2'      => $athlete2Node->id(),
            '@n2'      => $athlete2Node->label(),
            '@date'    => $fight['date'],
            '@verdict' => $verdictValue,
          ]
        );

        $isNearDuplicate = SomeTools::checkNearDuplicateConfrontation(
          (int) $athlete1Node->id(),
          (int) $athlete2Node->id(),
          $fight['date'],
          $verdictValue,
          $this->entityTypeManager->getStorage('node'),
          $this->logger
        );

        if ($isNearDuplicate) {
          $this->logger->notice(
            '[importFights] Item #@i ignoré (doublon potentiel < 1 an détecté) : @n1 vs @n2 — @date',
            ['@i' => $index, '@n1' => $athlete1Node->label(), '@n2' => $athlete2Node->label(), '@date' => $fight['date']]
          );
          $ret = true; // Traité (via update éventuel dans checkNearDuplicateConfrontation), pas une erreur.
          continue;
        }
      }
      // --- FIN VÉRIFICATION ANTI-DOUBLON ---

      // --- DÉCISION : confrontation passée ou combat futur ---
      $today = new \DateTime('today');
      try {
        $fightDateTime = new \DateTime($fight['date']);
      } catch (\Exception $e) {
        $fightDateTime = null;
      }

      $isFuture = $fightDateTime && $fightDateTime >= $today;

      if ($isFuture) {
        $aVenirStorage = $this->entityTypeManager->getStorage('node');

        $existingAVenir = $aVenirStorage->getQuery()
          ->condition('type', 'a_venir')
          ->condition('field_athlete_1', $athlete1Node->id())
          ->condition('field_athlete_2', $athlete2Node->id())
          ->condition('field_confrontation_date', $fight['date'])
          ->condition('status', 1)
          ->accessCheck(false)
          ->execute();

        if (empty($existingAVenir)) {
          $aVenir = Node::create([
            'type'                     => 'a_venir',
            'title'                    => $this->t('@wrestler1 vs @wrestler2', [
              '@wrestler1' => $athlete1Node->label(),
              '@wrestler2' => $athlete2Node->label(),
            ]),
            'field_athlete_1'          => ['target_id' => $athlete1Node->id()],
            'field_athlete_2'          => ['target_id' => $athlete2Node->id()],
            'field_confrontation_date' => $fight['date'],
            'field_is_lutte_simple'    => $field_is_lutte_simple === 1 ? 1 : 0,
            'field_face_2_face'        => $face2face,
            'status'                   => 1,
          ]);
          $aVenir->save();

          $this->logger->info('[importFights] Combat futur → nœud a_venir créé : nid=@nid (@n1 vs @n2, @date).', [
            '@nid'  => $aVenir->id(),
            '@n1'   => $athlete1Node->label(),
            '@n2'   => $athlete2Node->label(),
            '@date' => $fight['date'],
          ]);
        } else {
          $this->logger->info('[importFights] a_venir déjà existant pour @n1 vs @n2 (@date), ignoré.', [
            '@n1'   => $athlete1Node->label(),
            '@n2'   => $athlete2Node->label(),
            '@date' => $fight['date'],
          ]);
        }

        $ret = true;
        sleep(1);
        continue;
      }

      $confrontation = Node::create([
        'type'                     => 'confrontation',
        'title'                    => $this->t('@wrestler1 vs @wrestler2', [
          '@wrestler1' => $athlete1Node->label(),
          '@wrestler2' => $athlete2Node->label(),
        ]),
        'field_id_text'            => $fightId,
        'field_athlete_1'          => ['target_id' => $athlete1Node->id()],
        'field_athlete_2'          => ['target_id' => $athlete2Node->id()],
        'field_vainqueur'          => $verdictValue,
        'field_confrontation_date' => $fight['date'],
        'field_face_2_face'        => $face2face,
        'field_video_du_combat'    => $video_combat,
        'field_is_lutte_simple'    => $field_is_lutte_simple === 1 ? 1 : 0,
      ]);

      $confrontation->save();

      $this->logger->info('[importFights] Confrontation créée : nid=@nid, id=@fid.', [
        '@nid' => $confrontation->id(),
        '@fid' => $fightId,
      ]);

      $winner = $loser = null;
      if (stripos($verdictValue, $athlete1Node->label()) !== FALSE) {
        $winner = $athlete1Node;
        $loser  = $athlete2Node;
      } elseif (stripos($verdictValue, $athlete2Node->label()) !== FALSE) {
        $winner = $athlete2Node;
        $loser  = $athlete1Node;
      }

      if ($winner && $loser) {
        SomeTools::updateKingStatus($winner, $loser);
      }

      $ret = true;
      sleep(1);
    }

    $this->logger->info('[importFights] Terminé. ret=@ret.', ['@ret' => $ret ? 'true' : 'false']);
    return $ret;
  }

  /**
   * Mise à jour forcée des vidéos via la file d'attente.
   */
  public function updateVideosFights($fighter)
  {
    $confrontations = $this->getConfrontationsByAthlete($fighter);
    $queue          = \Drupal::queue('updatevideos_wrestling_importer_queue');

    foreach ($confrontations as $confrontation) {
      //$this->logger->info("updateVideosFights: " . $confrontation->id());
      $queue->createItem($confrontation->id());
    }
  }
}
