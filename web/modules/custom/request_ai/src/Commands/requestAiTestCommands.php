<?php

namespace Drupal\request_ai\Commands;

use Drush\Commands\DrushCommands;
use Drupal\sl_live_search\Service\LiveSearchService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;

/**
 * Commande Drush pour tester les requêtes IA basées sur la configuration du site.
 */
class requestAiTestCommands extends DrushCommands {

  /**
   * Le service LiveSearch.
   *
   * @var \Drupal\sl_live_search\Service\LiveSearchService
   */
  protected $liveSearchService;

  /**
   * Le canal de log pour request_ai.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $requestAiLogger;

  /**
   * La fabrique de configuration Drupal.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructeur avec injection de dépendances.
   */
  public function __construct(
    LiveSearchService $liveSearchService,
    LoggerChannelFactoryInterface $loggerFactory,
    ConfigFactoryInterface $configFactory
  ) {
    parent::__construct();
    $this->liveSearchService = $liveSearchService;
    $this->requestAiLogger = $loggerFactory->get('request_ai');
    $this->configFactory = $configFactory;
  }

  #[Command(name: 'request-ai:run', aliases: ['air'])]
  #[Option(name: 'enginef', description: 'Le moteur de recherche IA à utiliser (grok, gemini)')]
  #[Usage(name: 'drush air --enginef=grok', description: 'Exécute le test de requête IA avec le moteur Grok')]
  public function air(array $options = ['enginef' => NULL]): void {

    $config = $this->configFactory->get('request_ai.settings');

    // Détermination du moteur (option Drush OU config formulaire OU 'grok' par défaut)
    $engine_force = $options['enginef'] ?? $config->get('engine') ?? 'grok';

    $this->io()->note(sprintf('Moteur actif passé au test : %s', $engine_force));

    // Passage de la variable $engine_force en paramètre
    $data = $this->testRequestAi($engine_force);

    if (empty($data)) {
      $this->io()->warning('Aucune donnée retournée ou une erreur est survenue.');
      return;
    }

    $this->io()->success('Requête IA exécutée avec succès !');
    $this->io()->writeln(print_r($data, TRUE));
  }

  /**
   * Exécute la requête de test IA pour un moteur spécifique.
   *
   * @param string $engine
   *   Le moteur à utiliser (grok, gemini, etc.)
   */
  public function testRequestAi(string $engine = 'grok'): array {
    try {
      $response_content = null;
      try {
        $prompt = $this->getPrompt();

        // --- LOG SYSTÉMATIQUE DU PROMPT ---
        $this->requestAiLogger->notice('Prompt envoyé (Moteur: @engine) : @prompt', [
          '@prompt' => $prompt,
          '@engine' => $engine,
        ]);

        if (stripos($prompt, '__ignore__') !== false) {
          $response_content = $this->getDefaultResponse();
          $response_content = str_replace('__debug__', '', $response_content);
        }
        else {
          $prompt = str_replace('__debug__', '', $prompt);

          $response = $this->liveSearchService->liveSearch($prompt, $engine, 'normal', true, true);

          $this->requestAiLogger->notice('<pre>@data</pre>', ['@data' => print_r($response, TRUE)]);

          if (isset($response['success']) && $response['success'] && !empty($response['content'])) {
            $response_content = $response['content'];
          }

          if (isset($response['raw']['citations'])) {
            $this->requestAiLogger->debug('<pre>Citations: @citations</pre>', ['@citations' => print_r($response['raw']['citations'], TRUE)]);
          }
        }

        $this->requestAiLogger->debug('<pre>@data response_content</pre>', ['@data' => print_r($response_content, TRUE)]);
      }
      catch (\Exception $e) {
        $this->requestAiLogger->error($e->getMessage());
      }

      $data = $this->decodeJson($response_content, __METHOD__);

      if (!empty($data) && (stripos(preg_replace('/^\s+/u', '', $this->getPrompt()), '__debug__') !== false || stripos(preg_replace('/^\s+/u', '', $this->getPrompt()), '__ignore__') !== false && stripos(preg_replace('/^\s+/u', '', $this->getDefaultResponse()), '__debug__') !== false)) {
        $this->requestAiLogger->notice('Reponse: @data', ['@data' => print_r($data, TRUE)]);
      }

      return $data ?? [];
    }
    catch (\Exception $e) {
      $this->requestAiLogger->error('API error: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Récupère le prompt de test défini dans la configuration du formulaire.
   */
  protected function getPrompt(): string {
    $config = $this->configFactory->get('request_ai.settings');
    return $config->get('prompt_test') ?? 'Exemple de prompt par défaut __debug__';
  }

  /**
   * Retourne une réponse par défaut en cas d'instruction __ignore__.
   */
  protected function getDefaultResponse(): string {
    return json_encode(['status' => 'ignored']);
  }

  /**
   * Décode le contenu JSON reçu.
   */
  protected function decodeJson(?string $content, string $method): array {
    if (empty($content)) {
      return [];
    }
    $decoded = json_decode($content, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

}