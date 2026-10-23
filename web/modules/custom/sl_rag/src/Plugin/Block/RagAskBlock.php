<?php

namespace Drupal\sl_rag\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bloc : poser une question textuelle et afficher la réponse RAG.
 *
 * @Block(
 *   id = "sl_rag_ask_block",
 *   admin_label = @Translation("SL RAG — Question / réponse"),
 *   category = @Translation("SL RAG")
 * )
 */
class RagAskBlock extends BlockBase implements ContainerFactoryPluginInterface
{

  protected FormBuilderInterface $formBuilder;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    FormBuilderInterface $form_builder
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->formBuilder = $form_builder;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder')
    );
  }

  public function defaultConfiguration()
  {
    return [
      'block_title_override' => '',
    ] + parent::defaultConfiguration();
  }

  public function blockForm($form, $form_state)
  {
    $form['block_title_override'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sous-titre affiché dans le bloc'),
      '#default_value' => $this->configuration['block_title_override'] ?? '',
      '#description' => $this->t('Optionnel. Ex. « Posez une question sur les lutteurs ».'),
    ];
    return $form;
  }

  public function blockSubmit($form, $form_state)
  {
    $this->configuration['block_title_override'] = $form_state->getValue('block_title_override');
  }

  public function build()
  {
    $build = [
      '#attributes' => [
        'class' => ['sl-rag-ask-block'],
      ],
      '#attached' => [
        'library' => ['sl_rag/ask_block'],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    $subtitle = trim((string) ($this->configuration['block_title_override'] ?? ''));
    if ($subtitle !== '') {
      $build['subtitle'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $subtitle,
        '#attributes' => ['class' => ['sl-rag-ask-subtitle']],
        '#weight' => -10,
      ];
    }

    $build['form'] = $this->formBuilder->getForm('\Drupal\sl_rag\Form\RagAskForm');

    return $build;
  }

}
