<?php

namespace Drupal\banner_hero\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Render\Markup;
use Drupal\file\Entity\File;

/**
 * Provides a 'Banner Hero' block.
 *
 * @Block(
 *   id = "banner_hero_block",
 *   admin_label = @Translation("Banner Hero Block")
 * )
 */
class BannerHeroBlock extends BlockBase {

  public function build() {
    $fid_desktop = \Drupal::state()->get('banner_hero_image_fid_desktop');
    $fid_mobile  = \Drupal::state()->get('banner_hero_image_fid_mobile');
    $url_desktop = '';
    $url_mobile  = '';

    // --- Image uploadée ---
    if ($fid_desktop && $file = File::load($fid_desktop)) {
      $uri           = $file->getFileUri();
      $style_storage = \Drupal::entityTypeManager()->getStorage('image_style');
      $file_url_gen  = \Drupal::service('file_url_generator');

      $desktop_style = $style_storage->load('banner_hero_desktop');
      $url_desktop   = $desktop_style
        ? $desktop_style->buildUrl($uri)
        : $file_url_gen->generateAbsoluteString($uri);
    }

    if ($fid_mobile && $file = File::load($fid_mobile)) {
      $uri          = $file->getFileUri();
      $mobile_style = \Drupal::entityTypeManager()->getStorage('image_style')->load('banner_hero_mobile');
      $url_mobile   = $mobile_style
        ? $mobile_style->buildUrl($uri)
        : \Drupal::service('file_url_generator')->generateAbsoluteString($uri);
    }

    // Mobile fallback sur desktop uploadé si pas d'image mobile uploadée
    if (!empty($url_desktop) && empty($url_mobile)) {
      $url_mobile = $url_desktop;
    }

    // --- Fallback chemins bruts (config) ---
    if (empty($url_desktop)) {
      $config      = \Drupal::service('config.factory')->get('banner_hero.settings');
      $url_desktop = $config->get('banner_image_url');
      $url_mobile  = $config->get('banner_image_url_mobile') ?: $url_desktop;

      if (empty($url_desktop)) {
        return [];
      }
    }

    return [
      '#markup' => Markup::create($this->buildPictureTag($url_desktop, $url_mobile)),
      '#cache'  => [
        'tags'    => ['config:banner_hero.settings'],
        'contexts' => [],
      ],
    ];
  }

  /**
   * Construit le tag <picture> responsive.
   */
  protected function buildPictureTag(string $url_desktop, string $url_mobile): string {
    return sprintf(
      '<picture>
        <source media="(max-width: 479px)" srcset="%s">
        <img
          src="%s"
          alt="Hero Banner"
          class="banner-hero"
          width="1920"
          height="600"
          loading="eager"
          fetchpriority="high"
        >
      </picture>',
      htmlspecialchars($url_mobile,  ENT_QUOTES, 'UTF-8'),
      htmlspecialchars($url_desktop, ENT_QUOTES, 'UTF-8')
    );
  }

}