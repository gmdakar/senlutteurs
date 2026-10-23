<?php

namespace Drupal\athletes_envogue_cron\Service;

use Drupal\shared_lib\Service\BaseWrestlingService;

/**
 * Service pour gérer les athlètes en vogue.
 */
class AthleteService extends BaseWrestlingService {

  /**
   * Fetch athletes from Gemini API.
   */
  public function fetchAthletesEnVogue(): array {
    try {
      $response_content = null;
      try {
          $prompt = $this->getPrompt();
          
          // --- LOG SYSTÉMATIQUE DU PROMPT ---
          $this->logger->notice('Prompt envoyé : @prompt', [
            '@prompt' => $prompt,
          ]);

          if (stripos($prompt, '__ignore__') !== false) {
              $response_content = $this->getDefaultResponse();
              $response_content = str_replace('__debug__', '', $response_content);
          }
          else {
              $prompt = str_replace('__debug__', '', $prompt);
              
              /** @var \Drupal\sl_live_search\Service\LiveSearchService $this->liveSearchService */
              // Utilisation du service injecté via le parent
              $response = $this->liveSearchService->liveSearch($prompt);
              
              $this->logger->notice('<pre>@data</pre>', ['@data' => print_r($response, TRUE)]);

              if ($response['success'] && !empty($response['content'])) {
                  $response_content = $response['content'];
              }
              
              if (isset($response['raw']['citations'])) {
                $this->logger->debug('<pre>Citations: @citations</pre>', ['@citations' => print_r($response['raw']['citations'], TRUE)]);
              }
          }
          
          $this->logger->debug('<pre>@data response_content</pre>', ['@data' => print_r($response_content, TRUE)]);
      }
      catch (\Exception $e) {
          $this->logger->error($e->getMessage());
      }
      
      $data = $this->decodeJson($response_content, __METHOD__);
      
      if (!empty($data) && (stripos(preg_replace('/^\s+/u', '', $this->getPrompt()), '__debug__') !== false || stripos(preg_replace('/^\s+/u', '', $this->getPrompt()), '__ignore__') !== false && stripos(preg_replace('/^\s+/u', '', $this->getDefaultResponse()), '__debug__') !== false))  
        $this->logger->notice('Reponse: @data', ['@data' => print_r($data, TRUE)]);


      return $data ?? [];
    } catch (\Exception $e) {
        $this->logger->error('API error: @msg', ['@msg' => $e->getMessage()]);
        return [];
    }

  }

  public function enqueueAthlete($athlete): void {
    $queue = \Drupal::queue('athlete_envogue_queue');
    $queue->createItem($athlete);
  }

  protected function getPrompt(string $wrestlerName = ''): string {
    $prompt = $this->configFactory->get('athletes_envogue_cron.settings')->get('prompt');
    return $prompt ? strtr($prompt, ['@wrestler' => $wrestlerName]) : '';
  }

  protected function getDefaultResponse(): string
  {
    return $this->configFactory->get('athletes_envogue_cron.settings')->get('default_response') ?? '';
  }

}