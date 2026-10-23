#!/usr/bin/env bash
MODULE=$1
VERSION=${2:-""}

if [ -z "$VERSION" ]; then
  ddev composer require drupal/$MODULE && ddev drush en -y $MODULE
else
  ddev composer require drupal/$MODULE:$VERSION && ddev drush en -y $MODULE
fi

ddev drush cr

