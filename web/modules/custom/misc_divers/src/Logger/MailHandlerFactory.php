<?php

namespace Drupal\misc_divers\Logger;

use Monolog\Handler\NativeMailerHandler;
use Drupal\Core\Config\ConfigFactoryInterface;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * Factory to build the Monolog email handler with dynamic settings.
 */
class MailHandlerFactory {

  /**
   * Creates the handler.
   */
  public static function create(ConfigFactoryInterface $config_factory) {
    $config = $config_factory->get('misc_divers.settings');
    $site_config = $config_factory->get('system.site');

    $to = $config->get('email_destinataire_logs_errors') ?: $site_config->get('mail');
    $from = $site_config->get('mail') ?: 'noreply@senlutteurs.com';
    $subject = "[Senlutteurs AI] Critical Error Detected";

    $mailer = new NativeMailerHandler(
      $to,
      $subject,
      $from,
      Logger::ERROR
    );

    // Filtre pour exclure les messages non pertinents des mails.
    // Monolog 3.x : le processor reçoit un LogRecord (objet), pas un array.
    $mailer->pushProcessor(function (LogRecord $record): LogRecord {
      $channel = $record->channel;
      $message = $record->message;

      $excluded_channels = ['sl_crawler_index'];
      $excluded_keywords = ['Quota', 'SuspendQueue', 'QuotaExceeded', '429'];

      $should_downgrade = in_array($channel, $excluded_channels, TRUE);

      if (!$should_downgrade) {
        foreach ($excluded_keywords as $keyword) {
          if (str_contains($message, $keyword)) {
            $should_downgrade = TRUE;
            break;
          }
        }
      }

      if ($should_downgrade) {
        // with() retourne une nouvelle instance avec le level modifié.
        return $record->with(level: \Monolog\Level::Warning);
      }

      return $record;
    });

    return $mailer;
  }

}