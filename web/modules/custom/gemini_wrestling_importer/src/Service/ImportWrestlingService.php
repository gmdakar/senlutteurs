<?php

namespace Drupal\gemini_wrestling_importer\Service;

/**
 * Service for importing wrestling fights from Gemini.
 */
class ImportWrestlingService extends GeminiBaseService {

  /**
   * NOTE : Zéro constructeur. 
   * Hérite de GeminiBaseService -> BaseWrestlingService.
   * Accès automatique à $this->configFactory, $this->liveSearchService, etc.
   */

  /**
   * Génère le prompt pour la recherche de vidéos de combat.
   */
  protected function getPromptFindVideos(string $dateFight, string $wrestler1, string $wrestler2): string {
    $prompt = $this->configFactory->get('gemini_wrestling_importer.settings')->get('promptFindVideos');
    
    if (!$prompt) {
      return '';
    }

    return strtr($prompt, [
      '@date' => $dateFight,
      '@wrestler1' => $wrestler1,
      '@wrestler2' => $wrestler2,
    ]);
  }
}