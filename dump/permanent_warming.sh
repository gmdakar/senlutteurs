#!/bin/bash

# permanent_warming.sh
# Chauffe Redis (pas lscache) ciblée : X derniers nodes modifiés/créés.
# Cron toutes les heures.

PROJECT_ROOT="$HOME/www"
DRUSH="$PROJECT_ROOT/vendor/drush/drush/drush"
LOG_FILE="$HOME/cron_permanent_warming.log"

# --- CONFIGURATION OPTIONNELLE VIA PARAMÈTRE ---
# Si un argument est passé au script, on l'utilise, sinon on garde 100 par défaut.
LIMIT_NODES=${1:-100}

# Optionnel : Sécurité pour s'assurer que c'est bien un nombre
if ! [[ "$LIMIT_NODES" =~ ^[0-9]+$ ]]; then
  echo "Erreur : La limite fournie doit être un nombre entier." >&2
  exit 1
fi
# -----------------------------------------------

URL_COUNT=0
ERROR_COUNT=0

echo "--- Permanent Warming (cache interne, pas lscache) Started: $(date) ---" > "$LOG_FILE"
echo "Configuration : Chauffe des $LIMIT_NODES derniers nodes." >> "$LOG_FILE"
echo "------------------------------------------------------------------" >> "$LOG_FILE"

# Récupère les X derniers nodes modifiés/créés (status publié)
echo "Récupération des $LIMIT_NODES derniers nodes modifiés..." >> "$LOG_FILE"

NIDS=$($DRUSH php-eval "
  \$nids = \Drupal::entityQuery('node')
    ->condition('status', 1)
    ->sort('changed', 'DESC')
    ->range(0, $LIMIT_NODES)
    ->accessCheck(FALSE)
    ->execute();
  echo implode(PHP_EOL, array_values(\$nids));
" 2>/dev/null)

if [ -z "$NIDS" ]; then
  echo "Aucun node trouvé. Rien à chauffer." >> "$LOG_FILE"
  exit 0
fi

NID_COUNT=$(echo "$NIDS" | wc -l | tr -d ' ')
echo "$NID_COUNT node(s) récupérés." >> "$LOG_FILE"
echo "------------------------------------------------------------------" >> "$LOG_FILE"

while read -r nid; do
  [ -z "$nid" ] && continue

  URLS=$($DRUSH php-eval "
    \$node = \Drupal\node\Entity\Node::load($nid);
    if (!\$node) { exit; }

    \$languages = \Drupal::languageManager()->getLanguages();
    \$alias_manager = \Drupal::service('path_alias.manager');
    \$base = 'https://www.senlutteurs.com';

    foreach (\$languages as \$langcode => \$lang) {
      \$internal = '/node/$nid';
      \$alias = \$alias_manager->getAliasByPath(\$internal, \$langcode);
      \$path = '/' . \$langcode . \$alias;
      echo \$base . \$path . PHP_EOL;
    }
  " 2>/dev/null)

  if [ -z "$URLS" ]; then
    echo "[NID:$nid] URLs non résolues, ignoré." >> "$LOG_FILE"
    continue
  fi

  while read -r url; do
    [ -z "$url" ] && continue

    URL_COUNT=$((URL_COUNT + 1))

    # Throttle adaptatif selon CPU
    sys_cpu=$(ps -u "$USER" -o %cpu | awk '{sum+=$1} END {print sum}')
    sys_cpu_int=${sys_cpu%.*}
    if   [ "$sys_cpu_int" -gt 150 ]; then sleep 3
    elif [ "$sys_cpu_int" -gt 100 ]; then sleep 2
    elif [ "$sys_cpu_int" -gt 50  ]; then sleep 1
    else                                   sleep 0.3
    fi

    http_code=$(curl -s -o /dev/null --max-time 10 -w "%{http_code}" \
      -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36" \
      "$url" 2>/dev/null) || http_code="ERR"

    if [ "$http_code" = "ERR" ] || [ "$http_code" = "000" ]; then
      ERROR_COUNT=$((ERROR_COUNT + 1))
      echo "[$URL_COUNT] [NID:$nid] [ERROR] [CPU: ${sys_cpu}%] $url" >> "$LOG_FILE"
    else
      echo "[$URL_COUNT] [NID:$nid] [$http_code] [CPU: ${sys_cpu}%] $url" >> "$LOG_FILE"
    fi

  done <<< "$URLS"

done <<< "$NIDS"

echo "------------------------------------------------------------------" >> "$LOG_FILE"
echo "--- Permanent Warming Finished: $(date) ---" >> "$LOG_FILE"
echo "    Total URLs : $URL_COUNT | ERRORS : $ERROR_COUNT" >> "$LOG_FILE"
echo "------------------------------------------------------------------" >> "$LOG_FILE"