/**
 * Quissly for PrestaShop - Configuration's sections open and close (each a <details>, so they
 * work without this script too); the page remembers which ones the merchant left open, as the
 * WooCommerce plugin's Configuration does. Features starts open, the rest closed.
 */
(function () {
    'use strict';

    var KEY = 'quissly_configuration_sections';

    function boot() {
        var open = {};
        try {
            open = JSON.parse(window.localStorage.getItem(KEY) || '{}') || {};
        } catch (e) {
            open = {};
        }
        Array.prototype.forEach.call(document.querySelectorAll('.q-config details[data-q-section]'), function (section) {
            var key = section.getAttribute('data-q-section');
            if (Object.prototype.hasOwnProperty.call(open, key)) {
                section.open = !!open[key];
            }
            if (window.location.hash && section.id && window.location.hash === '#' + section.id) {
                section.open = true;
            }
            section.addEventListener('toggle', function () {
                open[key] = section.open;
                try {
                    window.localStorage.setItem(KEY, JSON.stringify(open));
                } catch (e) {
                    return;
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
