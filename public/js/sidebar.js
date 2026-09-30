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

        // Do not merely open the active section: browsers can restore an old
        // open attribute on any other section, even after a full page refresh.
        for (const section of sections) section.open = section === best?.section;

        if (best?.identified && !best.link.hasAttribute('aria-current')) {
            best.link.setAttribute('aria-current', 'page');
            best.link.classList.add('bg-white/10', 'text-white');
        }
    }

    openCurrentSection();
    window.addEventListener('pageshow', openCurrentSection);

    const sidebar = document.getElementById('app-sidebar');
    const toggle = document.querySelector('[data-sidebar-toggle]');
    if (!sidebar || !toggle) return;

    function setSidebarVisible(visible) {
        sidebar.hidden = !visible;
        toggle.setAttribute('aria-expanded', String(visible));
        const label = visible ? 'Hide sidebar' : 'Show sidebar';
        toggle.setAttribute('aria-label', label);
        toggle.title = `${label} (Alt+Ctrl+Z)`;
        toggle.querySelector('[data-sidebar-toggle-label]').textContent = label;
    }

    toggle.addEventListener('click', () => setSidebarVisible(sidebar.hidden));
    window.addEventListener('keydown', (event) => {
        if (event.altKey && event.ctrlKey && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            setSidebarVisible(sidebar.hidden);
            toggle.focus();
        }
    });
})();
