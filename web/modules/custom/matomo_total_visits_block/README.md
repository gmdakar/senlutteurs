Matomo Total Visits Block
-------------------------

Provides two blocks:

1. **Matomo total visits** – cumulative visit count (from archive_numeric tables)
2. **Matomo Top 5 countries** – Top 5 visitor countries as percentages with flag icons + tooltip on hover

Requirements
------------
1️⃣ Matomo database must be MySQL/MariaDB  
2️⃣ Drupal 9 / 10 / 11  
3️⃣ Add a DB connection in settings.php like:

```php
$databases['matomo']['default'] = [
  'database' => 'YOUR_MATOMO_DB_NAME',
  'username' => 'YOUR_MATOMO_DB_USER',
  'password' => 'YOUR_MATOMO_DB_PASSWORD',
  'host' => 'localhost',
  'driver' => 'mysql',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
];
```

Usage
-----
- Enable the module
- Go to Structure → Block layout
- Add the desired block:
  - "Matomo total visits"
  - "Matomo Top 5 countries"
- Configure:
  - Matomo site ID (ex: 1)
  - Database key: matomo
  - Cache duration (Top countries only – default 1 week)

Top 5 countries block
---------------------
- Data source: `matomo_log_visit.location_country`
- Percentages calculated against the total of geolocated visits
- Flag icons served from flagcdn.com (lightweight PNG)
- Country name displayed on hover (tooltip)
- Cached for 1 week by default (configurable)
- Visual progress bar for each country

How the total visits block works
--------------------------------
- Finds all monthly tables `matomo_archive_numeric_YYYY_MM`
- Builds UNION query dynamically
- Sums `nb_visits` where period=1 (daily archives → accurate cumulative total)
