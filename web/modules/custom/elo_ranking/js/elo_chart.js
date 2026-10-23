(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.eloChart = {
    attach: function (context) {
      const canvas = context.querySelector("#elo-chart");
      if (!canvas || canvas.dataset.chartReady === "1") return;

      canvas.dataset.chartReady = "1";

      var nid = drupalSettings && drupalSettings.elo_ranking && drupalSettings.elo_ranking.nid
        ? drupalSettings.elo_ranking.nid
        : (canvas.closest('article') ? canvas.closest('article').getAttribute('data-history-node-id') : null);

      if (!nid) {
        console.warn("No athlete nid found");
        return;
      }

      this.showSpinner(canvas);

      Promise.all([
        fetch('/elo-check-status').then(function(r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        }),
        fetch('/elo-history/' + nid, { cache: 'no-cache' }).then(function(r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
      ])
      .then(function(results) {
        var status = results[0];
        var data = results[1];
        this.hideSpinner(canvas);
        if (status.rebuild_active === true) {
          this.handleMaintenance(canvas, context);
        } else {
          this.renderChart(canvas, context, data);
        }
      }.bind(this))
      .catch(function(err) {
        console.error("[ELO] Erreur:", err);
        this.hideSpinner(canvas);
        this.renderChart(canvas, context, null);
      }.bind(this));
    },

    showSpinner: function (canvas) {
      canvas.style.display = 'none';
      if (document.getElementById('elo-spinner')) return;

      var spinner = document.createElement('div');
      spinner.id = 'elo-spinner';
      spinner.style.display = 'flex';
      spinner.style.alignItems = 'center';
      spinner.style.justifyContent = 'center';
      spinner.style.height = '200px';
      spinner.style.width = '100%';

      var circle = document.createElement('div');
      circle.style.width = '28px';
      circle.style.height = '28px';
      circle.style.border = '3px solid #e0e0e0';
      circle.style.borderTopColor = '#54b4eb';
      circle.style.borderRadius = '50%';
      circle.style.animation = 'elo-spin 0.8s linear infinite';

      if (!document.getElementById('elo-spin-style')) {
        var style = document.createElement('style');
        style.id = 'elo-spin-style';
        style.textContent = '@keyframes elo-spin { to { transform: rotate(360deg); } }';
        document.head.appendChild(style);
      }

      spinner.appendChild(circle);
      canvas.parentNode.insertBefore(spinner, canvas);
    },

    hideSpinner: function (canvas) {
      var spinner = document.getElementById('elo-spinner');
      if (spinner) spinner.remove();
    },

    renderChart: function (canvas, context, data) {
      var maintenanceMsg = context.getElementById
        ? context.getElementById('elo-rebuild-msg')
        : document.getElementById('elo-rebuild-msg');
      if (maintenanceMsg) maintenanceMsg.remove();

      canvas.style.display = 'block';

      var ratingField = document.querySelector('.field--name-elo-rating');
      if (ratingField) ratingField.style.display = '';

      var disclaimer = document.querySelector('.elo-disclaimer');
      if (disclaimer) disclaimer.style.display = '';

      if (typeof Chart === "undefined") {
        console.error("Chart.js not loaded");
        return;
      }

      var nid = drupalSettings && drupalSettings.elo_ranking && drupalSettings.elo_ranking.nid
        ? drupalSettings.elo_ranking.nid
        : (canvas.closest('article') ? canvas.closest('article').getAttribute('data-history-node-id') : null);

      if (!nid) {
        console.warn("No athlete nid found");
        return;
      }

      var renderWithData = function(data) {
        if (canvas.chartInstance) {
          canvas.chartInstance.destroy();
        }

        if (!Array.isArray(data) || data.length === 0) {
          console.warn("Empty Elo history dataset");
          return;
        }

        var labels = [];
        var values = [];

        data.forEach(function(row) {
          var year = row.date_display ? row.date_display.split('/').pop() : "";
          labels.push(year);

          var elo = Number(row.elo);
          if (!isFinite(elo)) elo = 0;
          values.push(elo);
        });

        canvas.chartInstance = new Chart(canvas, {
          type: "line",
          data: {
            labels: labels,
            datasets: [{
              label: Drupal.t("Evolution performance carriere (points)"),
              data: values,
              borderColor: "#54b4eb",
              borderWidth: 2,
              tension: 0.35,
              pointRadius: 2.5,
              fill: false
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            scales: {
              x: {
                grid: { display: true, color: "#eeeeee" },
                ticks: { autoSkip: true, maxTicksLimit: 10 }
              },
              y: {
                suggestedMin: 950,
                suggestedMax: 1300,
                grid: { display: true, color: "#eeeeee" }
              }
            },
            plugins: {
              legend: { display: true, position: 'top' },
              tooltip: { enabled: true }
            }
          }
        });
      };

      if (data) {
        renderWithData(data);
      } else {
        fetch("/elo-history/" + nid, { cache: 'no-cache' })
          .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
          })
          .then(renderWithData)
          .catch(function(error) {
            console.error("Elo chart error:", error);
            if (canvas.chartInstance) canvas.chartInstance.destroy();
          });
      }
    },

    handleMaintenance: function (canvas, context) {
      canvas.style.display = 'none';

      var ratingField = document.querySelector('.field--name-elo-rating');
      if (ratingField) ratingField.style.display = 'none';

      var disclaimer = document.querySelector('.elo-disclaimer');
      if (disclaimer) disclaimer.style.display = 'none';

      if (!document.getElementById('elo-rebuild-msg')) {
        var msg = document.createElement('div');
        msg.id = 'elo-rebuild-msg';
        msg.className = 'alert alert-warning text-center';
        msg.style.padding = '20px';
        msg.style.marginTop = '20px';
        msg.innerHTML = Drupal.t("Recalcul des performances en cours...");
        canvas.parentNode.insertBefore(msg, canvas);
      }
    }
  };
})(Drupal, drupalSettings);