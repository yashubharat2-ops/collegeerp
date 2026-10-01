/* =============================================================================
 * College ERP — signed-in user panel behaviour (progressive enhancement)
 * =============================================================================
 *
 * The panel in resources/views/components/user/menu.blade.php is a native
 * <details>/<summary> disclosure, so it already opens and closes without any
 * script. This file adds only what a disclosure cannot do on its own:
 *
 *   1. close when the pointer goes down outside the component,
 *   2. Escape closes and hands focus back to the trigger,
 *   3. ArrowDown/ArrowUp/Home/End move through the enabled rows (the disabled
 *      "this build has no such screen yet" rows are skipped, never focused as if
 *      they worked), Tab away closes,
 *   4. clicking a row that navigates closes the panel, so a back navigation or
 *      an interrupted click never leaves a stale menu open.
 *
 * It touches no route, no permission and no logout behaviour: the logout row is
 * the same POST form to `route('logout')` the header used before.
 *
 * Linked by resources/views/layouts/app.blade.php as a module script (deferred
 * by the browser), served straight from `public/` so it works with no build.
 */
(function () {
    'use strict';

    var menus = Array.prototype.slice.call(document.querySelectorAll('[data-user-menu]'));

    if (menus.length === 0) {
        return;
    }

    function triggerOf(menu) {
        return menu.querySelector('[data-user-menu-trigger]');
    }

    function rowsOf(menu) {
        return Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]'))
            .filter(function (row) {
                return row.getAttribute('aria-disabled') !== 'true';
            });
    }

    function close(menu, returnFocus) {
        if (!menu.open) {
            return;
        }
        menu.open = false;
        if (returnFocus) {
            var trigger = triggerOf(menu);
            if (trigger) {
                trigger.focus({ preventScroll: true });
            }
        }
    }

    function focusRow(menu, index) {
        var list = rowsOf(menu);
        if (list.length === 0) {
            return;
        }
        list[(index + list.length) % list.length].focus({ preventScroll: true });
    }

    menus.forEach(function (menu) {
        menu.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                // Works whether focus sits on the summary or inside the panel.
                if (menu.open) {
                    event.preventDefault();
                    close(menu, true);
                }
                return;
            }

            var list = rowsOf(menu);
            if (list.length === 0) {
                return;
            }
            var current = list.indexOf(document.activeElement);

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (!menu.open) {
                    menu.open = true;
                }
                if (current < 0) {
                    focusRow(menu, event.key === 'ArrowDown' ? 0 : list.length - 1);
                    return;
                }
                focusRow(menu, current + (event.key === 'ArrowDown' ? 1 : -1));
                return;
            }

            if (current < 0) {
                return;
            }

            if (event.key === 'Home') {
                event.preventDefault();
                focusRow(menu, 0);
            } else if (event.key === 'End') {
                event.preventDefault();
                focusRow(menu, list.length - 1);
            } else if (event.key === 'Tab') {
                // Tabbing out of the panel closes it; the focus keeps moving.
                close(menu, false);
            }
        });

        // A row that navigates should never leave the menu open behind it.
        menu.addEventListener('click', function (event) {
            var row = event.target && event.target.closest ? event.target.closest('.erp-user-menu__item') : null;
            if (row && row.hasAttribute('href')) {
                menu.open = false;
            }
        });

        var logout = menu.querySelector('[data-user-menu-logout]');
        if (logout) {
            logout.addEventListener('submit', function () {
                menu.open = false;
            });
        }
    });

    document.addEventListener('pointerdown', function (event) {
        menus.forEach(function (menu) {
            if (menu.open && !menu.contains(event.target)) {
                menu.open = false;
            }
        });
    }, true);
})();
