(function (Drupal, drupalSettings, once) {
  Drupal.behaviors.athleteCptCounter = {
    attach: function (context) {
      once('athleteCptCounter', 'html', context).forEach(function () {
        const nid = drupalSettings.athlete_cpt_counter?.nid;
        if (nid) {
          fetch('/athlete/increment-cpt/' + nid, { 
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          })
          .then(response => response.json())
          .then(data => {
             console.log('Success:', data);
          })
          .catch(error => {});
        }
      });
    }
  };
})(Drupal, drupalSettings, once);