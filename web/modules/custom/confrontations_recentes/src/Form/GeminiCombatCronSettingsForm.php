<?php

namespace Drupal\confrontations_recentes\Form;

use Drupal\shared_lib\Form\BaseGeminiSettingsForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Confrontations Récentes Gemini Cron settings.
 */
class GeminiCombatCronSettingsForm extends BaseGeminiSettingsForm
{

  public function getFormId()
  {
    return 'confrontations_recentes_settings_form';
  }

  protected function getConfigName(): string
  {
    return 'confrontations_recentes.settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->config($this->getConfigName());

    // Common fields from base class.
    $this->addCommonFields($form, $config);

    // Specific field: prompt_verdict.
    $form['prompt_verdict'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default Prompt Verdict'),
      '#default_value' => $config->get('prompt_verdict'),
      '#description' => $this->t('Prompt used to determine verdict. Supports __ignore__ or __debug__.'),
      '#required' => TRUE,
    ];

    // Specific field: prompt_all_verdicts.
    $form['prompt_all_verdicts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default Prompt All Verdicts'),
      '#default_value' => $config->get('prompt_all_verdicts'),
      '#description' => $this->t('Prompt used to determine all verdicts (placeholder @resultBrutJson). Supports __ignore__ or __debug__. IMPORTANT — pour chaque combat encore à resultat "?", l\'IA doit indiquer combat_a_eu_lieu true/false : true uniquement si le combat a BIEN eu lieu (même si le vainqueur est encore flou) ; false ou omission si non joué / reporté / simple face-à-face / doute. Les combats non confirmés sont exclus automatiquement avant insertion. Format attendu par combat : garder les champs d\'origine + "combat_a_eu_lieu": true|false.'),
      '#required' => TRUE,
    ];

    // Specific field: prompt_update_unknown_results.
    $form['prompt_update_unknown_results'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt Update Unknown Results'),
      '#default_value' => $config->get('prompt_update_unknown_results'),
      '#description' => $this->t('Prompt used by drush confrontations_recentes:update-unknown-results (crur) to try resolving combats whose field_vainqueur is still "?". Must use the placeholder @fightsJson, and instruct the AI to return ONLY a pure JSON array of {field_id_text, resultat} for combats with a now-known result — omitting any combat still unresolved.'),
      '#required' => TRUE,
    ];

    // Specific field: prompt_confirm_wrestlers.
    $form['prompt_confirm_wrestlers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt Confirmation Lutteurs avant insertion'),
      '#default_value' => $config->get('prompt_confirm_wrestlers'),
      '#description' => $this->t('Prompt appelé AVANT la création de chaque nouvelle confrontation, pour demander à l\'IA de confirmer que le combat correspond bien aux 2 profils de lutteurs (via leur historique récent) et de corriger un éventuel mauvais suffixe générationnel (2, 3, junior...) avant insertion. Placeholders disponibles : @wrestler1, @wrestler2, @date, @historique1, @historique2 (JSON des 2 derniers combats connus de chaque lutteur), @suggestions1, @suggestions2 (JSON de variantes de noms proches déjà en base). Laisser vide pour désactiver cette étape (aucun blocage). Doit retourner UNIQUEMENT un JSON de la forme {"lutteur1":"...","lutteur2":"...","date_precise":"...","resultat":"...","correspond_aux_profils":true,"commentaire":"..."}.'),
      '#required' => FALSE,
    ];

    // Specific field: prompt_ai_doublons_combats.
    $form['prompt_ai_doublons_combats'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt Résolution IA des doublons de combats ambigus'),
      '#default_value' => $config->get('prompt_ai_doublons_combats'),
      '#description' => $this->t('Prompt utilisé par la queue confrontations_doublons_ai_queue (drush cdbl --task=ai_combats) pour trancher les groupes de combats suspectés de doublons que les règles automatiques ne peuvent pas résoudre : même lutteur pivot, adversaire dont le nom commence par les 3 mêmes premiers caractères (après normalisation) mais dates pouvant différer jusqu\'à 7 jours d\'écart (abus de langage / abréviation / erreur de saisie de date, ex. "Arsa" le 1er juin vs "Arsenal 2" le 2 juin vs "Arsa 2" le 6 juin). Placeholders disponibles : @pivot (nom du lutteur pivot), @ecurie_pivot (écurie du pivot, ou "inconnue"), @ecuries_adversaires (JSON objet {"Nom adversaire": "Écurie", ...}, ex: {"Modou lo": "Parcelles Mbollo", "Balla gaye 2": "Xam Sa Cossan"}), @date (date du premier combat du groupe, à titre indicatif), @combats_json (JSON des combats : nid, field_id_text, lutteur1, lutteur2, date, vainqueur), @suggestions (JSON des synonymes/variantes du pivot issus de misc_divers.settings:remplacementsYtb via SomeTools::getSynonyms — le nom du pivot lui-même est exclu). Doit retourner UNIQUEMENT un JSON array, UN élément par combat fourni en entrée (même field_id_text que fourni), de la forme [{"field_id_text":"...","lutteur1":"...","lutteur2":"...","date":"...","delete":0,"raison":"..."}, ...] où delete vaut 1 pour les combats à supprimer (doublons confirmés du même combat réel) et 0 pour le combat à conserver — le choix du combat conservé (meilleure orthographe, adversaire le mieux identifié...) est laissé libre à l\'IA. Ne JAMAIS mettre delete=1 sur tous les éléments : il doit toujours en rester au moins un à conserver. Note : la date finale du combat conservé est imposée programmatiquement (la plus ancienne du groupe), la valeur "date" renvoyée par l\'IA n\'est donc pas utilisée pour ce point. Laisser vide pour désactiver cette étape (aucun traitement IA, les groupes ambigus restent simplement en attente).'),
      '#required' => FALSE,
    ];

    // Specific field: prompt_ai_doublons_combats_debug_pivot_athlete (liste multiple + Chosen).
    $debugPivotIds = $config->get('prompt_ai_doublons_combats_debug_pivot_athlete') ?? [];
    // Rétrocompatibilité : ancienne config stockait un seul NID (int).
    if (!is_array($debugPivotIds)) {
      $debugPivotIds = $debugPivotIds ? [(int) $debugPivotIds] : [];
    }
    $debugPivotIds = array_values(array_filter(array_map('intval', $debugPivotIds)));

    // Options = tous les athlètes (nid => label). Select multiple pour que
    // le module Chosen puisse s'y attacher (Chosen ne gère pas entity_autocomplete).
    $athleteOptions = [];
    $athleteStorage = \Drupal::entityTypeManager()->getStorage('node');
    $athleteNids = $athleteStorage->getQuery()
      ->condition('type', 'athlete')
      ->accessCheck(FALSE)
      ->sort('title')
      ->execute();
    if ($athleteNids) {
      foreach ($athleteStorage->loadMultiple($athleteNids) as $athlete) {
        $label = $athlete->label();
        // Normalisation Unicode NFC : certains titres ont été importés en
        // forme décomposée (ex. "e" + accent combinant U+0301) au lieu de la
        // forme précomposée (ex. "é" = U+00E9). Les deux s'affichent à
        // l'identique mais diffèrent binairement, ce qui casse la recherche
        // texte stricte du widget Chosen côté client (l'utilisateur tape en
        // NFC via son clavier). On force donc NFC ici avant de construire
        // les options.
        if (class_exists('Normalizer')) {
          $normalized = \Normalizer::normalize($label, \Normalizer::FORM_C);
          if ($normalized !== FALSE) {
            $label = $normalized;
          }
        }
        $athleteOptions[$athlete->id()] = $label . ' (' . $athlete->id() . ')';
      }
    }

    $form['prompt_ai_doublons_combats_debug_pivot_athlete'] = [
      '#type' => 'select',
      '#title' => $this->t('Lutteurs à traiter (debug) pour le Prompt Résolution IA des doublons de combats ambigus'),
      '#options' => $athleteOptions,
      '#default_value' => $debugPivotIds,
      '#multiple' => TRUE,
      '#chosen' => TRUE,
      '#attributes' => [
        'class' => ['chosen-enable'],
        'data-placeholder' => $this->t('Choisir un ou plusieurs lutteurs…'),
      ],
      '#description' => $this->t('Optionnel — usage debug uniquement. Si un ou plusieurs lutteurs sont sélectionnés ici, la queue confrontations_doublons_ai_queue (drush cdbl --task=ai_combats) ne traite QUE les groupes de combats dont le pivot est l\'un de ces lutteurs ; tous les autres groupes sont ignorés silencieusement (mise en queue et traitement). Laisser vide pour le fonctionnement normal (tous les lutteurs traités).'),
      '#required' => FALSE,
    ];

    // Specific field: prompt_ai_cross_athlete_doublons.
    $form['prompt_ai_cross_athlete_doublons'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt Résolution IA des doublons cross-athlètes'),
      '#default_value' => $config->get('prompt_ai_cross_athlete_doublons'),
      '#description' => $this->t('Prompt utilisé par la queue confrontations_cross_athlete_doublons_ai_queue (drush cdbl --task=ai_cross [--days=7]). Deux combats publiés dans la fenêtre --days (défaut 7 jours), sans NID athlète en commun, mais noms proches des deux côtés (ex. Confiance vs Fils De Falaye / Confiance Tp vs Elou Fils De Falaye). Placeholders : @combats_json (les 2 combats : nid, field_id_text, lutteur1, lutteur2, lutteur1_nid, lutteur2_nid, date, vainqueur), @date. Doit retourner UNIQUEMENT un JSON : {"same_fight":1,"keep_combat_field_id":"...","keep_names":["NomA","NomB"],"fusions":[{"from":"NomVariante","to":"NomCanonique"}],"raison":"..."}. same_fight=0 si combats distincts. La direction réelle des fusions est ensuite corrigée par le code (survivant = fiche avec le plus de combats published). Laisser vide pour désactiver.'),
      '#required' => FALSE,
    ];

    // Specific field: prompt_ai_lutte_simple.
    $form['prompt_ai_lutte_simple'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt Résolution IA des combats suspectés de lutte simple'),
      '#default_value' => $config->get('prompt_ai_lutte_simple'),
      '#description' => $this->t('Prompt utilisé par la queue confrontations_lutte_simple_ai_queue (drush clsr) pour trancher, pour un lutteur ayant déjà au moins 50% de ses combats connus marqués "lutte simple" (field_is_lutte_simple), si ses combats encore non marqués en sont également. Placeholders disponibles : @pivot (nom du lutteur), @ecurie (nom de son écurie, ou "inconnue"), @ratio (pourcentage déjà en lutte simple, à titre indicatif), @combats_json (JSON de la liste des combats à trancher, 3 maximum : field_id_text, lutteur1, lutteur2, date, vainqueur). Doit retourner UNIQUEMENT un JSON array, UN élément par combat fourni en entrée (même field_id_text que fourni), de la forme [{"field_id_text":"...","is_lutte_simple":0,"raison":"..."}, ...] où is_lutte_simple vaut 1 si le combat est bien de la lutte simple (sans frappe) et 0 sinon. Laisser vide pour désactiver cette étape (les lots restent simplement en attente).'),
      '#required' => FALSE,
    ];

    // Specific field: prompt_ai_homonymes_generationnels.
    $form['prompt_ai_homonymes_generationnels'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt Résolution IA des homonymes générationnels suspects'),
      '#default_value' => $config->get('prompt_ai_homonymes_generationnels'),
      '#description' => $this->t('Prompt utilisé par la queue confrontations_homonymes_generationnels_ai_queue (drush chgr) pour vérifier, pour un lutteur "d\'origine" (sans suffixe générationnel) ayant au moins un homonyme générationnel en base (ex: "Boy Faye" / "Boy Faye 2" / "Boy Faye Junior") ET un trou d\'au moins 5 ans détecté dans sa carrière, si ses combats suspects (ceux à partir du trou) lui appartiennent réellement ou doivent être réattribués à un de ses homonymes. Placeholders disponibles : @pivot (nom du lutteur d\'origine), @ecurie (son écurie, ou "inconnue"), @homonymes (JSON des homonymes générationnels déjà connus en base), @suggestions (JSON de variantes de noms proches déjà en base, cf CombatService::confirmWrestlersBeforeInsert), @gap_annees, @gap_date_avant, @gap_date_apres (informations sur le trou détecté, à titre indicatif), @combats_json (JSON des combats suspects : nid, field_id_text, adversaire, date, vainqueur). Doit retourner UNIQUEMENT un JSON de la forme {"verdicts":[{"field_id_text":"...","nom_lutteur_correct":"...","commentaire":"..."}, ...]}, UN élément par combat fourni en entrée (même field_id_text). "nom_lutteur_correct" doit TOUJOURS être renseigné : soit le nom du pivot lui-même (@pivot, si le combat lui appartient bien), soit le nom exact d\'un des homonymes fourni dans @homonymes/@suggestions s\'il correspond, soit, si aucun ne correspond, un nouveau nom généré par l\'IA suivant le lutteur concerné (ex: "Boy Faye 4", ou un nom/surnom par lequel il est plus connu type "Boy Faye (Pout)", ou un nom lié à sa localité/écurie type "Boy Faye (Mor Fadam)" — sans les mots "écurie de"/"école de lutte"). Si l\'écurie du lutteur réattribué est connue, l\'ajouter en fin de valeur séparée par un slash, ex: "Boy Faye 3/Mor Fadam" (convention Nom/Ecurie déjà utilisée ailleurs dans ce module). Laisser vide pour désactiver cette étape (les lots restent simplement en attente).'),
      '#required' => FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $values = $this->saveCommonFields($form_state);

    $this->config($this->getConfigName())
      ->set('prompt', $values['prompt'])
      ->set('default_response', $values['default_response'])
      ->set('promptFindVideos', $values['promptFindVideos'])
      ->set('prompt_verdict', $form_state->getValue('prompt_verdict'))
      ->set('prompt_all_verdicts', $form_state->getValue('prompt_all_verdicts'))
      ->set('prompt_update_unknown_results', $form_state->getValue('prompt_update_unknown_results'))
      ->set('prompt_confirm_wrestlers', $form_state->getValue('prompt_confirm_wrestlers'))
      ->set('prompt_ai_doublons_combats', $form_state->getValue('prompt_ai_doublons_combats'))
      ->set('prompt_ai_cross_athlete_doublons', $form_state->getValue('prompt_ai_cross_athlete_doublons'))
      ->set('prompt_ai_lutte_simple', $form_state->getValue('prompt_ai_lutte_simple'))
      ->set('prompt_ai_homonymes_generationnels', $form_state->getValue('prompt_ai_homonymes_generationnels'))
      ->set('prompt_ai_doublons_combats_debug_pivot_athlete', array_values(array_filter(array_map(
        'intval',
        (array) ($form_state->getValue('prompt_ai_doublons_combats_debug_pivot_athlete') ?? [])
      ))))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
