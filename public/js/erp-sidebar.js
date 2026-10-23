/* =============================================================================
 * College ERP — sidebar behaviour (progressive enhancement)
 * =============================================================================
 *
 * Served as a static asset from `public/` and linked by
 * resources/views/layouts/app.blade.php as `<script type="module">` — deferred by
 * the browser, which is when this file wants to run — so it needs no bundler: the sidebar
 * already renders in its correct state on the server (the group that owns the
 * current route is open, the current row is `aria-current="page"`), and this
 * file only adds the interaction on top:
 *
 *   1. collapsible module groups (click / Enter / Space, ARIA kept in sync),
 *   2. menu search, which filters the list and restores the server state,
 *   3. the desktop icon rail, remembered across reloads in localStorage,
 *   4. the mobile drawer (open, close, scrim, Escape, focus handling),
 *   5. tooltips for icon-only rows — rail mode only, never in the expanded
 *      sidebar, so an entry is never given two tooltips.
 *
 * It changes no routing and no permissions: every row it touches is a row the
 * server already decided to render.
 */
(function () {
    'use strict';

    var RAIL_KEY = 'collegeerp:sidebar:rail';
    // Gap between the rail's right edge and the tooltip, and the margin kept from the
    // viewport. The tooltip is anchored to the sidebar, not to the icon, so every row's
    // label starts on exactly the same x — a fixed column of labels, not a stagger.
    var TIP_GAP = 10;
    var TIP_EDGE = 8;
    var DESKTOP = '(min-width: 1024px)';

    var sidebar = document.getElementById('erp-sidebar');
    if (!sidebar || sidebar.dataset.navReady === 'true') {
        return;
    }

    var desktop = window.matchMedia(DESKTOP);
    var groups = Array.prototype.slice.call(sidebar.querySelectorAll('.nav-group'));
    var rows = Array.prototype.slice.call(sidebar.querySelectorAll('.nav-link, .nav-dashboard'));
    var heads = Array.prototype.slice.call(sidebar.querySelectorAll('.nav-group__head'));
    var list = document.getElementById('erp-nav-list');
    var scroll = sidebar.querySelector('.erp-nav__scroll');
    var nav = document.getElementById('erp-nav');
    var search = sidebar.querySelector('[data-nav-search]');
    var subLabels = Array.prototype.slice.call(sidebar.querySelectorAll('[data-nav-sub-label]'));
    var clear = sidebar.querySelector('[data-nav-search-clear]');
    var empty = sidebar.querySelector('[data-nav-empty]');
    var railButton = sidebar.querySelector('[data-nav-rail-toggle]');
    var drawerOpen = document.querySelector('[data-nav-drawer-open]');
    var drawerClose = sidebar.querySelector('[data-nav-drawer-close]');
    var scrim = document.querySelector('[data-nav-overlay]');

    // Remember the state the server rendered, so a cleared search restores the
    // open group of the current route instead of an arbitrary one.
    groups.forEach(function (group) {
        var head = group.querySelector('.nav-group__head');
        var items = group.querySelector('.nav-items');
        group.dataset.navServerOpen = head && head.getAttribute('aria-expanded') === 'true' ? 'true' : 'false';
        if (items) {
            items.dataset.navServerHidden = items.hasAttribute('hidden') ? 'true' : 'false';
        }
    });

    sidebar.dataset.navReady = 'true';

    function isRail() {
        return desktop.matches && sidebar.dataset.rail === 'true';
    }

    function itemsOf(group) {
        return group.querySelector('.nav-items');
    }

    function setOpen(group, open, closeSiblings) {
        // Nested groups are accordions within their own parent module only. Keeping
        // this in the shared state path covers clicks and ArrowRight without adding
        // another event handler; unrelated parent modules retain their own state.
        if (open && closeSiblings !== false && group.hasAttribute('data-nested-group') && group.parentElement) {
            Array.prototype.forEach.call(group.parentElement.children, function (sibling) {
                if (sibling !== group && sibling.hasAttribute('data-nested-group')) {
                    setOpen(sibling, false);
                }
            });
        }
        var head = group.querySelector('.nav-group__head');
        var items = itemsOf(group);
        group.setAttribute('data-nav-open', open ? 'true' : 'false');
        if (head) {
            head.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        if (items) {
            items.toggleAttribute('hidden', !open);
        }
    }

    /* 1 · Collapsible groups ------------------------------------------------ */

    heads.forEach(function (head) {
        head.addEventListener('click', function () {
            var group = head.closest('.nav-group');
            if (!group) {
                return;
            }
            // In the rail there is no room for children: expand the sidebar first,
            // then open the group the user actually clicked.
            if (isRail()) {
                setRail(false);
                setOpen(group, true);

                return;
            }
            setOpen(group, group.getAttribute('data-nav-open') !== 'true');
        });
    });

    /* 2 · Menu search ------------------------------------------------------- */

    function normalize(value) {
        return (value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function resetSearch() {
        if (nav) {
            nav.removeAttribute('data-nav-filtering');
            nav.removeAttribute('data-nav-empty');
        }
        groups.forEach(function (group) {
            group.removeAttribute('data-nav-match');
            setOpen(group, group.dataset.navServerOpen === 'true');
        });
        rows.forEach(function (row) {
            row.removeAttribute('data-nav-match');
        });
        subLabels.forEach(function (label) {
            label.removeAttribute('hidden');
        });
        if (list) {
            list.removeAttribute('hidden');
        }
        if (empty) {
            empty.setAttribute('hidden', '');
        }
        if (clear) {
            clear.setAttribute('hidden', '');
        }
    }

    function applySearch(term) {
        var query = normalize(term);
        if (query === '') {
            resetSearch();

            return;
        }
        var matches = 0;
        groups.forEach(function (group) {
            var label = normalize(group.querySelector('.nav-group__label')
                ? group.querySelector('.nav-group__label').textContent
                : '');
            var groupHit = label.indexOf(query) !== -1;
            var childHits = 0;
            Array.prototype.forEach.call(group.querySelectorAll('.nav-link'), function (row) {
                var hit = groupHit || normalize(row.textContent).indexOf(query) !== -1;
                row.setAttribute('data-nav-match', hit ? 'true' : 'false');
                if (hit) {
                    childHits += 1;
                }
            });
            var show = groupHit || childHits > 0;
            group.setAttribute('data-nav-match', show ? 'true' : 'false');
            if (show) {
                matches += 1;
                // Filtering is a temporary reveal-all view: hiding a matching result
                // behind the single-open accordion would make search incomplete.
                setOpen(group, true, false);
            }
        });
        var dashboard = sidebar.querySelector('.nav-dashboard');
        if (dashboard) {
            var dashboardHit = normalize(dashboard.textContent).indexOf(query) !== -1;
            dashboard.setAttribute('data-nav-match', dashboardHit ? 'true' : 'false');
            if (dashboardHit) {
                matches += 1;
            }
        }
        /* A sub-group label heads the rows that follow it, so while the list is
         * filtered it has to leave with them: a heading over nothing is worse than
         * no heading. Each label is kept only while at least one row between it and
         * the next label (or the end of its container) is still showing. */
        subLabels.forEach(function (label) {
            var keepsAHeading = false;
            var sibling = label.nextElementSibling;
            while (sibling && !sibling.hasAttribute('data-nav-sub-label')) {
                if (sibling.classList.contains('nav-link') && sibling.getAttribute('data-nav-match') !== 'false') {
                    keepsAHeading = true;
                    break;
                }
                sibling = sibling.nextElementSibling;
            }
            label.toggleAttribute('hidden', !keepsAHeading);
        });
        if (nav) {
            nav.setAttribute('data-nav-filtering', 'true');
            if (matches === 0) {
                nav.setAttribute('data-nav-empty', 'true');
            } else {
                nav.removeAttribute('data-nav-empty');
            }
        }
        if (empty) {
            empty.toggleAttribute('hidden', matches !== 0);
        }
        if (list) {
            list.toggleAttribute('hidden', matches === 0);
        }
        if (clear) {
            clear.toggleAttribute('hidden', false);
        }
    }

    if (search) {
        search.addEventListener('input', function () {
            applySearch(search.value);
        });
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                search.value = '';
                resetSearch();
                event.stopPropagation();
            }
        });
    }

    if (clear) {
        clear.addEventListener('click', function () {
            if (search) {
                search.value = '';
                search.focus();
            }
            resetSearch();
        });
    }

    /* 3 · Desktop icon rail ------------------------------------------------- */

    /* The rail state a visitor starts in. This browser's own choice (the toggle above)
     * wins; only when nothing has been clicked here yet does the account preference
     * `sidebar.rail_by_default`, rendered by the server, decide. */
    function preferredRail() {
        try {
            var stored = window.localStorage.getItem(RAIL_KEY);
            if (stored !== null) {
                return stored === 'true';
            }
        } catch (error) {
            /* Private mode / storage disabled: fall through to the account preference. */
        }
        return sidebar.dataset.railDefault === 'true';
    }

    function setRail(rail, persist) {
        sidebar.dataset.rail = rail ? 'true' : 'false';
        if (railButton) {
            railButton.setAttribute('aria-expanded', rail ? 'false' : 'true');
            railButton.setAttribute('aria-label', rail ? 'Expand sidebar' : 'Collapse sidebar');
        }
        // Re-syncing after a breakpoint crossing must not overwrite the visitor's own
        // choice, and must not freeze an account default into this browser's storage.
        if (persist === false) {
            hideTip();
            return;
        }
        try {
            if (rail) {
                window.localStorage.setItem(RAIL_KEY, 'true');
            } else {
                window.localStorage.setItem(RAIL_KEY, 'false');
            }
        } catch (error) {
            /* Private mode / storage disabled: the toggle still works for the visit. */
        }
        hideTip();
    }

    if (railButton) {
        railButton.addEventListener('click', function () {
            setRail(!isRail());
        });
    }

    if (desktop.matches && preferredRail()) {
        setRail(true, false);
    }

    /* 4 · Mobile drawer ----------------------------------------------------- */

    var lastFocused = null;

    function drawerOpenState(open) {
        var root = document.documentElement;
        if (open) {
            lastFocused = document.activeElement;
            root.setAttribute('data-nav-drawer', 'open');
            if (scrim) {
                scrim.removeAttribute('hidden');
            }
            document.body.classList.add('erp-nav-drawer-lock');
            var focusable = sidebar.querySelector('[data-nav-drawer-close]');
            if (focusable) {
                focusable.focus();
            }
        } else {
            root.removeAttribute('data-nav-drawer');
            if (scrim) {
                scrim.setAttribute('hidden', '');
            }
            document.body.classList.remove('erp-nav-drawer-lock');
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
            lastFocused = null;
        }
    }

    function isDrawerOpen() {
        return document.documentElement.getAttribute('data-nav-drawer') === 'open';
    }

    if (drawerOpen) {
        drawerOpen.addEventListener('click', function () {
            drawerOpenState(true);
        });
    }
    if (drawerClose) {
        drawerClose.addEventListener('click', function () {
            drawerOpenState(false);
        });
    }
    if (scrim) {
        scrim.addEventListener('click', function () {
            drawerOpenState(false);
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isDrawerOpen()) {
            drawerOpenState(false);
        }
    });

    // Crossing the breakpoint must never leave a scrim, a lock or a rail behind:
    // below 1024px the sidebar is a drawer, so the rail preference is only
    // applied while there is room for it (and restored when there is again).
    function syncViewport() {
        if (desktop.matches) {
            if (isDrawerOpen()) {
                drawerOpenState(false);
            }
            setRail(preferredRail(), false);
        } else {
            sidebar.dataset.rail = 'false';
        }
    }

    if (typeof desktop.addEventListener === 'function') {
        desktop.addEventListener('change', syncViewport);
    } else if (typeof desktop.addListener === 'function') {
        desktop.addListener(syncViewport);
    }

    /* 5 · Rail tooltips ----------------------------------------------------- */

    var tip = null;
    var tipTarget = null;

    function hideTip() {
        // The id this element advertised disappears with it, so the reference has to go
        // too; leaving a dangling aria-describedby on a hidden label is a bug screen
        // readers report as an empty description.
        if (tipTarget) {
            tipTarget.removeAttribute('aria-describedby');
            tipTarget = null;
        }
        if (tip) {
            tip.remove();
            tip = null;
        }
    }

    function labelOf(target) {
        var labelled = target.querySelector('.nav-group__label, .erp-nav-brand__name');
        if (labelled) {
            return labelled.textContent;
        }
        var spans = target.querySelectorAll('span');
        // The visible label is the row's last <span>: the leading one is the icon box.
        return spans.length ? spans[spans.length - 1].textContent : '';
    }

    function showTip(target) {
        // Rail mode only, and only where the rail exists: below the desktop breakpoint
        // the sidebar is a drawer that shows its labels, so a tooltip would repeat them.
        if (!isRail() || !desktop.matches) {
            return;
        }
        var text = (labelOf(target) || '').trim();
        if (!text) {
            return;
        }
        hideTip();
        tip = document.createElement('div');
        tip.className = 'erp-nav-tip';
        tip.setAttribute('role', 'tooltip');
        tip.setAttribute('id', 'erp-nav-tip');
        tip.textContent = text;
        // On <body> on purpose, so no scroll container or overflow rule can clip it; see
        // the note in public/css/erp-sidebar.css about why its colours are literals.
        document.body.appendChild(tip);
        var box = target.getBoundingClientRect();
        var rail = sidebar.getBoundingClientRect();
        var tipBox = tip.getBoundingClientRect();
        var top = box.top + box.height / 2 - tipBox.height / 2;
        var left = rail.right + TIP_GAP;
        // A label is never allowed to hang off the edge of the window: it shrinks its
        // offset until it fits, and the pill wraps instead of being cut.
        if (left + tipBox.width > window.innerWidth - TIP_EDGE) {
            left = Math.max(TIP_EDGE, window.innerWidth - tipBox.width - TIP_EDGE);
        }
        tip.style.top = Math.max(TIP_EDGE, Math.min(top, window.innerHeight - tipBox.height - TIP_EDGE)) + 'px';
        tip.style.left = left + 'px';
        target.setAttribute('aria-describedby', 'erp-nav-tip');
        tipTarget = target;
    }

    heads.concat([sidebar.querySelector('.nav-dashboard')])
        .filter(Boolean)
        .forEach(function (target) {
            target.addEventListener('mouseenter', function () {
                showTip(target);
            });
            target.addEventListener('mouseleave', hideTip);
            target.addEventListener('focus', function () {
                showTip(target);
            });
            target.addEventListener('blur', hideTip);
        });

    if (scroll) {
        scroll.addEventListener('scroll', hideTip, { passive: true });
    }

    /* 6 · Keyboard traversal ------------------------------------------------- */

    /* The rows are ordinary tab stops, which is correct but slow in a list this
     * long: reaching "Timetable" from the search field is ~130 Tab presses. Arrow
     * keys move focus between rows the way a tree widget does, while Tab keeps
     * working exactly as it did — focus is never trapped and no row is taken out of
     * the tab order.
     *
     *   ArrowDown / ArrowUp   next / previous visible row (group heads included)
     *   Home / End            first / last visible row
     *   ArrowRight            open the group the head owns
     *   ArrowLeft             close it
     *
     * "Visible" means what the user can actually see: a row in a closed group, a
     * row filtered out by the menu search, and a row hidden inside the rail all stay
     * out of the sequence. */
    function rowsInOrder() {
        return heads.concat([sidebar.querySelector('.nav-dashboard')])
            .filter(Boolean)
            .sort(function (one, two) {
                return one.compareDocumentPosition(two) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
            });
    }

    function isReachable(row) {
        if (row.hasAttribute('hidden') || row.getAttribute('data-nav-match') === 'false') {
            return false;
        }
        var group = row.closest('.nav-group');
        if (group && (group.getAttribute('data-nav-open') !== 'true' || group.getAttribute('data-nav-match') === 'false')) {
            return false;
        }

        return row.offsetParent !== null;
    }

    sidebar.addEventListener('keydown', function (event) {
        if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) {
            return;
        }
        var head = event.target.closest ? event.target.closest('.nav-group__head') : null;
        var row = event.target.closest ? event.target.closest('.nav-link, .nav-dashboard, .nav-group__head') : null;
        if (!row) {
            return;
        }
        var group = row.closest('.nav-group');
        var sequence = rowsInOrder().filter(isReachable);
        var index = sequence.indexOf(row);

        if (event.key === 'ArrowRight' && head && group) {
            event.preventDefault();
            setOpen(group, true);

            return;
        }
        if (event.key === 'ArrowLeft' && head && group) {
            event.preventDefault();
            setOpen(group, false);

            return;
        }
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp' && event.key !== 'Home' && event.key !== 'End') {
            return;
        }
        if (index === -1) {
            return;
        }

        event.preventDefault();
        var target = row;
        if (event.key === 'ArrowDown') {
            target = sequence[Math.min(index + 1, sequence.length - 1)];
        } else if (event.key === 'ArrowUp') {
            target = sequence[Math.max(index - 1, 0)];
        } else if (event.key === 'Home') {
            target = sequence[0];
        } else {
            target = sequence[sequence.length - 1];
        }
        if (target && target !== row) {
            hideTip();
            target.focus();
        }
    });
})();
