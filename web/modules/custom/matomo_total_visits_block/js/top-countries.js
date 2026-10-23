/**
 * @file
 * Track clicks on the Matomo Top countries collapsible title.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.matomoTopCountriesToggle = {
    attach(context) {
      once('matomo-top-countries-toggle', '.matomo-top-countries__details', context).forEach((details) => {
        details.addEventListener('toggle', function () {
          if (typeof _paq === 'undefined') {
            return;
          }
          const action = this.open ? 'Open' : 'Close';
          _paq.push([
            'trackEvent',
            'Top Countries',
            action,
            'Top pays visiteurs du site'
          ]);
        });
      });
    }
  };

})(Drupal, once);
