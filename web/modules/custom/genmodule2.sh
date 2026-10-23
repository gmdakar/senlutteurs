# 1. Nettoyage des lignes fantômes dans les tables de champs
ddev drush sqlq "DELETE FROM node__field_athlete WHERE bundle = 'roi_des_arenes'" -y
ddev drush sqlq "DELETE FROM node_revision__field_athlete WHERE bundle = 'roi_des_arenes'" -y
ddev drush sqlq "DELETE FROM node__field_date_debut WHERE bundle = 'roi_des_arenes'" -y
ddev drush sqlq "DELETE FROM node_revision__field_date_debut WHERE bundle = 'roi_des_arenes'" -y
ddev drush sqlq "DELETE FROM node__field_date_fin WHERE bundle = 'roi_des_arenes'" -y
ddev drush sqlq "DELETE FROM node_revision__field_date_fin WHERE bundle = 'roi_des_arenes'" -y
ddev drush sqlq "DELETE FROM node__field_date_intronisation WHERE bundle = 'roi_des_arenes'" -y
ddev drush sqlq "DELETE FROM node_revision__field_date_intronisation WHERE bundle = 'roi_des_arenes'" -y

# 2. Supprime les configs restantes (bundle + champs + displays)
ddev drush config:delete node.type.roi_des_arenes -y
ddev drush config:delete field.storage.node.field_athlete -y
ddev drush config:delete field.storage.node.field_date_debut -y
ddev drush config:delete field.storage.node.field_date_fin -y
ddev drush config:delete field.storage.node.field_date_intronisation -y
ddev drush config:delete field.field.node.roi_des_arenes.field_athlete -y
ddev drush config:delete field.field.node.roi_des_arenes.field_date_debut -y
ddev drush config:delete field.field.node.roi_des_arenes.field_date_fin -y
ddev drush config:delete field.field.node.roi_des_arenes.field_date_intronisation -y
ddev drush config:delete core.entity_form_display.node.roi_des_arenes.default -y
ddev drush config:delete core.entity_view_display.node.roi_des_arenes.default -y

# 3. Cache et réinstallation propre
ddev drush cr
ddev drush -y pmu rois_des_arenes
ddev drush cr