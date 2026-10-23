<?php

namespace Drupal\athletes_envogue_cron\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Gemini Athletes En Vogue Cron settings.
 */
class AthletesEnVogueCronSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'athletes_envogue_cron_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['athletes_envogue_cron.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('athletes_envogue_cron.settings');

    /*$form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Gemini API Key'),
      '#default_value' => $config->get('api_key_grok') ?? '',
      '#description' => $this->t('Enter your Gemini API key to allow access to the generative language API.'),
      '#required' => TRUE,
    ];*/

    $form['prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default Prompt'),
      '#default_value' => $config->get('prompt'),
      '#description' => $this->t('Enter the default text prompt for API requests.<br>NB: add __ignore__ force the use of the default response value below. Or add __debug__ to log the response and display of the array value of the current json response.'),
      '#required' => TRUE,
    ];

    $form['default_response'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default Response'),
      '#default_value' => $config->get('default_response'),
      '#description' => $this->t('Enter the default Json response for API requests (for test for example).<br>NB: add __debug__ to log the response and the display of the array value of the current json response.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('athletes_envogue_cron.settings')
     // ->set('api_key', $form_state->getValue('api_key'))
      ->set('prompt', $form_state->getValue('prompt'))
      ->set('default_response', $form_state->getValue('default_response'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
