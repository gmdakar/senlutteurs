<?php

// phpcs:ignoreFile

/**
 * @file
 * Example Drupal settings.php for senlutteurs.
 *
 * Copy this file to settings.php and fill in the real values
 * (preferably via environment variables / .env).
 *
 * NEVER commit the real settings.php to a public repository.
 */

$databases = [];

/**
 * Load .env file (Symfony Dotenv).
 * Adjust the path according to your project structure.
 */
$dotenv = new Symfony\Component\Dotenv\Dotenv();
$dotenv->usePutenv()->load(dirname(__DIR__, 3) . '/.env');

/**** Database configuration ****/
$driver = 'mysql';

$databases['default']['default'] = [
  'database' => $_ENV['DB_NAME'] ?? 'your_database_name',
  'username' => $_ENV['DB_USER'] ?? 'your_db_user',
  'password' => $_ENV['DB_PASS'] ?? 'your_db_password',
  'host'     => $_ENV['DB_HOST'] ?? 'localhost',
  'port'     => $_ENV['DB_PORT'] ?? 3306,
  'driver'   => $driver,
  'prefix'   => '',
  'collation'=> 'utf8mb4_general_ci',
];

// Optional secondary database (Matomo example)
$databases['matomo']['default'] = [
  'database' => $_ENV['DB_NAME_MATOMO'] ?? 'your_matomo_db',
  'username' => $_ENV['DB_USER_MATOMO'] ?? 'your_matomo_user',
  'password' => $_ENV['DB_PASS_MATOMO'] ?? 'your_matomo_password',
  'host'     => $_ENV['DB_HOST_MATOMO'] ?? 'localhost',
  'port'     => $_ENV['DB_PORT_MATOMO'] ?? 3306,
  'driver'   => $driver,
];

/**
 * Hash salt – MUST be unique and secret.
 * Generate one with: php -r "echo bin2hex(random_bytes(32));"
 */
$settings['hash_salt'] = $_ENV['HASH_SALT'] ?? 'CHANGE-ME-TO-A-LONG-RANDOM-STRING';

/**
 * Base URL and trusted hosts
 */
$base_url = 'https://www.example.com'; // Change to your production domain

$settings['trusted_host_patterns'] = [
  '^example\.com$',
  '^www\.example\.com$',
  // Add local DDEV pattern if needed
  // '^yourproject\.ddev\.site$',
];

/**
 * Cron
 */
$settings['cron_safe_threshold'] = 0;
$settings['misc_divers_cron_key'] = $_ENV['CRON_KEY'] ?? 'your-secret-cron-key';

/**
 * Private files path (outside web root)
 */
$settings['file_private_path'] = DRUPAL_ROOT . '/../private';

/**
 * Config sync directory
 */
if (empty($settings['config_sync_directory'])) {
  $settings['config_sync_directory'] = '../config/sync';
}

/**
 * Services YAML
 */
$settings['container_yamls'][] = 'modules/contrib/redis/example.services.yml';
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';

/**
 * Environment-specific includes
 */
if (($_ENV['ENVIRONMENT'] ?? 'prod') === 'dev') {
  if (file_exists(__DIR__ . '/settings.dev.php')) {
    include __DIR__ . '/settings.dev.php';
  }
}
else {
  // Production / staging logging
  if (file_exists($app_root . '/' . $site_path . '/monolog.services.light.yml')) {
    $settings['container_yamls'][] = $app_root . '/' . $site_path . '/monolog.services.light.yml';
  }

  // Force-disable Devel in non-dev environments
  $config['core.extension']['devel'] = 0;
  $settings['extension_disallowed_list'][] = 'devel';
}

/**
 * DDEV automatic include (only when running under DDEV)
 */
$ddev_settings = dirname(__FILE__) . '/settings.ddev.php';
if (getenv('IS_DDEV_PROJECT') == 'true' && is_readable($ddev_settings)) {
  require $ddev_settings;
}

/**
 * Backup & Migrate naming
 */
$config['backup_migrate.backup_migrate_settings.senlutteurs_profil']['config']['namer']['filename'] =
  'sl_' . ($_ENV['ENVIRONMENT'] ?? 'prod');

/**
 * Public files base URL (relative is often preferred)
 */
$settings['file_public_base_url'] = '/sites/default/files';

/**
 * Redis cache backend
 */
$settings['cache']['default'] = 'cache.backend.redis';
$settings['cache']['bins']['discovery'] = 'cache.backend.redis';
$settings['cache']['bins']['bootstrap'] = 'cache.backend.redis';
$settings['cache']['bins']['config'] = 'cache.backend.redis';

// Cache tags stay on database (required for LSCache purger)
$settings['cache']['bins']['cache_tags'] = 'cache.backend.database';

if (($_ENV['ENVIRONMENT'] ?? 'prod') === 'dev') {
  // ---- Local DDEV Redis ----
  $settings['redis.connection']['interface'] = 'PhpRedis';
  $settings['redis.connection']['host']      = 'redis';
  $settings['redis.connection']['port']      = 6379;
  $settings['cache_prefix']['default']       = 'sl_local_';
}
else {
  // ---- Production Redis (UNIX socket example) ----
  $settings['redis.connection']['interface'] = 'PhpRedis';
  $settings['redis.connection']['host']      = '/path/to/your/redis.sock'; // ← change this
  $settings['redis.connection']['port']      = 0; // required for UNIX socket
  $settings['cache_prefix']['default']       = 'sl_prod_';
}

/**
 * Other recommended settings
 */
$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];

$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;
$settings['state_cache'] = TRUE;
$settings['migrate_node_migrate_type_classic'] = FALSE;

$settings['update_free_access'] = FALSE;
