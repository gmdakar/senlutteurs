<?php

namespace Drupal\combats_manquants\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\shared_lib\Tools\SomeTools;

/**
 * Formulaire public (anonyme) de proposition d'un combat manquant.
 *
 * Étapes à la soumission (voir validateForm()/submitForm()) :
 *  1. Les 2 lutteurs doivent être des athlètes déjà reconnus en base
 *     (comme dans combat_propositions).
 *  2. Vérification de doublon SYNCHRONE : un combat "confrontation" existe
 *     déjà entre ces 2 lutteurs (peu importe l'ordre) sur l'année indiquée
 *     (jour/mois et verdict ignorés) => on bloque avec un message, rien
 *     n'est mis en queue.
 *  3. Sinon, l'item est mis en queue (combats_manquants_ai_queue) pour
 *     confirmation ASYNCHRONE par IA (voir CombatsManquantsAiQueue) : le
 *     visiteur n'attend jamais le résultat de l'appel IA, il est simplement
 *     remercié et informé que sa contribution sera étudiée.
 */
class CombatManquantForm extends FormBase {

  public function getFormId() {
    return 'combat_manquant_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#prefix'] = '<div id="combat-manquant-form-wrapper">';
    $form['#suffix'] = '</div>';
    $form['#attached']['library'][] = 'combats_manquants/combats_manquants_form';

    // Chosen masque le <select> (display:none). La validation HTML5 native
    // sur un select required caché bloque la soumission SANS aucun message
    // visible ("rien ne se passe"). On laisse Drupal valider côté serveur.
    $form['#attributes']['novalidate'] = 'novalidate';

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Vous pensez qu’un combat manque dans le palmarès d’un lutteur ? Proposez-le ci-dessous, il sera vérifié avant ajout.') . '</p>',
    ];

    // 1. Année du combat (liste déroulante 1950 -> année en cours).
    $currentYear = (int) date('Y');
    $years = array_combine(range($currentYear, 1950), range($currentYear, 1950));

    $form['annee'] = [
      '#type' => 'select',
      '#title' => $this->t('Année du combat'),
      '#options' => $years,
      '#default_value' => $currentYear,
      '#required' => TRUE,
      // Chosen (site-wide) casse la soumission AJAX + le rebuild du select.
      '#chosen' => FALSE,
    ];

    // 2. Lutteur 1 (autocomplétion nom + écurie).
    $form['lutteur_1'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Lutteur 1'),
      '#autocomplete_route_name' => 'combats_manquants.autocomplete_lutteur',
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => $this->t('Entrez les premières lettres du nom…'),
        'autocomplete' => 'off',
      ],
    ];

    // Champ caché : écurie du lutteur 1, pré-rempli en JS lors de la
    // sélection d'une suggestion (voir combats_manquants.js). Purement
    // indicatif côté UX : à la soumission, l'écurie réellement transmise à
    // l'IA est toujours relue depuis le node athlète (field_ecurie), pas
    // depuis ce champ, pour éviter toute valeur non fiable.
    $form['lutteur_1_ecurie'] = [
      '#type' => 'hidden',
      '#default_value' => '',
    ];

    // 3. Lutteur 2 (autocomplétion nom + écurie).
    $form['lutteur_2'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Lutteur 2'),
      '#autocomplete_route_name' => 'combats_manquants.autocomplete_lutteur',
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => $this->t('Entrez les premières lettres du nom…'),
        'autocomplete' => 'off',
      ],
    ];

    $form['lutteur_2_ecurie'] = [
      '#type' => 'hidden',
      '#default_value' => '',
    ];

    // 4. Verdict : n'apparaît (visuellement) qu'une fois les 2 lutteurs
    // renseignés. Les libellés "victoire_1"/"victoire_2" sont réécrits en
    // JS avec les noms saisis à titre cosmétique (voir combats_manquants.js
    // ::updateVerdictLabels) ; le nom RÉEL et canonique (celui de l'athlète
    // reconnu en base) est toujours celui utilisé côté serveur dans
    // validateForm()/submitForm(), quel que soit ce qui est affiché ici.
    $form['verdict'] = [
      '#type' => 'select',
      '#title' => $this->t('Verdict'),
      '#options' => [
        'victoire_1' => $this->t('Victoire du lutteur 1'),
        'victoire_2' => $this->t('Victoire du lutteur 2'),
        'nul' => $this->t('Match nul'),
        'litige' => $this->t('Litige (sans verdict)'),
      ],
      '#empty_option' => $this->t('- Sélectionnez -'),
      '#required' => TRUE,
      '#chosen' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="lutteur_1"]' => ['filled' => TRUE],
          ':input[name="lutteur_2"]' => ['filled' => TRUE],
        ],
      ],
    ];

    // 5. Commentaire (facultatif) : contexte utile (source, souvenir, etc.)
    // transmis à l'IA en complément pour l'aider à confirmer le combat.
    $form['commentaire'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Commentaire (facultatif)'),
      '#description' => $this->t('Toute précision utile : contexte, source, ce dont vous vous souvenez…'),
      '#rows' => 3,
      '#required' => FALSE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Proposer ce combat'),
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'wrapper' => 'combat-manquant-form-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Vérification en cours…'),
        ],
      ],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $annee = (int) $form_state->getValue('annee');
    $lutteur_1_raw = (string) $form_state->getValue('lutteur_1');
    $lutteur_2_raw = (string) $form_state->getValue('lutteur_2');
    $verdictKey = $form_state->getValue('verdict');

    $lutteur_1_tech = SomeTools::technicalWrestlerName(SomeTools::correctNames($lutteur_1_raw));
    $lutteur_2_tech = SomeTools::technicalWrestlerName(SomeTools::correctNames($lutteur_2_raw));

    if ($lutteur_1_tech === $lutteur_2_tech) {
      $form_state->setErrorByName('lutteur_2', $this->t('Les deux lutteurs doivent être différents.'));
      return;
    }

    $node_storage = \Drupal::entityTypeManager()->getStorage('node');

    // Résout un nom technique en node athlète (charge le premier trouvé).
    $resolve_athlete = function (string $tech_name) use ($node_storage) {
      $nids = $node_storage->getQuery()
        ->condition('type', 'athlete')
        ->condition('field_athlete_nom_technique', $tech_name)
        ->accessCheck(FALSE)
        ->range(0, 1)
        ->execute();
      if (empty($nids)) {
        return NULL;
      }
      return $node_storage->load(reset($nids));
    };

    $lutteur1_node = $resolve_athlete($lutteur_1_tech);
    $lutteur2_node = $resolve_athlete($lutteur_2_tech);

    if (!$lutteur1_node) {
      $form_state->setErrorByName('lutteur_1', $this->t('Le lutteur "@name" n\'est pas reconnu.', ['@name' => $lutteur_1_raw]));
    }
    if (!$lutteur2_node) {
      $form_state->setErrorByName('lutteur_2', $this->t('Le lutteur "@name" n\'est pas reconnu.', ['@name' => $lutteur_2_raw]));
    }
    if (!$lutteur1_node || !$lutteur2_node) {
      return;
    }

    if (empty($verdictKey)) {
      $form_state->setErrorByName('verdict', $this->t('Merci de sélectionner un verdict.'));
      return;
    }

    // Vérification de doublon SYNCHRONE : même paire de lutteurs (peu
    // importe l'ordre), même année (jour/mois et verdict ignorés). On
    // n'appelle l'IA que si aucun combat de cette paire n'existe déjà pour
    // cette année-là.
    $yearStart = $annee . '-01-01';
    $yearEnd = $annee . '-12-31';

    $confrontationQuery = $node_storage->getQuery()
      ->condition('type', 'confrontation')
      ->condition('field_confrontation_date', $yearStart, '>=')
      ->condition('field_confrontation_date', $yearEnd, '<=')
      ->accessCheck(FALSE)
      ->range(0, 1);
    $pairGroup = $confrontationQuery->orConditionGroup()
      ->condition($confrontationQuery->andConditionGroup()
        ->condition('field_athlete_1', $lutteur1_node->id())
        ->condition('field_athlete_2', $lutteur2_node->id()))
      ->condition($confrontationQuery->andConditionGroup()
        ->condition('field_athlete_1', $lutteur2_node->id())
        ->condition('field_athlete_2', $lutteur1_node->id()));
    $existing = $confrontationQuery->condition($pairGroup)->execute();

    if (!empty($existing)) {
      $form_state->setError($form, $this->t('Ce combat existe déjà dans notre base pour l’année @annee. Merci pour votre vigilance !', ['@annee' => $annee]));
      return;
    }

    // Mémorisation des nodes résolus pour submitForm() (évite de refaire
    // les mêmes requêtes).
    $form_state->set('lutteur_1_node_id', (int) $lutteur1_node->id());
    $form_state->set('lutteur_2_node_id', (int) $lutteur2_node->id());
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $annee = (int) $form_state->getValue('annee');
    $verdictKey = $form_state->getValue('verdict');
    $commentaire = trim((string) $form_state->getValue('commentaire'));

    $lutteur1Nid = $form_state->get('lutteur_1_node_id');
    $lutteur2Nid = $form_state->get('lutteur_2_node_id');

    // Mise en queue : le traitement (nouvel appel de vérification + appel
    // IA + insertion éventuelle) est entièrement ASYNCHRONE, traité au
    // prochain cron par CombatsManquantsAiQueue. On ne transmet que les
    // NID des athlètes : le nom canonique et l'écurie sont relus à jour au
    // moment du traitement, pas figés ici (voir CombatsManquantsAiQueue).
    \Drupal::queue('combats_manquants_ai_queue')->createItem([
      'annee' => $annee,
      'lutteur_1_nid' => $lutteur1Nid,
      'lutteur_2_nid' => $lutteur2Nid,
      'verdict_key' => $verdictKey,
      'commentaire' => $commentaire,
      'submitted' => \Drupal::time()->getRequestTime(),
    ]);

    \Drupal::logger('combats_manquants')->notice(
      'Nouvelle proposition de combat internaute mise en queue : lutteur1 nid @n1, lutteur2 nid @n2, année @annee, verdict @v.',
      ['@n1' => $lutteur1Nid, '@n2' => $lutteur2Nid, '@annee' => $annee, '@v' => $verdictKey]
    );
  }

  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    if ($form_state->getErrors()) {
      // Messages DANS le wrapper : la région messages de la page n'est pas
      // mise à jour par un ReplaceCommand du formulaire.
      $form['status_messages'] = [
        '#type' => 'status_messages',
        '#weight' => -1000,
      ];
      // ReplaceCommand (et non HtmlCommand) : $form inclut déjà #prefix /
      // #suffix. HtmlCommand imbriquait un 2e wrapper du même id et
      // cassait l'autocomplete + le bouton AJAX.
      $response->addCommand(new ReplaceCommand('#combat-manquant-form-wrapper', $form));
      return $response;
    }

    $message = $this->t('Merci pour votre contribution ! Notre système va étudier ce combat et l’ajoutera au palmarès s’il est confirmé.');
    $response->addCommand(new ReplaceCommand(
      '#combat-manquant-form-wrapper',
      '<div id="combat-manquant-form-wrapper"><div class="combat-manquant-success">' . $message . '</div></div>'
    ));

    return $response;
  }

}
