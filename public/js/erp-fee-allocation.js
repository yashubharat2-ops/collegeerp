// Progressive enhancement only. The server never trusts these checkbox states:
// preview and confirmation both re-query the selected/all-filtered scope.
const root = document.querySelector('[data-fee-allocation]');
if (root) {
    const structure = root.querySelector('[data-allocation-structure]');
    const groups = [...root.querySelectorAll('[data-allocation-heads]')];
    const syncHeads = () => {
        for (const group of groups) {
            const selected = structure.value !== '' && group.dataset.allocationHeads === structure.value;
            group.hidden = structure.value !== '' && !selected;
            for (const checkbox of group.querySelectorAll('[data-allocation-head]')) {
                checkbox.disabled = structure.value !== '' && !selected;
                if (!selected) checkbox.checked = false;
            }
        }
    };
    structure?.addEventListener('change', syncHeads);
    syncHeads();

    const page = root.querySelector('[data-allocation-select-page]');
    const rows = [...root.querySelectorAll('[data-allocation-row]')];
    const syncPage = () => {
        if (!page) return;
        const checked = rows.filter((row) => row.checked).length;
        page.checked = rows.length > 0 && checked === rows.length;
        page.indeterminate = checked > 0 && checked < rows.length;
    };
    page?.addEventListener('change', () => {
        for (const row of rows) row.checked = page.checked;
        syncPage();
    });
    for (const row of rows) row.addEventListener('change', syncPage);
    syncPage();

    for (const scope of root.querySelectorAll('[data-allocation-scope]')) {
        scope.addEventListener('change', () => {
            const allFiltered = root.querySelector('[data-allocation-scope][value="all_filtered"]')?.checked;
            if (page) page.disabled = Boolean(allFiltered);
            // Keep row values intact so switching back does not destroy a user's
            // page selection; the server ignores them in all-filtered mode.
        });
    }
}
