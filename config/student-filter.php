<?php

use App\Models\Student;
use App\Models\StudentEnrollment;

/**
 * Canonical Student Filter definitions.
 *
 * Labels, request keys and query semantics live here once. Modules may choose a
 * subset of fields, but must not re-label a status or reuse one of these keys for
 * a different domain concept.
 *
 * The three status dimensions are deliberately separate and never swapped:
 *
 *  - `student_status`  → students.status (the Student Status field: Active /
 *                        Inactive). The stored vocabulary is wider
 *                        ({@see Student::STATUSES}) and old links may still use
 *                        it, but the control offers the two operational values.
 *  - `academic_status` → a DERIVED academic/progress status (Continue, Dropout,
 *                        Pass Out, New Registration, Transfer Out). This schema
 *                        has no academic-status column, so each option is mapped
 *                        to an existing, documented predicate — see
 *                        `academic_status_options` / `academic_status_gaps`
 *                        below and docs/student_filter_system.md.
 *  - `enrollment_status` → the raw student_enrollments.status
 *                        ({@see StudentEnrollment::STATUSES}). Still accepted for
 *                        existing links and module tests, but no longer rendered
 *                        as a control (it is not the Academic Status vocabulary).
 *
 * Identity fields (`aadhaar`, `apaar_id`) are `type => identity`: they are never
 * parsed from the query string, never rendered as a GET control and never
 * echoed back. Their values are submitted once by POST to
 * StudentIdentityFilterController, stored server-side under an opaque token and
 * resolved from the session — so no Aadhaar or ABC ID value can appear in a URL,
 * a log line, an error message or a browser-visible filter summary.
 */
return [
    'sensitive_permission' => 'students.view_sensitive_identity',

    /*
     * Session-backed vault that keeps identity-search values out of the URL.
     * `ttl_minutes` bounds how long an applied identity search survives; a new
     * search replaces the old token on the same page.
     */
    'identity' => [
        'session_key' => 'student.filter.identity',
        'ttl_minutes' => 60,
        'token_length' => 40,
    ],

    /*
     * Student Status control values. The column stores more
     * (Student::STATUSES); this list is what the shared control offers.
     */
    'student_status_options' => ['active', 'inactive'],

    /*
     * Academic Status: the requested vocabulary, restricted to the values this
     * application can actually answer from existing data. `label` is what the
     * user sees, the key is what travels in the query string.
     *
     * Each option is a real database predicate (StudentFilter::applyAcademicStatus)
     * that reads the existing progression ledger (`student_academic_records`:
     * academic_status / promotion_status / completion_status) and the student
     * lifecycle columns together — no new column, no new table:
     *
     *  continue         students.status = 'active' OR a record is 'enrolled'
     *  dropout          a 'discontinued'/'withdrawn' record that was not
     *                   transferred out, OR students.status = 'withdrawn' with no
     *                   approved/issued StudentTransfer
     *  pass_out         students.status = 'graduated' OR a record is 'passed' /
     *                   completion_status 'completed'
     *  new_registration students.registration_submitted_at IS NOT NULL (created
     *                   through the existing single / bulk registration workflow)
     *  transfer_out     an approved transfer request / issued TC exists, OR a
     *                   record is 'transferred'
     */
    'academic_status_options' => [
        'continue' => 'Continue',
        'dropout' => 'Dropout',
        'pass_out' => 'Pass Out',
        'new_registration' => 'New Registration',
        'transfer_out' => 'Transfer Out',
    ],

    /*
     * Requested academic statuses this schema cannot answer. They are listed for
     * the user instead of being offered as controls that would silently match
     * nothing.
     */
    'academic_status_gaps' => [
        'online_registration' => 'Registrations are not marked online/offline: the single-registration form and the bulk importer write the same fields, so no column distinguishes them.',
        'transfer_in' => 'Only transfer-out records exist (student_transfers). There is no transfer-in register to read.',
    ],

    /*
     * The compact panel: exactly these eleven controls, in this order. The shared
     * component always renders a `date_range` field last, so REG. DATE keeps the
     * final position even though it is listed here beside the other filters.
     *
     * `student_list_fields` is the Students-list projection and is deliberately
     * identical: one canonical panel everywhere. Fields that are no longer
     * rendered (email, mobile_number, enrollment_number, father_name,
     * mother_name, registration_status, enrollment_status, and the profile
     * filters gender / category / blood_group / nationality / mother_tongue /
     * date_of_birth) are still DEFINED below, so existing bookmarks, exports and
     * module links keep filtering exactly as before.
     */
    'default_fields' => [
        'registration_number',
        'student_name',
        'student_status',
        'academic_status',
        'academic_year_id',
        'department_id',
        'program_id',
        'academic_context',
        'aadhaar',
        'apaar_id',
        'registration_date',
    ],

    'student_list_fields' => [
        'registration_number',
        'student_name',
        'student_status',
        'academic_status',
        'academic_year_id',
        'department_id',
        'program_id',
        'academic_context',
        'aadhaar',
        'apaar_id',
        'registration_date',
    ],

    /*
     * Canonical definitions. The order below is the panel order: the eleven
     * rendered controls first (see `default_fields`), then the fields that are
     * still parsed from the query string for existing links, exports and
     * module pages but are no longer drawn in the compact panel.
     */
    'fields' => [
        'registration_number' => [
            'label' => 'REG.NO',
            'type' => 'text',
            'request' => 'student_number',
            'match' => 'contains',
            'max' => 100,
            'placeholder' => 'Registration number',
            'help' => 'Partial or full match on the student registration number.',
        ],
        'student_name' => [
            'label' => 'NAME OF STUDENT',
            'type' => 'text',
            'match' => 'name',
            'max' => 150,
            'placeholder' => 'First, middle or last name',
            'help' => 'Partial match on first, middle or last name.',
        ],
        'student_status' => [
            'label' => 'STUDENT STATUS',
            'type' => 'select',
            'options' => 'student_statuses',
            'placeholder' => 'All',
            'values' => Student::STATUSES,
            'help' => 'The student account status (Active / Inactive), not the academic progress.',
        ],
        'academic_status' => [
            'label' => 'ACADEMIC STATUS',
            'type' => 'select',
            'options' => 'academic_statuses',
            'placeholder' => 'All',
            'help' => 'Academic progress from the progression ledger and transfer records — not the Student Status. Not tracked: Online Registration, Transfer In.',
        ],
        'academic_year_id' => [
            'label' => 'Academic year',
            'type' => 'select',
            'options' => 'academic_years',
            'placeholder' => 'All',
        ],
        'department_id' => [
            'label' => 'Department',
            'type' => 'select',
            'options' => 'departments',
            'placeholder' => 'All',
        ],
        'program_id' => [
            'label' => 'PROGRAM / CLASS',
            'type' => 'select',
            'options' => 'programs',
            'placeholder' => 'All',
        ],
        'academic_context' => [
            'label' => 'SEMESTER / SECTION',
            'type' => 'select',
            'options' => 'academic_contexts',
            'placeholder' => 'All',
            'depends_on' => ['program_id', 'academic_year_id'],
            'help' => 'Dependent on Program and Academic year. A Section is this application\'s batch cohort, so this filter also covers Batch.',
        ],
        'aadhaar' => [
            'label' => 'AADHAAR NUMBER',
            'type' => 'identity',
            'request' => 'aadhaar',
            'sensitive' => true,
            'max' => 20,
            'inputmode' => 'numeric',
            'maxlength' => 20,
            'placeholder' => '12 digits or last 4',
            'help' => 'Authorized roles only. Exact match: 12 digits match the stored digest, 4 digits match the stored last four. Submitted by POST and never placed in the URL.',
        ],
        'apaar_id' => [
            'label' => 'ABC ID / APAAR ID',
            'type' => 'identity',
            'request' => 'apaar_id',
            'sensitive' => true,
            'max' => 30,
            'placeholder' => 'Exact ABC / APAAR ID',
            'help' => 'Authorized roles only. Exact match, submitted by POST and never placed in the URL.',
        ],
        // Rendered last by the shared component, whatever its position here.
        'registration_date' => [
            'label' => 'REG. DATE',
            'type' => 'date_range',
            'from_request' => 'admission_date_from',
            'to_request' => 'admission_date_to',
            'help' => 'Both boundary dates are included.',
        ],

        /* ------------------------------------------------------------------
         * Not drawn in the compact panel any more. These definitions stay so
         * that existing bookmarks, saved exports and module links keep
         * filtering exactly as they did; removing them would silently widen
         * those queries.
         * ---------------------------------------------------------------- */
        'registration_status' => [
            'label' => 'REGISTRATION',
            'type' => 'select',
            'options' => 'registration_statuses',
            'placeholder' => 'All',
            'values' => [...Student::REGISTRATION_STATUSES, 'legacy'],
            'legacy' => true,
        ],
        'enrollment_status' => [
            'label' => 'ENROLLMENT STATUS',
            'type' => 'select',
            'options' => 'enrollment_statuses',
            'placeholder' => 'All',
            'values' => StudentEnrollment::STATUSES,
            'legacy' => true,
        ],
        'email' => [
            'label' => 'E-MAIL',
            'type' => 'text',
            'match' => 'contains',
            'max' => 150,
            'legacy' => true,
        ],
        'enrollment_number' => [
            'label' => 'ENROLLMENT NO.',
            'type' => 'text',
            'match' => 'enrollment_exact',
            'max' => 100,
            'legacy' => true,
        ],
        'mobile_number' => [
            'label' => 'MOBILE NUMBER',
            'type' => 'text',
            'match' => 'exact',
            'max' => 30,
            'legacy' => true,
        ],
        'father_name' => [
            'label' => "FATHER'S NAME",
            'type' => 'text',
            'match' => 'contains',
            'max' => 150,
            'legacy' => true,
        ],
        'mother_name' => [
            'label' => "MOTHER'S NAME",
            'type' => 'text',
            'match' => 'contains',
            'max' => 150,
            'legacy' => true,
        ],
    ],
];
