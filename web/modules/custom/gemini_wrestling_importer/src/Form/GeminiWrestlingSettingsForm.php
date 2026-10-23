<?php

namespace Drupal\gemini_wrestling_importer\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Gemini Combat Cron settings.
 */
class GeminiWrestlingSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'gemini_wrestling_importer_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['gemini_wrestling_importer.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('gemini_wrestling_importer.settings');

    $form['prompt'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Default Prompt'),
      '#default_value' => $config->get('prompt'),
      '#description'   => $this->t(
        'Enter the default text prompt for API requests.<br>' .
        'NB: add __ignore__ force the use of the default response value below. ' .
        'Or add __debug__ to log the response and display of the array value of the current json response.'
      ),
      '#required'      => TRUE,
    ];

    $form['prompt_json_fights_part1'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Default promptJsonFightsPart1 for Titles from Youtube'),
      '#default_value' => $config->get('prompt_json_fights_part1'),
      '#description'   => $this->t(
        'Enter the default text prompt titles from Youtube for API requests.<br>' .
        'NB: add __ignore__ force the use of the default response value below. ' .
        'Or add __debug__ to log the response and display of the array value of the current json response.'
      ),
      '#required'      => TRUE,
    ];

    $form['prompt_json_fights_part2'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Default promptJsonFightsPart2 for Titles from Youtube'),
      '#default_value' => $config->get('prompt_json_fights_part2'),
      '#description'   => $this->t(
        'Enter the default text prompt titles from Youtube for API requests.<br>' .
        'NB: add __ignore__ force the use of the default response value below. ' .
        'Or add __debug__ to log the response and display of the array value of the current json response.'
      ),
      '#required'      => TRUE,
    ];

    $form['prompt_diff'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Default Prompt for Diff'),
      '#default_value' => $config->get('prompt_diff'),
      '#description'   => $this->t(
        'Enter the default text prompt diff for API requests.<br>' .
        'NB: add __ignore__ force the use of the default response value below. ' .
        'Or add __debug__ to log the response and display of the array value of the current json response.'
      ),
      '#required'      => TRUE,
    ];

    $form['wrestling_importer_use_diff'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Use Diff'),
      '#default_value' => $config->get('wrestling_importer_use_diff') ?? 0,
      '#description'   => $this->t(
        'If checked, the diff mode will be used instead of a full import. ' .
        'The prompt above (Default Prompt for Diff) will be used.'
      ),
    ];

    $form['wrestling_importer_use_llm'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Use LLM'),
      '#default_value' => $config->get('wrestling_importer_use_llm') ?? 'grok',
      '#options'       => [
        'gemini'  => $this->t('Gemini'),
        'grok'    => $this->t('Grok'),
        'chatgpt' => $this->t('ChatGPT'),
        'claude'  => $this->t('Claude'),
      ],
      '#description'   => $this->t('Select the LLM engine to use for API requests.'),
      '#required'      => TRUE,
    ];

    $form['default_response'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Default Response'),
      '#default_value' => $config->get('default_response'),
      '#description'   => $this->t(
        'Enter the default Json response for API requests (for test for example).<br>' .
        'NB: add __debug__ to log the response and the display of the array value of the current json response.'
      ),
    ];

    $form['promptFindVideos'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Default prompt find videos'),
      '#default_value' => $config->get('promptFindVideos'),
      '#description'   => $this->t(
        'Enter the default text prompt Find Videos for API requests.<br>' .
        'NB: add __ignore__ to ignore the stage of searching video on Internet. ' .
        'Or add __debug__ to log the response and the display of the array value of the current json response.'
      ),
      '#required'      => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('gemini_wrestling_importer.settings')
      ->set('prompt', $form_state->getValue('prompt'))
      ->set('prompt_diff', $form_state->getValue('prompt_diff'))
      ->set('prompt_json_fights_part1', $form_state->getValue('prompt_json_fights_part1'))
      ->set('prompt_json_fights_part2', $form_state->getValue('prompt_json_fights_part2'))
      ->set('wrestling_importer_use_diff', (int) $form_state->getValue('wrestling_importer_use_diff'))
      ->set('wrestling_importer_use_llm', $form_state->getValue('wrestling_importer_use_llm'))
      ->set('default_response', $form_state->getValue('default_response'))
      ->set('promptFindVideos', $form_state->getValue('promptFindVideos'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}