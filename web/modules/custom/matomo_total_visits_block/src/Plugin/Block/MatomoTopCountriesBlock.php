<?php

namespace Drupal\matomo_total_visits_block\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;

/**
 * Provides a block showing the Top N visitor countries as percentages with flags.
 *
 * @Block(
 *   id = "matomo_top_countries_block",
 *   admin_label = @Translation("Matomo Top N countries"),
 *   category = @Translation("Matomo")
 * )
 */
class MatomoTopCountriesBlock extends BlockBase
{

  /**
   * ISO 3166-1 alpha-2 → country name (FR).
   */
  protected static array $countryNames = [
    'af' => 'Afghanistan',
    'al' => 'Albanie',
    'dz' => 'Algérie',
    'ad' => 'Andorre',
    'ao' => 'Angola',
    'ar' => 'Argentine',
    'am' => 'Arménie',
    'au' => 'Australie',
    'at' => 'Autriche',
    'az' => 'Azerbaïdjan',
    'bh' => 'Bahreïn',
    'bd' => 'Bangladesh',
    'by' => 'Biélorussie',
    'be' => 'Belgique',
    'bj' => 'Bénin',
    'bt' => 'Bhoutan',
    'bo' => 'Bolivie',
    'ba' => 'Bosnie-Herzégovine',
    'bw' => 'Botswana',
    'br' => 'Brésil',
    'bn' => 'Brunei',
    'bg' => 'Bulgarie',
    'bf' => 'Burkina Faso',
    'bi' => 'Burundi',
    'kh' => 'Cambodge',
    'cm' => 'Cameroun',
    'ca' => 'Canada',
    'cv' => 'Cap-Vert',
    'cf' => 'République centrafricaine',
    'td' => 'Tchad',
    'cl' => 'Chili',
    'cn' => 'Chine',
    'co' => 'Colombie',
    'km' => 'Comores',
    'cg' => 'Congo',
    'cd' => 'RD Congo',
    'cr' => 'Costa Rica',
    'ci' => 'Côte d\'Ivoire',
    'hr' => 'Croatie',
    'cu' => 'Cuba',
    'cy' => 'Chypre',
    'cz' => 'Tchéquie',
    'dk' => 'Danemark',
    'dj' => 'Djibouti',
    'do' => 'République dominicaine',
    'ec' => 'Équateur',
    'eg' => 'Égypte',
    'sv' => 'Salvador',
    'gq' => 'Guinée équatoriale',
    'er' => 'Érythrée',
    'ee' => 'Estonie',
    'sz' => 'Eswatini',
    'et' => 'Éthiopie',
    'fj' => 'Fidji',
    'fi' => 'Finlande',
    'fr' => 'France',
    'ga' => 'Gabon',
    'gm' => 'Gambie',
    'ge' => 'Géorgie',
    'de' => 'Allemagne',
    'gh' => 'Ghana',
    'gr' => 'Grèce',
    'gt' => 'Guatemala',
    'gn' => 'Guinée',
    'gw' => 'Guinée-Bissau',
    'gy' => 'Guyana',
    'ht' => 'Haïti',
    'hn' => 'Honduras',
    'hk' => 'Hong Kong',
    'hu' => 'Hongrie',
    'is' => 'Islande',
    'in' => 'Inde',
    'id' => 'Indonésie',
    'ir' => 'Iran',
    'iq' => 'Irak',
    'ie' => 'Irlande',
    'il' => 'Israël',
    'it' => 'Italie',
    'jm' => 'Jamaïque',
    'jp' => 'Japon',
    'jo' => 'Jordanie',
    'kz' => 'Kazakhstan',
    'ke' => 'Kenya',
    'kw' => 'Koweït',
    'kg' => 'Kirghizistan',
    'la' => 'Laos',
    'lv' => 'Lettonie',
    'lb' => 'Liban',
    'ls' => 'Lesotho',
    'lr' => 'Liberia',
    'ly' => 'Libye',
    'li' => 'Liechtenstein',
    'lt' => 'Lituanie',
    'lu' => 'Luxembourg',
    'mo' => 'Macao',
    'mg' => 'Madagascar',
    'mw' => 'Malawi',
    'my' => 'Malaisie',
    'mv' => 'Maldives',
    'ml' => 'Mali',
    'mt' => 'Malte',
    'mr' => 'Mauritanie',
    'mu' => 'Maurice',
    'mx' => 'Mexique',
    'md' => 'Moldavie',
    'mc' => 'Monaco',
    'mn' => 'Mongolie',
    'me' => 'Monténégro',
    'ma' => 'Maroc',
    'mz' => 'Mozambique',
    'mm' => 'Myanmar',
    'na' => 'Namibie',
    'np' => 'Népal',
    'nl' => 'Pays-Bas',
    'nz' => 'Nouvelle-Zélande',
    'ni' => 'Nicaragua',
    'ne' => 'Niger',
    'ng' => 'Nigeria',
    'kp' => 'Corée du Nord',
    'mk' => 'Macédoine du Nord',
    'no' => 'Norvège',
    'om' => 'Oman',
    'pk' => 'Pakistan',
    'ps' => 'Palestine',
    'pa' => 'Panama',
    'pg' => 'Papouasie-Nouvelle-Guinée',
    'py' => 'Paraguay',
    'pe' => 'Pérou',
    'ph' => 'Philippines',
    'pl' => 'Pologne',
    'pt' => 'Portugal',
    'qa' => 'Qatar',
    'ro' => 'Roumanie',
    'ru' => 'Russie',
    'rw' => 'Rwanda',
    'sa' => 'Arabie saoudite',
    'sn' => 'Sénégal',
    'rs' => 'Serbie',
    'sc' => 'Seychelles',
    'sl' => 'Sierra Leone',
    'sg' => 'Singapour',
    'sk' => 'Slovaquie',
    'si' => 'Slovénie',
    'so' => 'Somalie',
    'za' => 'Afrique du Sud',
    'kr' => 'Corée du Sud',
    'ss' => 'Soudan du Sud',
    'es' => 'Espagne',
    'lk' => 'Sri Lanka',
    'sd' => 'Soudan',
    'sr' => 'Suriname',
    'se' => 'Suède',
    'ch' => 'Suisse',
    'sy' => 'Syrie',
    'tw' => 'Taïwan',
    'tj' => 'Tadjikistan',
    'tz' => 'Tanzanie',
    'th' => 'Thaïlande',
    'tl' => 'Timor oriental',
    'tg' => 'Togo',
    'tt' => 'Trinité-et-Tobago',
    'tn' => 'Tunisie',
    'tr' => 'Turquie',
    'tm' => 'Turkménistan',
    'ug' => 'Ouganda',
    'ua' => 'Ukraine',
    'ae' => 'Émirats arabes unis',
    'gb' => 'Royaume-Uni',
    'us' => 'États-Unis',
    'uy' => 'Uruguay',
    'uz' => 'Ouzbékistan',
    've' => 'Venezuela',
    'vn' => 'Viêt Nam',
    'ye' => 'Yémen',
    'zm' => 'Zambie',
    'zw' => 'Zimbabwe',
  ];

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration()
  {
    return [
      'matomo_site_id' => 1,
      'matomo_database_key' => 'matomo',
      'cache_max_age' => 604800, // 1 semaine
      'top_nb_pays' => 5,
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
      '#min' => 1,
    ];

    $form['matomo_database_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database connection key'),
      '#description' => $this->t('Database key defined in settings.php (default: matomo)'),
      '#default_value' => $this->configuration['matomo_database_key'],
      '#required' => TRUE,
    ];

    $form['top_nb_pays'] = [
      '#type' => 'number',
      '#title' => $this->t('Nombre de pays à afficher'),
      '#description' => $this->t('Top N pays (ex: 5, 10…). Maximum 50.'),
      '#default_value' => $this->configuration['top_nb_pays'] ?? 5,
      '#required' => TRUE,
      '#min' => 1,
      '#max' => 50,
    ];

    $form['cache_max_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache duration (seconds)'),
      '#description' => $this->t('Default: 604800 (1 week). Minimum recommended: 86400 (1 day).'),
      '#default_value' => $this->configuration['cache_max_age'],
      '#required' => TRUE,
      '#min' => 3600,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state)
  {
    $this->configuration['matomo_site_id'] = (int) $form_state->getValue('matomo_site_id');
    $this->configuration['matomo_database_key'] = $form_state->getValue('matomo_database_key');
    $this->configuration['top_nb_pays'] = (int) $form_state->getValue('top_nb_pays');
    $this->configuration['cache_max_age'] = (int) $form_state->getValue('cache_max_age');
  }

  /**
   * {@inheritdoc}
   */
  public function build()
  {
    $site_id = (int) $this->configuration['matomo_site_id'];
    $key = $this->configuration['matomo_database_key'];
    $max_age = (int) $this->configuration['cache_max_age'];
    // Sécurise le LIMIT (entre 1 et 50)
    $limit = max(1, min(50, (int) ($this->configuration['top_nb_pays'] ?? 5)));

    try {
      $conn = Database::getConnection('default', $key);

      // Total visits (all countries with valid geo)
      $total = (int) $conn->query("
        SELECT COUNT(*)
        FROM matomo_log_visit
        WHERE idsite = :idsite
          AND location_country IS NOT NULL
          AND location_country != ''
          AND location_country != 'xx'
      ", [':idsite' => $site_id])->fetchField();

      if ($total === 0) {
        return [
          '#markup' => $this->t('Aucune donnée de géolocalisation disponible.'),
          '#cache' => ['max-age' => $max_age],
        ];
      }

      // Top N countries — LIMIT interpolé de façon sécurisée
      $rows = $conn->query("
        SELECT
          location_country AS country,
          COUNT(*) AS visits
        FROM matomo_log_visit
        WHERE idsite = :idsite
          AND location_country IS NOT NULL
          AND location_country != ''
          AND location_country != 'xx'
        GROUP BY location_country
        ORDER BY visits DESC
        LIMIT " . $limit, [':idsite' => $site_id])->fetchAll();

      $items = [];
      foreach ($rows as $row) {
        $code = strtolower($row->country);
        $visits = (int) $row->visits;
        $percent = round(($visits / $total) * 100, 1);
        $name = self::$countryNames[$code] ?? strtoupper($code);

        $items[] = [
          'code' => $code,
          'name' => $name,
          'visits' => $visits,
          'percent' => $percent,
          'flag_url' => 'https://flagcdn.com/w40/' . $code . '.png',
        ];
      }

      return [
        '#theme' => 'matomo_top_countries',
        '#items' => $items,
        '#total' => $total,
        '#attached' => [
          'library' => ['matomo_total_visits_block/top_countries'],
        ],
        '#cache' => [
          'max-age' => $max_age,
          'tags' => ['matomo_top_countries'],
        ],
      ];
    } catch (\Exception $e) {
      \Drupal::logger('matomo_total_visits_block')->error(
        'Erreur Top countries Matomo: @msg',
        ['@msg' => $e->getMessage()]
      );
      return [
        '#markup' => $this->t('Données pays temporairement indisponibles.'),
        '#cache' => ['max-age' => 3600],
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge()
  {
    return (int) ($this->configuration['cache_max_age'] ?? 604800);
  }
}
