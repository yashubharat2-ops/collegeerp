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

    function displayDateToIso(value) {
        var trimmed = value.trim();
        if (trimmed === '') {
            return '';
        }

        var parts = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(trimmed);
        if (!parts) {
            return null;
        }

        var day = Number(parts[1]);
        var month = Number(parts[2]);
        var year = Number(parts[3]);
        if (year < 1 || month < 1 || month > 12) {
            return null;
        }

        var leapYear = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
        var daysInMonth = [31, leapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        if (day < 1 || day > daysInMonth[month - 1]) {
            return null;
        }

        return String(year).padStart(4, '0') + '-'
            + String(month).padStart(2, '0') + '-'
            + String(day).padStart(2, '0');
    }

    function syncDateDisplay(field) {
        var targetId = field.getAttribute('data-list-date-target');
        var queryValue = targetId ? document.getElementById(targetId) : null;
        if (!queryValue) {
            return true;
        }

        var isoDate = displayDateToIso(field.value);
        if (isoDate === null) {
            queryValue.value = '';
            field.setCustomValidity('Enter a valid date using DD/MM/YYYY.');
            field.setAttribute('aria-invalid', 'true');
            return false;
        }

        queryValue.value = isoDate;
        field.setCustomValidity('');
        field.removeAttribute('aria-invalid');
        return true;
    }

    function initDateRangeFilters() {
        var ranges = document.querySelectorAll('[data-list-date-range]');
        if (ranges.length === 0) {
            return;
        }

        ranges.forEach(function (range) {
            var displayFields = Array.prototype.slice.call(range.querySelectorAll('[data-list-date-display]'));
            displayFields.forEach(function (field) {
                if (field.dataset.listDateInit === 'true') {
                    return;
                }
                field.dataset.listDateInit = 'true';
                syncDateDisplay(field);
                field.addEventListener('input', function () { syncDateDisplay(field); });
                field.addEventListener('change', function () { syncDateDisplay(field); });
            });

            var form = range.closest('form');
            if (!form || form.dataset.listDateRangeBound === 'true') {
                return;
            }
            form.dataset.listDateRangeBound = 'true';
            form.addEventListener('submit', function (event) {
                var allValid = true;
                Array.prototype.slice.call(form.querySelectorAll('[data-list-date-display]')).forEach(function (field) {
                    if (!syncDateDisplay(field)) {
                        allValid = false;
                    }
                });

                if (!allValid) {
                    event.preventDefault();
                    var firstInvalid = form.querySelector('[data-list-date-display][aria-invalid="true"]');
                    if (firstInvalid && typeof firstInvalid.reportValidity === 'function') {
                        firstInvalid.reportValidity();
                    }
                }
            });
        });
    }

    function initListPage() {
        initBulkSelection();
        initDateRangeFilters();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initListPage);
    } else {
        initListPage();
    }
})();
