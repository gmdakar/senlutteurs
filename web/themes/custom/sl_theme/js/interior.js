/**
 * @file
 * Interior page utilities.
 */
(function ($, Drupal, drupalSettings, once) {
  'use strict';

  // Liste des types obsolètes à ignorer totalement
  const signalObsoletes = ['s_lutte_frappe', 's_anomalie'];

  // ---------------------------------------------------------------------------
  // slWebPrefixRedirect
  // En environnement non-dev, force le préfixe /web dans l'URL pour :
  //   • les pages d'administration  (/admin/)
  //   • les pages avec ?signaler=ano
  // Sans ce préfixe, LiteSpeed/N0C ne route pas correctement vers Drupal.
  // La variable d'environnement est injectée via drupalSettings par PHP
  // (hook_page_attachments) depuis $_ENV['ENVIRONMENT'].
  // ---------------------------------------------------------------------------
  Drupal.behaviors.slWebPrefixRedirect = {
    attach(context, settings) {
      once('web-prefix-redirect', 'html', context).forEach(() => {

        // Récupération de l'environnement injecté par PHP
        // Fallback sur 'dev' si absent → pas de redirection par défaut (safe)
        const env = settings?.sl_signalements?.environment ?? 'dev';
        if (env === 'dev') return;

        const path = window.location.pathname;
        const search = window.location.search;

        // Déjà préfixé : rien à faire, évite une boucle de redirection
        if (path.startsWith('/web/') || path === '/web') return;

        // Cas déclencheurs du préfixage /web en prod
        //const isAdmin    = path.includes('/admin/');   // back-office Drupal
        const isSignaler = search.includes('signaler=ano'); // mode signalement

        // Aucun des cas concernés → on laisse l'URL telle quelle
        //if (!isAdmin && !isSignaler) return;
        if (!isSignaler) return;

        // Redirection avec replace() : pas d'entrée supplémentaire
        // dans l'historique du navigateur (transparent pour l'utilisateur)
        window.location.replace(
          window.location.origin +
          '/web' +
          path +
          search +
          window.location.hash
        );
      });
    }
  };

  //corriger bug reset button
  Drupal.behaviors.slBefResetFix = {
    attach(context) {
      once('bef-reset-fix', '.views-exposed-form', context).forEach(form => {
        const resetBtn = form.querySelector('button[name="reset"]');
        if (!resetBtn) return;

        resetBtn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopImmediatePropagation();
          // L'action du form contient déjà le bon chemin (/web/fr/lutteurs)
          window.location.href = form.getAttribute('action') || window.location.pathname;
        }, true); // capture phase pour passer avant les handlers BEF
      });
    }
  };

  // ---------------------------------------------------------------------------
  // toggleHomonymesBlock
  // Affiche le bloc des homonymes uniquement s'il contient au moins 2 liens.
  // ---------------------------------------------------------------------------
  Drupal.behaviors.toggleHomonymesBlock = {
    attach(context) {
      // On cible le bloc spécifique des homonymes
      once('toggle-homonymes', '.view-lutteurs-indexed.view-display-id-block_3', context).forEach(block => {
        // On compte le nombre de liens d'homonymes à l'intérieur
        const linksCount = block.querySelectorAll('.views-field-title a').length;

        // Si on a au moins 2 liens, on affiche le bloc
        if (linksCount >= 2) {
          block.style.display = 'block'; // Ou 'flex', 'grid' selon votre design
        }
      });
    }
  };

  Drupal.behaviors.ecurieSelectionOrder = {
    attach: function (context, settings) {
      once('ecurie-order', '.field--widget-options-select select[multiple]', context)
        .forEach(function (selectEl) {
          let selectionOrder = [];

          $(selectEl).on('change', function () {
            const currentlySelected = Array.from(selectEl.selectedOptions).map(o => o.value);
            selectionOrder = selectionOrder.filter(v => currentlySelected.includes(v));
            currentlySelected.forEach(function (val) {
              if (!selectionOrder.includes(val)) selectionOrder.push(val);
            });
            selectionOrder.forEach(function (val) {
              const opt = selectEl.querySelector('option[value="' + CSS.escape(val) + '"]');
              if (opt) selectEl.appendChild(opt);
            });
            $(selectEl).trigger('chosen:updated');
          });
        });
    }
  };

  // ---------------------------------------------------------------------------
  // slSignalementsActions
  // Gère les clics sur tous les boutons de signalement : standards + variantes.
  //
  // Deux chemins selon la présence de data-token :
  //   • Standard  → token depuis drupalSettings + body { athlete_name, lutteur_nid }
  //   • Variante  → token dans data-token       + body { variant_nid }
  // ---------------------------------------------------------------------------
  Drupal.behaviors.slSignalementsActions = {
    attach(context) {
      $(once('signalement-delegation', 'body', context)).on('click', 'a[id*="signal_"]', async function (e) {
        const btn = this;
        const isAthletePage = document.body.classList.contains('node--type-athlete');

        if (!isAthletePage) return;
        e.preventDefault();

        const rawId = btn.id || '';

        // ── Variante ──────────────────────────────────────────────────────────
        // ID pattern : signal_s_variante_athlete_1_{confNid}_{variantNid}
        const variantMatch = rawId.match(/signal_(s_variante_athlete_[12])_(\d+)_(\d+)/);
        if (variantMatch) {
          const eventType = variantMatch[1];   // s_variante_athlete_1 | s_variante_athlete_2
          const confNid = variantMatch[2];
          const variantNid = variantMatch[3];
          const token = btn.dataset.token;

          if (!token) {
            console.error('Token manquant pour variante', rawId);
            return;
          }

          await _sendSignal(btn, `/api/signal/${confNid}/${eventType}/${token}`, { variant_nid: parseInt(variantNid, 10) });
          return;
        }

        // ── Standard ──────────────────────────────────────────────────────────
        // ID pattern : signal_{s_type}_{confNid}
        const match = rawId.match(/signal_(s_[a-zA-Z0-9_]+)_(\d+)/);
        if (!match) return;

        const eventType = match[1];
        const id = match[2];
        const token = drupalSettings?.sl_signalements?.tokens?.[id]?.[eventType];
        const athleteName = btn.dataset.athleteName || null;
        const nodeMatch = document.body.className.match(/\bnode-(\d+)\b/);
        const lutteurNid = nodeMatch ? nodeMatch[1] : null;

        if (!token) {
          console.error('Token manquant pour', eventType);
          return;
        }

        const bodyData = {
          ...(athleteName ? { athlete_name: athleteName } : {}),
          ...(lutteurNid ? { lutteur_nid: lutteurNid } : {}),
        };

        await _sendSignal(btn, `/api/signal/${id}/${eventType}/${token}`, bodyData);
      });
    }
  };

  /**
   * Envoie un signalement en POST et remplace le bouton par le feedback.
   *
   * @param {HTMLElement} btn       Le bouton cliqué.
   * @param {string}      url       URL complète du endpoint.
   * @param {Object}      bodyData  Corps JSON à envoyer.
   */
  async function _sendSignal(btn, url, bodyData) {
    btn.style.opacity = '0.5';
    btn.style.pointerEvents = 'none';

    try {
      const isLoggedIn = drupalSettings?.user?.uid && drupalSettings.user.uid !== '0';
      const isAdmin = drupalSettings?.user?.permissionsHash === 'is-admin';

      const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/json',
          ...(isAdmin ? { 'X-Force-Apply': '1' } : {}),
        },
        body: JSON.stringify({
          ...bodyData,
          ...(isLoggedIn ? { is_logged_in: true } : {}),
        }),
      });

      const data = await response.json();
      const feedback = document.createElement('span');
      Object.assign(feedback.style, {
        fontSize: '0.85em',
        fontWeight: 'bold',
        display: 'block',
        marginTop: '4px',
      });

      if (response.ok) {
        feedback.style.color = 'green';
        feedback.textContent = `👍 ${data.message || 'Signalé'}`;
      } else {
        feedback.style.color = '#d9534f';
        feedback.textContent = `⚠️ ${data.error || 'Erreur'}`;
      }

      btn.replaceWith(feedback);
    } catch (err) {
      console.error('Erreur technique :', err);
      btn.style.opacity = '1';
      btn.style.pointerEvents = 'auto';
    }
  }

  // ---------------------------------------------------------------------------
  // slVariantButtons
  // Pour chaque ligne de confrontation, interroge /api/sl/variants/{confNid}
  // et injecte dynamiquement des boutons "Vrai adversaire : X" si des variantes
  // de nom existent en base (ex. "Talfa 2", "Talfa Junior").
  //
  // Les boutons sont insérés dans la même cellule que les boutons vainqueur
  // existants (colonne athlete_1 ou athlete_2 selon le slot retourné par l'API).
  // Chaque bouton porte :
  //   id          = signal_s_variante_athlete_1_{confNid}_{variantNid}
  //   data-token  = token HMAC calculé côté serveur (inclut le variantNid)
  // ---------------------------------------------------------------------------
  Drupal.behaviors.slVariantButtons = {
    attach(context) {
      if (!document.body.classList.contains('node--type-athlete')) return;

      const h1 = document.querySelector('h1 .field__item');
      const currentName = h1 ? h1.textContent.trim().toLowerCase() : null;

      once('variant-buttons', 'table tbody tr', context).forEach(async row => {

        const existingBtn = row.querySelector('a[id*="signal_"]');
        if (!existingBtn) return;

        const actionsContainer = existingBtn.parentElement;
        if (!actionsContainer) return;

        const idMatch = existingBtn.id.match(/signal_[a-zA-Z0-9_]+_(\d+)/);
        if (!idMatch) return;

        const confNid = idMatch[1];

        // --- API
        let variantsData;
        try {
          const res = await fetch(`/api/sl/variants/${confNid}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
          });
          if (!res.ok) return;
          variantsData = await res.json();
        } catch (e) {
          console.warn(`Variants API error for ${confNid}`, e);
          return;
        }

        const wrapper = document.createElement('div');

        let hasVariants = false;

        for (const [slot, info] of Object.entries(variantsData)) {
          if (!info || !info.variants || !info.variants.length) continue;

          // ❗ IGNORER le lutteur courant (Talfa ici)
          if (currentName && info.name.toLowerCase() === currentName) continue;

          for (const v of info.variants) {

            const btn = document.createElement('a');

            btn.id = `signal_${v.type}_${confNid}_${v.nid}`;
            btn.dataset.token = v.token;
            btn.href = '#';

            // ✅ même wording que les autres boutons
            btn.textContent = `Signaler adversaire est ${v.name}`;

            // ✅ reprendre les classes existantes
            btn.className = existingBtn.className;

            wrapper.appendChild(btn);
            hasVariants = true;
          }
        }

        if (hasVariants) {
          actionsContainer.appendChild(wrapper);
        }
      });
    }
  };

  // ---------------------------------------------------------------------------
  // manageAthleteTables
  // ---------------------------------------------------------------------------
  Drupal.behaviors.manageAthleteTables = {
    attach(context) {
      if (!document.body.classList.contains('node--type-athlete')) return;

      once('athlete-table-logic', 'table', context).forEach(table => {
        const hasSignal = table.querySelector('a[id*="signal_"], a[href*="signal_"]');
        const rows = table.querySelectorAll('tr');

        rows.forEach(row => {
          if (hasSignal) {
            if (row.cells[4]) row.cells[4].style.display = 'none';
            if (row.cells[5]) row.cells[5].style.display = 'none';
          }
          else {
            const lastIndex = row.cells.length - 1;
            if (lastIndex >= 0) row.cells[lastIndex].style.display = 'none';
          }
        });
      });
    }
  };

  // ---------------------------------------------------------------------------
  // fixWrestlerColumn
  // ---------------------------------------------------------------------------
  Drupal.behaviors.fixWrestlerColumn = {
    attach(context) {
      if (!document.body.classList.contains('node--type-athlete')) return;
      const h1 = document.querySelector('h1 .field__item');
      if (!h1) return;
      const wrestlerName = h1.textContent.trim();

      once('fix-columns-logic', 'table tbody tr', context).forEach(row => {
        const cols = row.querySelectorAll('td');
        if (cols.length < 4) return;

        const col2 = cols[1];
        const col3 = cols[2];
        const verdictCol = cols[3];

        if (col3.textContent.trim() === wrestlerName && col2.textContent.trim() !== wrestlerName) {
          const tmpHTML = col2.innerHTML;
          col2.innerHTML = col3.innerHTML;
          col3.innerHTML = tmpHTML;
        }

        const verdictText = verdictCol.textContent.replace(/\*\*.*$/, '').trim();
        verdictCol.classList.remove('verdict-victory', 'verdict-defeat');

        if (verdictText === wrestlerName) {
          verdictCol.classList.add('verdict-victory');
          if (!verdictCol.querySelector('.icon-win')) {
            verdictCol.insertAdjacentHTML('afterbegin', '<span class="icon-win"></span> ');
          }
        }
        else if (verdictText !== '' && verdictText !== 'Nul' && verdictText !== '?') {
          verdictCol.classList.add('verdict-defeat');
        }
      });
    }
  };

  // ---------------------------------------------------------------------------
  // hideViewFooterOnFilter
  // Masque le bloc .view-footer au clic sur n'importe quel filtre et
  // maintient le masquage même après les rechargements Ajax de Views / BEF.
  // ---------------------------------------------------------------------------
  Drupal.behaviors.hideViewFooterOnFilter = {
    attach(context) {
      const formSelector = '#views-exposed-form-confrontations-block-3';

      // Variable utilitaire pour masquer le footer dans le DOM
      const hideFooter = () => {
        const form = document.querySelector(formSelector);
        const viewContainer = form ? form.closest('.view') : null;
        const footers = viewContainer
          ? viewContainer.querySelectorAll('.view-footer')
          : document.querySelectorAll('.view-footer');

        footers.forEach(footer => {
          footer.style.display = 'none';
        });
      };

      // 1. Écoute le changement au clic direct sur les radios
      once('hide-footer-on-radio', `${formSelector} input[name="field_is_lutte_simple"]`, context)
        .forEach(radio => {
          radio.addEventListener('change', hideFooter);
        });

      // 2. Écoute la fin de CHAQUE requête Ajax (rechargement Views / BEF)
      once('hide-footer-ajax-listener', 'body', context).forEach(() => {
        $(document).ajaxComplete(function (event, xhr, settings) {
          // Si la requête Ajax concerne le formulaire Views / BEF
          if (settings.extraData && settings.extraData.view_name) {
            hideFooter();
          }
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings, once);