<?php

namespace Drupal\misc_divers\Traits;

/**
 * Trait pour la gestion du planning des commandes Drush.
 */
trait CronScheduleTrait {
  /**
  * Vérifie le planning (Heure fixe + Jour optionnel + Bypass en mode Dev).
  */
  protected function isScheduledNow(string $command_name): bool {
    $now = new \DateTime('now', new \DateTimeZone('Africa/Dakar'));

    // --- ENVIRO DE DÉVELOPPEMENT : BYPASS GLOBAL ---
    if (isset($_ENV['ENVIRONMENT']) && $_ENV['ENVIRONMENT'] === 'dev') {
      $msg = "RUN OK (DEV ENV) : [$command_name] lancé immédiatement en environnement de développement à " . $now->format('H:i:s');
      \Drupal::logger('misc_divers')->notice($msg);
      $this->output()->writeln("<info>$msg</info>");
      return TRUE;
    }

    $config = \Drupal::config('misc_divers.settings');
    $schedules = $config->get('schedules') ?: [];
    
    $current_day = (int) $now->format('N'); // 1-7
    $current_timestamp = $now->getTimestamp();

    foreach ($schedules as $task) {
      if ($task['command'] === $command_name) {
        
        // --- CAS BYPASSED (Config manuelle) ---
        if ($task['day'] === 'bypassed') {
          $msg = "RUN OK (BYPASS) : [$command_name] lancé sans restriction à " . $now->format('H:i:s');
          \Drupal::logger('misc_divers')->notice($msg);
          $this->output()->writeln("<info>$msg</info>");
          return TRUE;
        }

        // --- LOGIQUE DU JOUR ---
        $day_match = ($task['day'] === 'all' || (int)$task['day'] === $current_day);

        if ($day_match) {
          $scheduled_time = \DateTime::createFromFormat(
            'Y-m-d H:i', 
            $now->format('Y-m-d') . ' ' . $task['time'], 
            new \DateTimeZone('Africa/Dakar')
          );

          if ($scheduled_time) {
            $start_window = $scheduled_time->getTimestamp();
            $end_window = $start_window + 60;

            if ($current_timestamp >= $start_window && $current_timestamp < $end_window) {
              $msg = "RUN cron command OK : [$command_name] lancé à " . $now->format('H:i:s');
              \Drupal::logger('misc_divers')->notice($msg);
              
              $this->output()->writeln("<info>$msg</info>");
              return TRUE;
            }
          }
        }
      }
    }

    $this->output()->writeln("<comment>SKIP : [$command_name] hors créneau horaire.</comment>");
    return FALSE;
  }
}