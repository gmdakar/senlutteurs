<?php

namespace Drupal\sl_rag\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sl_rag\Service\RagAskService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formulaire public RAG en AJAX : question + affichage de la réponse sans reload.
 */
class RagAskForm extends FormBase
{

  protected RagAskService $askService;

  public function __construct(RagAskService $askService)
  {
    $this->askService = $askService;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('sl_rag.ask_service')
    );
  }

  public function getFormId()
  {
    return 'sl_rag_ask_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form['#attributes']['class'][] = 'sl-rag-ask-form';
    $form['#prefix'] = '<div id="sl-rag-ask-form-wrapper" class="sl-rag-ask-form-wrapper">';
    $form['#suffix'] = '</div>';
    $form['#tree'] = TRUE;

    $form['question'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Votre question'),
      //'#placeholder' => $this->t('Ex. Qui a le meilleur palmarès entre Ndiol Walo et Domou Dangou ?'),
      '#required' => TRUE,
      '#rows' => 3,
      '#default_value' => $form_state->getValue('question') ?? '',
      '#attributes' => [
        'class' => ['sl-rag-ask-question'],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Poser la question'),
      '#attributes' => [
        'class' => ['button', 'button--primary', 'sl-rag-ask-submit'],
      ],
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'wrapper' => 'sl-rag-ask-form-wrapper',
        'effect' => 'fade',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Recherche en cours…'),
        ],
      ],
    ];

    // Overlay d'attente (affiché par JS au début de l'AJAX).
    $form['loading'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['sl-rag-ask-loading'],
        'hidden' => 'hidden',
        'aria-live' => 'polite',
        'aria-busy' => 'false',
      ],
      'inner' => [
        '#markup' =>
        '<div class="sl-rag-ask-loading-inner">'
          . '<div class="sl-rag-ask-spinner" aria-hidden="true"></div>'
          . '<p class="sl-rag-ask-loading-text">' . $this->t('Recherche en cours…') . '</p>'
          . '<p class="sl-rag-ask-loading-hint">' . $this->t('Cela peut prendre quelques secondes.') . '</p>'
          . '</div>',
      ],
    ];

    // Zone résultat.
    $result = $form_state->get('rag_result');
    $form['result'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'sl-rag-ask-result',
        'class' => ['sl-rag-ask-result'],
      ],
      '#weight' => 100,
    ];

    if (is_array($result)) {
      if (!empty($result['success']) && !empty($result['answer'])) {
        $safe = nl2br(htmlspecialchars((string) $result['answer'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $form['result']['answer'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['sl-rag-ask-answer']],
          'title' => [
            '#markup' => '<h3>' . $this->t('Réponse') . '</h3>',
          ],
          'body' => [
            '#markup' => '<div class="sl-rag-ask-answer-body">' . $safe . '</div>',
          ],
        ];
        if (!empty($result['docs_count'])) {
          /*$form['result']['meta'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['sl-rag-ask-meta']],
            'text' => [
              '#markup' => $this->t('Fiches utilisées : @n', ['@n' => (int) $result['docs_count']]),
            ],
          ];*/
        }
      } else {
        $error = $result['error'] ?? $result['answer'] ?? $this->t('Aucune réponse.');
        $form['result']['error'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['sl-rag-ask-error', 'messages', 'messages--error']],
          'msg' => [
            '#markup' => htmlspecialchars((string) $error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
          ],
        ];
      }
    } else {
      // Placeholder vide pour garder l'ancre AJAX stable.
      $form['result']['#attributes']['class'][] = 'sl-rag-ask-result--empty';
    }

    $form['#cache'] = [
      'max-age' => 0,
    ];

    return $form;
  }

  /**
   * Vide la réponse précédente dès qu'une nouvelle soumission démarre.
   *
   * Évite que l'ancienne réponse (conservée dans le form_state AJAX)
   * réapparaisse si la validation échoue (ex. question vide).
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    $form_state->set('rag_result', NULL);
  }

  /**
   * Submit classique (fallback sans JS) + stockage du résultat pour rebuild.
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $question = trim((string) $form_state->getValue('question'));
    if ($question === '') {
      $form_state->set('rag_result', [
        'success' => FALSE,
        'answer' => NULL,
        'error' => (string) $this->t('Veuillez saisir une question.'),
        'docs_count' => 0,
      ]);
      $form_state->setRebuild(TRUE);
      return;
    }

    $config = $this->config('sl_rag.settings');
    $maxDocs = (int) ($config->get('vector_top_k') ?: 10);
    if ($maxDocs < 5) {
      $maxDocs = 10;
    }

    try {
      $result = $this->askService->ask($question, [
        'max_docs' => $maxDocs,
        'dry_run' => FALSE,
      ]);
    } catch (\Throwable $e) {
      $result = [
        'success' => FALSE,
        'answer' => NULL,
        'error' => (string) $this->t('Erreur technique : @m', ['@m' => $e->getMessage()]),
        'docs_count' => 0,
      ];
    }

    $form_state->set('rag_result', $result);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Callback AJAX : renvoie le formulaire reconstruit (avec la réponse).
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state)
  {
    return $form;
  }
}
