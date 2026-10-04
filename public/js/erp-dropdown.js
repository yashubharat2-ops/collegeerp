/* =============================================================================
 * College ERP — Reusable Dropdown Menu Foundation
 * =============================================================================
 *
 * Progressive enhancement for list toolbars (the Students list uses it for the
 * page-level and bulk-bar Export menus). The markup comes from
 * resources/views/components/list/dropdown.blade.php, and this file only toggles
 * it:
 *
 *  - the trigger opens / closes its own panel and keeps aria-expanded honest,
 *  - opening one dropdown closes any other one on the page,
 *  - a click outside, or Escape, closes the open one,
 *  - menu items are NEVER intercepted: an <a> keeps its navigation and a
 *    [data-bulk-action] button keeps its own handler (public/js/erp-list.js), so
 *    a dropdown inside the bulk selection bar cannot swallow a bulk action.
 *
 * The panel stays in the DOM while hidden, which is what lets the bulk script
 * bind its items before the menu has ever been opened. Nothing here is required
 * for the actions to work server-side: hiding a menu item is presentation, while
 * every endpoint re-checks permission, tenant scope and the record policy.
 */
(function () {
    'use strict';

    function initDropdowns() {
        var dropdowns = Array.prototype.slice.call(document.querySelectorAll('[data-dropdown]'));
        if (dropdowns.length === 0) {
            return;
        }

        function close(dd) {
            var panel = dd.querySelector('[data-dropdown-menu]');
            var trigger = dd.querySelector('[data-dropdown-trigger]');
            if (panel) {
                panel.classList.add('hidden');
            }
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'false');
            }
        }

        function closeAll(except) {
            dropdowns.forEach(function (dd) {
                if (dd !== except) {
                    close(dd);
                }
            });
        }

        dropdowns.forEach(function (dd) {
            if (dd.dataset.dropdownInit === 'true') {
                return;
            }
            dd.dataset.dropdownInit = 'true';

            var trigger = dd.querySelector('[data-dropdown-trigger]');
            var panel = dd.querySelector('[data-dropdown-menu]');
            if (!trigger || !panel) {
                return;
            }

            trigger.addEventListener('click', function () {
                var willOpen = panel.classList.contains('hidden');
                closeAll(dd);
                panel.classList.toggle('hidden', !willOpen);
                trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });

            // An item that runs an action (or follows a link) closes the menu.
            // Its own click behaviour — navigation, or the bulk POST — is left
            // strictly alone.
            panel.addEventListener('click', function () {
                close(dd);
            });
        });

        document.addEventListener('click', function (event) {
            var inside = dropdowns.some(function (dd) {
                return dd.contains(event.target);
            });
            if (!inside) {
                closeAll(null);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAll(null);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDropdowns);
    } else {
        initDropdowns();
    }
})();
