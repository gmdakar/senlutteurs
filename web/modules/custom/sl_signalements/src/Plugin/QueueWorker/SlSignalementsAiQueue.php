<?php

namespace Drupal\sl_signalements\Plugin\QueueWorker;

use Drupal\shared_lib\Plugin\QueueWorker\BaseCombatQueueWorker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\node\Entity\Node;
use Drupal\shared_lib\Tools\SomeTools;

/**
 * Traite un par un les signalements soumis via SignalementApiController::receive()
 * pour les utilisateurs NON bypass (anonymes, sans force_apply) : depuis que
 * le seuil configuré (misc_divers.settings:max_nb_signalements) ne déclenche
 * plus directement l'action, CHAQUE signalement de ce type doit être validé
 * par l'IA avant application effective, à l'image de
 * combats_manquants_ai_queue.
 *
 * Le rôle admin (is_admin dans SignalementApiController) continue d'exécuter
 * l'action immédiatement, sans jamais transiter par cette queue.
 *
 * 1. Rechargement défensif du node (a pu être supprimé/modifié entre-temps).
 * 2. Interrogation de l'IA (sl_live_search) via un prompt configurable
 *    (sl_signalements.settings:prompt_validation_signalement).
 * 3. Si confirmé : application de la règle métier via
 *    SToolsActions::appliquerReglesMetier() (même logique que le chemin
 *    bypass), avec un log dédié utilisant logger->error() (volontairement,
 *    pour que toute action automatique déclenchée par un internaute soit
 *    très visible dans les logs, même si ce n'est pas une erreur à
 *    proprement parler - convention déjà en place dans ce module et dans
 *    combats_manquants).
 * 4. Que l'IA confirme ou non, le marqueur "pending" (State API) posé par le
 *    contrôleur au moment de la mise en queue est levé à la fin du
 *    traitement - SAUF en cas d'échec technique de l'appel IA
 *    (SuspendQueueException), afin de ne pas perdre le signalement : il sera
 *    rejoué au prochain cron avec le marqueur toujours actif.
 *
 * Aucun retour n'est jamais donné à l'internaute sur l'issue (confirmé ou
 * non) : l'API a déjà répondu "en cours d'analyse" au moment de la
 * soumission (voir SignalementApiController::receive()).
 *
 * @QueueWorker(
 *   id = "sl_signalements_ai_queue",
 *   title = @Translation("Validation IA des actions de signalement"),
 *   cron = {"time" = 120}
 * )
 */
class SlSignalementsAiQueue extends BaseCombatQueueWorker implements \Drupal\Core\Plugin\ContainerFactoryPluginInterface {

  /**
   * Clé State API sous laquelle est stockée la liste des signalements
   * (nid:type[:variant_nid]) actuellement en attente de traitement par
   * cette queue - évite qu'un même signalement soit remis en queue à
   * chaque nouvel appel API tant qu'il n'a pas été traité.
   */
  protected const PENDING_STATE_KEY = 'sl_signalements.ai_queue.pending';

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

  /**
   * Libellés (en français) de l'action demandée, par type de signalement.
   * @see SToolsActions::appliquerReglesMetier()
   */
  protected const ACTION_LABELS = [
    's_nul' => 'Changer le verdict de ce combat en "Nul" (match nul réel, résultat d\'égalité officiellement reconnu - PAS un litige/contestation).',
    's_litige' => 'Changer le verdict de ce combat en "Litige (sans verdict)" (issue contestée/non tranchée, absence de vainqueur désigné - PAS un match nul).',
    's_lutte_simple' => 'Marquer ce combat comme lutte simple (sans frappe), et non lutte avec frappe.',
    's_lutte_frappe' => 'Marquer ce combat comme lutte avec frappe, et non lutte simple.',
    's_vainqueur_lutteur1' => 'Attribuer la victoire de ce combat à @detail_athlete.',
    's_vainqueur_lutteur2' => 'Attribuer la victoire de ce combat à @detail_athlete.',
    's_combat_doublon' => 'Supprimer ce combat, signalé comme doublon d\'un autre combat déjà enregistré pour @detail_athlete.',
    's_combat_fantome' => 'Supprimer ce combat, signalé comme n\'ayant jamais réellement eu lieu ("combat fantôme").',
    's_variante_athlete_1' => 'Remplacer le 1er athlète de ce combat par la variante de nom suivante : @detail_athlete.',
    's_variante_athlete_2' => 'Remplacer le 2e athlète de ce combat par la variante de nom suivante : @detail_athlete.',
  ];

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
   *   ['nid' (int), 'type' (string), 'athlete_name' (?string),
   *    'lutteur_nid' (?int), 'variant_nid' (?int),
   *    'submitted' (int timestamp)]
   */
  public function processItem($data) {
    if (empty($data['nid']) || empty($data['type'])) {
      $this->logger->warning('SlSignalementsAiQueue : item incomplet, ignoré. Contenu : @data', [
        '@data' => $this->escapeForLog(print_r($data, TRUE)),
      ]);
      return;
    }

    $nid = (int) $data['nid'];
    $type = (string) $data['type'];
    $pendingKey = $this->buildPendingKey($nid, $type, $data['variant_nid'] ?? NULL);

    $storage = $this->entityTypeManager->getStorage('node');

    // Rechargement défensif : le combat a pu être supprimé/modifié entre la
    // soumission du signalement et le passage du cron.
    $node = $storage->load($nid);

    if (!$node || $node->bundle() !== 'confrontation') {
      $this->logger->notice('SlSignalementsAiQueue : combat @nid introuvable entre-temps - signalement (@type) ignoré.', [
        '@nid' => $nid,
        '@type' => $type,
      ]);
      $this->clearPending($pendingKey);
      return;
    }

    $labelLutteur1 = $node->hasField('field_athlete_1') && !$node->get('field_athlete_1')->isEmpty()
      ? (string) $node->get('field_athlete_1')->entity?->label()
      : 'inconnu';
    $labelLutteur2 = $node->hasField('field_athlete_2') && !$node->get('field_athlete_2')->isEmpty()
      ? (string) $node->get('field_athlete_2')->entity?->label()
      : 'inconnu';
    $verdictActuel = (string) ($node->get('field_vainqueur')->value ?? '');
    $dateCombat = (string) ($node->get('field_confrontation_date')->value ?? 'inconnue');

    $actionDemandee = $this->resolveActionLabel($type, $data);
    $details = $this->resolveDetails($type, $data);

    $prompt = $this->buildPrompt($nid, $labelLutteur1, $labelLutteur2, $dateCombat, $verdictActuel, $actionDemandee, $details);

    if (trim($prompt) === '') {
      $this->logger->warning('SlSignalementsAiQueue : prompt_validation_signalement non configuré (voir /admin/config/content/sl-signalements) - signalement ignoré (@nid, @type).', [
        '@nid' => $nid,
        '@type' => $type,
      ]);
      $this->clearPending($pendingKey);
      return;
    }

    $this->logger->notice('Prompt de validation signalement envoyé (nid @nid, type @type) : @prompt', [
      '@nid' => $nid,
      '@type' => $type,
      '@prompt' => $this->escapeForLog($prompt),
    ]);

    // NE PAS attraper SuspendQueueException ici : en cas d'échec technique,
    // l'item doit être rejoué au prochain cron avec le marqueur "pending"
    // toujours actif (voir callLiveSearchWithRetry()).
    $response = $this->callLiveSearchWithRetry($prompt, $nid, $type);

    $verdictIa = SomeTools::decodeJson($response['content'], __METHOD__);

    if (!is_array($verdictIa) || !array_key_exists('confirme', $verdictIa)) {
      $this->logger->warning('SlSignalementsAiQueue : réponse IA invalide/vide pour le signalement nid @nid / type @type - action NON appliquée. Contenu brut : @raw', [
        '@nid' => $nid,
        '@type' => $type,
        '@raw' => $this->escapeForLog((string) ($response['content'] ?? '')),
      ]);
      $this->clearPending($pendingKey);
      return;
    }

    $commentaireIa = (string) ($verdictIa['commentaire'] ?? '');
    $confirme = !empty($verdictIa['confirme']);

    // Corrections collatérales (date / verdict / adversaire) : valables que confirme
    // soit true ou false. Appliquées AVANT la règle métier (qui peut
    // supprimer le node en cas de combat fantôme).
    $this->applyUpdateDate($node, $verdictIa['update_date'] ?? NULL, $type, $commentaireIa);
    $this->applyUpdateVerdict($node, $verdictIa['update_verdict'] ?? NULL, $type, $commentaireIa);
    $this->applyUpdateAdversaire($node, $verdictIa['update_adversaire'] ?? NULL, $type, $commentaireIa);

    if (!$confirme) {
      $this->logger->notice('SlSignalementsAiQueue : signalement NON confirmé par l\'IA - nid @nid, type @type. Commentaire IA : @commentaire', [
        '@nid' => $nid,
        '@type' => $type,
        '@commentaire' => $this->escapeForLog($commentaireIa),
      ]);
      $this->clearPending($pendingKey);
      return;
    }

    // Confirmé par l'IA : application effective de la règle métier, comme
    // pour le chemin "bypass" (admin / connecté / force_apply).
    /** @var \Drupal\sl_signalements\Tools\SToolsActions $tools */
    $tools = \Drupal::service('sl_signalements.tools_actions');

    $result = $tools->appliquerReglesMetier(
      $node,
      $type,
      $data['athlete_name'] ?? NULL,
      isset($data['lutteur_nid']) ? (int) $data['lutteur_nid'] : NULL,
      isset($data['variant_nid']) ? (int) $data['variant_nid'] : NULL
    );

    // Log volontairement en error() : toute action automatique déclenchée
    // par un signalement anonyme doit rester très visible dans les logs
    // (convention déjà en place dans SignalementApiController::receive()
    // et dans combats_manquants).
    $this->logger->error(
      "SIGNALEMENT Action (validée IA) | type=@type | nid=@nid | title=@title | athlete_name=@athlete_name | lutteur_nid=@lutteur_nid | variant_nid=@variant_nid | commentaire_ia=@commentaire_ia",
      [
        '@type' => $type,
        '@nid' => $node->id(),
        '@title' => $node->getTitle(),
        '@athlete_name' => $data['athlete_name'] ?? '-',
        '@lutteur_nid' => $data['lutteur_nid'] ?? '-',
        '@variant_nid' => $data['variant_nid'] ?? '-',
        '@commentaire_ia' => $this->escapeForLog($commentaireIa),
      ]
    );

    if ($result !== FALSE) {
      $node->setNewRevision(FALSE);
      $node->save();
    }

    $this->clearPending($pendingKey);
  }

  /**
   * Construit le libellé (en français) de l'action demandée par le
   * signalement, à insérer dans le prompt IA (@action_demandee).
   */
  protected function resolveActionLabel(string $type, array $data): string {
    $template = self::ACTION_LABELS[$type] ?? ('Action demandée : ' . $type . '.');
    return strtr($template, ['@detail_athlete' => $this->resolveDetailAthlete($type, $data)]);
  }

  /**
   * Résout le nom de l'athlète concerné par le détail de l'action
   * (vainqueur désigné, athlète dont on signale le doublon, variante de nom
   * proposée), pour insertion dans le libellé de l'action et dans @details.
   */
  protected function resolveDetailAthlete(string $type, array $data): string {
    switch ($type) {
      case 's_vainqueur_lutteur1':
      case 's_vainqueur_lutteur2':
        return !empty($data['athlete_name']) ? (string) $data['athlete_name'] : 'un athlète non précisé';

      case 's_combat_doublon':
        if (!empty($data['lutteur_nid'])) {
          $lutteur = Node::load((int) $data['lutteur_nid']);
          if ($lutteur) {
            return (string) $lutteur->label();
          }
        }
        return 'un athlète non précisé';

      case 's_variante_athlete_1':
      case 's_variante_athlete_2':
        if (!empty($data['variant_nid'])) {
          $variant = Node::load((int) $data['variant_nid']);
          if ($variant) {
            return (string) $variant->label();
          }
        }
        return 'une variante non précisée';

      default:
        return '';
    }
  }

  /**
   * Détails complémentaires (@details) insérés dans le prompt, en plus du
   * libellé de l'action elle-même (ex. commentaire libre de l'internaute
   * pour un combat fantôme/doublon, s'il existe un champ dédié à l'avenir).
   * Vide pour l'instant, gardé comme point d'extension.
   */
  protected function resolveDetails(string $type, array $data): string {
    return '';
  }

  /**
   * Construit le prompt à partir du template configurable
   * sl_signalements.settings:prompt_validation_signalement.
   */
  protected function buildPrompt(int $nid, string $labelLutteur1, string $labelLutteur2, string $dateCombat, string $verdictActuel, string $actionDemandee, string $details): string {
    // Toujours relire la config (évite prompt obsolète en cache Redis / même process cron).
    $this->configFactory->reset('sl_signalements.settings');
    $promptTemplate = $this->configFactory
      ->get('sl_signalements.settings')
      ->get('prompt_validation_signalement') ?? '';

    if (trim($promptTemplate) === '') {
      return '';
    }

    $verdictLabel = $verdictActuel !== '' ? $verdictActuel : 'non renseigné';
    $affiche = trim($labelLutteur1 . ' vs ' . $labelLutteur2);

    return strtr($promptTemplate, [
      '@nid' => (string) $nid,
      '@lutteur_1' => $labelLutteur1,
      '@lutteur_2' => $labelLutteur2,
      // Affiche = "Lutteur1 vs Lutteur2" (placeholder utilisé dans le prompt).
      '@affiche' => $affiche,
      '@date_combat' => $dateCombat,
      // Alias : le prompt utilise @verdict ; on garde aussi @verdict_actuel.
      '@verdict' => $verdictLabel,
      '@verdict_actuel' => $verdictLabel,
      '@action_demandee' => $actionDemandee,
      '@details' => $details,
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
   *   pas perdu et sera retraité automatiquement au prochain cron, avec le
   *   marqueur "pending" toujours actif.
   */
  protected function callLiveSearchWithRetry(string $prompt, int $nid, string $type): array {
    $lastError = 'inconnue';

    for ($attempt = 1; $attempt <= self::AI_CALL_MAX_ATTEMPTS; $attempt++) {
      if ($attempt > 1) {
        $this->logger->warning('Appel IA (validation signalement) : nouvelle tentative @attempt/@max après pause de @delay s (nid @nid, type @type). Erreur précédente : @err', [
          '@attempt' => $attempt,
          '@max' => self::AI_CALL_MAX_ATTEMPTS,
          '@delay' => self::AI_CALL_RETRY_DELAY_SECONDS,
          '@nid' => $nid,
          '@type' => $type,
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
      $this->logger->warning('Appel IA (validation signalement) échec/vide (tentative @attempt/@max) - nid @nid, type @type. Erreur : @err', [
        '@attempt' => $attempt,
        '@max' => self::AI_CALL_MAX_ATTEMPTS,
        '@nid' => $nid,
        '@type' => $type,
        '@err' => $lastError,
      ]);
    }

    $this->logger->warning('Appel IA (validation signalement) en échec après @max tentative(s) - nid @nid, type @type - item retraité au prochain cron. Dernière erreur : @err', [
      '@max' => self::AI_CALL_MAX_ATTEMPTS,
      '@nid' => $nid,
      '@type' => $type,
      '@err' => $lastError,
    ]);

    throw new SuspendQueueException(
      'Appel IA (validation signalement) en échec après ' . self::AI_CALL_MAX_ATTEMPTS . ' tentative(s) : ' . $lastError
    );
  }

  /**
   * Construit la clé d'identification unique d'un signalement pour le
   * suivi "pending" (State API). Inclut variant_nid pour les types variante,
   * car un même combat peut recevoir 2 signalements de variante distincts
   * (athlète 1 ET athlète 2) simultanément.
   */
  public static function buildPendingKey(int $nid, string $type, ?int $variantNid = NULL): string {
    $key = $nid . ':' . $type;
    if ($variantNid) {
      $key .= ':' . $variantNid;
    }
    return $key;
  }

  /**
   * Indique si un signalement (nid + type [+ variant_nid]) est déjà en
   * attente de traitement par cette queue.
   */
  public static function isPending(string $pendingKey): bool {
    $pending = \Drupal::state()->get(self::PENDING_STATE_KEY, []);
    return !empty($pending[$pendingKey]);
  }

  /**
   * Marque un signalement comme mis en queue, pour éviter qu'il ne soit
   * remis en queue à chaque nouvel appel API tant qu'il n'a pas été traité.
   */
  public static function markPending(string $pendingKey): void {
    $pending = \Drupal::state()->get(self::PENDING_STATE_KEY, []);
    $pending[$pendingKey] = TRUE;
    \Drupal::state()->set(self::PENDING_STATE_KEY, $pending);
  }

  /**
   * Lève le marqueur "pending" une fois le signalement traité (confirmé ou
   * non par l'IA). N'est volontairement PAS appelée en cas d'échec
   * technique de l'appel IA (SuspendQueueException) : le marqueur reste
   * actif pour éviter une remise en queue en doublon avant le prochain
   * passage du cron sur cet item déjà en file.
   */
  protected function clearPending(string $pendingKey): void {
    $pending = \Drupal::state()->get(self::PENDING_STATE_KEY, []);
    if (isset($pending[$pendingKey])) {
      unset($pending[$pendingKey]);
      \Drupal::state()->set(self::PENDING_STATE_KEY, $pending);
    }
  }

  /**
   * Applique update_date sur le node si fourni et valide (YYYY-MM-DD).
   */
  protected function applyUpdateDate($node, $rawDate, string $type, string $commentaireIa): void {
    $dateCorrigee = $this->normalizeUpdateDate($rawDate);
    if ($dateCorrigee === NULL || !$node->hasField('field_confrontation_date')) {
      return;
    }

    $ancienne = (string) ($node->get('field_confrontation_date')->value ?? '');
    // Comparer uniquement la partie date (YYYY-MM-DD).
    $ancienneJour = $ancienne !== '' ? substr($ancienne, 0, 10) : '';
    if ($ancienneJour === $dateCorrigee) {
      return;
    }

    $node->set('field_confrontation_date', $dateCorrigee);
    $node->setNewRevision(FALSE);
    $node->save();

    $this->logger->error(
      'SIGNALEMENT Date corrigée via update_date | nid=@nid | title=@title | ancienne=@ancienne | nouvelle=@nouvelle | type_signalement=@type | commentaire_ia=@commentaire',
      [
        '@nid' => $node->id(),
        '@title' => $node->getTitle(),
        '@ancienne' => $ancienneJour !== '' ? $ancienneJour : '-',
        '@nouvelle' => $dateCorrigee,
        '@type' => $type,
        '@commentaire' => $this->escapeForLog($commentaireIa),
      ]
    );
  }

  /**
   * Applique update_verdict sur le node si fourni et reconnu.
   */
  protected function applyUpdateVerdict($node, $rawVerdict, string $type, string $commentaireIa): void {
    $verdictCorrige = $this->normalizeUpdateVerdict($rawVerdict);
    if ($verdictCorrige === NULL || !$node->hasField('field_vainqueur')) {
      return;
    }

    $ancien = (string) ($node->get('field_vainqueur')->value ?? '');
    if ($ancien === $verdictCorrige) {
      return;
    }

    $node->set('field_vainqueur', $verdictCorrige);
    $node->setNewRevision(FALSE);
    $node->save();

    $this->logger->error(
      'SIGNALEMENT Verdict corrigé via update_verdict | nid=@nid | title=@title | ancien=@ancien | nouveau=@nouveau | type_signalement=@type | commentaire_ia=@commentaire',
      [
        '@nid' => $node->id(),
        '@title' => $node->getTitle(),
        '@ancien' => $ancien !== '' ? $ancien : '-',
        '@nouveau' => $verdictCorrige,
        '@type' => $type,
        '@commentaire' => $this->escapeForLog($commentaireIa),
      ]
    );
  }

  /**
   * Normalise update_date (JSON IA) en YYYY-MM-DD.
   * - "YYYY-MM-DD" valide → tel quel
   * - "YYYY" seul → YYYY-01-01 (date de repli)
   * - sinon NULL
   */
  protected function normalizeUpdateDate($raw): ?string {
    if ($raw === NULL || $raw === FALSE || $raw === '') {
      return NULL;
    }
    if (!is_string($raw) && !is_numeric($raw)) {
      return NULL;
    }

    $v = trim((string) $raw);
    if ($v === '') {
      return NULL;
    }

    // Année seule → repli 1er janvier.
    if (preg_match('/^(\d{4})$/', $v, $m)) {
      $year = (int) $m[1];
      if ($year < 1900 || $year > 2100) {
        return NULL;
      }
      return sprintf('%04d-01-01', $year);
    }

    // Date complète YYYY-MM-DD.
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
      $year  = (int) $m[1];
      $month = (int) $m[2];
      $day   = (int) $m[3];
      if ($year < 1900 || $year > 2100 || !checkdate($month, $day, $year)) {
        return NULL;
      }
      return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    return NULL;
  }

  /**
   * Normalise la valeur du champ JSON update_verdict renvoyé par l'IA.
   *
   * Valeurs acceptées (insensible à la casse / variantes) :
   *   - "Litige (sans verdict)" / "litige" / "sans verdict"
   *   - "Nul" / "Match nul" / "match_nul"
   *
   * @return string|null
   *   "Litige (sans verdict)", "Nul", ou NULL si absent / invalide.
   */
  protected function normalizeUpdateVerdict($raw): ?string {
    if ($raw === NULL || $raw === FALSE || $raw === '') {
      return NULL;
    }
    if (!is_string($raw) && !is_numeric($raw)) {
      return NULL;
    }

    $v = trim((string) $raw);
    if ($v === '') {
      return NULL;
    }

    $lower = mb_strtolower($v);

    if (
      $lower === 'nul'
      || $lower === 'match nul'
      || $lower === 'match_nul'
      || $lower === 'matchnul'
    ) {
      return 'Nul';
    }

    if (
      $lower === 'litige (sans verdict)'
      || $lower === 'litige'
      || $lower === 'sans verdict'
      || $lower === 'litige_sans_verdict'
    ) {
      return 'Litige (sans verdict)';
    }

    return NULL;
  }


  /**
   * Applique update_adversaire : remplace le mauvais lutteur par celui nommé par l'IA.
   *
   * Ex. JSON IA : "update_adversaire": "Modou 2"
   */
  protected function applyUpdateAdversaire($node, $rawName, string $type, string $commentaireIa): void {
    if ($rawName === NULL || $rawName === FALSE || $rawName === '') {
      return;
    }
    if (!is_string($rawName) && !is_numeric($rawName)) {
      return;
    }
    $name = trim((string) $rawName);
    if ($name === '' || !$node->hasField('field_athlete_1') || !$node->hasField('field_athlete_2')) {
      return;
    }

    $target = $this->findAthleteByName($name);
    if (!$target) {
      $this->logger->warning(
        'SIGNALEMENT update_adversaire : athlète "@name" introuvable | nid=@nid | type=@type',
        [
          '@name' => $name,
          '@nid' => $node->id(),
          '@type' => $type,
        ]
      );
      return;
    }

    $targetId = (int) $target->id();
    $id1 = !$node->get('field_athlete_1')->isEmpty() ? (int) $node->get('field_athlete_1')->target_id : 0;
    $id2 = !$node->get('field_athlete_2')->isEmpty() ? (int) $node->get('field_athlete_2')->target_id : 0;

    // Déjà présent sur le combat.
    if ($targetId === $id1 || $targetId === $id2) {
      return;
    }

    // Slot forcé par le type de signalement variante.
    $slot = NULL;
    if ($type === 's_variante_athlete_1') {
      $slot = 'field_athlete_1';
    }
    elseif ($type === 's_variante_athlete_2') {
      $slot = 'field_athlete_2';
    }
    else {
      $slot = $this->guessAdversaireSlot($node, $name);
    }

    if ($slot === NULL) {
      $this->logger->warning(
        'SIGNALEMENT update_adversaire : impossible de déterminer le slot à remplacer | nid=@nid | name=@name | type=@type',
        [
          '@nid' => $node->id(),
          '@name' => $name,
          '@type' => $type,
        ]
      );
      return;
    }

    $oldEntity = !$node->get($slot)->isEmpty() ? $node->get($slot)->entity : NULL;
    $oldName = $oldEntity ? $oldEntity->label() : '-';
    $oldId = $oldEntity ? (int) $oldEntity->id() : 0;

    if ($oldId === $targetId) {
      return;
    }

    $node->set($slot, ['target_id' => $targetId]);

    // Mettre à jour field_vainqueur si c'était l'ancien nom.
    if ($node->hasField('field_vainqueur') && $oldEntity) {
      $vainqueur = (string) ($node->get('field_vainqueur')->value ?? '');
      if ($vainqueur !== '' && mb_strtolower(trim($vainqueur)) === mb_strtolower(trim($oldName))) {
        $node->set('field_vainqueur', $target->label());
      }
    }

    $node->setNewRevision(FALSE);
    $node->save();

    $this->logger->error(
      'SIGNALEMENT Adversaire corrigé via update_adversaire | nid=@nid | title=@title | slot=@slot | ancien=@ancien | nouveau=@nouveau | type_signalement=@type | commentaire_ia=@commentaire',
      [
        '@nid' => $node->id(),
        '@title' => $node->getTitle(),
        '@slot' => $slot,
        '@ancien' => $oldName,
        '@nouveau' => $target->label(),
        '@type' => $type,
        '@commentaire' => $this->escapeForLog($commentaireIa),
      ]
    );
  }

  /**
   * Trouve un node athlete par titre ou nom technique (exact, insensible à la casse).
   */
  protected function findAthleteByName(string $name): ?\Drupal\node\NodeInterface {
    $name = trim($name);
    if ($name === '') {
      return NULL;
    }

    $storage = \Drupal::entityTypeManager()->getStorage('node');

    // 1) Titre exact.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'athlete')
      ->condition('title', $name)
      ->range(0, 1)
      ->execute();
    if ($ids) {
      return $storage->load(reset($ids));
    }

    // 2) Titre LIKE + match insensible à la casse.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'athlete')
      ->condition('title', $name, 'LIKE')
      ->range(0, 5)
      ->execute();
    if ($ids) {
      foreach ($storage->loadMultiple($ids) as $candidate) {
        if (mb_strtolower(trim($candidate->label())) === mb_strtolower($name)) {
          return $candidate;
        }
      }
      if (count($ids) === 1) {
        return $storage->load(reset($ids));
      }
    }

    // 3) Nom technique.
    try {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'athlete')
        ->condition('field_athlete_nom_technique', $name)
        ->range(0, 1)
        ->execute();
      if ($ids) {
        return $storage->load(reset($ids));
      }
    }
    catch (\Exception $e) {
      // Champ absent éventuel.
    }

    return NULL;
  }

  /**
   * Devine quel slot (athlete_1 / athlete_2) remplacer pour update_adversaire.
   */
  protected function guessAdversaireSlot($node, string $newName): ?string {
    $baseNew = $this->athleteNameBase($newName);
    $bestSlot = NULL;
    $bestScore = -1;

    foreach (['field_athlete_1', 'field_athlete_2'] as $slot) {
      if ($node->get($slot)->isEmpty() || !$node->get($slot)->entity) {
        continue;
      }
      $oldLabel = (string) $node->get($slot)->entity->label();
      $baseOld = $this->athleteNameBase($oldLabel);

      $score = 0;
      if ($baseOld !== '' && $baseOld === $baseNew) {
        $score = 100;
      }
      elseif ($baseOld !== '' && (str_contains($baseNew, $baseOld) || str_contains($baseOld, $baseNew))) {
        $score = 50;
      }

      if ($score > $bestScore) {
        $bestScore = $score;
        $bestSlot = $slot;
      }
    }

    return $bestScore > 0 ? $bestSlot : NULL;
  }

  /**
   * Nom de base sans suffixe numérique (Modou 2 → modou).
   */
  protected function athleteNameBase(string $name): string {
    $n = mb_strtolower(trim($name));
    $n = preg_replace('/\s+/', ' ', $n);
    $n = preg_replace('/\s*\d+\s*$/u', '', $n);
    return trim($n ?? '');
  }

  /**
   * Echappe { } avant injection dans un message de log Drupal.
   * Evite le mangling PSR-3 : {"a":1} → @"a":1
   */
  protected function escapeForLog(string $text): string {
    return str_replace(['{', '}'], ['｛', '｝'], $text);
  }

}
