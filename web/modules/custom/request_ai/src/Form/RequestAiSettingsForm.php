<?php

namespace Drupal\request_ai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Request AI settings.
 */
class RequestAiSettingsForm extends ConfigFormBase
{

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'request_ai_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return ['request_ai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->config('request_ai.settings');

    // Définition des modèles.
    $known_grok_models = ['grok-3', 'grok-4', 'grok-5', 'grok-6', 'grok-7'];
    $known_gemini_models = ['gemini-1.5', 'gemini-2.0', 'gemini-2.5', 'gemini-3.5', 'gemini-4.5', 'gemini-5.5'];

    $stored_model_grok = $config->get('model_grok');
    $is_custom_grok = $stored_model_grok && !in_array($stored_model_grok, $known_grok_models, TRUE);

    $stored_model_gemini = $config->get('model_gemini');
    $is_custom_gemini = $stored_model_gemini && !in_array($stored_model_gemini, $known_gemini_models, TRUE);

    // Préparation des options.
    $grok_options = array_combine($known_grok_models, array_map('ucfirst', $known_grok_models));
    $grok_options['_other_'] = $this->t('Other (custom)');

    $gemini_options = array_combine($known_gemini_models, array_map('ucfirst', $known_gemini_models));
    $gemini_options['_other_'] = $this->t('Other (custom)');

    // --- API YouTube ---
    $form['api_config'] = [
      '#type' => 'details',
      '#title' => $this->t('YouTube Configuration'),
      '#open' => TRUE,
    ];

    $form['api_config']['api_key_ytb'] = [
      '#type' => 'key_select',
      '#title' => $this->t('YouTube API Key'),
      '#default_value' => $config->get('api_key_ytb'),
      '#required' => TRUE,
    ];

    $form['api_config']['api_url_ytb'] = [
      '#type' => 'textfield',
      '#title' => $this->t('YouTube API URL'),
      '#default_value' => $config->get('api_url_ytb'),
      '#required' => TRUE,
      '#size' => 100,
    ];

    $form['api_config']['excluded_channels_ytb'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Exclude Youtube channels'),
      '#default_value' => $config->get('excluded_channels_ytb'),
      '#required' => TRUE,
      '#maxlength' => 512,
      '#size' => 300,
      '#description' => $this->t('ex: "ChannelId1|ChannelId2", où ChannelId1 est de la forme UCxxxxxx. cf html source de la chaine Youtube à exclure.'),
    ];

    // --- AI Model Settings ---
    $form['model_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Model Settings'),
      '#open' => TRUE,
    ];

    $form['model_settings']['engine'] = [
      '#type' => 'select',
      '#title' => $this->t('Which engine ?'),
      '#options' => [
        'grok' => $this->t('xAI Grok'),
        'gemini' => $this->t('Google Gemini'),
        'openrouter' => $this->t('OpenRouter'),
      ],
      '#default_value' => $config->get('engine') ?: 'grok',
      '#required' => TRUE,
    ];

    // SECTION GROK
    $form['model_settings']['grok_group'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('xAI Grok Configuration'),
      '#states' => ['visible' => [':input[name="engine"]' => ['value' => 'grok']]],
    ];
    $form['model_settings']['grok_group']['api_key_grok'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Grok API Key'),
      '#default_value' => $config->get('api_key_grok'),
    ];
    $form['model_settings']['grok_group']['model_grok'] = [
      '#type' => 'select',
      '#title' => $this->t('Grok Model'),
      '#options' => $grok_options,
      '#default_value' => $is_custom_grok ? '_other_' : ($stored_model_grok ?: 'grok-3'),
    ];
    $form['model_settings']['grok_group']['model_grok_custom'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom Grok model name'),
      '#default_value' => $is_custom_grok ? $stored_model_grok : '',
      '#states' => [
        'visible' => [':input[name="model_grok"]' => ['value' => '_other_']],
        'required' => [':input[name="model_grok"]' => ['value' => '_other_']],
      ],
    ];

    // SECTION GEMINI
    $form['model_settings']['gemini_group'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Google Gemini Configuration'),
      '#states' => ['visible' => [':input[name="engine"]' => ['value' => 'gemini']]],
    ];
    $form['model_settings']['gemini_group']['api_key_gemini'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Gemini API Key'),
      '#default_value' => $config->get('api_key_gemini'),
    ];
    $form['model_settings']['gemini_group']['model_gemini'] = [
      '#type' => 'select',
      '#title' => $this->t('Gemini Model'),
      '#options' => $gemini_options,
      '#default_value' => $is_custom_gemini ? '_other_' : ($stored_model_gemini ?: 'gemini-1.5'),
    ];
    $form['model_settings']['gemini_group']['model_gemini_custom'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom Gemini model name'),
      '#default_value' => $is_custom_gemini ? $stored_model_gemini : '',
      '#states' => [
        'visible' => [':input[name="model_gemini"]' => ['value' => '_other_']],
        'required' => [':input[name="model_gemini"]' => ['value' => '_other_']],
      ],
    ];

    // SECTION OPENROUTER
    $form['model_settings']['openrouter_group'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('OpenRouter Configuration'),
      '#states' => ['visible' => [':input[name="engine"]' => ['value' => 'openrouter']]],
    ];
    $form['model_settings']['openrouter_group']['api_key_openrouter'] = [
      '#type' => 'key_select',
      '#title' => $this->t('OpenRouter API Key'),
      '#default_value' => $config->get('api_key_openrouter'),
    ];
    $form['model_settings']['openrouter_group']['model_openrouter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom OpenRouter model name'),
      '#description' => $this->t('Identifiant du modèle tel que fourni par OpenRouter, ex : ~google/gemini-pro-latest'),
      '#default_value' => $config->get('model_openrouter') ?: '~google/gemini-pro-latest',
      '#size' => 60,
    ];

    $form['model_settings']['prompt_test'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt de test de requete AI.'),
      '#description' => $this->t('Le prompt par défaut qui sera utilisé lors du lancement de la commande Drush.  cf drush air --enginef="grok"'),
      '#default_value' => $config->get('prompt_test') ?: 'Exemple de prompt __debug__',
      '#rows' => 5,
    ];

    $form['model_settings']['prompt_get_ecuries'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt pour la récupération des écuries (ecurie-update)'),
      '#description' => $this->t('Le prompt complet utilisé par la commande drush ecurie-update. Doit contenir le jeton @lutteurs_sans_ecurie.'),
      '#default_value' => $config->get('prompt_get_ecuries'),
      '#rows' => 6,
    ];

    $form['model_settings']['prompt_get_real_dates'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt pour la récupération des dates réelles (date-reelle-update)'),
      '#description' => $this->t('Le prompt complet utilisé par la commande drush date-reelle-update. Doit contenir le jeton @combats_dates_incertaines.'),
      '#default_value' => $config->get('prompt_get_real_dates'),
      '#rows' => 6,
    ];

    // --- SECTION INDEXATION ---
    $form['indexing_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Indexing & SEO Settings'),
      '#open' => TRUE,
    ];

    // Google Indexing
    $form['indexing_settings']['google_group'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Google Indexing API'),
    ];
    $form['indexing_settings']['google_group']['google_indexing_api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Google Indexing API Key (JSON)'),
      '#default_value' => $config->get('google_indexing_api_key'),
    ];

    // Bing / IndexNow
    $form['indexing_settings']['bing_group'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Bing IndexNow Configuration'),
    ];
    $form['indexing_settings']['bing_group']['bing_indexnow_api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Bing IndexNow API Key'),
      '#default_value' => $config->get('bing_indexnow_api_key'),
    ];
    $form['indexing_settings']['bing_group']['bing_indexnow_api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bing IndexNow API URL'),
      '#default_value' => $config->get('bing_indexnow_api_url'),
    ];

    // Baidu
    $form['indexing_settings']['baidu_group'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Baidu Indexing Configuration'),
    ];
    $form['indexing_settings']['baidu_group']['baidu_api_token'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Baidu API Token'),
      '#default_value' => $config->get('baidu_api_token'),
    ];
    $form['indexing_settings']['baidu_group']['baidu_api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Baidu API URL'),
      '#default_value' => $config->get('baidu_api_url'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // Récupération et nettoyage du modèle Grok.
    $model_grok = $form_state->getValue('model_grok');
    if ($model_grok === '_other_') {
      $model_grok = trim($form_state->getValue('model_grok_custom'));
    }

    // Récupération et nettoyage du modèle Gemini.
    $model_gemini = $form_state->getValue('model_gemini');
    if ($model_gemini === '_other_') {
      $model_gemini = trim($form_state->getValue('model_gemini_custom'));
    }

    $this->config('request_ai.settings')
      ->set('engine', $form_state->getValue('engine'))
      ->set('api_key_ytb', $form_state->getValue('api_key_ytb'))
      ->set('api_url_ytb', $form_state->getValue('api_url_ytb'))
      ->set('excluded_channels_ytb', $form_state->getValue('excluded_channels_ytb'))
      ->set('api_key_grok', $form_state->getValue('api_key_grok'))
      ->set('api_key_gemini', $form_state->getValue('api_key_gemini'))
      ->set('api_key_openrouter', $form_state->getValue('api_key_openrouter'))
      ->set('model_grok', $model_grok)
      ->set('model_gemini', $model_gemini)
      ->set('model_openrouter', trim($form_state->getValue('model_openrouter')))
      ->set('prompt_test', $form_state->getValue('prompt_test'))
      ->set('prompt_get_ecuries', $form_state->getValue('prompt_get_ecuries'))
      ->set('prompt_get_real_dates', $form_state->getValue('prompt_get_real_dates'))
      ->set('google_indexing_api_key', $form_state->getValue('google_indexing_api_key'))
      ->set('bing_indexnow_api_key', $form_state->getValue('bing_indexnow_api_key'))
      ->set('bing_indexnow_api_url', $form_state->getValue('bing_indexnow_api_url'))
      ->set('baidu_api_token', $form_state->getValue('baidu_api_token'))
      ->set('baidu_api_url', $form_state->getValue('baidu_api_url'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
