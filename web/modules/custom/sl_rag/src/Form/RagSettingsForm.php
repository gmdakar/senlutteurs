<?php

namespace Drupal\sl_rag\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration du module SL RAG (recherche vectorielle B1).
 */
class RagSettingsForm extends ConfigFormBase
{

  public function getFormId()
  {
    return 'sl_rag_settings_form';
  }

  protected function getEditableConfigNames()
  {
    return ['sl_rag.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->config('sl_rag.settings');

    $form['vector'] = [
      '#type' => 'details',
      '#title' => $this->t('Recherche vectorielle (option B1)'),
      '#open' => TRUE,
    ];

    $form['vector']['vector_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activer la recherche vectorielle'),
      '#description' => $this->t('Si coché, sl-rag:ask interroge l’API vectorielle (Pinecone via search_api) au lieu du filtrage SQL basique.'),
      '#default_value' => (bool) $config->get('vector_enabled'),
    ];

    $form['vector']['vector_api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL de l’API de recherche'),
      '#description' => $this->t('Ex. http://host.docker.internal:8080 (API locale via DDEV) ou https://ton-app.up.railway.app'),
      '#default_value' => $config->get('vector_api_url') ?: 'http://host.docker.internal:8080',
      '#required' => FALSE,
    ];

    $form['vector']['vector_api_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret API (Bearer)'),
      '#description' => $this->t('Doit correspondre à API_SECRET dans le .env de search_api.py'),
      '#default_value' => $config->get('vector_api_secret') ?: '',
    ];

    $form['vector']['vector_top_k'] = [
      '#type' => 'number',
      '#title' => $this->t('Nombre de fiches à récupérer (top_k)'),
      '#default_value' => (int) ($config->get('vector_top_k') ?: 10),
      '#min' => 1,
      '#max' => 50,
    ];

    $form['vector']['vector_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout HTTP (secondes)'),
      '#default_value' => (int) ($config->get('vector_timeout') ?: 30),
      '#min' => 5,
      '#max' => 120,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $this->config('sl_rag.settings')
      ->set('vector_enabled', (bool) $form_state->getValue('vector_enabled'))
      ->set('vector_api_url', rtrim((string) $form_state->getValue('vector_api_url'), '/'))
      ->set('vector_api_secret', (string) $form_state->getValue('vector_api_secret'))
      ->set('vector_top_k', (int) $form_state->getValue('vector_top_k'))
      ->set('vector_timeout', (int) $form_state->getValue('vector_timeout'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
