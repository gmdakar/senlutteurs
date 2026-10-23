/**
 * @file
 * Global utilities.
 */
(function ($, Drupal, drupalSettings, once) {
  'use strict';

  function updateToggleLabel() {
    const el = document.querySelector('#superfish-main-toggle span');
    if (el) {
      const label = drupalSettings.superfish?.accordionToggle?.main ?? 'Navigation principale';
      el.textContent = label;
      return true;
    }
    return false;
  }

  // ---------------------------------------------------------------------------
  // sfAccordionLabel
  // ---------------------------------------------------------------------------
  Drupal.behaviors.sfAccordionLabel = {
    attach(context) {
      if (!updateToggleLabel()) {
        setTimeout(updateToggleLabel, 100);
        setTimeout(updateToggleLabel, 400);
        setTimeout(updateToggleLabel, 500);
      }
    }
  };

  // ---------------------------------------------------------------------------
  // slPwaTracking
  // ---------------------------------------------------------------------------
  Drupal.behaviors.slPwaTracking = {
    attach(context) {
      once('pwa-tracking', 'html', context).forEach(() => {
        let deferredPrompt = null;

        window.addEventListener('beforeinstallprompt', (e) => {
          deferredPrompt = e;
          const manualBlock = document.querySelector('#block-pwa-install-manual');
          if (manualBlock) {
            manualBlock.style.display = 'block';
          }
        });

        $(context).on('click', '#block-pwa-install-manual .pwa-a2hs a, #block-pwa-install-manual .pwa-a2hs button', function (e) {
          localStorage.setItem('pwa_origin', 'bouton');

          if (typeof _paq !== 'undefined') {
            _paq.push(['trackEvent', 'PWA', 'Click Install Button', 'User Intent']);
          }

          if (deferredPrompt) {
            e.preventDefault();
            deferredPrompt.prompt();

            deferredPrompt.userChoice.then((choiceResult) => {
              if (choiceResult.outcome === 'accepted') {
                console.log('L\'utilisateur a accepté l\'installation via le bouton');
              } else {
                console.log('L\'utilisateur a refusé l\'installation');
                localStorage.removeItem('pwa_origin');
              }
              deferredPrompt = null;
            });
          }
        });

        window.onappinstalled = (event) => {
          if (typeof _paq !== 'undefined') {
            const origin = localStorage.getItem('pwa_origin');
            if (origin === 'bouton') {
              _paq.push(['trackEvent', 'PWA', 'Installation', 'Via_Bouton_Site']);
            } else {
              _paq.push(['trackEvent', 'PWA', 'Installation', 'Via_Navigateur_Direct']);
            }
          }
          localStorage.removeItem('pwa_origin');
          deferredPrompt = null;
        };
      });
    }
  };

  // ---------------------------------------------------------------------------
  // matomoViewsAjaxTracking
  // ---------------------------------------------------------------------------
  Drupal.behaviors.matomoViewsAjaxTracking = {
    attach: function (context, settings) {
      if (typeof _paq !== 'undefined') {
        once('matomoAjaxTracking', 'html', context).forEach(function () {
          $(document).ajaxComplete(function (event, xhr, settingsAjax) {
            if (settingsAjax.url && settingsAjax.url.indexOf('views/ajax') !== -1) {
              setTimeout(function () {
                let currentPath = window.location.pathname;
                let allParams = new URLSearchParams();
                let vName = '';

                [settingsAjax.url.split('?')[1], settingsAjax.data].forEach(source => {
                  if (source) {
                    let p = (typeof source === 'string')
                      ? new URLSearchParams(source)
                      : new URLSearchParams($.param(source));
                    p.forEach((v, k) => {
                      if (v && v !== '') {
                        if (k === 'view_name') vName = v;
                        allParams.set(k, v);
                      }
                    });
                  }
                });

                const techKeys = [
                  'view_display_id', 'view_args', 'view_path', 'view_base_path',
                  'view_dom_id', 'pager_element', 'ajax_page_state', '_drupal_ajax',
                  '_wrapper_format', 'ajax_iframe_upload', 'hash', 'q',
                ];
                let cleanParams = new URLSearchParams();
                if (vName) cleanParams.set('view', vName);

                allParams.forEach((value, key) => {
                  const isTech = techKeys.includes(key)
                    || key.indexOf('ajax_page_state') === 0
                    || key === 'view_name';
                  if (!isTech && value !== 'undefined' && value !== 'null') {
                    cleanParams.set(key, value);
                  }
                });

                let fullUrl = currentPath + (cleanParams.toString() ? '?' + cleanParams.toString() : '');
                _paq.push(['setCustomUrl', fullUrl]);
                _paq.push(['setDocumentTitle', document.title]);
                _paq.push(['trackPageView']);
              }, 300);
            }
          });
        });
      }
    }
  };

  // ---------------------------------------------------------------------------
  // slSearchBebeBb — remplace les raccourcis par leur forme complète
  // ---------------------------------------------------------------------------
  Drupal.behaviors.slSearchBebeBb = {
    attach(context) {
      const synonyms = {
        'bb': 'bébé'
      };

      const selectors = [
        'input[name="search_api_fulltext"]',
        'input[name="title"]'
      ].join(', ');

      once('sl-search-synonyms', selectors, context).forEach((input) => {
        const applySynonym = () => {
          const raw = input.value;
          // Découpe en conservant les espaces et la pononctuation pour ne pas tout casser
          const words = raw.split(/(\s+)/);
          const replaced = words.map((w) => {
            const key = w.trim().toLowerCase();
            return synonyms.hasOwnProperty(key) ? synonyms[key] : w;
          });
          const newValue = replaced.join('');

          if (newValue !== raw) {
            input.value = newValue;
            // IMPORTANT : Déclencher un événement input/change pour que Drupal 
            // et les scripts de recherche en direct prennent en compte la modification
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
          }
        };

        // Écoute à la fois le blur ET l'appui sur la touche Espace / Entrée
        input.addEventListener('blur', applySynonym);
        input.addEventListener('keydown', (e) => {
          if (e.key === ' ' || e.key === 'Enter') {
            applySynonym();
          }
        });

        const form = input.closest('form');
        if (form) {
          form.addEventListener('submit', applySynonym);
        }
      });
    }
  };

})(jQuery, Drupal, drupalSettings, once);