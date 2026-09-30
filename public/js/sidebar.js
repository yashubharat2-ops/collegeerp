// Native <details>/<summary> provides keyboard and pointer toggling for each module.
// Reconcile browser-restored DOM state on load/pageshow: only the current route's
// parent may be open automatically (Firefox can restore unrelated <details open>).
(() => {
    function openCurrentSection() {
        const sections = document.querySelectorAll('aside nav details[data-sidebar-section]');
        const current = new URL(window.location.href);
        const currentPath = current.pathname.replace(/\/$/, '') || '/';
        let best = null;
        let bestScore = 0;

        for (const section of sections) {
            for (const link of section.querySelectorAll('a.nav-link[href]')) {
                const target = new URL(link.href, current);
                // APP_URL may differ from the browser host behind a proxy.
                const path = target.pathname.replace(/\/$/, '') || '/';
                const matchesQuery = [...target.searchParams].every(([key, value]) => current.searchParams.get(key) === value);
                let score = 0;
                let identified = false;

                if (link.getAttribute('aria-current') === 'page') {
                    score = 100000; // Respect existing server-rendered active links.
                    identified = true;
                } else if (currentPath === path) {
                    identified = matchesQuery;
                    score = (matchesQuery ? 20000 + target.searchParams.size * 100 : 10) + path.length;
                } else if (path !== '/' && currentPath.startsWith(path + '/')) {
                    identified = matchesQuery;
                    score = 1000 + path.length; // Detail/edit pages under an index URL.
                } else if (currentPath.split('/')[1] && currentPath.split('/')[1] === path.split('/')[1]) {
                    score = 1; // E.g. /certificates/123 from /certificates/requests.
                }

                if (score > bestScore) {
                    bestScore = score;
                    best = { section, link, identified };
                }
            }
        }

        // Browsers can restore an old open attribute even after a full refresh.
        for (const section of sections) {
            section.open = section === best?.section;
            if (section === best?.section) section.setAttribute('data-current-section', '');
            else section.removeAttribute('data-current-section');
        }

        if (best?.identified && !best.link.hasAttribute('aria-current')) {
            best.link.setAttribute('aria-current', 'page');
            best.link.classList.add('bg-indigo-600', 'text-white');
        }
    }

    const sidebar = document.getElementById('app-sidebar');
    const toggles = document.querySelectorAll('[data-sidebar-toggle]');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const desktop = window.matchMedia('(min-width: 1024px)');
    let collapsed = false;
    let mobileOpen = false;

    function syncSidebar() {
        if (!sidebar) return;
        const isMobile = !desktop.matches;
        // Older versions used [hidden] for the whole sidebar; do not restore it.
        sidebar.hidden = false;
        sidebar.classList.toggle('is-collapsed', !isMobile && collapsed);
        sidebar.classList.toggle('is-mobile-open', isMobile && mobileOpen);
        sidebar.inert = isMobile && !mobileOpen;
        if (backdrop) backdrop.hidden = !isMobile || !mobileOpen;
        document.body.classList.toggle('sidebar-drawer-open', isMobile && mobileOpen);

        for (const toggle of toggles) {
            const expanded = isMobile ? mobileOpen : !collapsed;
            const label = isMobile
                ? (mobileOpen ? 'Hide sidebar' : 'Show sidebar')
                : (collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            toggle.setAttribute('aria-expanded', String(expanded));
            toggle.setAttribute('aria-label', label);
            toggle.title = `${label} (Alt+Ctrl+Z)`;
            const text = toggle.querySelector('[data-sidebar-toggle-label]');
            if (text) text.textContent = label;
        }
    }

    function toggleSidebar() {
        if (desktop.matches) collapsed = !collapsed;
        else mobileOpen = !mobileOpen;
        syncSidebar();
    }

    openCurrentSection();
    syncSidebar();
    window.addEventListener('pageshow', () => {
        openCurrentSection();
        mobileOpen = false;
        syncSidebar();
    });
    desktop.addEventListener('change', syncSidebar);
    for (const toggle of toggles) toggle.addEventListener('click', () => {
        toggleSidebar();
        // The brand control disappears in icon-only mode; keep keyboard focus visible.
        if (desktop.matches && collapsed && toggle.hasAttribute('data-sidebar-header-toggle')) {
            document.querySelector('header [data-sidebar-toggle]')?.focus();
        }
    });
    if (backdrop) backdrop.addEventListener('click', () => { mobileOpen = false; syncSidebar(); });

    sidebar?.addEventListener('click', (event) => {
        const summary = event.target.closest('summary');
        if (!summary || !desktop.matches || !collapsed) return;
        // In icon-only mode, clicking a module first reveals its actual links.
        event.preventDefault();
        collapsed = false;
        syncSidebar();
        summary.parentElement.open = true;
    });

    window.addEventListener('keydown', (event) => {
        if (event.altKey && event.ctrlKey && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            toggleSidebar();
            toggles[0]?.focus();
        } else if (event.key === 'Escape' && mobileOpen) {
            mobileOpen = false;
            syncSidebar();
            toggles[0]?.focus();
        }
    });
})();
