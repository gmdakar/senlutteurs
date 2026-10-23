<?php

use Drupal\Core\Database\Database;

$connection = Database::getConnection('default', 'matomo');
$siteId = 1;

$results = $connection->query("
  SELECT 
    location_country AS country,
    COUNT(*) AS visits
  FROM matomo_log_visit
  WHERE idsite = :idsite
    AND location_country IS NOT NULL
    AND location_country != ''
    AND location_country != 'xx'
  GROUP BY location_country
  ORDER BY visits DESC
  LIMIT 5
", [':idsite' => $siteId])->fetchAll();

print "\n--- TOP 5 PAYS HISTORIQUE (MATOMO) ---\n";
foreach ($results as $row) {
    print strtoupper($row->country) . " : " . number_format($row->visits, 0, ',', ' ') . " visites\n";
}
print "--------------------------------------\n\n";
