<?php

namespace Drupal\gemini_nextcombats_cron\Form;

use Drupal\shared_lib\Form\BaseGeminiSettingsForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Gemini Next Combats Cron settings.
 */
class GeminiCombatCronSettingsForm extends BaseGeminiSettingsForm {

  public function getFormId() {
    return 'gemini_nextcombats_cron_settings_form';
  }

  protected function getConfigName(): string {
    return 'gemini_nextcombats_cron.settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config($this->getConfigName());

    // Common fields from the base class.
    $this->addCommonFields($form, $config);

    // Specific field: prompt_confirm_wrestlers.
    $form['prompt_confirm_wrestlers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt Confirmation Lutteurs avant insertion'),
      '#default_value' => $config->get('prompt_confirm_wrestlers'),
      '#description' => $this->t('Prompt appelé AVANT la création de chaque nouveau combat "à venir", pour demander à l\'IA de confirmer/corriger la bonne variante de chacun des 2 lutteurs (via son historique récent + les suggestions de noms proches déjà en base) avant insertion. Placeholders disponibles : @wrestler1, @wrestler2, @date, @historique1, @historique2 (JSON des 2 derniers combats connus de chaque lutteur), @suggestions1, @suggestions2 (JSON de variantes de noms proches déjà en base). Laisser vide pour désactiver cette étape (aucun blocage). Doit retourner UNIQUEMENT un JSON de la forme {"lutteur1":"...","lutteur2":"...","date_precise":"...","correspond_aux_profils":true,"commentaire":"..."}.'),
      '#required' => FALSE,
    ];

    
    // Specific field: prompt_ai_doublons_avenir.
    $form['prompt_ai_doublons_avenir'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt Résolution IA des doublons a_venir (même date)'),
      '#default_value' => $config->get('prompt_ai_doublons_avenir'),
      '#description' => $this->t('Prompt utilisé par la queue a_venir_doublons_ai_queue (drush cdbl --task=ai_avenir) et par l\'arbitrage synchrone à l\'insertion (ProcessCombatQueue) pour trancher les groupes de combats À VENIR suspects : même date + au moins un lutteur en commun (un lutteur ne peut pas avoir deux combats à venir distincts le même jour). Placeholders : @pivot, @ecurie_pivot, @nb_combats_pivot, @date, @combats_json, @nb_combats_adversaires, @historique_adversaires, @suggestions_adversaires. Doit retourner UNIQUEMENT un JSON {"verdicts":[{"field_id_text":"...","delete":0,"raison":"..."}, ...]} — UN élément par combat, delete=1 pour supprimer, delete=0 pour conserver (au moins un conservé). Laisser vide pour désactiver.'),
      '#required' => FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $this->saveCommonFields($form_state);

    $this->config($this->getConfigName())
      ->set('prompt', $values['prompt'])
      ->set('default_response', $values['default_response'])
      ->set('promptFindVideos', $values['promptFindVideos'])
      ->set('prompt_confirm_wrestlers', $form_state->getValue('prompt_confirm_wrestlers'))
      ->set('prompt_ai_doublons_avenir', $form_state->getValue('prompt_ai_doublons_avenir'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
