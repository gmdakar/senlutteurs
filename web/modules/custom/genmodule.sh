#!/bin/bash

# Nom du module
MODULE_NAME="sl_warming"

echo "=== Création de l'arborescence pour le module $MODULE_NAME ==="

# Création des répertoires conformes aux standards PSR-4 de Drupal 11
mkdir -p "${MODULE_NAME}/src/Drush/Commands"

# 1. Création du fichier d'info du module
cat << 'EOF' > "${MODULE_NAME}/${MODULE_NAME}.info.yml"
name: 'SenLutteurs Cache Warming'
type: module
description: 'Commande Drush native exploitant le Kernel de Drupal 11 et l API LiteSpeed pour réchauffer le cache (Desktop & Mobile).'
package: Custom
core_version_requirement: ^11
dependencies:
  - warmer:warmer
EOF

# 2. Création de la classe de commande Drush
cat << 'EOF' > "${MODULE_NAME}/src/Drush/Commands/SlWarmingCommands.php"
<?php

namespace Drupal\sl_warming\Drush\Commands;

use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Commands\DrushCommands;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Commande Drush pour le préchauffage applicatif du cache.
 */
class SlWarmingCommands extends DrushCommands {

  /**
   * Le Kernel de Drupal pour exécuter les requêtes en interne (RAM).
   */
  protected HttpKernelInterface $httpKernel;

  /**
   * Le client HTTP de Drupal pour récupérer le sitemap.
   */
  protected ClientInterface $httpClient;

  /**
   * Le stockage d'état de Drupal.
   */
  protected StateInterface $state;

  /**
   * La gestion des files d'attente.
   */
  protected QueueFactory $queueFactory;

  /**
   * Constructeur avec injection de dépendances automatique pour Drupal 11.
   */
  public function __construct(
    HttpKernelInterface $http_kernel,
    ClientInterface $http_client,
    StateInterface $state,
    QueueFactory $queue_factory
  ) {
    parent::__construct();
    $this->httpKernel = $http_kernel;
    $this->httpClient = $http_client;
    $this->state = $state;
    $this->queueFactory = $queue_factory;
  }

  #[Command(name: 'sl-warming', aliases: ['slw'])]
  #[Option(name: 'page-limit', description: 'Nombre maximum de pages sitemap à traiter (0 pour infini).')]
  public function runNativeWarming(array $options = ['page-limit' => 0]) {
    $pageLimit = (int) $options['page-limit'];
    $urlCount = 0;
    $hitCount = 0;
    $missCount = 0;

    $this->logger()->notice("--- Début du Warming Applicatif via API Native: " . date('Y-m-d H:i:s') . " ---");

    try {
      // 0. Vider la file d'attente de purge pour partir sur une ardoise propre
      $this->logger()->notice("Nettoyage de la file d'attente de purge...");
      $purgeQueue = $this->queueFactory->get('purge_processor_queue');
      if ($purgeQueue) {
        $purgeQueue->deleteQueue();
      }

      // 1. Chauffer le cache interne de Drupal de manière classique (Warmer)
      $this->logger()->notice("Préchauffage des entités et sitemaps internes (Warmer)...");
      $processQueue = drush_process_local_command('warmer:enqueue entity');
      $processQueue->run();
      $processQueue = drush_process_local_command('warmer:enqueue sitemap');
      $processQueue->run();
      $processQueue = drush_process_local_command('queue:run warmer');
      $processQueue->run();

      // 2. Définition des flags d'état
      $this->state->set('warming_in_progress', 1);
      $this->state->set('warming_started_at', time());

      // 3. Boucle sur le sitemap pour injection directe dans le moteur LiteSpeed
      $page = 1;
      $uaDesktop = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";
      $uaMobile = "Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1";

      while (true) {
        if ($pageLimit > 0 && $page > $pageLimit) {
          break;
        }

        // Récupération du sitemap xml via Guzzle
        try {
          $sitemapUrl = "https://www.senlutteurs.com/sitemap.xml?page=" . $page;
          $response = $this->httpClient->request('GET', $sitemapUrl);
          $xml = $response->getBody()->getContents();
        } 
        catch (\Exception $e) {
          $this->logger()->notice("Fin des pages sitemap ou erreur d'accès à la page $page.");
          break;
        }

        // Extraction des URLs
        preg_match_all('/<loc>([^<]+)<\/loc>/', $xml, $matches);
        $urls = $matches[1] ?? [];
        
        // Nettoyage pour ne pas boucler sur l'index du sitemap lui-même
        $urls = array_filter($urls, function($url) {
          return strpos($url, 'sitemap.xml') === false;
        });

        if (empty($urls)) {
          break;
        }

        foreach ($urls as $url) {
          $urlCount++;
          
          // Conversion de l'URL absolue en URI relative pour le Kernel de Drupal
          $parsedUrl = parse_url($url);
          $path = ($parsedUrl['path'] ?? '/') . (isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '');

          $this->applyThrottling();

          // Passe 1 : DESKTOP
          $requestDesktop = Request::create($path, 'GET');
          $requestDesktop->headers->set('User-Agent', $uaDesktop);
          $requestDesktop->headers->set('Accept-Encoding', 'gzip, deflate, br');
          $requestDesktop->headers->set('X-LiteSpeed-Cache-Control', 'public, max-age=86400');
          
          $responseDesktop = $this->httpKernel->handle($requestDesktop, HttpKernelInterface::SUB_REQUEST);
          $lscacheDesktop = $responseDesktop->headers->get('X-LiteSpeed-Cache', 'miss');

          // Passe 2 : MOBILE (Résout le problème du Vary de LiteSpeed)
          $requestMobile = Request::create($path, 'GET');
          $requestMobile->headers->set('User-Agent', $uaMobile);
          $requestMobile->headers->set('Accept-Encoding', 'gzip, deflate, br');
          $requestMobile->headers->set('X-LiteSpeed-Cache-Control', 'public, max-age=86400');
          
          $responseMobile = $this->httpKernel->handle($requestMobile, HttpKernelInterface::SUB_REQUEST);
          $lscacheMobile = $responseMobile->headers->get('X-LiteSpeed-Cache', 'miss');

          if (stripos($lscacheDesktop, 'hit') !== false && stripos($lscacheMobile, 'hit') !== false) {
            $hitCount++;
            $statusLabel = "HIT";
          } else {
            $missCount++;
            $statusLabel = "MISS/stored";
          }

          $this->logger()->info(sprintf("[%d] [%d] [%s] %s", $urlCount, $responseDesktop->getStatusCode(), $statusLabel, $path));
        }

        $page++;
      }

    } 
    catch (\Exception $e) {
      $this->logger()->error("Erreur durant l'exécution : " . $e->getMessage());
    } 
    finally {
      // Nettoyage systématique du flag (Équivalent du trap EXIT en Bash)
      $this->state->delete('warming_in_progress');
      $this->logger()->notice("--- Fin du traitement et nettoyage du flag ---");
      $this->logger()->success(sprintf("Total URLs : %d | Globaux HITs: %d | Remplis/MISS: %d", $urlCount, $hitCount, $missCount));
    }
  }

  /**
   * Throttling matériel dynamique basé sur la charge de l'utilisateur système.
   */
  private function applyThrottling() {
    $user = getcurrentuser();
    $cpuLoad = shell_exec("ps -u " . escapeshellarg($user) . " -o %cpu | awk '{sum+=$1} END {print sum}'");
    $cpuInt = (int) ($cpuLoad ?? 0);

    if ($cpuInt > 150) {
      usleep(3000000);
    } elseif ($cpuInt > 100) {
      usleep(2000000);
    } elseif ($cpuInt > 50) {
      usleep(1000000);
    } else {
      usleep(300000); // 0.3s par défaut pour les Subrequests ultra-rapides
    }
  }
}
EOF

# 3. Définition des permissions du module généré
chmod -R 755 "${MODULE_NAME}"

echo "========================================================="
echo " Le module '$MODULE_NAME' a été créé avec succès."
echo " Tu peux maintenant l'activer dans Drupal via :"
echo " drush en $MODULE_NAME"
echo " Puis exécuter la commande de warming native via :"
echo " drush sl-warming"
echo "========================================================="