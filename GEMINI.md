# Configuration et Directives - Projet Senelutte

## 1. Directives de Performance & Vitesse (IMPORTANT)
* **Pas de scan complet inutile :** Pour les tâches simples (ex: création de petits modules, ajouts de snippets, hooks), génère le code directement sans balayer inutilement l'ensemble des modules du projet.
* **Réponses directes :** Privilégie la vitesse d'exécution et va à l'essentiel sans longues étapes de réflexion explicite, sauf demande expresse.

## 2. Stack Technique & Environnement
* **Framework :** Drupal 11 / PHP 8.x
* **Environnement local :** DDEV
* **Base de données :** MariaDB / MySQL
* **Cache & Moteur :** Redis, LiteSpeed Cache, Search API

## 3. Commandes Exécutables
* Toujours utiliser **DDEV** pour les commandes système, Drush et Composer :
  * Vider les caches : `ddev drush cr`
  * Statut du site : `ddev drush st`
  * Exécution Drush : `ddev drush <command>`
  * Gestion dépendances : `ddev composer install` / `ddev composer require <package>`

## 4. Conventions de Code Drupal
* Respecter les standards de codage Drupal (Drupal Coding Standards / PSR-12).
* Pour tout code custom dans `web/modules/custom/` :
  * Typer impérativement les paramètres et valeurs de retour en PHP.
  * Privilégier l'injection de dépendances (Dependency Injection) au lieu des appels statiques `\Drupal::service()`.
  * Rédiger des commentaires DocBlock synthétiques et propres.

## 5. Règles de Sécurité et Portée
* Ne jamais modifier les fichiers du cœur de Drupal (`web/core/`) ni des modules contribués (`web/modules/contrib/`).
* Toutes les modifications et créations sur mesure doivent se faire exclusivement dans `web/modules/custom/` ou `web/themes/custom/`.