<?php

namespace Drupal\shared_lib\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Base class for Gemini settings forms.
 * Provides common prompt/defaultResponse/promptFindVideos fields.
 */
abstract class BaseGeminiSettingsForm extends ConfigFormBase {

  /**
   * Returns the config object name.
   *
   * Each child must implement this.
   */
  abstract protected function getConfigName(): string;

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [$this->getConfigName()];
  }

  /**
   * Adds the common Gemini fields to the form.
   */
  protected function addCommonFields(array &$form, $config): void {
    $form['prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default Prompt'),
      '#default_value' => $config->get('prompt'),
      '#description' => $this->t('Default text prompt for API requests.<br>NB: add __ignore__ or __debug__.'),
      '#required' => TRUE,
    ];

    $form['default_response'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default Response'),
      '#default_value' => $config->get('default_response'),
      '#description' => $this->t('Default JSON response for tests. Add __debug__ to log response. Ex. {"date_jour":"2026-06-15","combats_lutte_recents":[{"date_precise":"2026-06-14","is_lutte_simple":0,"lutteur1":"Prince","lutteur2":"Lac de Guiers 2","resultat":"Lac de Guiers 2","face2face":[],"video_combat":[],"id_text":"combat_001"}]}'),
    ];

    $form['promptFindVideos'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default Prompt Find Videos'),
      '#default_value' => $config->get('promptFindVideos'),
      '#description' => $this->t('Default prompt to search videos. Add __ignore__ or __debug__.'),
      '#required' => TRUE,
    ];
  }

  /**
   * Saves the common field values.
   */
  protected function saveCommonFields(FormStateInterface $form_state): array {
    return [
      'prompt' => $form_state->getValue('prompt'),
      'default_response' => $form_state->getValue('default_response'),
      'promptFindVideos' => $form_state->getValue('promptFindVideos'),
    ];
  }

}
