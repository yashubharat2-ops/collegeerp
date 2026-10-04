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

    var activeDatePicker = null;
    var datePickerDocumentEventsBound = false;
    var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var weekdayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    function daysInMonth(year, monthIndex) {
        var leapYear = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
        var monthDays = [31, leapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        return monthDays[monthIndex];
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
        if (year < 1 || month < 1 || month > 12 || day < 1 || day > daysInMonth(year, month - 1)) {
            return null;
        }

        return String(year).padStart(4, '0') + '-'
            + String(month).padStart(2, '0') + '-'
            + String(day).padStart(2, '0');
    }

    function isoDateToParts(value) {
        if (typeof value !== 'string') {
            return null;
        }

        var parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
        if (!parts) {
            return null;
        }

        var year = Number(parts[1]);
        var month = Number(parts[2]);
        var day = Number(parts[3]);
        if (year < 1 || month < 1 || month > 12 || day < 1 || day > daysInMonth(year, month - 1)) {
            return null;
        }

        return { year: year, month: month - 1, day: day };
    }

    function isoDateToDisplay(value) {
        var parts = isoDateToParts(value);
        if (!parts) {
            return '';
        }

        return String(parts.day).padStart(2, '0') + '/'
            + String(parts.month + 1).padStart(2, '0') + '/'
            + String(parts.year).padStart(4, '0');
    }

    function makeUtcDate(year, monthIndex, day) {
        var date = new Date(0);
        date.setUTCHours(0, 0, 0, 0);
        date.setUTCFullYear(year, monthIndex, day);
        return date;
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

    function setPickerExpanded(picker, expanded) {
        var value = expanded ? 'true' : 'false';
        picker.input.setAttribute('aria-expanded', value);
        picker.toggle.setAttribute('aria-expanded', value);
        picker.toggle.setAttribute('aria-label', (expanded ? 'Close ' : 'Open ')
            + picker.label.toLowerCase() + ' date calendar');
    }

    function focusPickerDay(picker) {
        var isoDate = String(picker.state.year).padStart(4, '0') + '-'
            + String(picker.state.month + 1).padStart(2, '0') + '-'
            + String(picker.state.focusDay).padStart(2, '0');
        var dayButtons = picker.days.querySelectorAll('[data-date-picker-day]');

        for (var i = 0; i < dayButtons.length; i++) {
            if (dayButtons[i].getAttribute('data-date-picker-day') === isoDate) {
                dayButtons[i].focus();
                return;
            }
        }
    }

    function renderPickerCalendar(picker) {
        var year = picker.state.year;
        var month = picker.state.month;
        var selectedIso = picker.queryValue.value;
        var firstDay = makeUtcDate(year, month, 1).getUTCDay();
        var totalDays = daysInMonth(year, month);
        var cellCount = Math.ceil((firstDay + totalDays) / 7) * 7;
        var row = null;

        picker.title.textContent = monthNames[month] + ' ' + year;
        picker.calendar.setAttribute('aria-label', monthNames[month] + ' ' + year + ' calendar');
        while (picker.days.firstChild) {
            picker.days.removeChild(picker.days.firstChild);
        }

        for (var cellIndex = 0; cellIndex < cellCount; cellIndex++) {
            if (cellIndex % 7 === 0) {
                row = document.createElement('tr');
                picker.days.appendChild(row);
            }

            var cell = document.createElement('td');
            var day = cellIndex - firstDay + 1;
            if (day < 1 || day > totalDays) {
                cell.setAttribute('aria-hidden', 'true');
            } else {
                var dayButton = document.createElement('button');
                var isoDate = String(year).padStart(4, '0') + '-'
                    + String(month + 1).padStart(2, '0') + '-'
                    + String(day).padStart(2, '0');
                dayButton.type = 'button';
                dayButton.className = 'erp-list-date-picker-day';
                dayButton.textContent = String(day);
                dayButton.setAttribute('data-date-picker-day', isoDate);
                dayButton.setAttribute('aria-label', weekdayNames[makeUtcDate(year, month, day).getUTCDay()]
                    + ', ' + monthNames[month] + ' ' + day + ', ' + year);
                dayButton.setAttribute('aria-pressed', selectedIso === isoDate ? 'true' : 'false');
                dayButton.tabIndex = day === picker.state.focusDay ? 0 : -1;
                if (selectedIso === isoDate) {
                    dayButton.classList.add('is-selected');
                }
                dayButton.addEventListener('click', function (event) {
                    selectPickerDate(picker, event.currentTarget.getAttribute('data-date-picker-day'));
                });
                cell.appendChild(dayButton);
            }

            row.appendChild(cell);
        }
    }

    function shiftPickerMonth(picker, amount, focusDayAfter) {
        var absoluteMonth = picker.state.year * 12 + picker.state.month + amount;
        var year = Math.floor(absoluteMonth / 12);
        var month = absoluteMonth - year * 12;
        if (year < 1 || year > 9999) {
            return;
        }

        picker.state.year = year;
        picker.state.month = month;
        picker.state.focusDay = Math.min(picker.state.focusDay, daysInMonth(year, month));
        renderPickerCalendar(picker);
        if (focusDayAfter) {
            focusPickerDay(picker);
        }
    }

    function shiftPickerDay(picker, amount) {
        var date = makeUtcDate(picker.state.year, picker.state.month, picker.state.focusDay + amount);
        var year = date.getUTCFullYear();
        if (year < 1 || year > 9999) {
            return;
        }

        picker.state.year = year;
        picker.state.month = date.getUTCMonth();
        picker.state.focusDay = date.getUTCDate();
        renderPickerCalendar(picker);
        focusPickerDay(picker);
    }

    function closeDatePicker(picker, returnFocus) {
        if (!picker) {
            return;
        }

        picker.popover.hidden = true;
        setPickerExpanded(picker, false);
        if (activeDatePicker === picker) {
            activeDatePicker = null;
        }
        if (returnFocus) {
            picker.input.focus();
        }
    }

    function openDatePicker(picker, focusCalendar) {
        if (activeDatePicker === picker && !picker.popover.hidden) {
            if (focusCalendar) {
                focusPickerDay(picker);
            }
            return;
        }
        if (activeDatePicker && activeDatePicker !== picker) {
            closeDatePicker(activeDatePicker, false);
        }

        var selectedValue = displayDateToIso(picker.input.value);
        var selected = selectedValue ? isoDateToParts(selectedValue) : null;
        if (selected) {
            picker.state.year = selected.year;
            picker.state.month = selected.month;
            picker.state.focusDay = selected.day;
        } else {
            // Show the current month only; never write today's date into either
            // the visible field or the backend query value.
            var today = new Date();
            picker.state.year = today.getFullYear();
            picker.state.month = today.getMonth();
            picker.state.focusDay = 1;
        }

        renderPickerCalendar(picker);
        picker.popover.hidden = false;
        activeDatePicker = picker;
        setPickerExpanded(picker, true);
        if (focusCalendar) {
            focusPickerDay(picker);
        }
    }

    function dispatchDateChange(input) {
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function updateOpenDatePickerFromInput(picker) {
        if (activeDatePicker !== picker || picker.popover.hidden) {
            return;
        }

        var isoDate = displayDateToIso(picker.input.value);
        if (isoDate === null) {
            return;
        }

        var parts = isoDate ? isoDateToParts(isoDate) : null;
        if (parts) {
            picker.state.year = parts.year;
            picker.state.month = parts.month;
            picker.state.focusDay = parts.day;
        }
        renderPickerCalendar(picker);
    }

    function selectPickerDate(picker, isoDate) {
        if (!isoDateToParts(isoDate)) {
            return;
        }

        picker.input.value = isoDateToDisplay(isoDate);
        syncDateDisplay(picker.input);
        dispatchDateChange(picker.input);
        closeDatePicker(picker, true);
    }

    function initDatePicker(root) {
        if (root.dataset.datePickerInit === 'true') {
            return;
        }
        root.dataset.datePickerInit = 'true';

        var input = root.querySelector('[data-list-date-display]');
        var targetId = input ? input.getAttribute('data-list-date-target') : null;
        var queryValue = targetId ? document.getElementById(targetId) : null;
        var toggle = root.querySelector('[data-date-picker-toggle]');
        var popover = root.querySelector('[data-date-picker-popover]');
        var title = root.querySelector('[data-date-picker-title]');
        var calendar = root.querySelector('[data-date-picker-calendar]');
        var days = root.querySelector('[data-date-picker-days]');
        var previous = root.querySelector('[data-date-picker-prev]');
        var next = root.querySelector('[data-date-picker-next]');
        var clear = root.querySelector('[data-date-picker-clear]');
        var close = root.querySelector('[data-date-picker-close]');
        if (!input || !queryValue || !toggle || !popover || !title || !calendar || !days || !previous || !next || !clear || !close) {
            return;
        }

        var picker = {
            root: root,
            input: input,
            queryValue: queryValue,
            toggle: toggle,
            popover: popover,
            title: title,
            calendar: calendar,
            days: days,
            label: root.getAttribute('data-date-picker-label') || 'date',
            state: { year: 0, month: 0, focusDay: 1 },
        };

        syncDateDisplay(input);
        input.addEventListener('input', function () {
            syncDateDisplay(input);
            updateOpenDatePickerFromInput(picker);
        });
        input.addEventListener('change', function () {
            syncDateDisplay(input);
            updateOpenDatePickerFromInput(picker);
        });
        input.addEventListener('click', function () { openDatePicker(picker, false); });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                openDatePicker(picker, true);
            }
        });
        toggle.addEventListener('click', function () {
            if (activeDatePicker === picker && !popover.hidden) {
                closeDatePicker(picker, false);
            } else {
                openDatePicker(picker);
            }
        });
        previous.addEventListener('click', function () { shiftPickerMonth(picker, -1, false); });
        next.addEventListener('click', function () { shiftPickerMonth(picker, 1, false); });
        clear.addEventListener('click', function () {
            input.value = '';
            syncDateDisplay(input);
            dispatchDateChange(input);
            closeDatePicker(picker, true);
        });
        close.addEventListener('click', function () { closeDatePicker(picker, true); });

        days.addEventListener('keydown', function (event) {
            var dayButton = event.target.closest('[data-date-picker-day]');
            if (!dayButton) {
                return;
            }

            var amount = 0;
            if (event.key === 'ArrowLeft') amount = -1;
            else if (event.key === 'ArrowRight') amount = 1;
            else if (event.key === 'ArrowUp') amount = -7;
            else if (event.key === 'ArrowDown') amount = 7;
            else if (event.key === 'Home') amount = -makeUtcDate(picker.state.year, picker.state.month, picker.state.focusDay).getUTCDay();
            else if (event.key === 'End') amount = 6 - makeUtcDate(picker.state.year, picker.state.month, picker.state.focusDay).getUTCDay();
            else if (event.key === 'PageUp' || event.key === 'PageDown') {
                event.preventDefault();
                shiftPickerMonth(picker, (event.key === 'PageUp' ? -1 : 1) * (event.shiftKey ? 12 : 1), true);
                return;
            } else {
                return;
            }

            event.preventDefault();
            shiftPickerDay(picker, amount);
        });

        root.addEventListener('focusout', function () {
            window.setTimeout(function () {
                if (activeDatePicker === picker && !root.contains(document.activeElement)) {
                    closeDatePicker(picker, false);
                }
            }, 0);
        });
    }

    function bindDatePickerDocumentEvents() {
        if (datePickerDocumentEventsBound) {
            return;
        }
        datePickerDocumentEventsBound = true;

        document.addEventListener('click', function (event) {
            if (activeDatePicker && !activeDatePicker.root.contains(event.target)) {
                closeDatePicker(activeDatePicker, false);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && activeDatePicker) {
                event.preventDefault();
                closeDatePicker(activeDatePicker, true);
            }
        });
    }

    function initDateRangeFilters() {
        var ranges = document.querySelectorAll('[data-list-date-range]');
        if (ranges.length === 0) {
            return;
        }

        Array.prototype.slice.call(ranges).forEach(function (range) {
            Array.prototype.slice.call(range.querySelectorAll('[data-erp-date-picker]')).forEach(initDatePicker);

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

        bindDatePickerDocumentEvents();
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
