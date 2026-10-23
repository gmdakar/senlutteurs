<?php

namespace Drupal\combats_manquants\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure le prompt IA utilisé pour confirmer les combats proposés par
 * les internautes (voir CombatsManquantsAiQueue::buildPrompt()).
 */
class CombatsManquantsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'combats_manquants_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['combats_manquants.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('combats_manquants.settings');

    $form['prompt_confirmation_combat'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt de confirmation IA du combat proposé'),
      '#default_value' => $config->get('prompt_confirmation_combat'),
      '#rows' => 18,
      '#required' => TRUE,
      '#description' => $this->t('Prompt envoyé au moteur IA configuré dans <a href=":url">Request AI</a> (via sl_live_search) pour confirmer l’existence d’un combat proposé par un internaute, AVANT insertion automatique en base. Variables disponibles, remplacées automatiquement avant envoi :<br>
        <ul>
          <li><code>@date_combat</code> : année indiquée par l’internaute (ex. "2013").</li>
          <li><code>@lutteur_1</code> / <code>@lutteur_2</code> : nom canonique de chaque lutteur (tel qu’enregistré en base).</li>
          <li><code>@ecurie_1</code> / <code>@ecurie_2</code> : écurie respective de chaque lutteur ("inconnue" si non renseignée).</li>
          <li><code>@verdict</code> : verdict indiqué par l’internaute (nom du vainqueur, "Nul" ou "Litige (sans verdict)").</li>
          <li><code>@commentaire</code> : commentaire libre laissé par l’internaute (peut être vide).</li>
        </ul>
        Le prompt doit demander une réponse JSON stricte contenant au minimum une clé booléenne <code>confirme</code>, une clé <code>date_combat</code> ("AAAA-MM-JJ", "AAAA-MM" ou <code>null</code>), et une clé <code>verdict_ia</code> : le verdict RÉEL (nom d’un des 2 lutteurs, "Nul" ou "Litige") si l’IA le connaît — y compris pour CORRIGER un verdict internaute erroné — ou <code>null</code> si le verdict n’est pas certain. Voir la valeur par défaut fournie à l’installation du module.', [
        ':url' => \Drupal\Core\Url::fromRoute('request_ai.settings')->toString(),
      ]),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('combats_manquants.settings')
      ->set('prompt_confirmation_combat', $form_state->getValue('prompt_confirmation_combat'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
