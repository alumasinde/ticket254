(function () {
    'use strict';

    var toggles = document.querySelectorAll('[data-password-toggle]');

    Array.prototype.forEach.call(toggles, function (toggle) {
        var input = document.getElementById(toggle.getAttribute('data-password-toggle'));
        if (!input) {
            return;
        }

        var field = toggle.parentNode;

        function setVisible(visible) {
            input.type = visible ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', visible ? 'true' : 'false');
            toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
            toggle.setAttribute('title', visible ? 'Hide password' : 'Show password');
            field.classList.toggle('is-revealed', visible);
            input.focus({ preventScroll: true });
            var end = input.value.length;
            try {
                input.setSelectionRange(end, end);
            } catch (error) {
                /* Some input types do not support selection; focus is enough. */
            }
        }

        toggle.addEventListener('click', function () {
            setVisible(input.type === 'password');
        });
    });

    var capped = document.querySelectorAll('input[data-caps-warning]');

    Array.prototype.forEach.call(capped, function (input) {
        var warning = document.getElementById(input.getAttribute('data-caps-warning'));
        if (!warning) {
            return;
        }

        function update(event) {
            if (typeof event.getModifierState !== 'function') {
                return;
            }
            warning.hidden = !event.getModifierState('CapsLock');
        }

        input.addEventListener('keydown', update);
        input.addEventListener('keyup', update);
        input.addEventListener('blur', function () {
            warning.hidden = true;
        });
    });
})();
