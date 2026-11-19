/**
 * Campus Library - small progressive-enhancement script.
 *
 * All navigation and destructive actions still work without JavaScript;
 * the script only improves the experience.
 */
(function () {
    'use strict';

    var body = document.body;

    // Mobile sidebar toggle.
    var navToggle = document.querySelector('[data-nav-toggle]');
    if (navToggle) {
        navToggle.addEventListener('click', function () {
            var open = body.classList.toggle('nav-open');
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && body.classList.contains('nav-open')) {
                body.classList.remove('nav-open');
                navToggle.setAttribute('aria-expanded', 'false');
                navToggle.focus();
            }
        });

        body.addEventListener('click', function (event) {
            if (!body.classList.contains('nav-open')) {
                return;
            }

            var sidebar = document.getElementById('primary-sidebar');
            if (sidebar && !sidebar.contains(event.target) && !navToggle.contains(event.target)) {
                body.classList.remove('nav-open');
                navToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // Dismiss flash messages.
    document.querySelectorAll('[data-dismiss-alert]').forEach(function (button) {
        button.addEventListener('click', function () {
            var alert = button.closest('.alert');
            if (alert) {
                alert.remove();
            }
        });
    });

    // Confirmation prompt for destructive submissions.
    document.querySelectorAll('[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var message = form.getAttribute('data-confirm') || 'Are you sure?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    // Show or hide password fields.
    document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
        button.addEventListener('click', function () {
            var target = document.getElementById(button.getAttribute('data-toggle-password'));
            if (!target) {
                return;
            }

            var showing = target.type === 'text';
            target.type = showing ? 'password' : 'text';
            button.textContent = showing ? 'Show' : 'Hide';
            button.setAttribute('aria-pressed', showing ? 'false' : 'true');
        });
    });
})();
