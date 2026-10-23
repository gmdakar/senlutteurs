<?php
namespace Drupal\fb_publisher\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class FacebookTestForm extends FormBase {

  public function getFormId() {
    return 'fb_publisher_test_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message to post'),
      '#default_value' => "test",
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send to Facebook'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $message = $form_state->getValue('message');
    $fb_service = \Drupal::service('fb_publisher.facebook_service');

    try {
      $post_id = $fb_service->postToPage($message);
      $this->messenger()->addMessage($this->t('Message posted successfully. Post ID: @id', ['@id' => $post_id]));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error: @msg', ['@msg' => $e->getMessage()]));
    }
  }
}