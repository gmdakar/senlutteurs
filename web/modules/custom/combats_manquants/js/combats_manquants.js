(function ($, Drupal, once) {

  // Namespace de logs dédié pour ce module : facile à filtrer dans la
  // console ("combats_manquants:").
  function log() {
    var args = Array.prototype.slice.call(arguments);
    args.unshift('[combats_manquants]');
    if (window.console && window.console.log) {
      window.console.log.apply(window.console, args);
    }
  }
  function warn() {
    var args = Array.prototype.slice.call(arguments);
    args.unshift('[combats_manquants]');
    if (window.console && window.console.warn) {
      window.console.warn.apply(window.console, args);
    }
  }

  Drupal.behaviors.combatsManquantsForm = {
    attach: function (context) {
      log('behavior attach() appelé. context =', context);

      // Listeners document : une seule fois (pas à chaque rebuild AJAX).
      once('combats-manquants-ajax-debug', 'html').forEach(function () {
        $(document).on('ajaxSend.combatsManquantsDebug', function (event, jqXHR, ajaxSettings) {
          log('ajaxSend ->', ajaxSettings.url);
        });
        $(document).on('ajaxComplete.combatsManquantsDebug', function (event, jqXHR, ajaxSettings) {
          log('ajaxComplete status=', jqXHR.status, 'url=', ajaxSettings.url);
        });
        $(document).on('ajaxError.combatsManquantsDebug', function (event, jqXHR, ajaxSettings, thrownError) {
          warn('ajaxError status=', jqXHR.status, 'erreur=', thrownError, 'url=', ajaxSettings.url, 'body=', jqXHR.responseText);
        });
      });

      var wrappers = once('combats-manquants-form', '#combat-manquant-form-wrapper', context);
      log('nombre de wrapper(s) #combat-manquant-form-wrapper trouvé(s) par once():', wrappers.length);

      wrappers.forEach(function (wrapper) {
        var $wrapper = $(wrapper);
        var $lutteur1 = $wrapper.find('[name="lutteur_1"]');
        var $lutteur2 = $wrapper.find('[name="lutteur_2"]');
        var $ecurie1 = $wrapper.find('[name="lutteur_1_ecurie"]');
        var $ecurie2 = $wrapper.find('[name="lutteur_2_ecurie"]');
        var $verdict = $wrapper.find('[name="verdict"]');

        log('champs trouvés dans le wrapper :', {
          lutteur1: $lutteur1.length,
          lutteur2: $lutteur2.length,
          ecurie1: $ecurie1.length,
          ecurie2: $ecurie2.length,
          verdict: $verdict.length
        });
        if ($lutteur1.length === 0 || $lutteur2.length === 0) {
          warn('champ(s) lutteur_1/lutteur_2 introuvable(s) : le sélecteur [name="..."] ne matche rien. Vérifier le HTML rendu du formulaire (nom réel de l\'input).');
        }

        $lutteur1.on('autocompleteselect', function (event, ui) {
          log('lutteur_1 : autocompleteselect, ui.item =', ui.item);
          $ecurie1.val(ui.item.ecurie || '');
          updateVerdictLabels(ui.item.value, null);
        });
        $lutteur2.on('autocompleteselect', function (event, ui) {
          log('lutteur_2 : autocompleteselect, ui.item =', ui.item);
          $ecurie2.val(ui.item.ecurie || '');
          updateVerdictLabels(null, ui.item.value);
        });

        // Si l'internaute modifie manuellement le nom après une sélection,
        // on ne peut plus garantir l'écurie associée : on vide le champ
        // caché plutôt que de conserver une valeur potentiellement fausse.
        $lutteur1.on('input', function () {
          $ecurie1.val('');
          updateVerdictLabels();
        });
        $lutteur2.on('input', function () {
          $ecurie2.val('');
          updateVerdictLabels();
        });

        /**
         * Met à jour le texte des options "victoire_1"/"victoire_2" du
         * select verdict avec les noms actuellement saisis, à titre
         * purement cosmétique : la résolution du vrai nom (canonique, tel
         * qu'enregistré en base) se fait toujours côté serveur dans
         * CombatManquantForm::validateForm(), quelle que soit la valeur
         * affichée ici.
         */
        function updateVerdictLabels(nom1Override, nom2Override) {
          var nom1 = (nom1Override != null ? nom1Override : ($lutteur1.val() || '')).trim() || 'lutteur 1';
          var nom2 = (nom2Override != null ? nom2Override : ($lutteur2.val() || '')).trim() || 'lutteur 2';

          $verdict.find('option[value="victoire_1"]').text(
            Drupal.t('Victoire de @nom', {'@nom': nom1})
          );
          $verdict.find('option[value="victoire_2"]').text(
            Drupal.t('Victoire de @nom', {'@nom': nom2})
          );
        }

        updateVerdictLabels();
        log('initialisation du wrapper terminée.');
      });
    }
  };
})(jQuery, Drupal, once);
