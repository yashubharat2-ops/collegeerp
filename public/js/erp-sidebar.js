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

    function setOpen(group, open) {
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
                setOpen(group, true);
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

    function setRail(rail) {
        sidebar.dataset.rail = rail ? 'true' : 'false';
        if (railButton) {
            railButton.setAttribute('aria-expanded', rail ? 'false' : 'true');
            railButton.setAttribute('aria-label', rail ? 'Expand sidebar' : 'Collapse sidebar');
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

    try {
        if (desktop.matches && window.localStorage.getItem(RAIL_KEY) === 'true') {
            setRail(true);
        }
    } catch (error) {
        /* No storage: stay expanded. */
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
            try {
                setRail(window.localStorage.getItem(RAIL_KEY) === 'true');
            } catch (error) {
                setRail(false);
            }
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

    function hideTip() {
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
        if (!isRail()) {
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
        document.body.appendChild(tip);
        var box = target.getBoundingClientRect();
        var tipBox = tip.getBoundingClientRect();
        var top = box.top + box.height / 2 - tipBox.height / 2;
        tip.style.top = Math.max(8, Math.min(top, window.innerHeight - tipBox.height - 8)) + 'px';
        tip.style.left = (box.right + 10) + 'px';
        target.setAttribute('aria-describedby', 'erp-nav-tip');
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
})();
