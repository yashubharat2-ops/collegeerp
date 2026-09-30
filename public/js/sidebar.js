// <details>/<summary> handles keyboard and pointer toggling without JavaScript.
// This enhancement only finds the current link and opens its parent on load.
(() => {
    const sections = document.querySelectorAll('aside nav details[data-sidebar-section]');
    const current = new URL(window.location.href);
    let best = null;
    let bestScore = 0;

    for (const section of sections) {
        for (const link of section.querySelectorAll('a.nav-link[href]')) {
            const target = new URL(link.href, current);
            // Laravel's APP_URL can differ from the browser host behind a proxy.
            const path = target.pathname.replace(/\/$/, '') || '/';
            const currentPath = current.pathname.replace(/\/$/, '') || '/';
            let score = 0;
            let identified = false;
            const matchesQuery = [...target.searchParams].every(([key, value]) => current.searchParams.get(key) === value);
            if (link.getAttribute('aria-current') === 'page') {
                score = 100000; // Keep existing server-rendered active links authoritative.
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

    if (best) {
        best.section.open = true;
        // A shared URL prefix or a mismatched filter can identify the section, but not a child.
        if (best.identified && !best.link.hasAttribute('aria-current')) {
            best.link.setAttribute('aria-current', 'page');
            best.link.classList.add('bg-white/10', 'text-white');
        }
    }
})();
