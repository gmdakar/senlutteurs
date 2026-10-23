<?php

namespace Drupal\combats_manquants\Plugin\QueueWorker;

use Drupal\shared_lib\Plugin\QueueWorker\BaseCombatQueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\shared_lib\Tools\SomeTools;

/**
 * Traite une par une les propositions de combat soumises par des internautes
 * anonymes (formulaire CombatManquantForm), mises en queue APRÈS que la
 * vérification de doublon synchrone a déjà été passée avec succès dans
 * CombatManquantForm::validateForm().
 *
 * Ici, le traitement est entièrement asynchrone (cron) :
 *  1. Rechargement défensif des 2 athlètes (le NID a pu devenir invalide
 *     entre-temps) + nouvelle vérification de doublon (le combat a pu être
 *     ajouté par ailleurs entre la soumission et le passage du cron).
 *  2. Interrogation de l'IA (sl_live_search) via un prompt configurable
 *     (combats_manquants.settings:prompt_confirmation_combat), qui confirme
 *     l'existence du combat au moins sur l'année indiquée ET, si le verdict
 *     internaute est erroné, renvoie le verdict réel à enregistrer.
 *  3. Si confirmé : insertion automatique du node "confrontation" avec le
 *     verdict CORRIGÉ par l'IA lorsqu'il est interprétable (sinon conservation
 *     du verdict internaute), avec un log dédié utilisant logger->error()
 *     (volontairement, pour que l'insertion automatique issue d'un internaute
 *     soit très visible dans les logs, même si ce n'est pas une erreur à
 *     proprement parler).
 *
 * Aucun retour n'est jamais donné à l'internaute sur l'issue (confirmé ou
 * non) : le formulaire l'a déjà remercié et informé au moment de la
 * soumission (voir CombatManquantForm::ajaxSubmit()).
 *
 * @QueueWorker(
 *   id = "combats_manquants_ai_queue",
 *   title = @Translation("Confirmation IA des combats proposés par les internautes"),
 *   cron = {"time" = 120}
 * )
 */
class CombatsManquantsAiQueue extends BaseCombatQueueWorker implements \Drupal\Core\Plugin\ContainerFactoryPluginInterface {

  /**
   * Nombre max de tentatives pour l'appel IA (sl_live_search) : 1 appel
   * initial + 1 nouvelle tentative après pause en cas d'échec technique
   * (timeout, panne réseau/API, réponse vide/invalide).
   */
  protected const AI_CALL_MAX_ATTEMPTS = 2;

  /**
   * Pause (en secondes) avant de rejouer l'appel IA après un échec
   * technique du premier essai.
   */
  protected const AI_CALL_RETRY_DELAY_SECONDS = 10;

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
   * @param array $data
   *   ['annee' (int), 'lutteur_1_nid' (int), 'lutteur_2_nid' (int),
   *    'verdict_key' (string : victoire_1|victoire_2|nul|litige),
   *    'commentaire' (string), 'submitted' (int timestamp)]
   */
  public function processItem($data) {
    if (empty($data['lutteur_1_nid']) || empty($data['lutteur_2_nid']) || empty($data['annee']) || empty($data['verdict_key'])) {
      $this->logger->warning('CombatsManquantsAiQueue : item incomplet, ignoré. Contenu : @data', [
        '@data' => print_r($data, TRUE),
      ]);
      return;
    }

    $storage = $this->entityTypeManager->getStorage('node');

    // Rechargement défensif : le NID a pu être fusionné/supprimé/dépublié
    // entre la soumission du formulaire et le passage du cron.
    $lutteur1Node = $storage->load($data['lutteur_1_nid']);
    $lutteur2Node = $storage->load($data['lutteur_2_nid']);

    if (!$lutteur1Node || !$lutteur1Node->isPublished() || !$lutteur2Node || !$lutteur2Node->isPublished()) {
      $this->logger->notice('CombatsManquantsAiQueue : un des 2 athlètes (nid @n1 / @n2) n\'existe plus ou n\'est plus publié - proposition ignorée.', [
        '@n1' => $data['lutteur_1_nid'],
        '@n2' => $data['lutteur_2_nid'],
      ]);
      return;
    }

    $labelLutteur1 = (string) $lutteur1Node->label();
    $labelLutteur2 = (string) $lutteur2Node->label();
    $ecurieLutteur1 = $this->getEcurieLabel($lutteur1Node);
    $ecurieLutteur2 = $this->getEcurieLabel($lutteur2Node);

    // Nouvelle vérification de doublon (le combat a pu être ajouté par un
    // autre moyen - ou une autre proposition confirmée entre-temps - depuis
    // la soumission du formulaire).
    if ($this->confrontationExistsForYear((int) $data['annee'], $lutteur1Node->id(), $lutteur2Node->id(), $storage)) {
      $this->logger->notice('CombatsManquantsAiQueue : combat @l1 vs @l2 (@annee) déjà présent en base entre-temps - proposition ignorée.', [
        '@l1' => $labelLutteur1,
        '@l2' => $labelLutteur2,
        '@annee' => $data['annee'],
      ]);
      return;
    }

    $verdictInternaute = $this->resolveVerdictLabel($data['verdict_key'], $labelLutteur1, $labelLutteur2);

    $prompt = $this->buildPrompt($data, $labelLutteur1, $labelLutteur2, $ecurieLutteur1, $ecurieLutteur2, $verdictInternaute);
    if (trim($prompt) === '') {
      $this->logger->warning('CombatsManquantsAiQueue : prompt_confirmation_combat non configuré (voir /admin/config/content/combats-manquants) - proposition ignorée.');
      return;
    }

    $this->logger->notice('Prompt de confirmation combat internaute envoyé (@l1 vs @l2, @annee) : @prompt', [
      '@l1' => $labelLutteur1,
      '@l2' => $labelLutteur2,
      '@annee' => $data['annee'],
      '@prompt' => $prompt,
    ]);

    $response = $this->callLiveSearchWithRetry($prompt, $labelLutteur1, $labelLutteur2, $data['annee']);

    $verdictIa = SomeTools::decodeJson($response['content'], __METHOD__);

    if (!is_array($verdictIa) || !array_key_exists('confirme', $verdictIa)) {
      $this->logger->warning('CombatsManquantsAiQueue : réponse IA invalide/vide pour @l1 vs @l2 (@annee) - proposition NON ajoutée. Contenu brut : @raw', [
        '@l1' => $labelLutteur1,
        '@l2' => $labelLutteur2,
        '@annee' => $data['annee'],
        '@raw' => $response['content'] ?? '',
      ]);
      return;
    }

    if (empty($verdictIa['confirme'])) {
      $this->logger->notice('CombatsManquantsAiQueue : combat proposé par un internaute NON confirmé par l\'IA - @l1 vs @l2 (@annee). Commentaire IA : @commentaire', [
        '@l1' => $labelLutteur1,
        '@l2' => $labelLutteur2,
        '@annee' => $data['annee'],
        '@commentaire' => $verdictIa['commentaire'] ?? '',
      ]);
      return;
    }

    // Confirmé par l'IA : dernière vérification anti-doublon (paranoïa,
    // course possible pendant l'appel IA lui-même, qui peut prendre
    // plusieurs secondes) avant toute insertion.
    if ($this->confrontationExistsForYear((int) $data['annee'], $lutteur1Node->id(), $lutteur2Node->id(), $storage)) {
      $this->logger->notice('CombatsManquantsAiQueue : combat @l1 vs @l2 (@annee) ajouté par ailleurs pendant l\'appel IA - insertion annulée pour éviter un doublon.', [
        '@l1' => $labelLutteur1,
        '@l2' => $labelLutteur2,
        '@annee' => $data['annee'],
      ]);
      return;
    }

    $finalDate = $this->resolveFinalDate($verdictIa['date_combat'] ?? NULL, (int) $data['annee']);
    $verdictFinal = $this->resolveFinalVerdict(
      $verdictIa['verdict_ia'] ?? NULL,
      $verdictInternaute,
      $labelLutteur1,
      $labelLutteur2
    );

    $technicalNames = [
      $this->technicalWrestlerName($labelLutteur1),
      $this->technicalWrestlerName($labelLutteur2),
    ];
    sort($technicalNames);
    $fightId = $technicalNames[0] . '_' . $technicalNames[1] . '_' . str_replace('-', '', $finalDate);

    if ($this->combatExists($fightId, 'confrontation')) {
      $this->logger->notice('CombatsManquantsAiQueue : combat @id déjà existant (field_id_text) - insertion annulée.', ['@id' => $fightId]);
      return;
    }

    $confrontation = Node::create([
      'type' => 'confrontation',
      'title' => $this->t('@w1 vs @w2', ['@w1' => $labelLutteur1, '@w2' => $labelLutteur2]),
      'field_id_text' => $fightId,
      'field_athlete_1' => ['target_id' => $lutteur1Node->id()],
      'field_athlete_2' => ['target_id' => $lutteur2Node->id()],
      'field_vainqueur' => $verdictFinal,
      'field_confrontation_date' => $finalDate,
      'field_is_lutte_simple' => FALSE,
    ]);

    try {
      $confrontation->save();
    }
    catch (\RuntimeException $e) {
      $this->logger->notice('CombatsManquantsAiQueue : doublon intercepté à la sauvegarde pour @id : @msg', [
        '@id' => $fightId,
        '@msg' => $e->getMessage(),
      ]);
      return;
    }

    // Log volontairement en error() (sur demande explicite), bien qu'il ne
    // s'agisse PAS d'une erreur : une insertion automatique déclenchée par
    // la proposition d'un internaute doit rester très visible dans les
    // logs pour permettre une relecture/contrôle a posteriori facile.
    $this->logger->error('Combat proposé par un internaute CONFIRMÉ par l\'IA et inséré automatiquement : NID @nid "@title" (@date). Verdict internaute : @verdict_user. Verdict enregistré : @verdict. Verdict IA brut : @verdict_ia. Commentaire IA : @commentaire.', [
      '@nid' => $confrontation->id(),
      '@title' => $confrontation->getTitle(),
      '@date' => $finalDate,
      '@verdict_user' => $verdictInternaute,
      '@verdict' => $verdictFinal,
      '@verdict_ia' => $verdictIa['verdict_ia'] ?? '(non fourni)',
      '@commentaire' => $verdictIa['commentaire'] ?? '',
    ]);

    // Mise à jour du statut "roi" (King Status) si le verdict désigne un
    // vainqueur clair, comme pour les combats insérés par les autres
    // pipelines du site.
    $winner = NULL;
    $loser = NULL;
    if ($verdictFinal === $labelLutteur1) {
      $winner = $lutteur1Node;
      $loser = $lutteur2Node;
    }
    elseif ($verdictFinal === $labelLutteur2) {
      $winner = $lutteur2Node;
      $loser = $lutteur1Node;
    }
    if ($winner && $loser) {
      SomeTools::updateKingStatus($winner, $loser);
    }
  }

  /**
   * Récupère le libellé de l'écurie d'un node athlète, ou '' si absente.
   */
  protected function getEcurieLabel($athleteNode): string {
    if ($athleteNode->hasField('field_ecurie') && !$athleteNode->get('field_ecurie')->isEmpty()) {
      $ecurieEntity = $athleteNode->get('field_ecurie')->entity;
      if ($ecurieEntity) {
        return (string) $ecurieEntity->label();
      }
    }
    return '';
  }

  /**
   * Résout la clé de verdict soumise par l'internaute (victoire_1,
   * victoire_2, nul, litige) en son libellé RÉEL et canonique, en se basant
   * sur les labels d'athlètes à jour (et non sur ce qui était affiché dans
   * le formulaire au moment de la saisie).
   */
  protected function resolveVerdictLabel(string $verdictKey, string $labelLutteur1, string $labelLutteur2): string {
    switch ($verdictKey) {
      case 'victoire_1':
        return $labelLutteur1;

      case 'victoire_2':
        return $labelLutteur2;

      case 'nul':
        return (string) $this->t('Nul');

      case 'litige':
      default:
        return (string) $this->t('Litige (sans verdict)');
    }
  }

  /**
   * Choisit le verdict à enregistrer : celui de l'IA s'il est interprétable
   * (vainqueur = un des 2 lutteurs, Nul, ou Litige), sinon celui de
   * l'internaute. C'est ici que le verdict erroné de l'internaute est
   * corrigé.
   */
  protected function resolveFinalVerdict($verdictIaRaw, string $verdictInternaute, string $labelLutteur1, string $labelLutteur2): string {
    if ($verdictIaRaw === NULL) {
      return $verdictInternaute;
    }
    $raw = trim((string) $verdictIaRaw);
    if ($raw === '' || strcasecmp($raw, 'null') === 0) {
      return $verdictInternaute;
    }

    $normalized = $this->normalizeVerdictString($raw);
    $normalizedLower = mb_strtolower($normalized);

    if ($this->looksLikeNul($normalizedLower)) {
      $corrected = (string) $this->t('Nul');
      $this->logVerdictCorrection($verdictInternaute, $corrected, $raw);
      return $corrected;
    }
    if ($this->looksLikeLitige($normalizedLower)) {
      $corrected = (string) $this->t('Litige (sans verdict)');
      $this->logVerdictCorrection($verdictInternaute, $corrected, $raw);
      return $corrected;
    }

    $techIa = $this->technicalWrestlerName($normalized);
    $tech1 = $this->technicalWrestlerName($labelLutteur1);
    $tech2 = $this->technicalWrestlerName($labelLutteur2);

    if ($techIa !== '' && $techIa === $tech1) {
      $this->logVerdictCorrection($verdictInternaute, $labelLutteur1, $raw);
      return $labelLutteur1;
    }
    if ($techIa !== '' && $techIa === $tech2) {
      $this->logVerdictCorrection($verdictInternaute, $labelLutteur2, $raw);
      return $labelLutteur2;
    }

    $this->logger->warning('CombatsManquantsAiQueue : verdict_ia "@raw" non interprétable (attendu : nom d\'un des 2 lutteurs, Nul ou Litige) - conservation du verdict internaute "@user".', [
      '@raw' => $raw,
      '@user' => $verdictInternaute,
    ]);
    return $verdictInternaute;
  }

  /**
   * Nettoie un verdict IA libre ("Victoire de X", guillemets, etc.).
   */
  protected function normalizeVerdictString(string $raw): string {
    $s = trim($raw, " \t\n\r\"'");
    $s = preg_replace('/^(victoire\s+de|vainqueur\s*:?|gagnant\s*:?|winner\s*:?)\s+/iu', '', $s);
    return trim((string) $s);
  }

  protected function looksLikeNul(string $normalizedLower): bool {
    return $normalizedLower === 'nul'
      || $normalizedLower === 'nulle'
      || $normalizedLower === 'match nul'
      || $normalizedLower === 'draw'
      || $normalizedLower === 'égalité'
      || $normalizedLower === 'egalite';
  }

  protected function looksLikeLitige(string $normalizedLower): bool {
    return $normalizedLower === 'litige'
      || $normalizedLower === 'litige (sans verdict)'
      || $normalizedLower === 'sans verdict'
      || $normalizedLower === 'no contest'
      || str_contains($normalizedLower, 'litige');
  }

  protected function logVerdictCorrection(string $from, string $to, string $raw): void {
    if ($from === $to) {
      return;
    }
    $this->logger->notice('CombatsManquantsAiQueue : verdict internaute "@from" corrigé en "@to" (verdict_ia brut : "@raw").', [
      '@from' => $from,
      '@to' => $to,
      '@raw' => $raw,
    ]);
  }

  /**
   * Vérifie si un combat "confrontation" existe déjà entre les 2 athlètes
   * donnés (peu importe l'ordre field_athlete_1/field_athlete_2) sur
   * l'année indiquée (jour/mois ignorés).
   */
  protected function confrontationExistsForYear(int $annee, $athlete1Id, $athlete2Id, $storage): bool {
    $yearStart = $annee . '-01-01';
    $yearEnd = $annee . '-12-31';

    $query = $storage->getQuery()
      ->condition('type', 'confrontation')
      ->condition('field_confrontation_date', $yearStart, '>=')
      ->condition('field_confrontation_date', $yearEnd, '<=')
      ->accessCheck(FALSE)
      ->range(0, 1);
    $pairGroup = $query->orConditionGroup()
      ->condition($query->andConditionGroup()
        ->condition('field_athlete_1', $athlete1Id)
        ->condition('field_athlete_2', $athlete2Id))
      ->condition($query->andConditionGroup()
        ->condition('field_athlete_1', $athlete2Id)
        ->condition('field_athlete_2', $athlete1Id));
    $existing = $query->condition($pairGroup)->execute();

    return !empty($existing);
  }

  /**
   * Détermine la date finale (AAAA-MM-JJ) à enregistrer, à partir de ce que
   * l'IA a réellement pu déterminer, avec une précision décroissante :
   *  - date complète connue (AAAA-MM-JJ, valide ET dans l'année proposée)
   *    => utilisée telle quelle ;
   *  - seulement le mois connu (AAAA-MM, valide ET dans l'année proposée)
   *    => 1er jour de ce mois (AAAA-MM-01) ;
   *  - rien de précis (ou incohérent avec l'année proposée) => 1er janvier
   *    de l'année indiquée (AAAA-01-01), en attendant une éventuelle
   *    rectification manuelle ultérieure - le pipeline de déduplication IA
   *    existant sait déjà rectifier des dates approximatives.
   */
  protected function resolveFinalDate(?string $dateCombatIa, int $annee): string {
    $dateCombatIa = $dateCombatIa !== NULL ? trim($dateCombatIa) : '';

    if ($dateCombatIa !== '') {
      // Cas 1 : date complète AAAA-MM-JJ.
      if ($this->isValidYmdDate($dateCombatIa)) {
        $iaYear = (int) substr($dateCombatIa, 0, 4);
        if ($iaYear === $annee) {
          return $dateCombatIa;
        }
        $this->logger->warning('CombatsManquantsAiQueue : date complète IA @date hors de l\'année proposée (@annee) - ignorée, repli sur AAAA-01-01.', [
          '@date' => $dateCombatIa,
          '@annee' => $annee,
        ]);
      }
      // Cas 2 : mois + année seulement, AAAA-MM.
      elseif ($this->isValidYearMonth($dateCombatIa)) {
        [$iaYear, $iaMonth] = array_map('intval', explode('-', $dateCombatIa));
        if ($iaYear === $annee) {
          return sprintf('%04d-%02d-01', $iaYear, $iaMonth);
        }
        $this->logger->warning('CombatsManquantsAiQueue : mois/année IA @date hors de l\'année proposée (@annee) - ignoré, repli sur AAAA-01-01.', [
          '@date' => $dateCombatIa,
          '@annee' => $annee,
        ]);
      }
      else {
        $this->logger->warning('CombatsManquantsAiQueue : date_combat IA "@date" dans un format inattendu (ni AAAA-MM-JJ, ni AAAA-MM) - ignorée, repli sur AAAA-01-01.', [
          '@date' => $dateCombatIa,
        ]);
      }
    }

    // Cas 3 (défaut) : seule l'année est connue/confirmée.
    return $annee . '-01-01';
  }

  /**
   * Valide strictement qu'une chaîne est une date réelle au format
   * AAAA-MM-JJ.
   */
  protected function isValidYmdDate(string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      return FALSE;
    }
    [$year, $month, $day] = array_map('intval', explode('-', $date));
    return checkdate($month, $day, $year);
  }

  /**
   * Valide strictement qu'une chaîne est un mois/année réel au format
   * AAAA-MM (jour inconnu).
   */
  protected function isValidYearMonth(string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}$/', $date)) {
      return FALSE;
    }
    [$year, $month] = array_map('intval', explode('-', $date));
    return $month >= 1 && $month <= 12 && $year >= 1900 && $year <= 2200;
  }

  /**
   * Construit le prompt à partir du template configurable
   * combats_manquants.settings:prompt_confirmation_combat.
   * Placeholders : @date_combat, @lutteur_1, @ecurie_1, @lutteur_2,
   * @ecurie_2, @verdict, @commentaire.
   */
  protected function buildPrompt(array $data, string $labelLutteur1, string $labelLutteur2, string $ecurieLutteur1, string $ecurieLutteur2, string $verdictLabel): string {
    $promptTemplate = $this->configFactory
      ->get('combats_manquants.settings')
      ->get('prompt_confirmation_combat') ?? '';

    if (trim($promptTemplate) === '') {
      return '';
    }

    return strtr($promptTemplate, [
      '@date_combat' => (string) $data['annee'],
      '@lutteur_1' => $labelLutteur1,
      '@ecurie_1' => $ecurieLutteur1 !== '' ? $ecurieLutteur1 : 'inconnue',
      '@lutteur_2' => $labelLutteur2,
      '@ecurie_2' => $ecurieLutteur2 !== '' ? $ecurieLutteur2 : 'inconnue',
      '@verdict' => $verdictLabel,
      '@commentaire' => $data['commentaire'] ?? '',
    ]);
  }

  /**
   * Appelle sl_live_search avec rattrapage automatique en cas d'échec
   * technique (timeout, panne réseau/API, réponse vide) : 1 appel initial
   * puis, en cas d'échec, 1 seule nouvelle tentative après une pause de
   * self::AI_CALL_RETRY_DELAY_SECONDS.
   *
   * @throws \Drupal\Core\Queue\SuspendQueueException
   *   Si l'appel échoue techniquement sur TOUTES les tentatives : ce n'est
   *   PAS un "non confirmé", mais une panne technique - l'item n'est donc
   *   pas perdu et sera retraité automatiquement au prochain cron.
   */
  protected function callLiveSearchWithRetry(string $prompt, string $labelLutteur1, string $labelLutteur2, $annee): array {
    $lastError = 'inconnue';

    for ($attempt = 1; $attempt <= self::AI_CALL_MAX_ATTEMPTS; $attempt++) {
      if ($attempt > 1) {
        $this->logger->warning('Appel IA (confirmation combat internaute) : nouvelle tentative @attempt/@max après pause de @delay s (@l1 vs @l2, @annee). Erreur précédente : @err', [
          '@attempt' => $attempt,
          '@max' => self::AI_CALL_MAX_ATTEMPTS,
          '@delay' => self::AI_CALL_RETRY_DELAY_SECONDS,
          '@l1' => $labelLutteur1,
          '@l2' => $labelLutteur2,
          '@annee' => $annee,
          '@err' => $lastError,
        ]);
        sleep(self::AI_CALL_RETRY_DELAY_SECONDS);
      }

      /** @var \Drupal\sl_live_search\Service\LiveSearchService $liveSearch */
      $liveSearch = \Drupal::service('sl_live_search.live_search_service');
      $response = $liveSearch->liveSearch($prompt);

      if (!empty($response['success']) && !empty($response['content'])) {
        return $response;
      }

      $lastError = $response['error'] ?? 'inconnue';
      $this->logger->warning('Appel IA (confirmation combat internaute) échec/vide (tentative @attempt/@max) - @l1 vs @l2, @annee. Erreur : @err', [
        '@attempt' => $attempt,
        '@max' => self::AI_CALL_MAX_ATTEMPTS,
        '@l1' => $labelLutteur1,
        '@l2' => $labelLutteur2,
        '@annee' => $annee,
        '@err' => $lastError,
      ]);
    }

    $this->logger->warning('Appel IA (confirmation combat internaute) en échec après @max tentative(s) - @l1 vs @l2, @annee - item retraité au prochain cron. Dernière erreur : @err', [
      '@max' => self::AI_CALL_MAX_ATTEMPTS,
      '@l1' => $labelLutteur1,
      '@l2' => $labelLutteur2,
      '@annee' => $annee,
      '@err' => $lastError,
    ]);

    throw new SuspendQueueException(
      'Appel IA (confirmation combat internaute) en échec après ' . self::AI_CALL_MAX_ATTEMPTS . ' tentative(s) : ' . $lastError
    );
  }

}
