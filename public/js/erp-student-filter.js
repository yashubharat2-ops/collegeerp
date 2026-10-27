/**
 * College ERP — Student Filter: dependent dropdowns.
 *
 * Progressive enhancement only. The shared Student Filter panel renders every
 * allowed Section / Semester option server-side (so the page works without
 * JavaScript), and this script narrows the SEMESTER / SECTION list to the options
 * that can actually be combined with the chosen PROGRAM / CLASS, academic year
 * and level.
 *
 * The server remains the authority: StudentFilter::assertAcademicContextCompatible
 * rejects an incompatible combination with a validation message, so a stale or
 * hand-edited request cannot produce a silently empty list.
 *
 * No inline code (the layout only allows external module scripts) and no
 * framework: plain DOM, one delegated change listener per form.
 */
function initStudentFilterDependencies() {
    var forms = document.querySelectorAll('[data-student-filter-form]');

    Array.prototype.forEach.call(forms, function (form) {
        if (form.dataset.studentFilterDeps === 'true') {
            return;
        }
        form.dataset.studentFilterDeps = 'true';

        var dependents = form.querySelectorAll('[data-student-filter-dependent]');

        Array.prototype.forEach.call(dependents, function (select) {
            var dependencies = (select.getAttribute('data-student-filter-dependent') || '')
                .split(/\s+/)
                .filter(Boolean);
            if (dependencies.length === 0) {
                return;
            }

            var controls = dependencies
                .map(function (name) {
                    return form.querySelector('[name="' + name + '"]');
                })
                .filter(Boolean);

            if (controls.length === 0) {
                return;
            }

            var sync = function () {
                var program = form.querySelector('[name="program_id"]');
                var year = form.querySelector('[name="academic_year_id"]');
                var programValue = program ? program.value : '';
                var yearValue = year ? year.value : '';

                Array.prototype.forEach.call(select.querySelectorAll('option'), function (option) {
                    if (!option.value) {
                        return;
                    }

                    var optionProgram = option.getAttribute('data-program') || '';
                    var optionYear = option.getAttribute('data-year') || '';

                    var compatible =
                        (programValue === '' || optionProgram === '' || optionProgram === programValue) &&
                        (yearValue === '' || optionYear === '' || optionYear === yearValue);

                    // A selected value is never dropped: the user must see what is
                    // applied, and the server validates the combination.
                    if (!compatible && !option.selected) {
                        option.hidden = true;
                        option.disabled = true;
                    } else {
                        option.hidden = false;
                        option.disabled = false;
                    }
                });
            };

            controls.forEach(function (control) {
                control.addEventListener('change', sync);
            });

            sync();
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStudentFilterDependencies);
} else {
    initStudentFilterDependencies();
}
