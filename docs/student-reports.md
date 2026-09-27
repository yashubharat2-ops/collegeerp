# Student Reports (read-only)

`/student-reports` is a separate, tenant-scoped REPORTS sidebar entry. The single
`student_reports.view` permission grants the eleven report views, the student
profile report, and the per-student history report. It does **not** grant access
to the operational Student/Admission modules or any write/download capability.
Only GET routes exist; no report or history tables, exports, file URLs, or cached
classifications are created.

| View | Source / interpretation |
| --- | --- |
| Student List | Students (one per row) with their current active enrollment, or an enrollment matching all selected academic filters on the *same* row. |
| Student Profile / Detail | The Student snapshot and its linked application/admission, enrollments, academic records, documents, promotions and transfers. No private files are served. |
| Admission | Official Admission rows, including admissions not yet converted to a Student. Student status only applies to converted admissions. |
| Enrollment | StudentEnrollment rows, including completed/withdrawn enrollments. |
| Student Strength | Distinct students per academic year/program/section, defaulting to active students and active enrollments. The overall unique-student count can differ from the sum of groups when a student has multiple enrollments. |
| New / Old | For each enrollment, **new** means its academic year equals the student's first non-deleted enrollment year (earliest enrollment date, ID tiebreak). **Old** means another year. The first year is determined before applying report filters. |
| Category / Gender / Caste | One distinct Student per gender, including “Not recorded”. The current Student and Admission schemas do **not** store category or caste; no fictitious buckets or inferred values are shown. |
| Student Documents Status | StudentDocument verification counts per student, including zero-document students. A document upload-date range applies to the counts and to the “No documents” selection. |
| Promotion | StudentPromotion decisions; academic filters refer to the **target**, dates to request creation. |
| Transfer / TC | StudentTransfer requests/TC issuance; academic filters refer to the linked enrollment (when present), dates to transfer date. |
| Student History | Paginated student picker and per-student chronological timeline from the existing StudentHistoryService; timeline date/category filters apply to events. |

Year, program, department (via Program), section (via Enrollment), student status,
record-specific status, search and inclusive date ranges are available where
applicable. Student/enrollment lists are paginated with a unique-ID ordering
tiebreak; the small gender summary is grouped in the database. All related
rows are eagerly loaded or aggregated, and every root query uses CollegeScope.
The first-enrollment subquery correlates on both `college_id` and `student_id`
and excludes soft-deleted enrollments.

Views use the existing `.print-area` / `.no-print` styles: filters/navigation
are hidden in print layout. Pagination means only the **current page** is
printable; no bulk export system is introduced in this phase.
