/**
 * @file
 * Gestion de la comparaison Elo - SenLutteurs
 * Utilise les alias d'URL fournis par l'API pour les liens vers les fiches.
 */
(function ($, Drupal, once) {
  Drupal.behaviors.eloCompare = {
    attach: function (context, settings) {

      // COULEURS DE RÉFÉRENCE
      const COLOR_1 = '#e63946'; // Rouge (Athlète 1)
      const COLOR_2 = '#1d3557'; // Bleu Nuit (Athlète 2)

      // --- 1. NETTOYAGE APRES SELECTION ---
      // Supprime le (nid) du champ visuel pour ne garder que le nom du lutteur
      $(context).find('.athlete-autocomplete').on('autocompleteclose', function (event, ui) {
        const $input = $(this);
        const value = $input.val();
        if (value.includes('(')) {
          const cleanedName = value.split(' (')[0];
          $input.val(cleanedName);
        }
      });

      // --- 2. STOCKAGE DE L'ID RÉEL ---
      $(context).find('.athlete-autocomplete').on('autocompleteselect', function (event, ui) {
        const match = ui.item.value.match(/\((\d+)\)$/);
        if (match) {
          $(this).data('real-id', match[1]);
          // Supprime le lien précédent si l'utilisateur change de sélection
          $(this).closest('.js-form-item').find('.link-to-node').remove();
        }
      });

      // --- 3. BOUTON COMPARER ---
      once('eloCompareInit', '#elo-compare-btn', context).forEach(function (btn) {
        $(btn).on('click', function (e) {
          e.preventDefault();
          
          if (typeof Chart === 'undefined') return;

          const $input1 = $(context).find('input[data-id="1"]');
          const $input2 = $(context).find('input[data-id="2"]');
          
          const id1 = $input1.data('real-id');
          const id2 = $input2.data('real-id');

          if (id1 && id2) {
            $(btn).text(Drupal.t('Chargement...')).prop('disabled', true);
            
            // Appel à l'API du Controller
            fetch(`/api/elo-ranking/compare/${id1}/${id2}`)
              .then(res => res.json())
              .then(data => {
                // AJOUT DES LIENS VERS LES PROFILS
                addNodeLink(1, data.athlete1.url, data.athlete1.name, COLOR_1);
                addNodeLink(2, data.athlete2.url, data.athlete2.name, COLOR_2);

                // Rendu du graphique
                rebuildAndRender(data, COLOR_1, COLOR_2);
                
                $(btn).text(Drupal.t('Comparer les carrières')).prop('disabled', false);

                // TRACKING MATOMO
                if (typeof _paq !== 'undefined') {
                  _paq.push(['trackEvent', 'Comparaison Elo', 'Combat', data.athlete1.name + ' vs ' + data.athlete2.name]);
                }
              })
              .catch(() => {
                alert(Drupal.t("Erreur lors de la récupération des données."));
                $(btn).text(Drupal.t('Comparer les carrières')).prop('disabled', false);
              });
          } else {
            alert("Veuillez sélectionner les deux lutteurs via les suggestions.");
          }
        });
      });

      /**
       * Génère un badge cliquable avec l'alias et target _blank
       */
      function addNodeLink(num, url, name, bgColor) {
        const $input = $(context).find(`input[data-id="${num}"]`);
        const $wrapper = $input.closest('.js-form-item');
        
        $wrapper.find('.link-to-node').remove();

        const targetUrl = url ? url : '#';

        const linkHtml = `
          <div class="link-to-node" style="margin-top: 8px;">
            <a href="${targetUrl}" target="_blank" class="badge text-decoration-none" 
               style="background-color: ${bgColor}; color: #ffffff; font-size: 0.85em; padding: 6px 14px; border-radius: 4px; display: inline-block; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
              Voir la fiche de ${name} ↗
            </a>
          </div>`;
        
        $wrapper.append(linkHtml);
      }

      // --- 4. BOUTON RESET ---
      once('eloResetInit', '#elo-reset-btn', context).forEach(function (btn) {
        $(btn).on('click', function (e) {
          e.preventDefault();
          $('.athlete-autocomplete').val('').data('real-id', null);
          $('.link-to-node').remove(); 
          $('#elo-chart-container').empty();
          if (window.myEloChart) window.myEloChart.destroy();

          if (typeof _paq !== 'undefined') {
            _paq.push(['trackEvent', 'Comparaison Elo', 'Action', 'Reset']);
          }
        });
      });

      /**
       * Rendu du graphique Chart.js
       */
      function rebuildAndRender(data, c1, c2) {
        const container = document.getElementById('elo-chart-container');
        if (!container) return;

        container.innerHTML = '<canvas id="elo-compare-chart"></canvas>';
        const ctx = document.getElementById('elo-compare-chart').getContext('2d');

        const allDates = [...new Set([...data.athlete1.dates, ...data.athlete2.dates])].sort((a, b) => new Date(a) - new Date(b));
        
        const mapScores = (athlete) => allDates.map(d => {
          const i = athlete.dates.indexOf(d);
          return i !== -1 ? athlete.scores[i] : null;
        });

        if (window.myEloChart instanceof Chart) window.myEloChart.destroy();

        window.myEloChart = new Chart(ctx, {
          type: 'line',
          data: {
            labels: allDates.map(d => d.split(' ')[0]),
            datasets: [
              {
                label: data.athlete1.name,
                data: mapScores(data.athlete1),
                borderColor: c1,
                backgroundColor: c1 + '20',
                spanGaps: true,
                tension: 0.3,
                borderWidth: 4,
                pointRadius: 4,
                pointBackgroundColor: c1
              },
              {
                label: data.athlete2.name,
                data: mapScores(data.athlete2),
                borderColor: c2,
                backgroundColor: c2 + '20',
                spanGaps: true,
                tension: 0.3,
                borderWidth: 4,
                pointRadius: 4,
                pointBackgroundColor: c2
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            // Interaction supprimée car les tooltips sont désactivés
            plugins: {
              legend: { 
                position: 'top', 
                labels: { font: { weight: 'bold' } } 
              },
              tooltip: {
                enabled: false // <-- Tooltips désactivés ici
              }
            },
            scales: {
              y: { title: { display: true, text: 'Score Elo' } },
              x: { title: { display: true, text: 'Date du combat' } }
            }
          }
        });

        setTimeout(() => {
          container.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 150);
      }
    }
  };
})(jQuery, Drupal, once);