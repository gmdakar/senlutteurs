#!/usr/bin/env bash
MODULE=$1

ddev drush pmu -y $MODULE ; ddev composer remove drupal/$MODULE ; ddev drush cr



