/*
 * Early application script. Adds a `js` class to <html> so CSS can distinguish
 * JavaScript-enabled pages from no-JavaScript fallbacks.
 */
(function () {
    'use strict';
    if (typeof document.documentElement !== 'undefined') {
        document.documentElement.classList.add('js');
    }
})();
