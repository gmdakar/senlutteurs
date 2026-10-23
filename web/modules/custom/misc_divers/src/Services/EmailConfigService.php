<?php

namespace Drupal\misc_divers\Services;

use Drupal\Core\Config\ConfigFactoryInterface;

class EmailConfigService {
  protected $configFactory;

  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  public function getRecipientEmail(): string {
    $config = $this->configFactory->get('misc_divers.settings');
    $site_config = $this->configFactory->get('system.site');
    return $config->get('email_destinataire_logs_errors') ?: $site_config->get('mail') ?: 'admin@example.com';
  }
}