<?php

namespace Drupal\misc_divers\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\redirect\Entity\Redirect;
use Drush\Attributes\Command;
use Drupal\misc_divers\Commands\CommandsBase;

/**
 * Répare les 404 du site Senlutteurs.
 * Gère spécifiquement les suffixes "Jr" -> "-2".
 */
class ReparePage404Commands extends CommandsBase
{

  protected $database;
  protected $entityTypeManager;

  public function __construct(Connection $database, EntityTypeManagerInterface $entityTypeManager)
  {
    parent::__construct();
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
  }

  #[Command(name: '404:fix', aliases: ['404fix'])]
  public function fixAll404()
  {

    // Check granularity authorization cron execution
    if (!$this->isScheduledNow('404:fix')) {
      return;
    }

    $results = $this->database->select('redirect_404', 'r')
      ->fields('r', ['path'])
      ->execute()
      ->fetchAll();

    if (empty($results)) {
      $this->io()->warning("La table redirect_404 est vide.");
      return;
    }

    // Chargement global unique des alias de lutteurs pour éviter les requêtes en boucle
    $lutteur_aliases = $this->database->select('path_alias', 'pa')
      ->fields('pa', ['alias', 'path', 'langcode'])
      ->condition('alias', '%/lutteurs/%', 'LIKE')
      ->execute()->fetchAll();

    // Chargement global unique des alias de combats et a_venir
    $combats_aliases = $this->database->select('path_alias', 'pa')
      ->fields('pa', ['alias', 'path', 'langcode'])
      ->condition('alias', '%/combats/%', 'LIKE')
      ->execute()->fetchAll();

    $avenir_aliases = $this->database->select('path_alias', 'pa')
      ->fields('pa', ['alias', 'path', 'langcode'])
      ->condition('alias', '%/a_venir/%', 'LIKE')
      ->execute()->fetchAll();

    $count = 0;
    foreach ($results as $row) {
      $this->processPath($row->path, $lutteur_aliases, $combats_aliases, $avenir_aliases);
      $count++;
    }
    $this->io()->success("Traitement terminé. {$count} chemins traités.");
  }

  public function processPath($path, array $lutteur_aliases, array $combats_aliases, array $avenir_aliases)
  {
    $container = \Drupal::getContainer();
    $logger = $container->get('logger.factory')->get('misc_divers');

    $clean_path = ltrim($path, '/');
    $path_no_lang = preg_replace('/^[a-z]{2}\//', '', $clean_path);

    // 1. FILTRE : On ignore le bruit technique
    if (preg_match('/\.(json|xml|mp4|mka|ogg|txt|xml|png|jpg|css|js)$/i', $path_no_lang) || str_contains($path_no_lang, '.well-known')) {
      return;
    }

    // 2. FILTRE : On ne traite que les sections clés
    if (!preg_match('/(lutteurs|combats|a_venir)/i', $path_no_lang)) {
      return;
    }

    $parts = explode('/', trim($path_no_lang, '/'));

    // =========================================================================
    // CAS DES LUTTEURS (ex: mbaye-tinejr -> mbaye-tine-2)
    // =========================================================================
    if (str_contains($path_no_lang, 'lutteurs')) {
      $slug_404 = null;
      foreach ($parts as $p) {
        if ($p !== 'lutteurs' && !is_numeric($p) && strlen($p) >= 3) {
          $slug_404 = mb_strtolower($p);
          break;
        }
      }

      if (!$slug_404) return;

      // --- LOGIQUE SPÉCIALE JUNIOR ---
      $is_jr_request = preg_match('/(jr|junior)$/i', $slug_404);
      $slug_404_normalized = preg_replace('/(-?jr|-?junior)$/i', '-2', $slug_404);

      $best_match = null;
      $max_sim = 0;

      foreach ($lutteur_aliases as $row) {
        $alias_parts = explode('/', trim($row->alias, '/'));
        $target_slug = null;
        foreach (array_reverse($alias_parts) as $segment) {
          if (!is_numeric($segment) && $segment !== 'lutteurs' && strlen($segment) >= 3) {
            $target_slug = mb_strtolower($segment);
            break;
          }
        }

        if (!$target_slug) continue;

        $current_sim = 0;
        similar_text($slug_404_normalized, $target_slug, $current_sim);

        if ($is_jr_request && str_ends_with($target_slug, '-2')) {
          $current_sim += 15;
        }

        if ($current_sim > 80 && $current_sim > $max_sim) {
          $max_sim = $current_sim;
          $best_match = $row;
        }
      }

      if ($best_match) {
        $this->saveAndLogRedirect($clean_path, 'internal:' . $best_match->alias, $best_match->alias, $logger, $best_match->langcode);
        return;
      }
    }

    // =========================================================================
    // CAS DES COMBATS / A_VENIR
    // =========================================================================
    if (str_contains($path_no_lang, 'combats') || str_contains($path_no_lang, 'a_venir')) {
      $vs_segment = end($parts);
      if (!str_contains($vs_segment, '-vs-')) return;

      $is_combats = str_contains($path_no_lang, 'combats');
      $aliases = $is_combats ? $combats_aliases : $avenir_aliases;

      $words_404 = array_filter(explode('-', str_replace('-vs-', '-', mb_strtolower($vs_segment))), fn($w) => strlen($w) >= 3);

      foreach ($aliases as $row) {
        $alias_parts = explode('/', mb_strtolower($row->alias));
        $alias_segment = end($alias_parts);
        if (!str_contains($alias_segment, '-vs-')) continue;

        $target_words = array_filter(explode('-', str_replace('-vs-', '-', $alias_segment)), fn($w) => strlen($w) >= 3);

        $score = 0;
        foreach ($target_words as $tw) {
          foreach ($words_404 as $w) {
            if (levenshtein($tw, $w) <= 1) {
              $score++;
              break;
            }
          }
        }

        if ($score >= count($target_words) && count($target_words) > 0) {
          $this->saveAndLogRedirect($clean_path, 'internal:' . $row->alias, $row->alias, $logger, $row->langcode);
          return;
        }
      }
    }
  }

  private function saveAndLogRedirect($source, $destination_uri, $display_path, $logger, $langcode = 'und')
  {
    $source_path = ltrim($source, '/');
    $existing = \Drupal::entityTypeManager()->getStorage('redirect')->loadByProperties([
      'redirect_source__path' => $source_path
    ]);
    if (!empty($existing)) return;

    $display_path = str_starts_with($display_path, '/') ? $display_path : '/' . $display_path;
    $redirect = Redirect::create([
      'redirect_source'   => $source_path,
      'redirect_redirect' => $destination_uri,
      'language'          => $langcode,
      'status_code'       => 301,
    ]);
    $redirect->save();

    $msg = sprintf("/%s -> %s", $source_path, $display_path);
    \Drush\drush::output()->writeln("<info>[notice] Redirection créée : $msg</info>");
    $logger->notice("404_FIX: " . $msg);
  }
}
