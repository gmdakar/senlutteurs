<?php

namespace Drupal\sl_signalements\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure le prompt IA utilisé pour valider les actions de signalement
 * (voir SlSignalementsAiQueue::buildPrompt()).
 */
class SlSignalementsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'sl_signalements_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['sl_signalements.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('sl_signalements.settings');

    $form['prompt_validation_signalement'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt de validation IA du signalement'),
      '#default_value' => $config->get('prompt_validation_signalement'),
      '#rows' => 14,
      '#required' => TRUE,
      '#description' => $this->t('Prompt envoyé au moteur IA configuré dans <a href=":url">Request AI</a> (via sl_live_search) pour valider une action de signalement (changement de verdict, suppression de combat doublon/fantôme, variante d’athlète, etc.), AVANT application effective en base. Variables disponibles, remplacées automatiquement avant envoi :<br>
        <ul>
          <li><code>@nid</code> : ID du node confrontation concerné.</li>
          <li><code>@lutteur_1</code> / <code>@lutteur_2</code> : nom canonique des 2 athlètes de la confrontation.</li>
          <li><code>@date_combat</code> : date actuellement enregistrée pour le combat.</li>
          <li><code>@verdict_actuel</code> : verdict actuellement enregistré en base.</li>
          <li><code>@action_demandee</code> : libellé de l’action demandée par le signalement (ex. "Changer le verdict en faveur de X", "Supprimer ce combat (doublon)"...).</li>
          <li><code>@details</code> : précisions complémentaires éventuelles (nom d’athlète indiqué par l’internaute, variante proposée...), peut être vide.</li>
        </ul>
        Le prompt doit demander une réponse JSON stricte contenant au minimum une clé booléenne <code>confirme</code> - voir la valeur par défaut fournie à l’installation du module.', [
        ':url' => \Drupal\Core\Url::fromRoute('request_ai.settings')->toString(),
      ]),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('sl_signalements.settings')
      ->set('prompt_validation_signalement', $form_state->getValue('prompt_validation_signalement'))
      ->save();

    // Prise en compte immédiate du nouveau prompt (cache config / Redis).
    \Drupal::configFactory()->reset('sl_signalements.settings');
    \Drupal::cache('config')->delete('sl_signalements.settings');

    parent::submitForm($form, $form_state);
  }

}
