(function () {
    var body = document.body;
    var toggle = document.querySelector('[data-nav-toggle]');
    var scrim = document.querySelector('[data-nav-scrim]');
    var sidebar = document.getElementById('app-sidebar');
    var userMenu = document.querySelector('.user-menu');

    function setNav(open) {
        body.classList.toggle('nav-open', open);

        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            setNav(!body.classList.contains('nav-open'));
        });
    }

    if (scrim) {
        scrim.addEventListener('click', function () {
            setNav(false);
        });
    }

    if (sidebar) {
        sidebar.addEventListener('click', function (event) {
            if (event.target.closest('a') && window.matchMedia('(max-width: 1024px)').matches) {
                setNav(false);
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setNav(false);

            if (userMenu) {
                userMenu.open = false;
            }
        }
    });

    document.addEventListener('click', function (event) {
        if (userMenu && userMenu.open && !userMenu.contains(event.target)) {
            userMenu.open = false;
        }
    });
})();
