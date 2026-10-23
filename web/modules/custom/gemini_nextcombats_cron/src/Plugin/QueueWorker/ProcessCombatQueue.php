<?php

namespace Drupal\gemini_nextcombats_cron\Plugin\QueueWorker;

use Drupal\shared_lib\Plugin\QueueWorker\BaseCombatQueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\Entity\Node;
use Drupal\shared_lib\Tools\SomeTools;

/**
 * @QueueWorker(
 *   id = "gemini_nextcombats_queue",
 *   title = @Translation("Process Gemini next Combats Queue"),
 *   cron = {"time" = 60}
 * )
 */
class ProcessCombatQueue extends BaseCombatQueueWorker implements \Drupal\Core\Plugin\ContainerFactoryPluginInterface {

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
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
   * Nettoie un nom brut de lutteur ("Nom (Ecurie)" ou "Nom/Ecurie") et le
   * découpe en [nom, ecurie|null]. Factorisé car ce même traitement doit
   * être appliqué aussi bien au nom brut initialement scrapé qu'au nom
   * potentiellement renvoyé par l'IA de confirmation (qui peut elle-même
   * produire un "Nom/Ecurie", voir la règle "Complétion d'écurie" du
   * prompt prompt_confirm_wrestlers).
   *
   * @return array{0: string, 1: string|null}
   */
  protected function splitNameAndEcurie(string $rawName): array {
    // "Tony Junior (Jr)" → "Tony Junior/Jr"
    $converted = preg_replace(
      '/([A-Za-zÀ-ÖØ-öø-ÿ\'\-]+(?:\s+[A-Za-zÀ-ÖØ-öø-ÿ\'\-]+)*)\s*\(([^)]+)\)/',
      '$1/$2',
      $rawName
    );

    $parts  = explode('/', $converted);
    $name   = trim($parts[0]);
    $ecurie = isset($parts[1]) ? trim($parts[1]) : null;

    return [$name, ($ecurie === '' ? null : $ecurie)];
  }

  public function processItem($data) {
    if (!isset($data["date_precise"]) || empty($data["date_precise"])) {
      $this->logger->debug('<pre>@data empty</pre>', ['@data' => print_r($data, TRUE)]);
      return;
    }

    //traitement des parentheses eventuels . Ex. "Tony Junior (Jr)"  →  "Tony Junior/Jr"
    // Puis split "Nom/Ecurie" → [nom, ecurie]. Factorisé dans
    // splitNameAndEcurie() car ce même traitement doit être réappliqué
    // après la confirmation IA (voir plus bas) : le prompt IA a lui-même
    // pour consigne d'ajouter "Nom/Ecurie" dans SA PROPRE réponse corrigée
    // (règle "Complétion d'écurie"), donc le nom renvoyé par l'IA peut, tout
    // autant que le nom brut scrapé, contenir un "/" à re-découper.
    [$fight_name_lutteur1, $fight_ecurie_lutteur1] = $this->splitNameAndEcurie($data['lutteur1']);
    [$fight_name_lutteur2, $fight_ecurie_lutteur2] = $this->splitNameAndEcurie($data['lutteur2']);

    if (empty($fight_name_lutteur1) || empty($fight_name_lutteur2)) {
      return;
    }

    // Confirmation IA des 2 noms de lutteurs AVANT toute création
    // d'athlète : on lui fournit l'historique des 2 derniers combats connus
    // de chacun + une liste de suggestions de variantes de noms proches déjà
    // en base (ex: "Pape thiam" → "Pape thiam 2", "Alboury" → "Alboury (kay
    // bakh)"), pour qu'elle confirme/corrige la bonne variante avant insertion
    // du combat à venir.
    $combatService = \Drupal::service('gemini_nextcombats_cron.combat_service');
    $confirmation  = $combatService->confirmWrestlersBeforeInsert(
      $fight_name_lutteur1,
      $fight_name_lutteur2,
      $data['date_precise']
    );

    if ($confirmation['ai_valid'] === FALSE) {
      \Drupal::logger('gemini_nextcombats_cron')->warning(
        'Combat à venir ignoré (IA : ne correspond pas aux profils) : @l1 vs @l2 — @date — @commentaire',
        [
          '@l1' => $fight_name_lutteur1,
          '@l2' => $fight_name_lutteur2,
          '@date' => $data['date_precise'],
          '@commentaire' => $confirmation['ai_commentaire'] ?? '',
        ]
      );
      return;
    }

    if ($confirmation['ai_corrected']) {
      // BUG CORRIGÉ : le nom renvoyé par l'IA peut lui-même contenir un
      // "Nom/Ecurie" (règle "Complétion d'écurie" du prompt) — il doit donc
      // être re-découpé exactement comme le nom brut initial, plutôt que
      // d'être utilisé tel quel comme nom complet (ce qui créait un athlète
      // "Pokola Baldé/Falaye Baldé" au lieu de nom="Pokola Baldé" +
      // écurie="Falaye Baldé", provoquant un doublon d'athlète — donc de
      // combat — pour la même personne).
      [$correctedName1, $correctedEcurie1] = $this->splitNameAndEcurie($this->correctNames($confirmation['lutteur1']));
      [$correctedName2, $correctedEcurie2] = $this->splitNameAndEcurie($this->correctNames($confirmation['lutteur2']));

      $fight_name_lutteur1 = $correctedName1;
      $fight_name_lutteur2 = $correctedName2;

      // On ne garde l'écurie déduite de la correction IA que si elle en a
      // fourni une ; sinon on conserve celle déjà extraite du nom brut
      // scrapé au tout début (ne pas effacer une info valide par un null).
      if ($correctedEcurie1 !== null) {
        $fight_ecurie_lutteur1 = $correctedEcurie1;
      }
      if ($correctedEcurie2 !== null) {
        $fight_ecurie_lutteur2 = $correctedEcurie2;
      }
    }

    //getOrCreateAthlete protégé par un lock par lutteur avec wait+retry
    // pour éviter la création de doublons d'athlètes en parallèle
    $lock = \Drupal::lock();

    $lutteur1_entity = SomeTools::getOrCreateAthleteLocked($lock, $fight_name_lutteur1, $fight_ecurie_lutteur1);
    $lutteur2_entity = SomeTools::getOrCreateAthleteLocked($lock, $fight_name_lutteur2, $fight_ecurie_lutteur2);

    if (!$lutteur1_entity || !$lutteur2_entity) {
      return;
    }

    $this->logger->debug('date_precise raw value: "@val"', ['@val' => $data['date_precise']]);
    $dateObj = $this->normalizeDate($data['date_precise']);

    // Guard: if the date couldn't be parsed, skip this item.
    if ($dateObj === NULL) {
      $this->logger->warning(
        'gemini_nextcombats_queue: Could not parse date_precise "@date" — skipping item. Data: <pre>@data</pre>',
        [
          '@date' => $data['date_precise'],
          '@data' => print_r($data, TRUE),
        ]
      );
      return;
    }

    $combat_date = $dateObj->format('Y-m-d') . 'T16:00:00';

    $fight_name_lutteur1 = $lutteur1_entity->label();
    $fight_name_lutteur2 = $lutteur2_entity->label();

    // Garde-fou métier : un lutteur ne peut pas avoir deux combats à venir
    // le même jour. Si un a_venir publié existe déjà à cette date avec au
    // moins un des deux lutteurs :
    //   - même paire d'athlètes (ordre indifférent) → MAJ face2face seulement
    //   - adversaire différent (variante de nom) → arbitrage IA synchrone
    //     (garder l'existant OU substituer la variante candidate sur le nœud).
    // On ne crée JAMAIS un second nœud.
    $dayYmd = $dateObj->format('Y-m-d');
    $existingSharedNid = SomeTools::findExistingAvenirSameDateSharedWrestler(
      (int) $lutteur1_entity->id(),
      (int) $lutteur2_entity->id(),
      $dayYmd
    );

    $names = [$this->technicalWrestlerName($fight_name_lutteur1), $this->technicalWrestlerName($fight_name_lutteur2)];
    sort($names);
    $fightDate = (new \DateTime($combat_date))->format('Ymd');
    $fightId = $names[0] . '_' . $names[1] . '_' . $fightDate;

    $exactNid = $this->combatExists($fightId, 'a_venir');
    $nid = $exactNid ?: $existingSharedNid;

    if ($nid) {
      $combatAvenir = $this->entityTypeManager
        ->getStorage('node')
        ->load($nid);

      if (!$combatAvenir) {
        return;
      }

      // Conflit de variante : même date + protagoniste commun, mais pas le
      // même field_id_text / pas la même paire d'athlètes exacte.
      $isVariantConflict = !$exactNid && $existingSharedNid
        && (int) $nid === (int) $existingSharedNid;

      if ($isVariantConflict) {
        $exL1 = (int) ($combatAvenir->get('field_lutteur_1')->target_id ?? 0);
        $exL2 = (int) ($combatAvenir->get('field_lutteur_2')->target_id ?? 0);
        $candPair = [(int) $lutteur1_entity->id(), (int) $lutteur2_entity->id()];
        sort($candPair);
        $exPair = [$exL1, $exL2];
        sort($exPair);
        $sameAthletes = ($candPair === $exPair);

        if (!$sameAthletes) {
          // Arbitrage IA : garder l'existant ou le remplacer par le candidat.
          $exLabel1 = $combatAvenir->get('field_lutteur_1')->entity
            ? $combatAvenir->get('field_lutteur_1')->entity->label() : '';
          $exLabel2 = $combatAvenir->get('field_lutteur_2')->entity
            ? $combatAvenir->get('field_lutteur_2')->entity->label() : '';
          $exTechId = trim((string) ($combatAvenir->get('field_id_text')->value ?? '')) ?: ('nid_' . $nid);

          $sharedIds = array_intersect([$exL1, $exL2], [(int) $lutteur1_entity->id(), (int) $lutteur2_entity->id()]);
          $pivotNid = $sharedIds ? (int) reset($sharedIds) : $exL1;
          $pivotNode = $this->entityTypeManager->getStorage('node')->load($pivotNid);
          $pivotName = $pivotNode ? $pivotNode->getTitle() : '';

          $existingPayload = [
            'nid'           => (int) $nid,
            'field_id_text' => $exTechId,
            'lutteur1'      => $exLabel1,
            'lutteur2'      => $exLabel2,
            'lutteur1_nid'  => $exL1,
            'lutteur2_nid'  => $exL2,
            'date'          => $dayYmd,
          ];
          $candidatePayload = [
            'nid'           => 0,
            'field_id_text' => $fightId,
            'lutteur1'      => $fight_name_lutteur1,
            'lutteur2'      => $fight_name_lutteur2,
            'lutteur1_nid'  => (int) $lutteur1_entity->id(),
            'lutteur2_nid'  => (int) $lutteur2_entity->id(),
            'date'          => $dayYmd,
          ];

          $combatService = \Drupal::service('gemini_nextcombats_cron.combat_service');
          $resolution = $combatService->resolveAvenirVariantConflict(
            $existingPayload,
            $candidatePayload,
            $pivotName
          );

          if (($resolution['choice'] ?? 'existing') === 'candidate') {
            // Substituer la variante candidate sur le nœud unique existant.
            $combatAvenir->set('field_lutteur_1', $lutteur1_entity->id());
            $combatAvenir->set('field_lutteur_2', $lutteur2_entity->id());
            $combatAvenir->setTitle($fight_name_lutteur1 . ' vs ' . $fight_name_lutteur2);
            $combatAvenir->set('field_id_text', $fightId);
            \Drupal::logger('gemini_nextcombats_cron')->notice(
              'a_venir variante SUBSTITUÉE (IA) NID @nid le @date : "@old1 vs @old2" → "@new1 vs @new2" — @raison',
              [
                '@nid'   => $nid,
                '@date'  => $dayYmd,
                '@old1'  => $exLabel1,
                '@old2'  => $exLabel2,
                '@new1'  => $fight_name_lutteur1,
                '@new2'  => $fight_name_lutteur2,
                '@raison'=> $resolution['raison'] ?? '',
              ]
            );
          }
          else {
            \Drupal::logger('gemini_nextcombats_cron')->notice(
              'a_venir variante CONSERVÉE (IA) NID @nid le @date : "@old1 vs @old2" (candidat "@new1 vs @new2" ignoré) — @raison',
              [
                '@nid'   => $nid,
                '@date'  => $dayYmd,
                '@old1'  => $exLabel1,
                '@old2'  => $exLabel2,
                '@new1'  => $fight_name_lutteur1,
                '@new2'  => $fight_name_lutteur2,
                '@raison'=> $resolution['raison'] ?? '',
              ]
            );
          }
        }
      }

      if (!$combatAvenir->hasField('field_avenir_face2face')) {
        $combatAvenir->save();
        return;
      }

      // MAJ face2face (existant exact ou après arbitrage).
      $existingUris = [];
      if (!$combatAvenir->get('field_avenir_face2face')->isEmpty()) {
        foreach ($combatAvenir->get('field_avenir_face2face')->getValue() as $item) {
          if (!empty($item['uri'])) {
            $existingUris[] = $item['uri'];
          }
        }
      }

      $newUris = [];
      $confrontWithVideos = $this->searchYoutubeVideos(
        $dateObj->format('Y-m-d'),
        $fight_name_lutteur1,
        $fight_name_lutteur2
      );

      if (!empty($confrontWithVideos['face2face'])) {
        foreach ($confrontWithVideos['face2face'] as $url) {
          $newUris[] = $url;
        }
      }

      $mergedUris = array_values(array_unique(array_merge($existingUris, $newUris)));

      $finalValues = array_map(function ($uri) {
        return ['uri' => $uri];
      }, $mergedUris);

      $this->logger->debug(
        '<pre>@data face2face FINAL</pre>',
        ['@data' => print_r($finalValues, TRUE)]
      );

      $combatAvenir->set('field_avenir_face2face', $finalValues);
      $combatAvenir->save();
    }
    else {

      //for new node, take face2face may come from data
      $face2face = NULL;
      if (isset($data['face2face'])) {
        if (!is_array($data['face2face'])) {
          $data_face2face = explode(',', $data['face2face'] ?? '');
        }
        else {
          $data_face2face = $data['face2face'];
        }

        if (!empty($data_face2face)) {
          $face2face = $this->urlsToUriArray($data_face2face);
        }
      }

      $combatAvenir = Node::create([
        'type' => 'a_venir',
        'title' => $fight_name_lutteur1 . ' vs ' . $fight_name_lutteur2,
        'field_lutteur_1' => $lutteur1_entity->id(),
        'field_lutteur_2' => $lutteur2_entity->id(),
        'field_date' => $combat_date,
        'field_avenir_face2face' => $face2face,
        'field_id_text' => $fightId,
        'status' => 1,
      ]);
      $combatAvenir->save();

      // Récupérer la liste persistante des nids traités dans ce batch
      $processedNids = \Drupal::state()->get('processed_combat_nids', []);

      // Ajouter le NID actuel
      $processedNids[] = $combatAvenir->id();

      // Mettre à jour le state
      \Drupal::state()->set('processed_combat_nids', $processedNids);

      //if no more item to process in the queue so do a little update for non traited itemps in this batch
      if (\Drupal::queue('gemini_nextcombats_queue')->numberOfItems() === 1) {
        \Drupal::logger('gemini_nextcombats_cron')->debug('Last item for gemini_nextcombats_queue !');

        //update eventuel new face to face videos for some nids, because after few weeks the json response of the cron may miss some previous in coming fights which are still displayed
        $this->updateFaceTofaceOThersFights($this->getUpcomingNodesUnprocessed());

        // remove eventuel expired previous in coming fights which are still displayed
        $this->removeExpiredFights();

        // Reset à vide le state
        \Drupal::state()->set('processed_combat_nids', []);
      }

      $this->logger->debug("gemini_nextcombats_queue : updateFace2FaceNode gethostname" . \Drupal::request()->getHost());

      //Add new related post on facebook page too
      if ($_ENV['ENVIRONMENT'] == 'prod') {  //on ne poste sur facebook qu'en prod
        $message = t('Lutte sénégalaise, combat à venir : ') . $combatAvenir->getTitle() . "\n";
        $message .= $combatAvenir->toUrl('canonical', ['absolute' => TRUE])->toString();
        $fb_service = \Drupal::service('fb_publisher.facebook_service');
        try {
          $post_id = $fb_service->postToPage($message);
          \Drupal::logger('gemini_nextcombats_cron')->info($this->t('Message posted successfully on facebook. Post ID: @id  and Message: @message', ['@id' => $post_id, '@message' => $message]));
        }
        catch (\Exception $e) {
          \Drupal::logger('gemini_nextcombats_cron')->warning($this->t('Error: @msg', ['@msg' => $e->getMessage()]));
        }
      }
    }
  }

  /***
   * Update face 2 face field for a node
   * param $node : node to update
   */
  public function updateFace2FaceNode($node) {
   
    $lutteur1_node = \Drupal\node\Entity\Node::load($node->get('field_lutteur_1')->target_id);
    $fight_name_lutteur1 = $lutteur1_node->label();
    $lutteur2_node = \Drupal\node\Entity\Node::load($node->get('field_lutteur_2')->target_id);
    $fight_name_lutteur2 = $lutteur2_node->label();
    $this->logger->info("gemini_nextcombats_queue : updateFace2FaceNode ". $fight_name_lutteur1);
    $this->logger->debug("gemini_nextcombats_queue2 : updateFace2FaceNode ". $fight_name_lutteur2);
    //for existing nodes, update face 2 face video from youtube search by api
    $existingUris = [];
    if (!$node->get('field_avenir_face2face')->isEmpty()) {
      foreach ($node->get('field_avenir_face2face')->getValue() as $item) {
        if (!empty($item['uri'])) {
          $existingUris[] = $item['uri'];
        }
      }
    }
    
   
    $dateObj = date('Y-m-d', strtotime($node->get('field_date')->value));
    $this->logger->debug("gemini_nextcombats_queue : updateFace2FaceNode ". $node->get('field_date')->value);
    $this->logger->debug("gemini_nextcombats_queue : updateFace2FaceNode ". $dateObj);
    $newUris = [];
    $confrontWithVideos = $this->searchYoutubeVideos(
      $dateObj,
      $fight_name_lutteur1,
      $fight_name_lutteur2
    );

    if (!empty($confrontWithVideos['face2face'])) {
      foreach ($confrontWithVideos['face2face'] as $url) {
        $newUris[] = $url;
      }
    }

    $mergedUris = array_values(array_unique(array_merge($existingUris, $newUris)));

    $finalValues = array_map(function ($uri) {
      return ['uri' => $uri];
    }, $mergedUris);

    $this->logger->debug(
      '<pre>@data face2face FINAL</pre>',
      ['@data' => print_r($finalValues, TRUE)]
    );

    $node->set('field_avenir_face2face', $finalValues);
    $node->save();
   
  }

  /** update eventuel new face to face videos for some nids, because after few weeks the json response 
   * of the cron may miss some previous in coming fights which are still displayed
   * param array $nodes : nodes to updated
   */
  public function updateFaceTofaceOThersFights(array $nodes) {
    foreach ($nodes as $node) {
      $this->updateFace2FaceNode($node);
    }
  }

  /**
   * Supprime les nodes "a_venir" expirés depuis plus d'une semaine et non traités.
   */
  function removeExpiredFights() {
      // Timestamp courant
      $today = \Drupal::time()->getCurrentTime();

      // Timestamp d'il y a 1 semaine
      $one_week_ago = $today - 7 * 24 * 60 * 60;

      // Construire la query
      $query = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
          ->condition('type', 'a_venir')
          ->accessCheck(FALSE)
          ->condition('status', 1) // nodes publiés
          ->condition('field_date.value', date('Y-m-d H:i:s', $one_week_ago), '<') // expirés depuis > 1 semaine
          ->sort('field_date', 'ASC');

      $nids = $query->execute();

      if (empty($nids)) {
          return [];
      }

      // Charger les nodes
      $nodes = Node::loadMultiple($nids);

      // Supprimer chaque node
      foreach ($nodes as $node) {
          $node->delete();
          \Drupal::logger('gemini_nextcombats_queue')->info('Node expiré depuis plus d\'une semaine supprimé : @nid', ['@nid' => $node->id()]);
      }

      return $nodes; // retourne les nodes supprimés si besoin
  }



  /**
   * Récupère les nodes "a_venir" non expirés et non encore traités.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  function getUpcomingNodesUnprocessed() {
    $today = \Drupal::time()->getCurrentTime(); // timestamp courant

    // Récupérer les NID déjà traités depuis le state
    $processed_nids = \Drupal::state()->get('processed_combat_nids', []);

    // Construire la query
    $query = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->condition('type', 'a_venir')
      ->accessCheck(FALSE)
      ->condition('status', 1) // nodes publiés
      ->condition('field_date.value', date('Y-m-d H:i:s', $today), '>=')
      ->sort('field_date', 'ASC');

    // Exclure les NID déjà traités
    if (!empty($processed_nids)) {
      $query->condition('nid', $processed_nids, 'NOT IN');
    }

    $nids = $query->execute();

    if (empty($nids)) {
      return [];
    }

    // Charger les nodes
    return Node::loadMultiple($nids);
  }

}
