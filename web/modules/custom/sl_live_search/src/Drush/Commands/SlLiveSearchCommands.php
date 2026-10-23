<?php

namespace Drupal\sl_live_search\Drush\Commands;

use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;
use Drupal\sl_live_search\Service\LiveSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Commandes ONLY FOR TESTS Drush pour le module SL Live Search.
 */
class SlLiveSearchCommands extends DrushCommands {

  /**
   * Le service de recherche en direct.
   *
   * @var \Drupal\sl_live_search\Service\LiveSearchService
   */
  protected $liveSearchService;

  /**
   * Constructeur de la commande.
   */
  public function __construct(LiveSearchService $liveSearchService) {
    parent::__construct();
    $this->liveSearchService = $liveSearchService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('sl_live_search.live_search_service')
    );
  }

  /**
   * Teste la recherche en direct via Grok (X.ai).
   *
   * @param string $prompt
   * La question ou le prompt à envoyer à l'API.
   * @param array $options
   * Les options de la commande.
   */
  #[CLI\Command(name: 'sl:live-search', aliases: ['slls'])]
  #[CLI\Argument(name: 'prompt', description: 'Le prompt à tester.')]
  #[CLI\Usage(name: 'drush sl:live-search "Liste des derniers combats de Reug Reug"', description: 'Teste la recherche live.')]
  public function testSearch(string $prompt, array $options = ['format' => 'table']): void {
    $io = $this->io();

    $io->title("🚀 Lancement du Test Live Search");
    $io->note("Prompt : " . $prompt);

    $io->section("Requête API en cours...");

    // Appel du service
    $result = $this->liveSearchService->liveSearch($prompt);

    if ($result['success'] ?? false) {
      $io->success("Réponse reçue avec succès !");
      
      $io->section("Contenu de la réponse :");
      $io->writeln($result['content']);

      if (!empty($result['source'])) {
        $io->info("📍 Source technique : " . $result['source']);
      }

      // Affichage optionnel des citations si présentes dans le raw
      if (isset($result['raw']['citations']) && !empty($result['raw']['citations'])) {
         $io->section("Citations trouvées :");
         $io->listing($result['raw']['citations']);
      }
    }
    else {
      $io->error("Échec de la requête API");
      
      if (isset($result['error'])) {
        $io->warning("Erreur retournée : " . print_r($result['error'], TRUE));
      }

      $io->section("Debug : Body envoyé");
      $io->code(json_encode($result['sent_body'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    $io->newLine();
    $io->text("--- Fin du test ---");
  }
}