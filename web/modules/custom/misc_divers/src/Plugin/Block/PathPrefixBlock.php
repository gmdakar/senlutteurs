<?php

namespace Drupal\misc_divers\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Fournit un lien "Current admin page" avec préfixe web/.
 *
 * @Block(
 * id = "misc_divers_admin_link_block",
 * admin_label = @Translation("Lien Admin Path Web"),
 * category = @Translation("Divers")
 * )
 */
class PathPrefixBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected $requestStack;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->requestStack = $request_stack;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('request_stack')
    );
  }

  public function build() {
    $request = $this->requestStack->getCurrentRequest();
    
    // Récupère le path courant avec la query string (ex: /fr/lutteurs/khadim-ndiaye/8672)
    $current_uri = $request->getRequestUri();
    
    // On construit l'URL manuellement avec le préfixe /web
    // On utilise fromUserInput pour garder le chemin tel quel
    $url = Url::fromUserInput('/web' . $current_uri);

    return [
      '#type' => 'link',
      '#title' => $this->t('Current admin page'),
      '#url' => $url,
      '#attributes' => [
        'class' => ['button', 'admin-path-link'],
      ],
      '#cache' => [
        'contexts' => ['url.path', 'url.query_args'],
      ],
    ];
  }

}