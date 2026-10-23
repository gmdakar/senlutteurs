/**
 * @file
 * Elo Master Control - v1.4 (detach + Chart.js plugin)
 */

(function ($, Drupal, drupalSettings, once) {
  'use strict';

  const CANVAS_SELECTOR = 'canvas[id^="chart-lutteurs-indexed-page-"]';

  // ============================================================
  // ✅ Plugin Chart.js global — bind events à chaque afterInit()
  // Décorrélé du cycle AJAX Drupal : fonctionne peu importe quand
  // et combien de fois un chart est (re)créé.
  // ============================================================
  if (!Chart.registry.plugins.get('eloInteraction')) {
    Chart.register({
      id: 'eloInteraction',
      afterInit(chart) {
        const canvas = chart.canvas;

        // Ignorer les charts non concernés (career chart, etc.)
        if (!canvas.matches(CANVAS_SELECTOR)) return;

        canvas.style.pointerEvents = 'auto';

        // Hover cursor
        chart.options.onHover = (event) => {
          canvas.style.cursor = (event.x <= 180) ? 'pointer' : 'default';
        };

        $(canvas)
          .off('mousemove.elo')
          .on('mousemove.elo', function (e) {
            const rect = this.getBoundingClientRect();
            this.style.cursor = ((e.clientX - rect.left) <= 180) ? 'pointer' : 'default';
          });

        // Click → ouvrir profil
        $(canvas)
          .off('click.elo')
          .on('click.elo', function (e) {
            const rect = canvas.getBoundingClientRect();
            if ((e.clientX - rect.left) > 180) return;

            const idx = Math.round(
              chart.scales.y.getValueForPixel(e.clientY - rect.top)
            );
            const urls = drupalSettings.elo_ranking?.ranking_urls;
            if (idx >= 0 && urls?.[idx]) {
              window.open(urls[idx], '_blank');
            }
          });

        // Style axes
        if (chart.options?.scales?.y?.ticks) {
          chart.options.scales.y.ticks.color = '#e63946';
          chart.options.scales.y.ticks.font = { weight: 'bold' };
        }

        chart.update();
        console.log('[ELO] Plugin bound:', canvas.id);
      }
    });
  }

  // ============================================================
  // Behavior Drupal : status check + career chart uniquement.
  // Le ranking chart est géré exclusivement par le plugin ci-dessus.
  // ============================================================
  Drupal.behaviors.eloMasterControl = {

    attach: function (context) {
      const self = this;

      // Status maintenance : une seule vérification par session
      if (!this._statusChecked) {
        this._statusChecked = true;

        fetch('/elo-check-status?nocache=' + Date.now(), {
          method: 'GET',
          headers: { 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' }
        })
        .then(res => res.json())
        .then(data => {
          self._maintenanceMode = data.rebuild_active === true;
          self._maintenanceMode ? self.handleMaintenance() : self._cleanMaintenance();
        })
        .catch(() => {
          self._maintenanceMode = false;
          self._cleanMaintenance();
        });

      } else if (!this._maintenanceMode) {
        // Sur chaque AJAX : retirer le style maintenance si présent
        this._cleanMaintenance();
      }

      // Career chart (once par canvas, context AJAX-safe)
      once('elo-career-load', '#elo-chart', context).forEach((canvas) => {
        const nid =
          drupalSettings.elo_ranking?.nid ||
          $(canvas).closest('article').data('history-node-id');
        if (nid) this.renderCareerChart(canvas, nid);
      });
    },

    // ✅ detach() : détruire les instances Chart.js AVANT que le DOM soit remplacé
    // Sans ça, les instances restent en mémoire → conflit sur re-navigation
    detach: function (context, settings, trigger) {
      if (trigger !== 'unload') return;

      context.querySelectorAll(CANVAS_SELECTOR).forEach((canvas) => {
        const chart = Chart.getChart(canvas);
        if (chart) {
          console.log('[ELO] Destroy before AJAX replace:', canvas.id);
          chart.destroy();
        }
      });

      // Career chart
      const careerCanvas = context.querySelector('#elo-chart');
      if (careerCanvas) {
        const chart = Chart.getChart(careerCanvas);
        if (chart) chart.destroy();
      }
    },

    _cleanMaintenance: function () {
      document.getElementById('elo-maintenance-style')?.remove();
      $('#elo-rebuild-msg').remove();
    },

    handleMaintenance: function () {
      if (!document.getElementById('elo-maintenance-style')) {
        $('<style>', {
          id: 'elo-maintenance-style',
          text: `
            ${CANVAS_SELECTOR},
            #block-sl-theme-elocompareathletes,
            #elo-chart { display: none !important; }
          `
        }).appendTo('head');
      }
      if (!$('#elo-rebuild-msg').length) {
        $('.block-page-title-block').after(`
          <div id="elo-rebuild-msg"
               class="alert alert-warning mt-4 text-center"
               style="padding:40px;">
            🔄 ${Drupal.t("Recalcul des performances en cours...")}
          </div>
        `);
      }
    },

    renderCareerChart: function (canvas, nid) {
      fetch(`/elo-history/${nid}?t=${Date.now()}`)
        .then(res => res.json())
        .then(data => {
          if (!data?.length) return;
          const existing = Chart.getChart(canvas);
          if (existing) existing.destroy();
          new Chart(canvas, {
            type: 'line',
            data: {
              labels: data.map(d => d.date_display.split('/').pop()),
              datasets: [{
                label: Drupal.t('Score'),
                data: data.map(d => d.elo),
                borderColor: '#54b4eb',
                tension: 0.3,
                fill: false
              }]
            },
            options: { responsive: true, maintainAspectRatio: false }
          });
        })
        .catch(err => console.error('[ELO] History error:', err));
    }
  };

})(jQuery, Drupal, drupalSettings, once);