<?php

namespace Drupal\banner_hero\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;

class BannerHeroSettingsForm extends FormBase {

  public function getFormId() {
    return 'banner_hero_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config       = \Drupal::service('config.factory')->getEditable('banner_hero.settings');
    $fid_desktop  = \Drupal::state()->get('banner_hero_image_fid_desktop');
    $fid_mobile   = \Drupal::state()->get('banner_hero_image_fid_mobile');

    // --- Fallback chemins bruts ---
    $form['fallback'] = [
      '#type'  => 'details',
      '#title' => $this->t('Fallback (chemin brut)'),
      '#open'  => TRUE,
    ];

    $form['fallback']['banner_image_url'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Image desktop'),
      '#default_value' => $config->get('banner_image_url'),
      '#description'   => $this->t('Ex: themes/custom/sl_theme/img/banner-hero.webp'),
    ];

    $form['fallback']['banner_image_url_mobile'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Image mobile (≤ 479px)'),
      '#default_value' => $config->get('banner_image_url_mobile'),
      '#description'   => $this->t('Ex: themes/custom/sl_theme/img/banner-hero-mobile.webp'),
    ];

    // --- Upload desktop ---
    $form['upload_desktop'] = [
      '#type'  => 'details',
      '#title' => $this->t('Upload desktop'),
      '#open'  => TRUE,
    ];

    $form['upload_desktop']['image_desktop'] = [
      '#type'              => 'managed_file',
      '#title'             => $this->t('Image desktop'),
      '#upload_location'   => 'public://banner_hero/',
      '#default_value'     => $fid_desktop ? [$fid_desktop] : [],
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'webp png jpg jpeg'],
        'FileSizeLimit' => ['fileLimit' => 2000000],
      ],
    ];

    if ($fid_desktop && $file = File::load($fid_desktop)) {
      $style_storage = \Drupal::entityTypeManager()->getStorage('image_style');
      $uri           = $file->getFileUri();
      $url           = ($s = $style_storage->load('banner_hero_desktop'))
        ? $s->buildUrl($uri)
        : \Drupal::service('file_url_generator')->generateAbsoluteString($uri);

      $form['upload_desktop']['preview_desktop'] = [
        '#markup'       => '<img src="' . htmlspecialchars($url) . '" style="max-width:300px;height:auto;display:block;margin-top:.5rem;">',
        '#allowed_tags' => ['img'],
      ];
    }

    // --- Upload mobile ---
    $form['upload_mobile'] = [
      '#type'  => 'details',
      '#title' => $this->t('Upload mobile (≤ 479px)'),
      '#open'  => TRUE,
    ];

    $form['upload_mobile']['image_mobile'] = [
      '#type'              => 'managed_file',
      '#title'             => $this->t('Image mobile'),
      '#upload_location'   => 'public://banner_hero/',
      '#default_value'     => $fid_mobile ? [$fid_mobile] : [],
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'webp png jpg jpeg'],
        'FileSizeLimit' => ['fileLimit' => 1000000],
      ],
    ];

    if ($fid_mobile && $file = File::load($fid_mobile)) {
      $style_storage = \Drupal::entityTypeManager()->getStorage('image_style');
      $uri           = $file->getFileUri();
      $url           = ($s = $style_storage->load('banner_hero_mobile'))
        ? $s->buildUrl($uri)
        : \Drupal::service('file_url_generator')->generateAbsoluteString($uri);

      $form['upload_mobile']['preview_mobile'] = [
        '#markup'       => '<img src="' . htmlspecialchars($url) . '" style="max-width:150px;height:auto;display:block;margin-top:.5rem;">',
        '#allowed_tags' => ['img'],
      ];
    }

    $form['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Save'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = \Drupal::service('config.factory')->getEditable('banner_hero.settings');
    $config
      ->set('banner_image_url', $form_state->getValue('banner_image_url'))
      ->set('banner_image_url_mobile', $form_state->getValue('banner_image_url_mobile'))
      ->save();

    foreach ([
      'image_desktop' => 'banner_hero_image_fid_desktop',
      'image_mobile'  => 'banner_hero_image_fid_mobile',
    ] as $field => $state_key) {
      $fid = $form_state->getValue($field)[0] ?? NULL;
      if ($fid && $file = File::load($fid)) {
        $file->setPermanent();
        $file->save();
      }
      \Drupal::state()->set($state_key, $fid);
    }

    $this->messenger()->addStatus($this->t('Banner image updated.'));
  }

}
