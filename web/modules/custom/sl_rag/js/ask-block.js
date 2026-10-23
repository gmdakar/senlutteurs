/**
 * @file
 * Formulaire SL RAG : spinner AJAX, vidage de la réponse précédente
 * et traçage Matomo (clic + question), même convention que sl_theme (_paq).
 */
(function (Drupal, once) {
  'use strict';

  var TRACK_DELAY_MS = 800;
  var QUESTION_MAX_LENGTH = 200;
  var lastTrackAt = 0;

  /**
   * Vide la zone de réponse précédente.
   */
  function clearResult(form) {
    var result = form.querySelector('#sl-rag-ask-result');
    if (!result) {
      var wrapper = form.closest('#sl-rag-ask-form-wrapper') || form.parentElement;
      if (wrapper) {
        result = wrapper.querySelector('#sl-rag-ask-result');
      }
    }
    if (!result) {
      result = document.getElementById('sl-rag-ask-result');
    }
    if (result) {
      result.innerHTML = '';
      result.classList.add('sl-rag-ask-result--empty');
      result.classList.remove('sl-rag-ask-result--has-answer');
    }
  }

  function showLoading(form) {
    clearResult(form);
    var overlay = form.querySelector('.sl-rag-ask-loading');
    if (overlay) {
      overlay.removeAttribute('hidden');
      overlay.setAttribute('aria-busy', 'true');
    }
    form.classList.add('sl-rag-ask-form--loading');
  }

  function hideLoading(form) {
    var overlay = form.querySelector('.sl-rag-ask-loading');
    if (overlay) {
      overlay.setAttribute('hidden', 'hidden');
      overlay.setAttribute('aria-busy', 'false');
    }
    form.classList.remove('sl-rag-ask-form--loading');
    var submit = form.querySelector('.sl-rag-ask-submit');
    if (submit) {
      submit.disabled = false;
    }
  }

  /**
   * Trace dans Matomo le clic sur « Poser la question » et la question posée.
   * Anti-doublon : mousedown (déclencheur AJAX Drupal), click et clavier
   * peuvent se succéder pour une même action.
   */
  function trackAsk(form) {
    if (typeof _paq === 'undefined') {
      return;
    }
    var now = Date.now();
    if (now - lastTrackAt < TRACK_DELAY_MS) {
      return;
    }
    lastTrackAt = now;

    var field = form.querySelector('.sl-rag-ask-question');
    var question = field ? String(field.value || '').trim() : '';

    _paq.push(['trackEvent', 'RAG', 'Click Poser la question', question ? 'Avec question' : 'Question vide']);

    if (question) {
      _paq.push(['trackEvent', 'RAG', 'Question posée', question.substring(0, QUESTION_MAX_LENGTH)]);
    }
  }

  function onAsk(form) {
    trackAsk(form);
    showLoading(form);
  }

  Drupal.behaviors.slRagAskLoading = {
    attach: function (context) {
      once('sl-rag-ask-loading', '.sl-rag-ask-form', context).forEach(function (form) {
        var submit = form.querySelector('.sl-rag-ask-submit');

        if (submit) {
          // Drupal.ajax déclenche l'appel sur « mousedown » : on passe avant
          // (phase de capture) pour vider l'ancienne réponse immédiatement.
          ['mousedown', 'click'].forEach(function (evt) {
            submit.addEventListener(evt, function () {
              onAsk(form);
            }, true);
          });
          submit.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
              onAsk(form);
            }
          }, true);
        }

        // Fallback submit classique (sans AJAX).
        form.addEventListener('submit', function () {
          onAsk(form);
        });
      });

      // Fin d'appel AJAX (succès ou erreur) : retire l'overlay si le formulaire
      // n'a pas été remplacé (ex. erreur réseau).
      once('sl-rag-ask-ajax-end', 'html', context).forEach(function () {
        if (window.jQuery) {
          window.jQuery(document).on('ajaxComplete.slRag ajaxError.slRag', function () {
            document.querySelectorAll('.sl-rag-ask-form--loading').forEach(hideLoading);
          });
        }
      });

      // Après remplacement AJAX du wrapper, scroller vers le résultat.
      once('sl-rag-ask-result-scroll', '#sl-rag-ask-result:not(.sl-rag-ask-result--empty)', context).forEach(function (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      });
    }
  };
})(Drupal, once);
