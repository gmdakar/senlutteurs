<?php

namespace Drupal\confrontations_recentes\Plugin\QueueWorker;

use Drupal\shared_lib\Plugin\QueueWorker\BaseCombatQueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\Entity\Node;
use Drupal\shared_lib\Tools\SomeTools;

/**
 * @QueueWorker(
 *   id = "confrontations_recentes_queue",
 *   title = @Translation("Process Confrontations récentes Queue"),
 *   cron = {"time" = 600}
 * )
 */
class ConfrontationsRecentesQueue extends BaseCombatQueueWorker implements \Drupal\Core\Plugin\ContainerFactoryPluginInterface
{

  // No constructor — inherited from base.

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

  public function processItem($data)
  {
    // 1. Validation date
    if (empty($data["date_precise"])) {
      \Drupal::logger('confrontations_recentes')->debug(
        '<pre>@data empty</pre>',
        ['@data' => print_r($data, TRUE)]
      );
      return;
    }

    $dateObj = $this->normalizeDate($data['date_precise']);

    // 2. Filtre intervalle 1 mois
    $now         = new \DateTime('now', new \DateTimeZone('UTC'));
    $oneMonthAgo = (clone $now)->modify('-1 month');

    if ($dateObj < $oneMonthAgo || $dateObj > $now) {
      \Drupal::logger('confrontations_recentes')->notice(
        '⏩ Combat ignoré (hors intervalle 1 mois): @date — @lutteurs',
        ['@date' => $dateObj->format('Y-m-d'), '@lutteurs' => $data['lutteur1'] . ' vs ' . $data['lutteur2']]
      );
      return;
    }

    // 4. Calcul de la date UNE SEULE FOIS
    $combat_date = $dateObj->format('Y-m-d');
    $fightDateId = $dateObj->format('Ymd');

    // 3-0. Combat issu d'un "a_venir" : les 2 athlètes sont DÉJÀ identifiés
    // en base (field_lutteur_1/2 du node a_venir). On les réutilise tels quels
    // et on court-circuite correction IA + getOrCreateAthlete, sinon l'IA peut
    // rattacher le combat à un homonyme (ex: "Talfa" → "Talfa (door Dooraat)")
    // et créer un fightId différent de celui de l'a_venir.
    $combatService   = \Drupal::service('confrontations_recentes.combat_service');
    $lock            = \Drupal::lock();
    $lutteur1_entity = NULL;
    $lutteur2_entity = NULL;
    $presetAthletes  = $this->loadAthletesFromAvenir($data);
    if ($presetAthletes) {
      [$lutteur1_entity, $lutteur2_entity] = $presetAthletes;
      \Drupal::logger('confrontations_recentes')->notice(
        'Athlètes repris depuis l\'a_venir @id : @l1 vs @l2 (confirmation IA/homonymes ignorée)',
        [
          '@id' => $data['id_text'],
          '@l1' => (string) $lutteur1_entity->label(),
          '@l2' => (string) $lutteur2_entity->label(),
        ]
      );
    }

    if (!$presetAthletes) {
      // 3. Normalisation des noms (écurie entre parenthèses → slash)
      $pattern = '/([A-Za-zÀ-ÖØ-öø-ÿ\'\-]+(?:\s+[A-Za-zÀ-ÖØ-öø-ÿ\'\-]+)*)\s*\(([^)]+)\)/';
      $data['lutteur1'] = preg_replace($pattern, '$1/$2', $data['lutteur1']);
      $data['lutteur2'] = preg_replace($pattern, '$1/$2', $data['lutteur2']);

      $names1 = explode('/', $data['lutteur1']);
      $fight_name_lutteur1   = $this->correctNames(trim($names1[0]));
      $fight_ecurie_lutteur1 = isset($names1[1]) ? trim($names1[1]) : NULL;

      $names2 = explode('/', $data['lutteur2']);
      $fight_name_lutteur2   = $this->correctNames(trim($names2[0]));
      $fight_ecurie_lutteur2 = isset($names2[1]) ? trim($names2[1]) : NULL;

      if (empty($fight_name_lutteur1) || empty($fight_name_lutteur2)) {
        return;
      }

      // 4-bis. Confirmation IA des 2 noms de lutteurs AVANT toute création
      // d'athlète : on lui fournit l'historique des 2 derniers combats connus
      // de chacun, l'écurie d'entrée si connue, + une liste de suggestions de
      // variantes de noms proches déjà en base (ignorant les suffixes
      // numériques/junior/jr), pour qu'elle confirme que le combat à cette
      // date correspond bien aux 2 profils, et corrige éventuellement le nom
      // (nettoyage de texte parasite, orthographe CNG, homonymie via
      // parenthèse, ou complétion d'écurie via "Nom/Ecurie") d'un des 2
      // lutteurs.
      $confirmation  = $combatService->confirmWrestlersBeforeInsert(
        $fight_name_lutteur1,
        $fight_name_lutteur2,
        $data['date_precise'],
        $fight_ecurie_lutteur1,
        $fight_ecurie_lutteur2
      );

      if ($confirmation['ai_valid'] === FALSE) {
        \Drupal::logger('confrontations_recentes')->warning(
          'Combat ignoré (IA : ne correspond pas aux profils) : @l1 vs @l2 — @date — @commentaire',
          [
            '@l1' => $fight_name_lutteur1,
            '@l2' => $fight_name_lutteur2,
            '@date' => $combat_date,
            '@commentaire' => $confirmation['ai_commentaire'] ?? '',
          ]
        );
        return;
      }

      // Échec technique de l'IA (prompt non configuré, liveSearch en échec,
      // JSON invalide...) ALORS QUE des suggestions de noms proches existaient
      // en base : risque réel d'homonymie/fusion avec un mauvais athlète
      // existant (getOrCreateAthlete matche par nom technique). Dans ce cas
      // précis, on préfère bloquer l'insertion plutôt que risquer un
      // rattachement erroné — le combat sera retenté au prochain run (la
      // queue le renverra, il n'est pas perdu).
      if ($confirmation['ai_valid'] === null && !empty($confirmation['had_suggestions'])) {
        \Drupal::logger('confrontations_recentes')->warning(
          'Combat ignoré (échec technique IA + homonymes potentiels en base) : @l1 vs @l2 — @date. Sera retenté.',
          ['@l1' => $fight_name_lutteur1, '@l2' => $fight_name_lutteur2, '@date' => $combat_date]
        );
        return;
      }

      if ($confirmation['ai_corrected']) {
        // Ré-application du pattern Name/Ecurie sur les noms corrigés par
        // l'IA. 2 cas de figure possibles :
        //  - homonymie levée : parenthèse ajoutée au nom, ex: "Alboury (Pout)"
        //    → reste tel quel, pas de split (pas de "/").
        //  - écurie complétée par l'IA (absente en entrée) : format
        //    "Nom/Ecurie", ex: "Baleine/Mor Fadam" → à splitter.
        // Dans les 2 cas, l'écurie ainsi obtenue reste prioritairement celle
        // déjà connue depuis les données d'entrée : on ne l'écrase que si
        // elle était vide.
        $correctedL1 = trim($confirmation['lutteur1']);
        $correctedL2 = trim($confirmation['lutteur2']);

        if (str_contains($correctedL1, '/')) {
          $partsL1 = explode('/', $correctedL1);
          $fight_name_lutteur1 = $this->correctNames(trim($partsL1[0]));
          if (empty($fight_ecurie_lutteur1) && isset($partsL1[1]) && trim($partsL1[1]) !== '') {
            $fight_ecurie_lutteur1 = trim($partsL1[1]);
          }
        } else {
          $fight_name_lutteur1 = $this->correctNames($correctedL1);
        }

        if (str_contains($correctedL2, '/')) {
          $partsL2 = explode('/', $correctedL2);
          $fight_name_lutteur2 = $this->correctNames(trim($partsL2[0]));
          if (empty($fight_ecurie_lutteur2) && isset($partsL2[1]) && trim($partsL2[1]) !== '') {
            $fight_ecurie_lutteur2 = trim($partsL2[1]);
          }
        } else {
          $fight_name_lutteur2 = $this->correctNames($correctedL2);
        }
      }

      // 5. getOrCreateAthlete protégé par un lock par lutteur avec wait+retry
      // pour éviter la création de doublons d'athlètes en parallèle
      $lutteur1_entity = SomeTools::getOrCreateAthleteLocked($lock, $fight_name_lutteur1, $fight_ecurie_lutteur1);
      $lutteur2_entity = SomeTools::getOrCreateAthleteLocked($lock, $fight_name_lutteur2, $fight_ecurie_lutteur2);

      if (!$lutteur1_entity || !$lutteur2_entity) {
        \Drupal::logger('confrontations_recentes')->error(
          'Lutteur non trouvé ou créé pour @l1 ou @l2',
          ['@l1' => $fight_name_lutteur1, '@l2' => $fight_name_lutteur2]
        );
        return;
      }


    }

    // 6. Labels canoniques (issus de l'entité, pas de l'input)
    $labelLutteur1 = (string) $lutteur1_entity->label();
    $labelLutteur2 = (string) $lutteur2_entity->label();

    // 7. Construction du fightId (noms triés alphabétiquement)
    $technicalNames = [
      $this->technicalWrestlerName($labelLutteur1),
      $this->technicalWrestlerName($labelLutteur2),
    ];
    sort($technicalNames);
    $fightId = $technicalNames[0] . '_' . $technicalNames[1] . '_' . $fightDateId;

    // 8. Lock sur le combat entier : évite le travail inutile (YouTube, verdict…)
    // en cas de traitements parallèles du même combat.
    // La UNIQUE KEY sur field_id_text est le filet de sécurité final.
    $combatLockName = 'confrontation_create_' . $fightId;

    if (!$lock->acquire($combatLockName, 30)) {
      \Drupal::logger('confrontations_recentes')->warning(
        'ConfrontationsRecentesQueue Lock non acquis pour @id — combat ignoré (traitement concurrent).',
        ['@id' => $fightId]
      );
      return;
    }

    try {
      // 9. Vérification doublon DANS le lock (première ligne de défense)
      if ($this->combatExists($fightId, 'confrontation')) {
        \Drupal::logger('confrontations_recentes')->notice(
          'Combat déjà existant : @id',
          ['@id' => $fightId]
        );
        return;
      }

      // 10. Vidéos
      $existing_face2face    = !empty($data['face2face'])
        ? (is_array($data['face2face']) ? $data['face2face'] : explode(',', $data['face2face']))
        : [];
      $existing_video_combat = !empty($data['video_combat'])
        ? (is_array($data['video_combat']) ? $data['video_combat'] : explode(',', $data['video_combat']))
        : [];

      \Drupal::logger('confrontations_recentes')->info(
        'Recherche vidéos YouTube pour @combat',
        ['@combat' => "$labelLutteur1 vs $labelLutteur2"]
      );
      // Combats récents (cri) : video_combat max 5 jours (pas de vidéos plus anciennes).
      $youtubeResults = $this->searchYoutubeVideos($combat_date, $labelLutteur1, $labelLutteur2, 5);

      $face2face    = $this->urlsToUriArray(array_unique(array_merge($existing_face2face, $youtubeResults['face2face'] ?? [])));
      $video_combat = $this->urlsToUriArray(array_unique(array_merge($existing_video_combat, $youtubeResults['video_combat'] ?? [])));

      // 11. Tendance
      $tendance = '';
      if (!empty($data['tendance'])) {
        $tendance = $data['tendance'];
        \Drupal::logger('confrontations_recentes')->debug(
          'VERDICT FORTE TENDANCE @l1 vs @l2 : @tendance',
          ['@l1' => $labelLutteur1, '@l2' => $labelLutteur2, '@tendance' => $tendance]
        );
      }

      // 12. Verdict
      $verdictRaw = empty($data['resultat'])
        ? $combatService->getResultVerdict($data['date_precise'], $labelLutteur1, $labelLutteur2)
        : $data['resultat'];

      $verdictValue = $this->correctNames($verdictRaw);
      $cleanVerdict = SomeTools::cleanForMatch($verdictRaw);
      $cleanName1   = SomeTools::cleanForMatch($labelLutteur1);
      $cleanName2   = SomeTools::cleanForMatch($labelLutteur2);

      if (trim($verdictRaw) === '?') {
        // Garde-fou : un resultat "?" n'est insérable que si l'étape IA
        // a explicitement confirmé que le combat a bien eu lieu
        // (combat_a_eu_lieu). Sinon → skip (affiche non jouée / date erronée).
        $playedFlag = $data['combat_a_eu_lieu'] ?? null;
        $playedConfirmed = ($playedFlag === true || $playedFlag === 1
          || $playedFlag === '1' || $playedFlag === 'true');
        if (!$playedConfirmed) {
          \Drupal::logger('confrontations_recentes')->notice(
            'Combat ignoré (resultat=? sans combat_a_eu_lieu confirmé) : @l1 vs @l2 — @date (source: @src)',
            [
              '@l1'  => $labelLutteur1,
              '@l2'  => $labelLutteur2,
              '@date' => $combat_date,
              '@src'  => $data['source'] ?? '?',
            ]
          );
          return;
        }
        $verdictValue = '?';
      } elseif ($cleanVerdict === 'nul' || str_contains($cleanVerdict, 'match nul')) {
        $verdictValue = (string) $this->t('Nul');
      } elseif (str_contains($cleanVerdict, 'litige') || str_contains($cleanVerdict, 'sans verdict')) {
        $verdictValue = (string) $this->t('Litige (sans verdict)');
      } else {
        $winnerIdx = $this->matchVerdictToFighter($cleanVerdict, $labelLutteur1, $labelLutteur2);
        if ($winnerIdx === 1) {
          $verdictValue = $labelLutteur1;
        } elseif ($winnerIdx === 2) {
          $verdictValue = $labelLutteur2;
        } else {
          \Drupal::logger('confrontations_recentes')->notice(
            'Skipped: verdict "@verdict" ne contient aucun lutteur (@combat)',
            ['@verdict' => $verdictRaw, '@combat' => "$labelLutteur1 vs $labelLutteur2"]
          );
          return;
        }
      }

      // 12-bis. Vérification anti-doublon : même paire de lutteurs, < 1 an d'écart.
      // Si un doublon est détecté, la méthode gère elle-même la mise à jour
      // (date et/ou verdict) de la confrontation existante — on n'insère rien.
      $isNearDuplicate = SomeTools::checkNearDuplicateConfrontation(
        (int) $lutteur1_entity->id(),
        (int) $lutteur2_entity->id(),
        $combat_date,
        $verdictValue,
        \Drupal::entityTypeManager()->getStorage('node'),
        \Drupal::logger('confrontations_recentes')
      );

      if ($isNearDuplicate) {
        \Drupal::logger('confrontations_recentes')->notice(
          'Combat ignoré (doublon potentiel < 1 an détecté) : @l1 vs @l2 — @date',
          ['@l1' => $labelLutteur1, '@l2' => $labelLutteur2, '@date' => $combat_date]
        );
        return;
      }

      // 13. Création du nœud
      $confrontation = Node::create([
        'type'                  => 'confrontation',
        'title'                 => $this->t('@w1 vs @w2', ['@w1' => $labelLutteur1, '@w2' => $labelLutteur2]),
        'field_id_text'         => $fightId,
        'field_athlete_1'       => ['target_id' => $lutteur1_entity->id()],
        'field_athlete_2'       => ['target_id' => $lutteur2_entity->id()],
        'field_vainqueur'       => $verdictValue,
        'field_tendance'        => $tendance,
        'field_confrontation_date' => $combat_date,
        'field_face_2_face'     => $face2face,
        'field_video_du_combat' => $video_combat,
        'field_is_lutte_simple' => isset($data['is_lutte_simple']) ? (bool) $data['is_lutte_simple'] : FALSE,
      ]);

      try {
        $confrontation->save();
        \Drupal::logger('confrontations_recentes')->notice(
          '✅ Nouveau combat créé : @title',
          ['@title' => $confrontation->getTitle()]
        );
      } catch (\RuntimeException $e) {
        // Doublon intercepté par la validation applicative du presave
        \Drupal::logger('confrontations_recentes')->notice(
          'Doublon ignoré pour @id : @msg',
          ['@id' => $fightId, '@msg' => $e->getMessage()]
        );
        return;
      }

      // 14. King Status
      $winner = NULL;
      $loser  = NULL;
      if ($verdictValue === $labelLutteur1) {
        $winner = $lutteur1_entity;
        $loser  = $lutteur2_entity;
      } elseif ($verdictValue === $labelLutteur2) {
        $winner = $lutteur2_entity;
        $loser  = $lutteur1_entity;
      }

      if ($winner && $loser) {
        SomeTools::updateKingStatus($winner, $loser);
      }
    } finally {
      // Le lock est TOUJOURS libéré, même si une exception est levée
      $lock->release($combatLockName);
    }

    // 15. Facebook (hors du lock, pas critique pour la déduplication)
    if (isset($_ENV['ENVIRONMENT']) && $_ENV['ENVIRONMENT'] === 'prod') {
      $message  = $this->t('Lutte sénégalaise, combat : ') . $confrontation->getTitle() . "\n";
      $message .= $confrontation->toUrl('canonical', ['absolute' => TRUE])->toString();
      try {
        $post_id = \Drupal::service('fb_publisher.facebook_service')->postToPage($message);
        \Drupal::logger('confrontations_recentes')->info('FB post ID: @id', ['@id' => $post_id]);
      } catch (\Exception $e) {
        \Drupal::logger('confrontations_recentes')->warning('FB Error: @msg', ['@msg' => $e->getMessage()]);
      }
    }
  }

  /**
   * Retrouve les 2 athlètes d'un combat issu d'un a_venir (via id_text).
   *
   * @return array|null [$athlete1, $athlete2] ou NULL (flux normal).
   */
  protected function loadAthletesFromAvenir(array $data): ?array
  {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $node = NULL;

    // 1. Lien direct par id_text.
    if (!empty($data['from_a_venir']) && !empty($data['id_text'])) {
      $nids = $storage->getQuery()
        ->condition('type', 'a_venir')
        ->condition('field_id_text', $data['id_text'])
        ->accessCheck(FALSE)
        ->range(0, 1)
        ->execute();
      $node = $nids ? $storage->load(reset($nids)) : NULL;
    }

    // 2. Filet : a_venir du même jour (±1 j, field_date est un datetime UTC)
    //    avec les mêmes 2 noms de base.
    if (!$node && !empty($data['date_precise'])) {
      $d    = $this->normalizeDate($data['date_precise']);
      $from = (clone $d)->modify('-1 day')->format('Y-m-d\T00:00:00');
      $to   = (clone $d)->modify('+2 day')->format('Y-m-d\T00:00:00');

      $key = static function (string $n): string {
        return SomeTools::cleanForMatch(trim(preg_replace('/\s*[\(\/].*$/u', '', $n)));
      };
      $want = [$key((string) ($data['lutteur1'] ?? '')), $key((string) ($data['lutteur2'] ?? ''))];
      sort($want);

      $nids = $storage->getQuery()
        ->condition('type', 'a_venir')
        ->condition('field_date', $from, '>=')
        ->condition('field_date', $to, '<')
        ->accessCheck(FALSE)
        ->execute();

      foreach ($storage->loadMultiple($nids) as $cand) {
        if (!$cand->hasField('field_lutteur_1') || !$cand->hasField('field_lutteur_2')) {
          continue;
        }
        $e1 = $cand->get('field_lutteur_1')->entity;
        $e2 = $cand->get('field_lutteur_2')->entity;
        if (!$e1 || !$e2) {
          continue;
        }
        $have = [$key((string) $e1->label()), $key((string) $e2->label())];
        sort($have);
        if ($have === $want) {
          $node = $cand;
          break;
        }
      }
    }

    if (!$node || !$node->hasField('field_lutteur_1') || !$node->hasField('field_lutteur_2')) {
      return NULL;
    }
    $a1 = $node->get('field_lutteur_1')->entity;
    $a2 = $node->get('field_lutteur_2')->entity;
    return ($a1 && $a2) ? [$a1, $a2] : NULL;
  }

  /**
   * Indique quel lutteur (1 ou 2) est cité dans le verdict, NULL si aucun.
   *
   * Compare le label complet ET le nom de base (sans "(écurie)" ni "/écurie"),
   * et départage par le nom le plus long ("Boy Niang 2" prime sur "Boy Niang").
   */
  protected function matchVerdictToFighter(string $cleanVerdict, string $label1, string $label2): ?int
  {
    $best = NULL;
    $bestLen = 0;
    foreach ([1 => $label1, 2 => $label2] as $idx => $label) {
      $base = trim(preg_replace('/\s*[\(\/].*$/u', '', $label));
      foreach ([SomeTools::cleanForMatch($label), SomeTools::cleanForMatch($base)] as $needle) {
        if ($needle === '' || stripos($cleanVerdict, $needle) === FALSE) {
          continue;
        }
        if (strlen($needle) > $bestLen) {
          $best = $idx;
          $bestLen = strlen($needle);
        }
      }
    }
    return $best;
  }
}
