<?php

namespace Drupal\matomo_total_visits_block\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;

/**
 * @Block(
 *   id = "matomo_total_visits_block",
 *   admin_label = @Translation("Matomo total visits")
 * )
 */
class MatomoTotalVisitsBlock extends BlockBase
{

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration()
  {
    return [
      'matomo_site_id' => 1,
      'matomo_database_key' => 'matomo',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state)
  {
    $form['matomo_site_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Matomo site ID'),
      '#default_value' => $this->configuration['matomo_site_id'],
      '#required' => TRUE,
    ];

    $form['matomo_database_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database connection key'),
      '#description' => $this->t('Database key defined in settings.php (default: matomo)'),
      '#default_value' => $this->configuration['matomo_database_key'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state)
  {
    $this->configuration['matomo_site_id'] = $form_state->getValue('matomo_site_id');
    $this->configuration['matomo_database_key'] = $form_state->getValue('matomo_database_key');
  }

  /**
   * {@inheritdoc}
   */
  public function build()
  {
    $site_id = (int) $this->configuration['matomo_site_id'];
    $key = $this->configuration['matomo_database_key'];

    try {
      $conn = Database::getConnection('default', $key);

      // Total exact depuis le début = COUNT(*) dans log_visit
      $total = (int) $conn->query(
        "SELECT COUNT(*) FROM {matomo_log_visit} WHERE idsite = :idsite",
        [':idsite' => $site_id]
      )->fetchField();

      return [
        '#markup' => $this->t('<strong>Visites : @count</strong>', [
          '@count' => number_format($total, 0, ',', "\u{202F}"),
        ]),
        '#cache' => [
          'max-age' => 3600,
        ],
      ];
    } catch (\Exception $e) {
      \Drupal::logger('matomo_total_visits_block')->error(
        'Erreur connexion Matomo: @msg',
        ['@msg' => $e->getMessage()]
      );
      return ['#markup' => $this->t('Données de visites temporairement indisponibles.')];
    }
  }
}
