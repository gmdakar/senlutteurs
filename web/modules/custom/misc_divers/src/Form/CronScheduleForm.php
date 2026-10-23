<?php

namespace Drupal\misc_divers\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class CronScheduleForm extends ConfigFormBase {

  protected function getEditableConfigNames() {
    return ['misc_divers.settings'];
  }

  public function getFormId() {
    return 'misc_divers_cron_schedule_form';
  }

  protected function getDrushCommands() {
    $commands = [];
    $module_handler = \Drupal::moduleHandler();
    foreach ($module_handler->getModuleList() as $extension) {
      $path = DRUPAL_ROOT . '/' . $extension->getPath() . '/src/Commands';
      if (is_dir($path) && strpos($path, '/custom/') !== false) {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
        foreach ($files as $file) {
          if ($file->isFile() && $file->getExtension() === 'php') {
            $content = file_get_contents($file->getRealPath());
            if (preg_match_all("/(?:@command\s+|Command\(name:\s*[\047\042])([a-z0-9:_-]+)/i", $content, $matches)) {
              foreach (array_filter($matches[1]) as $cmd) $commands[$cmd] = $cmd;
            }
          }
        }
      }
    }
    asort($commands);
    return $commands;
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('misc_divers.settings');
    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'misc_divers/misc_divers_assets';

    if ($form_state->get('items') === NULL) {
      $form_state->set('items', $config->get('schedules') ?: []);
    }

    $items = $form_state->get('items');
    $commands_options = $this->getDrushCommands();

    $form['help_text'] = [
      '#markup' => '<div class="cron-help-banner"><strong>Gestion du Cron :</strong> Le mode Bypassed ignore les restrictions horaires.</div>',
    ];

    $form['container'] = [
      '#type' => 'container',
      '#prefix' => '<div id="cron-items-wrapper">',
      '#suffix' => '</div>',
    ];

    foreach ($items as $delta => $value) {
      $form['container'][$delta] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['cron-item-row']],
      ];

      $form['container'][$delta]['index'] = ['#markup' => '<span class="cron-index">#' . ($delta + 1) . '</span>'];

      $form['container'][$delta]['command'] = [
        '#type' => 'select',
        '#options' => $commands_options,
        '#default_value' => $value['command'] ?? '',
        '#empty_option' => '- Commande -',
        '#attributes' => ['class' => ['cron-field-command']],
      ];

      $form['container'][$delta]['day'] = [
        '#type' => 'select',
        '#options' => [
          'all'      => '- Tous les jours -',
          'bypassed' => '⚡ BYPASSED (Toujours OK)',
          1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'
        ],
        '#default_value' => $value['day'] ?? 'all',
        '#attributes' => ['class' => ['cron-field-day']],
      ];

      $form['container'][$delta]['time'] = [
        '#type' => 'textfield',
        '#default_value' => $value['time'] ?? '00:00',
        '#attributes' => [
          'class' => ['cron-field-time'],
          'maxlength' => 5,
          'oninput' => "if(this.value.length == 2 && !this.value.includes(':')) { this.value = this.value + ':'; }",
          'onfocus' => "this.select();",
        ],
        // Masquage dynamique si BYPASSED est sélectionné
        '#states' => [
          'invisible' => [
            ':input[name="container[' . $delta . '][day]"]' => ['value' => 'bypassed'],
          ],
        ],
      ];

      $form['container'][$delta]['remove'] = [
        '#type' => 'submit',
        '#value' => 'Supprimer',
        '#name' => 'remove_' . $delta,
        '#submit' => ['::removeItemSubmit'],
        '#ajax' => ['callback' => '::ajaxCallback', 'wrapper' => 'cron-items-wrapper'],
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['cron-remove-button']],
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['style' => 'margin-top: 20px; display: flex; gap: 10px;']
    ];

    $form['actions']['add_item'] = [
      '#type' => 'submit',
      '#value' => 'Ajouter une tâche',
      '#submit' => ['::addItemSubmit'],
      '#ajax' => ['callback' => '::ajaxCallback', 'wrapper' => 'cron-items-wrapper'],
    ];
    
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Enregistrer la configuration',
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    if (end($trigger['#parents']) !== 'submit') return;

    $values = $form_state->getValue('container') ?: [];
    $usage_map = [];

    foreach ($values as $delta => $item) {
      if (empty($item['command']) || empty($item['time'])) continue;

      $day = $item['day'];
      $time = $item['time'];

      if ($day === 'bypassed') continue;

      if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
        $form_state->setErrorByName("container][$delta][time", "Format HH:mm invalide.");
        continue;
      }

      if (isset($usage_map[$day][$time])) {
        \Drupal::messenger()->addWarning(t("Risque de collision : Plusieurs tâches à @time (@day).", [
          '@time' => $time,
          '@day' => ($day === 'all' ? 'Tous les jours' : 'ce jour-là'),
        ]));
      }

      if ($day !== 'all' && isset($usage_map['all'][$time])) {
        \Drupal::messenger()->addWarning(t("Attention : Une tâche quotidienne tourne déjà à @time.", [
          '@time' => $time,
        ]));
      }

      $usage_map[$day][$time] = TRUE;
    }
  }

  public function ajaxCallback(array &$form, FormStateInterface $form_state) {
    return $form['container'];
  }

  public function addItemSubmit(array &$form, FormStateInterface $form_state) {
    $items = $form_state->getValue('container') ?: [];
    $items[] = ['command' => '', 'day' => 'all', 'time' => '00:00'];
    $form_state->set('items', $items);
    $form_state->setRebuild();
  }

  public function removeItemSubmit(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $delta = str_replace('remove_', '', $trigger['#name']);
    $items = $form_state->get('items') ?: [];
    unset($items[$delta]);
    $form_state->set('items', array_values($items));
    $form_state->setRebuild();
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue('container') ?: [];
    $to_save = [];

    foreach ($values as $item) {
      if (!empty($item['command'])) {
        $to_save[] = [
          'command' => $item['command'],
          'day'     => $item['day'],
          'time'    => $item['time'],
        ];
      }
    }

    $this->config('misc_divers.settings')->set('schedules', $to_save)->save();
    parent::submitForm($form, $form_state);
  }
}