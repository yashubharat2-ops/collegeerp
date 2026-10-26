<?php

use App\Models\Student;
use App\Models\StudentEnrollment;

/**
 * Canonical Student Filter definitions.
 *
 * Labels, request keys and query semantics live here once. Modules may choose a
 * subset of fields, but must not re-label a status or reuse one of these keys for
 * a different domain concept. In particular, `student_status` is Student.status,
 * `enrollment_status` is StudentEnrollment.status, and neither is an admission
 * application workflow status.
 */
return [
    'sensitive_permission' => 'students.view_sensitive_identity',

    'default_fields' => [
        'registration_number',
        'registration_date',
        'enrollment_status',
        'student_status',
        'program_id',
        'academic_context',
        'batch',
        'student_name',
        'unique_student_id',
        'email',
        'apaar_id',
        'enrollment_number',
        'mobile_number',
        'aadhaar_last4',
        'father_name',
        'mother_name',
    ],

    'fields' => [
        'registration_number' => [
            'label' => 'REG.NO',
            'type' => 'text',
            'request' => 'student_number',
            'match' => 'contains',
            'max' => 100,
            'placeholder' => 'College registration number',
        ],
        'registration_date' => [
            'label' => 'REG. DATE',
            'type' => 'date_range',
            'from_request' => 'admission_date_from',
            'to_request' => 'admission_date_to',
        ],
        'enrollment_status' => [
            'label' => 'ACADEMIC STATUS',
            'type' => 'select',
            'options' => 'enrollment_statuses',
            'placeholder' => 'All academic statuses',
            'values' => StudentEnrollment::STATUSES,
        ],
        'student_status' => [
            'label' => 'STATUS',
            'type' => 'select',
            'options' => 'student_statuses',
            'placeholder' => 'All student statuses',
            'values' => Student::STATUSES,
        ],
        'registration_status' => [
            'label' => 'REGISTRATION',
            'type' => 'select',
            'options' => 'registration_statuses',
            'placeholder' => 'All registration states',
            'values' => [...Student::REGISTRATION_STATUSES, 'legacy'],
        ],
        'program_id' => [
            'label' => 'PROGRAM/CLASS',
            'type' => 'select',
            'options' => 'programs',
            'placeholder' => 'All programs / classes',
        ],
        'academic_context' => [
            'label' => 'SEMESTER/SECTION',
            'type' => 'select',
            'options' => 'academic_contexts',
            'placeholder' => 'All semesters / sections',
        ],
        'batch' => [
            'label' => 'BATCH',
            'type' => 'unavailable',
            'reason' => 'No Batch master or batch column exists in the current schema. Section is not silently reused as Batch.',
        ],
        'student_name' => [
            'label' => 'NAME OF STUDENT',
            'type' => 'text',
            'match' => 'name',
            'max' => 150,
            'placeholder' => 'First, middle or last name',
        ],
        'unique_student_id' => [
            'label' => 'UNIQUE STUDENT ID',
            'type' => 'text',
            'match' => 'exact_student_number',
            'max' => 100,
            'placeholder' => 'Same college-issued number as REG.NO',
        ],
        'email' => [
            'label' => 'E-MAIL',
            'type' => 'text',
            'match' => 'contains',
            'max' => 150,
            'placeholder' => 'Partial e-mail address',
        ],
        'apaar_id' => [
            'label' => 'ABC ID / APAAR ID',
            'type' => 'text',
            'match' => 'exact',
            'max' => 30,
            'placeholder' => 'Exact ABC / APAAR ID',
        ],
        'enrollment_number' => [
            'label' => 'ENROLLMENT NO.',
            'type' => 'text',
            'match' => 'enrollment_exact',
            'max' => 100,
            'placeholder' => 'Exact enrollment number',
        ],
        'mobile_number' => [
            'label' => 'MOBILE NUMBER',
            'type' => 'text',
            'match' => 'exact',
            'max' => 30,
            'inputmode' => 'tel',
            'placeholder' => 'Exact mobile number',
        ],
        'aadhaar_last4' => [
            'label' => 'AADHAAR NUMBER',
            'type' => 'text',
            'match' => 'aadhaar_last4',
            'sensitive' => true,
            'inputmode' => 'numeric',
            'maxlength' => 4,
            'placeholder' => 'Last 4 digits only',
            'help' => 'Permission-gated exact last-four match. Never enter or submit a full Aadhaar number here.',
        ],
        'father_name' => [
            'label' => "FATHER'S NAME",
            'type' => 'text',
            'match' => 'contains',
            'max' => 150,
        ],
        'mother_name' => [
            'label' => "MOTHER'S NAME",
            'type' => 'text',
            'match' => 'contains',
            'max' => 150,
        ],

        // Existing Student List dimensions. They are extensions, not additional
        // names for PROGRAM/CLASS or SEMESTER/SECTION.
        'academic_year_id' => [
            'label' => 'Academic year',
            'type' => 'select',
            'options' => 'academic_years',
            'placeholder' => 'All academic years',
        ],
        'department_id' => [
            'label' => 'Department',
            'type' => 'select',
            'options' => 'departments',
            'placeholder' => 'All departments',
        ],
    ],
];
