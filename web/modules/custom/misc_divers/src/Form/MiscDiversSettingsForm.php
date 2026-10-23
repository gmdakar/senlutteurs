<?php

namespace Drupal\misc_divers\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class MiscDiversSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames() {
    return ['misc_divers.settings'];
  }

  public function getFormId() {
    return 'misc_divers_cron_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('misc_divers.settings');
    
    // Retrieve the site's system email address
    $site_mail = $this->configFactory->get('system.site')->get('mail');

    $form['email_destinataire_logs_errors'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email destinataire des logs erreurs'),
      // Use the site mail as a fallback if the config is not yet set
      '#default_value' => $config->get('email_destinataire_logs_errors') ?: $site_mail,
      '#description' => $this->t('Ex: mymail1@gmail.com;mymail2@gmail.com'),
      '#size' => 50,
      '#required' => TRUE, // Optional: usually a good idea for logs
    ];

    $form['seuil_max_number_fights'] = [
      '#type' => 'number',
      '#title' => $this->t('Seuil minimum de nombre de combats'),
      '#default_value' => $config->get('seuil_max_number_fights') ?? 6,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Seuil minimum de nombre de combats pour autoriser recherche de combats pour un lutteur (entre 1 et 100). Donc si 6 alors même si 5 combats on autorisera.'),
    ];

    $form['seuil_cpt_to_complet_combats'] = [
      '#type' => 'number',
      '#title' => $this->t('Seuil compteur recherche automatique de combats'),
      '#default_value' => $config->get('seuil_cpt_to_complet_combats') ?? 5,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('En gros, si une page de lutteur est affichée N fois et qu\'il a moins de seuil_max_number_fights combats alors on autorise la recherche automatique de combats supplémentaires pour ce lutteur.'),
    ];

    $form['seuil_max_nb_reset_cpt_lutteur'] = [
      '#type' => 'number',
      '#title' => $this->t('Seuil nombre de cycles max de recherche automatique de combats'),
      '#default_value' => $config->get('seuil_max_nb_reset_cpt_lutteur') ?? 5,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('En gros, si une page de lutteur est affichée seuil_cpt_to_complet_combats fois à N reprise alors on stoppe définitivement la recherche automatique même si moins seuil_max_number_fights combats.'),
    ];

    $form['max_nb_signalements'] = [
      '#type' => 'number',
      '#title' => $this->t('Seuil de signalements sur un même combat'),
      '#default_value' => $config->get('max_nb_signalements') ?? 5,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Nombre max de fois pour declencher au delà, une action pour tout signalement sur un même combat (entre 1 et 100)'),
    ];

    $form['max_votes_proposition'] = [
      '#type' => 'number',
      '#title' => $this->t('Nombre max de propositions de votes'),
      '#default_value' => $config->get('max_votes_proposition') ?? 5,
      '#min' => 5,
      '#max' => 15,
      '#description' => $this->t('Nombre max de propositions de votes (entre 5 et 15)'),
    ];
    
    $form['cron_iterations'] = [
      '#type' => 'number',
      '#title' => $this->t('Nombre d’itérations du sitemap'),
      '#default_value' => $config->get('cron_iterations') ?? 5,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Nombre de lots à exécuter à chaque appel du cron personnalisé.'),
    ];

    // L'existant pour autre chose
    $default_remplacements = $config->get('remplacements') ?? [];
    $form['remplacements'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Remplacements (Existant)'),
      '#default_value' => $this->arrayToText($default_remplacements),
    ];

    // 🆕 AJOUT : Nouveau champ spécifique pour YouTube
    $default_remplacements_ytb = $config->get('remplacementsYtb') ?? [];
    $form['remplacementsYtb'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Synonymes de noms de lutteurs (uniquement pour recherche API YouTube)'),
      '#default_value' => $this->arrayToText($default_remplacements_ytb),
      '#description' => $this->t('Saisir une paire par ligne au format <code>mot_incorrect|mot_correct</code>.<br>Exemple : <code>mohamed ndao tyson|Tyson</code>.'),
    ];

    // --- YouTube Scoring & Filtering ---
    $form['youtube_logic'] = [
      '#type' => 'details',
      '#title' => $this->t('YouTube Search Logic (CSV)'),
      '#open' => TRUE,
      '#description' => $this->t('Saisissez les mots séparés par des virgules.'),
    ];

    $form['youtube_logic']['exclude_words_ytb'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excluded Keywords'),
      '#default_value' => $config->get('exclude_words_ytb'),
      '#description' => $this->t('Ex: analyse, debrief, interview'),
    ];

    $form['youtube_logic']['archive_words_ytb'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Archive Keywords'),
      '#default_value' => $config->get('archive_words_ytb'),
      '#description' => $this->t('Ex: archive, retro, retrospective'),
      '#maxlength' => 356, 
      '#size' => 300,
    ];

    $form['youtube_logic']['face2face_patterns_ytb'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Face2Face Keywords'),
      '#default_value' => $config->get('face2face_patterns_ytb'),
      '#description' => $this->t('Ex: face a face, confrontation, pesee'),
      '#maxlength' => 356, 
      '#size' => 300,
    ];

    $form['youtube_logic']['combat_patterns_ytb'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Combat Keywords'),
      '#default_value' => $config->get('combat_patterns_ytb'),
      '#description' => $this->t('Ex: combat, match, fight'),
      '#maxlength' => 356, 
      '#size' => 300,
    ];

    $form['youtube_logic']['separators_patterns_ytb'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Seperators Keywords'),
      '#default_value' => $config->get('separators_patterns_ytb'),
      '#description' => $this->t('Ex: vs, contre'),
      '#maxlength' => 356, 
      '#size' => 300,
    ];

    $form['youtube_logic']['integral_patterns_ytb'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full Match Keywords (+20 pts)'),
      '#default_value' => $config->get('integral_patterns_ytb'),
      '#description' => $this->t('Ex: integralite, full, complet'),
      '#maxlength' => 356, 
      '#size' => 300,
    ];

    $form['youtube_logic']['victory_patterns_ytb'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Victory Keywords (+50 pts)'),
      '#default_value' => $config->get('victory_patterns_ytb'),
      '#description' => $this->t('Ex: bat, gagne, victoire, vainqueur'),
      '#maxlength' => 356, 
      '#size' => 300,
    ];

    $form['youtube_logic']['ko_patterns_ytb'] = [
      '#type' => 'textfield',
      '#title' => $this->t('KO Keywords (+60 pts)'),
      '#default_value' => $config->get('ko_patterns_ytb'),
      '#description' => $this->t('Ex: mis ko, met ko, dodo'),
      '#maxlength' => 356, 
      '#size' => 300,
    ];


    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Conversion des deux champs textarea en tableaux
    $remplacements = $this->textToArray($form_state->getValue('remplacements'));
    $remplacementsYtb = $this->textToArray($form_state->getValue('remplacementsYtb'));

    $this->configFactory->getEditable('misc_divers.settings')
      ->set('email_destinataire_logs_errors', $form_state->getValue('email_destinataire_logs_errors'))
      ->set('cron_iterations', $form_state->getValue('cron_iterations'))
      ->set('max_votes_proposition', $form_state->getValue('max_votes_proposition'))
      ->set('max_nb_signalements', $form_state->getValue('max_nb_signalements'))
      ->set('seuil_max_number_fights', $form_state->getValue('seuil_max_number_fights'))
      ->set('seuil_cpt_to_complet_combats', $form_state->getValue('seuil_cpt_to_complet_combats'))
      ->set('seuil_max_nb_reset_cpt_lutteur', $form_state->getValue('seuil_max_nb_reset_cpt_lutteur'))
      ->set('remplacements', $remplacements) 
      ->set('remplacementsYtb', $remplacementsYtb)
      ->set('exclude_words_ytb', $form_state->getValue('exclude_words_ytb'))
      ->set('archive_words_ytb', $form_state->getValue('archive_words_ytb'))
      ->set('face2face_patterns_ytb', $form_state->getValue('face2face_patterns_ytb'))
      ->set('combat_patterns_ytb', $form_state->getValue('combat_patterns_ytb'))
      ->set('separators_patterns_ytb', $form_state->getValue('separators_patterns_ytb'))
      ->set('integral_patterns_ytb', $form_state->getValue('integral_patterns_ytb'))
      ->set('victory_patterns_ytb', $form_state->getValue('victory_patterns_ytb'))
      ->set('ko_patterns_ytb', $form_state->getValue('ko_patterns_ytb'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  private function textToArray(string $text): array {
    $lines = preg_split('/\r\n|\r|\n/', trim($text));
    $array = [];
    foreach ($lines as $line) {
      if (strpos($line, '|') !== FALSE) {
        [$k, $v] = array_map('trim', explode('|', $line, 2));
        if ($k && $v) {
          $array[$k] = $v;
        }
      }
    }
    return $array;
  }

  private function arrayToText(array $array): string {
    $lines = [];
    foreach ($array as $k => $v) {
      $lines[] = "$k|$v";
    }
    return implode("\n", $lines);
  }

}