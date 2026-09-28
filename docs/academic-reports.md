# Academic Reports (read-only)

`/academic-reports` is the second entry in the REPORTS sidebar section (after
Student Reports; the section stays immediately after Inventory / Asset
Management). The single `academic_reports.view` permission grants all nine
views. It does **not** grant access to the operational Academics pages (subject
enrollment, sections, timetable, attendance, calendar, workload) and those
pages' permissions do not grant the reports. Only one GET route exists; no report
tables, snapshots, exports or cached aggregates are created. The REPORTS heading
is shown when the user holds either report permission, and each link needs its
own permission.

| View | Source / interpretation |
| --- | --- |
| Enrollment / Subject Enrollment | `academic_subject_enrollments` rows (active, dropped, completed) with the linked Student Enrollment, year, term, program/department, section and subject. Totals: unique students and subjects. |
| Class / Section Strength | One row per Section: capacity, **active** Student Enrollments (distinct students), occupancy, students with active subject enrollments, distinct subjects/faculty from active Faculty Subject Assignments, active weekly timetable periods. A term filter narrows only the term-bearing columns (subject enrollments, assignments, timetable). |
| Subject-wise Student | Distinct students per subject + term + section, split by subject-enrollment status, with a link to the matching enrollment rows. Totals count unique students, so they need not equal the sum of rows. |
| Faculty-wise Subject | Faculty Subject Assignments ordered by faculty, with active timetable periods/week and active enrolled students in the assignment's context. An assignment with no term/section covers every term/section of its year. |
| Timetable | Timetable entries in weekly order (day, start time, section, id). Defaults to **active** entries; "All statuses" includes inactive ones. Shows each entry's duration and total weekly hours. |
| Attendance | The subject attendance register (newest first) with status totals. Program/department come from the attendance row's section. |
| Student Attendance Summary | Per student + subject + term, or per student overall. Attendance % = (present + late) ÷ recorded sessions; leave is shown but not counted as attended. "Attendance below (%)" lists shortages (SQL `HAVING`). |
| Faculty Workload | Per faculty + year + term from **active** timetable entries: periods/week, hours/week, distinct subjects and sections, and active assignment count (an assignment with no term counts for every term of its year). |
| Academic Calendar | Calendar events in date order with status totals. The date window includes every overlapping event; a term filter also includes year-wide events (no term) of that term's year. |

Filters (only those relevant to the selected view are shown and applied; any
other query parameter is ignored): Academic Year, Term / Semester, Department,
Program / Course, Class / Section, Subject, Faculty, record status, attendance
status, day, event type, student/faculty/title search and inclusive date ranges.
Department means the Program's department for class-based views and the
**faculty's** department for Faculty-wise Subject and Faculty Workload.

Implementation notes:

* `App\Domain\Academic\Services\AcademicReportService` builds every root query,
  correlated count and dropdown from models carrying `CollegeScope` (and
  `SoftDeletes` where the model has it), so rows and counts never cross
  colleges and soft-deleted rows are excluded. An ID from another college in a
  filter simply matches nothing.
* Lists paginate 20 per page with a unique-ID tiebreak and keep the query string.
  Grouped views aggregate in the database and paginate groups.
* Labels are eager-loaded and per-row counts are correlated subqueries or one
  grouped query per page, so the query count does not grow with rows (a test
  asserts this for every view). Timetable durations are summed in PHP from a
  single query because SQLite and MySQL share no portable time-difference
  function.
* Views use the existing `.print-area` / `.no-print` styles; printing shows the
  current page only. No bulk export is introduced in this phase.
