/* =============================================================================
 * College ERP — Reusable Bulk Selection & List UX Foundation
 * =============================================================================
 *
 * Progressive enhancement for listing pages:
 *  - "Select All" current page checkbox with indeterminate state
 *  - Individual row selection checkboxes
 *  - Dynamic selected counter
 *  - Smooth display/hide of bulk action bar (only visible when selections exist)
 *  - Clear selection trigger
 *  - Submitting bulk actions to backend contract endpoint
 */
(function () {
    'use strict';

    function initBulkSelection() {
        var bulkBars = document.querySelectorAll('[data-bulk-selection]');
        if (bulkBars.length === 0) {
            return;
        }

        bulkBars.forEach(function (bar) {
            if (bar.dataset.bulkInit === 'true') {
                return;
            }
            bar.dataset.bulkInit = 'true';

            // Find context table or container
            var container = bar.closest('.panel') || document.body;
            var selectAll = container.querySelector('[data-select-all]');
            var rowCheckboxes = Array.prototype.slice.call(container.querySelectorAll('[data-select-row]'));
            var countBadge = bar.querySelector('[data-selected-count]');
            var clearBtn = bar.querySelector('[data-bulk-clear]');

            function getSelectedIds() {
                return rowCheckboxes
                    .filter(function (cb) { return cb.checked; })
                    .map(function (cb) { return cb.value; });
            }

            function updateUI() {
                var selected = getSelectedIds();
                var totalRows = rowCheckboxes.length;
                var count = selected.length;

                if (countBadge) {
                    countBadge.textContent = count;
                }

                if (count > 0) {
                    bar.classList.remove('hidden');
                } else {
                    bar.classList.add('hidden');
                }

                if (selectAll) {
                    if (count === 0) {
                        selectAll.checked = false;
                        selectAll.indeterminate = false;
                    } else if (count === totalRows && totalRows > 0) {
                        selectAll.checked = true;
                        selectAll.indeterminate = false;
                    } else {
                        selectAll.checked = false;
                        selectAll.indeterminate = true;
                    }
                }
            }

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    var shouldCheck = selectAll.checked;
                    rowCheckboxes.forEach(function (cb) {
                        cb.checked = shouldCheck;
                    });
                    updateUI();
                });
            }

            rowCheckboxes.forEach(function (cb) {
                cb.addEventListener('change', function () {
                    updateUI();
                });
            });

            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    rowCheckboxes.forEach(function (cb) {
                        cb.checked = false;
                    });
                    if (selectAll) {
                        selectAll.checked = false;
                        selectAll.indeterminate = false;
                    }
                    updateUI();
                });
            }

            // Listen for bulk action triggers inside the bar
            var actionButtons = Array.prototype.slice.call(bar.querySelectorAll('[data-bulk-action]'));
            actionButtons.forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var action = btn.dataset.bulkAction;
                    var confirmMsg = btn.dataset.confirm;
                    var selected = getSelectedIds();

                    if (selected.length === 0) {
                        alert('Please select at least one record.');
                        return;
                    }

                    if (confirmMsg && !window.confirm(confirmMsg)) {
                        return;
                    }

                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = bar.dataset.endpoint || '/bulk-actions';

                    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
                    if (csrfMeta) {
                        var csrfInput = document.createElement('input');
                        csrfInput.type = 'hidden';
                        csrfInput.name = '_token';
                        csrfInput.value = csrfMeta.content;
                        form.appendChild(csrfInput);
                    }

                    var moduleInput = document.createElement('input');
                    moduleInput.type = 'hidden';
                    moduleInput.name = 'module';
                    moduleInput.value = bar.dataset.module;
                    form.appendChild(moduleInput);

                    var actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = action;
                    form.appendChild(actionInput);

                    selected.forEach(function (id) {
                        var idInput = document.createElement('input');
                        idInput.type = 'hidden';
                        idInput.name = 'ids[]';
                        idInput.value = id;
                        form.appendChild(idInput);
                    });

                    document.body.appendChild(form);
                    form.submit();
                });
            });

            // Initial state
            updateUI();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBulkSelection);
    } else {
        initBulkSelection();
    }
})();
