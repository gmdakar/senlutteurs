<?php

namespace Drupal\gemini_wrestling_importer\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\gemini_wrestling_importer\Service\GeminiWrestlingService;
use Drupal\shared_lib\Tools\SomeTools;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for importing wrestling fights.
 */
class GeminiWrestlingForm extends FormBase {

  protected GeminiWrestlingService $geminiWrestlingService;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected LoggerInterface $logger;

  public function __construct(
    GeminiWrestlingService $gemini_wrestling_service,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerInterface $logger,
  ) {
    $this->geminiWrestlingService = $gemini_wrestling_service;
    $this->entityTypeManager      = $entity_type_manager;
    $this->logger                 = $logger;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('gemini_wrestling_importer.service'),
      $container->get('entity_type.manager'),
      $container->get('logger.channel.default'),
    );
  }

  public function getFormId() {
    return 'gemini_wrestling_importer_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['wrestler_name'] = [
      '#type'               => 'entity_autocomplete',
      '#target_type'        => 'node',
      '#title'              => $this->t('Nom du lutteur'),
      '#default_value'      => $form_state->getValue('wrestler_name'),
      '#description'        => $this->t(
        'Saisissez le nom du lutteur (autocomplétion). ' .
        'Saisie libre possible : un ou plusieurs lutteurs séparés par <code>|</code>, ' .
        'avec écurie optionnelle : <code>Balla Gaye 2/Rock|Modou Lô/Thiossane</code>. ' .
        'Si le lutteur n\'existe pas, il sera créé automatiquement.'
      ),
      '#selection_handler'  => 'default',
      '#selection_settings' => ['target_bundles' => ['athlete']],
      '#validate_reference' => FALSE,
      '#required'           => TRUE,
      '#element_validate'   => [],
    ];

    $form['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Lancer l\'importation'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $raw = trim((string) $form_state->getValue('wrestler_name'));

    // ── Cas autocomplete : "Nom du lutteur (nid)" ─────────────────────────
    $nid = EntityAutocomplete::extractEntityIdFromAutocompleteInput($raw);
    if (!$nid && is_numeric($raw)) {
      $nid = (int) $raw;
    }

    if ($nid) {
      $node         = $this->entityTypeManager->getStorage('node')->load($nid);
      $wrestlerName = $node ? $node->getTitle() : $raw;
      $this->importForWrestler($wrestlerName, NULL, TRUE);
      $form_state->setRebuild(TRUE);
      return;
    }

    // ── Cas texte libre : 1 ou N lutteurs séparés par | ───────────────────
    $fighters = $this->parseFighterField($raw);

    if (empty($fighters)) {
      $this->messenger()->addWarning($this->t('Aucun lutteur valide trouvé.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    foreach ($fighters as $f) {
      $this->importForWrestler($f['name'], $f['ecurie'] ?: NULL, FALSE);
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * Parse le champ fighter en une liste de ['name' => ..., 'ecurie' => ...].
   */
  protected function parseFighterField(string $raw): array {
    $entries = [];
    foreach (explode('|', $raw) as $part) {
      $part = trim($part);
      if ($part === '') {
        continue;
      }
      $slash_pos = strrpos($part, '/');
      if ($slash_pos !== FALSE && !str_contains($part, '://')) {
        $name   = trim(substr($part, 0, $slash_pos));
        $ecurie = trim(substr($part, $slash_pos + 1));
      }
      else {
        $name   = $part;
        $ecurie = '';
      }
      if ($name !== '') {
        $entries[] = ['name' => $name, 'ecurie' => $ecurie];
      }
    }
    return $entries;
  }

  /**
   * Importe les combats pour un lutteur donné.
   */
  protected function importForWrestler(string $wrestlerName, ?string $ecurie, bool $isExistingAthlete): void {
    $config = \Drupal::config('gemini_wrestling_importer.settings');
    $engine = $config->get('wrestling_importer_use_llm') ?? 'grok';
    $mode   = $config->get('wrestling_importer_use_diff') ? 'diff' : 'normal';
    $result = $this->geminiWrestlingService->getFightsFromAiRequest($wrestlerName, $engine, $mode);

    /* POUR TEST ONLY
    $result = [];
    $result['success'] = true;
    $result['fights'] = json_decode('[
        {
            "id": "batika_layegainde_20120101",
            "del": 1
        },
        {
            "id": "batika_toto_20100101",
            "del": "1"
        },
        {
            "id": "batika_gouyeguiespoir_20071223",
            "alter": {
                "dte": "2007-12-23",
                "adv": "Gouye Gui / Ecole de lutte Mor Fadam",
                "res": "Gouye Gui",
                "ls": "0"
            }
        }
    ]', true);
    */

    if (!empty($result['success']) && !empty($result['fights'])) {

      if (!$isExistingAthlete) {
        $node = SomeTools::getOrCreateAthlete(
          $wrestlerName,
          $this->entityTypeManager,
          $this->logger,
          $ecurie,
        );
        if (!$node) {
          $this->messenger()->addWarning(
            $this->t('Impossible de créer le lutteur "@name".', ['@name' => $wrestlerName])
          );
          $this->logger->warning(
            'gemini_wrestling_importer : impossible de créer le lutteur "@name".',
            ['@name' => $wrestlerName]
          );
          return;
        }
        $this->messenger()->addStatus(
          $this->t('Lutteur "@name" créé/trouvé (nid: @nid).', [
            '@name' => $wrestlerName,
            '@nid'  => $node->id(),
          ])
        );
        $this->logger->info(
          'gemini_wrestling_importer : lutteur "@name" créé (nid: @nid).',
          ['@name' => $wrestlerName, '@nid' => $node->id()]
        );
      }

      // La détection diff/normal est déléguée à importFights().
      $this->geminiWrestlingService->importFights($result['fights'], $wrestlerName);

      $this->messenger()->addStatus(
        $this->t('Importation terminée pour @wrestler (@count combats).', [
          '@wrestler' => $wrestlerName,
          '@count'    => count($result['fights']),
        ])
      );
      $this->logger->info(
        'gemini_wrestling_importer : importation terminée pour "@wrestler" (@count combats).',
        ['@wrestler' => $wrestlerName, '@count' => count($result['fights'])]
      );
    }
    else {
      if (isset($result['success']) && !$result['success']) {
        $this->messenger()->addWarning(
          $this->t('Erreur pour @wrestler : @error', [
            '@wrestler' => $wrestlerName,
            '@error'    => $result['error'] ?? 'unknown',
          ])
        );
        $this->logger->warning(
          'gemini_wrestling_importer : échec récupération pour "@wrestler" — @error.',
          ['@wrestler' => $wrestlerName, '@error' => $result['error'] ?? 'unknown']
        );
      }
      else {
        $this->messenger()->addWarning(
          $isExistingAthlete
            ? $this->t('Aucun nouveau combat trouvé pour @wrestler.', ['@wrestler' => $wrestlerName])
            : $this->t('Aucun combat trouvé pour @wrestler. Lutteur non créé.', ['@wrestler' => $wrestlerName])
        );
        $this->logger->warning(
          $isExistingAthlete
            ? 'gemini_wrestling_importer : aucun nouveau combat trouvé pour "@wrestler" (existant).'
            : 'gemini_wrestling_importer : aucun combat trouvé pour "@wrestler" — lutteur non créé.',
          ['@wrestler' => $wrestlerName]
        );
      }
    }
  }

}