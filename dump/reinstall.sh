#!/usr/bin/env bash
MODULE=$1
VERSION=${2:-""}

#remove
ddev drush pmu -y $MODULE ; ddev composer remove drupal/$MODULE ; ddev drush cr

#re-add
if [ -z "$VERSION" ]; then
  ddev composer require drupal/$MODULE && ddev drush en -y $MODULE
else
  ddev composer require drupal/$MODULE:$VERSION && ddev drush en -y $MODULE
fi

ddev drush cr


