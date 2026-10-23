<?php
namespace Drupal\fb_publisher\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class FacebookConfigForm extends ConfigFormBase {

  protected function getEditableConfigNames() {
    return ['fb_publisher.settings'];
  }

  public function getFormId() {
    return 'fb_publisher_config_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('fb_publisher.settings');

    $form['app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App ID'),
      '#default_value' => $config->get('app_id'),
      '#required' => TRUE,
    ];

    $form['app_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App Secret'),
      '#default_value' => $config->get('app_secret'),
      '#required' => TRUE,
    ];

    $form['page_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Page ID'),
      '#default_value' => $config->get('page_id'),
      '#required' => TRUE,
    ];

    $form['page_access_token'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Page Access Token'),
      '#default_value' => $config->get('page_access_token'),
      '#required' => TRUE,
      '#cols' => 80,   // largeur visible (optionnel)
      '#rows' => 3,    // hauteur visible (optionnel)
    ];


    return parent::buildForm($form, $form_state) + $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('fb_publisher.settings')
      ->set('app_id', $form_state->getValue('app_id'))
      ->set('app_secret', $form_state->getValue('app_secret'))
      ->set('page_id', $form_state->getValue('page_id'))
      ->set('page_access_token', $form_state->getValue('page_access_token'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}