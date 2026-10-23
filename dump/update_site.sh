#!/bin/bash

# --- CONFIGURATION ---
PROJECT_ROOT="$HOME/www"
PHP_BIN="/usr/bin/php"
COMPOSER_PHAR="$HOME/composer.phar"
DRUSH="$PROJECT_ROOT/vendor/drush/drush/drush"
LOG_FILE="$HOME/cron_update.log"

# --- SCRIPT START ---
echo "--- Update started: $(date) ---" > "$LOG_FILE"

# Déplacement dans le dossier du projet
cd "$PROJECT_ROOT" || { echo "Error: Could not enter $PROJECT_ROOT" >> "$LOG_FILE"; exit 1; }

# Etape 1 set maintenance 1
echo "elo:rebuild" >> "$LOG_FILE"
$DRUSH elo:rebuild >> "$LOG_FILE" 2>&1

# Etape 2 elo rebuild
echo "sset system.maintenance_mode 1" >> "$LOG_FILE"
$DRUSH sset system.maintenance_mode 1 >> "$LOG_FILE" 2>&1


# Etape pour eviter les queues cloudlfare pleines on purge cloudlflare (attention pour lscache ca ne peut se faire que via planethoster mais pas indispensable ici)
echo "p:invalidate everything" >> "$LOG_FILE"
$DRUSH p:invalidate everything >> "$LOG_FILE" 2>&1

echo "p:queue-empty" >> "$LOG_FILE"
$DRUSH p:queue-empty >> "$LOG_FILE" 2>&1

# 3. Mise à jour via Composer
echo "Running Composer update..." >> "$LOG_FILE"
$PHP_BIN "$COMPOSER_PHAR" update --with-all-dependencies >> "$LOG_FILE" 2>&1

# 4. Mises à jour de la base de données
echo "Applying database updates..." >> "$LOG_FILE"
$DRUSH updb -y >> "$LOG_FILE" 2>&1

# 5. Nettoyage du cache interne Drupal
echo "Rebuilding cache..." >> "$LOG_FILE"
$DRUSH cr >> "$LOG_FILE" 2>&1

# 6. Search API : Reset tracking, Nettoyage et Indexation
echo "Search API: Reset tracking, Clearing and Indexing..." >> "$LOG_FILE"
$DRUSH sapi-rt >> "$LOG_FILE" 2>&1
$DRUSH sapi-c >> "$LOG_FILE" 2>&1
$DRUSH sapi-i >> "$LOG_FILE" 2>&1

#7. reset eventuel set state de prompts
echo "sl:live-search-reset"
$DRUSH sl:live-search-reset >> "$LOG_FILE" 2>&1

# 8. Désactivation du mode maintenance
echo "Exiting maintenance mode..." >> "$LOG_FILE"
$DRUSH sset system.maintenance_mode 0 >> "$LOG_FILE" 2>&1

# 8.0 rebuild cache drupal
echo "rebuild drupal cache drush cr" >> "$LOG_FILE"
$DRUSH cr >> "$LOG_FILE" 2>&1

# 7. Cache interne (pas lscache) warming (avant de sortir du mode maintenance) / remplacer par github actions cf dump/
echo "Launching cache warming de cache interne pas lscache ..." >> "$LOG_FILE"
bash "$HOME/warming.sh" >> "$LOG_FILE" 2>&1

# 7c. Purge LiteSpeed Cache
#echo "Purging LiteSpeed Cache..." >> "$LOG_FILE"
# Utilisation de la commande officielle du module Lscache pour vider le cache externe (LiteSpeed)
#$DRUSH cache:rebuild-external -y >> "$LOG_FILE" 2>&1


# 7c. Purge LiteSpeed Cache via curl
echo "Purging LiteSpeed Cache via curl..." >> "$LOG_FILE"
curl -s -X PURGE -H "Host: www.senlutteurs.com" http://127.0.0.1/ >> "$LOG_FILE" 2>&1

# 7d. Purge Cloudflare cache again
echo "p:invalidate everything" >> "$LOG_FILE"
$DRUSH p:invalidate everything >> "$LOG_FILE" 2>&1

echo "--- Update finished: $(date) ---" >> "$LOG_FILE"
echo "-------------------------------------------" >> "$LOG_FILE"