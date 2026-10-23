<?php

// phpcs:ignoreFile

/**
 * @file
 * Example settings.dev.php for local development (senlutteurs).
 *
 * Copy this file to settings.dev.php and adapt if needed.
 * This file is only included when ENVIRONMENT=dev in .env
 *
 * NEVER commit the real settings.dev.php to a public repository.
 */

/**
 * Enable local development services.
 */
$settings['container_yamls'][] = DRUPAL_ROOT . '/sites/development.services.yml';

/**
 * Show all error messages, with backtrace information.
 */
$config['system.logging']['error_level'] = 'verbose';

/**
 * Disable CSS and JS aggregation.
 */
$config['system.performance']['css']['preprocess'] = FALSE;
$config['system.performance']['js']['preprocess'] = FALSE;

/**
 * Allow access to rebuild.php.
 */
$settings['rebuild_access'] = TRUE;

/**
 * Skip file system permissions hardening.
 */
$settings['skip_permissions_hardening'] = TRUE;

/**
 * Trusted host patterns (very permissive in local).
 */
$settings['trusted_host_patterns'] = ['.*'];

/**
 * Don't use Symfony's APCLoader.
 */
$settings['class_loader_auto_detect'] = FALSE;

/**
 * Config sync directory (fallback).
 */
if (empty($settings['config_sync_directory'])) {
  $settings['config_sync_directory'] = 'sites/default/files/sync';
}

/**
 * Override Symfony Mailer to use Mailpit (or local SMTP).
 */
$config['symfony_mailer.settings']['default_transport'] = 'sendmail';
$config['symfony_mailer.mailer_transport.sendmail']['plugin'] = 'smtp';
$config['symfony_mailer.mailer_transport.sendmail']['configuration']['user'] = '';
$config['symfony_mailer.mailer_transport.sendmail']['configuration']['pass'] = '';
$config['symfony_mailer.mailer_transport.sendmail']['configuration']['host'] = 'localhost';
$config['symfony_mailer.mailer_transport.sendmail']['configuration']['port'] = '1025';

/**
 * Custom Monolog configuration for full logging in dev.
 */
if (file_exists($app_root . '/' . $site_path . '/monolog.services.full.yml')) {
  $settings['container_yamls'][] = $app_root . '/' . $site_path . '/monolog.services.full.yml';
}

/**
 * Disable Twig cache and other caches in local debug.
 */
if (file_exists($app_root . '/' . $site_path . '/services.local.debug.yml')) {
  $settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.local.debug.yml';
}

/**
 * Force null cache backends (very useful in development).
 */
$settings['cache']['bins']['render'] = 'cache.backend.null';
$settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.null';
$settings['cache']['bins']['page'] = 'cache.backend.null';

/**
 * Disable Matomo tracking on local development.
 */
$config['matomo.settings']['site_id'] = '';

/**
 * Base URL (can be overridden).
 */
$base_url = 'https://www.senlutteurs.com'; // or your local URL

/**
 * Disable Automated Logout module in local.
 */
$config['autologout.settings']['enabled'] = FALSE;

/**
 * Enable config_split for "dev" environment.
 */
$config['config_split.config_split.dev']['status'] = TRUE;

/**
 * Cloudflare – bypass check in local dev.
 */
$config['cloudflare.settings']['bypass_cloudflare_check'] = TRUE;

/**
 * Reverse proxy settings (useful behind DDEV / Docker).
 */
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = [$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'];

/**
 * Disable Critical CSS cache in local.
 */
$config['critical_css.settings']['disable_cache'] = TRUE;
