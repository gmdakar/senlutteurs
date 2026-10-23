#!/bin/bash

PROJECT_ROOT="$HOME/www"
DRUSH="$PROJECT_ROOT/vendor/drush/drush/drush"
LOG_FILE="$HOME/cron_warming.log"

URL_COUNT=0
HIT_COUNT=0
MISS_COUNT=0

# ---------------------------------------------------------------------------
# Cleanup : supprime le flag même en cas d'interruption (Ctrl+C, SIGTERM…)
# ---------------------------------------------------------------------------
cleanup() {
  echo "--- Cleanup : suppression du flag warming_in_progress ---" >> "$LOG_FILE"
  $DRUSH state:del warming_in_progress >> "$LOG_FILE" 2>&1
}
trap cleanup EXIT

echo "--- Production Warming pour redis only par exemple ou cache interne Started: $(date) ---" > "$LOG_FILE"
echo "Format des logs : [Index] [Code HTTP] [Cache] [CPU% | RAM%] URL" >> "$LOG_FILE"
echo "------------------------------------------------------------------" >> "$LOG_FILE"

# 0. Ardoise propre : vider la queue Purge AVANT de chauffer.
echo "Purge queue pre-warming (ardoise propre)..." >> "$LOG_FILE"
$DRUSH p:queue-empty -y >> "$LOG_FILE" 2>&1
echo "Purge queue vidée." >> "$LOG_FILE"
echo "------------------------------------------------------------------" >> "$LOG_FILE"

# 1. Chauffe cache Drupal (Internal Page Cache) : cache interne pas lscache
echo "Enqueuing entity pages and sitemap ..." >> "$LOG_FILE"
$DRUSH warmer:enqueue entity >> "$LOG_FILE" 2>&1
$DRUSH warmer:enqueue sitemap >> "$LOG_FILE" 2>&1
$DRUSH queue:run warmer >> "$LOG_FILE" 2>&1
echo "Drupal cache warmed." >> "$LOG_FILE"
echo "------------------------------------------------------------------" >> "$LOG_FILE"

# 2. Chauffe LSCache via sitemap global.
echo "Activation du flag warming_in_progress..." >> "$LOG_FILE"
$DRUSH state:set warming_in_progress 1            >> "$LOG_FILE" 2>&1
$DRUSH state:set warming_started_at "$(date +%s)" >> "$LOG_FILE" 2>&1