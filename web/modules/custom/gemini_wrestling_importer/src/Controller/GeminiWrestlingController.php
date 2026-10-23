<?php

namespace Drupal\gemini_wrestling_importer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\gemini_wrestling_importer\Service\GeminiWrestlingService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the Gemini Wrestling Importer module.
 */
class GeminiWrestlingController extends ControllerBase {

  protected $geminiWrestlingService;

  public function __construct(GeminiWrestlingService $gemini_wrestling_service) {
    $this->geminiWrestlingService = $gemini_wrestling_service;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('gemini_wrestling_importer.service')
    );
  }

  /**
   * A simple test page to demonstrate API usage.
   *
   * @return array
   * A render array.
   */
  public function testPage() {
    $wrestlerName = 'Modou Lô';
    $config = \Drupal::config('gemini_wrestling_importer.settings');
    $mode   = $config->get('wrestling_importer_use_diff') ? 'diff' : 'normal';
    $engine  = $config->get('wrestling_importer_use_llm') ?? 'grok';
    $fights = $this->geminiWrestlingService->getFightsFromAiRequest($wrestlerName, $engine, $mode);

    $build['content'] = [
      '#markup' => $this->t('<h2>Test de l\'API Gemini pour @wrestler</h2><pre>@data</pre>', [
        '@wrestler' => $wrestlerName,
        '@data' => print_r($fights, TRUE),
      ]),
    ];

    return $build;
  }
}