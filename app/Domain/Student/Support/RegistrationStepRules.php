<?php

namespace App\Domain\Student\Support;

use App\Domain\Student\Services\GeoLocationService;
use App\Domain\Student\Services\StudentPhotoService;
use App\Domain\Student\Rules\ValidAadhaar;
use App\Models\Student;
use App\Models\StudentQualification;
use Illuminate\Validation\Rule;

/**
 * The four wizard steps, their validation and their server-side derivation.
 *
 * One class owns three things so they cannot drift apart:
 *
 *  1. the step vocabulary ({@see STEPS}) — the exact titles the UI shows;
 *  2. the validation rules per step ({@see rules()}), split into STRICT ("Next"
 *     and "Submit") and DRAFT ("Save draft") mode. Draft mode keeps the shape
 *     and safety limits (lengths, file size/type, numeric ranges, Aadhaar
 *     checksum) but does not demand the fields the canonical Student form never
 *     required — no optional field is turned into a mandatory one here;
 *  3. the server-side derivation ({@see normalise()}): the dependent address
 *     chain, the guardian auto-fill and the qualification marks arithmetic are
 *     computed from the authoritative record, never trusted from the browser.
 *
 * Cross-field checks that need the stored draft (course-wise document
 * requirements, "is the previous step complete?") live in
 * RegistrationSubmissionService, which can see the whole draft.
 */
final class RegistrationStepRules
{
    public const STEP_BASIC = 1;
    public const STEP_ACADEMIC = 2;
    public const STEP_DOCUMENTS = 3;
    public const STEP_REVIEW = 4;

    /**
     * The exact labels, in order — the progress indicator is built from this.
     *
     * Parent/Guardian is no longer a step of its own: it is captured inside
     * Basic Information (see the section order in the step-1 view), so the
     * wizard is Basic → Academic → Documents → Review.
     */
    public const STEPS = [
        self::STEP_BASIC => 'Basic Information',
        self::STEP_ACADEMIC => 'Academic Record',
        self::STEP_DOCUMENTS => 'Attach Documents',
        self::STEP_REVIEW => 'Final Review',
    ];

    /** Media captured on step 1: field name → stored column + label. */
    public const IMAGE_FIELDS = [
        'photo' => ['column' => 'photo_path', 'label' => 'student photograph'],
        'signature' => ['column' => 'signature_path', 'label' => 'student signature'],
        'father_photo' => ['column' => 'father_photo_path', 'label' => "father's photograph"],
        'mother_photo' => ['column' => 'mother_photo_path', 'label' => "mother's photograph"],
        'guardian_photo' => ['column' => 'guardian_photo_path', 'label' => "guardian's photograph"],
    ];

    /** Step-1 non-file fields that map straight onto student columns. */
    public const BASIC_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender', 'category',
        'mother_tongue', 'blood_group', 'nationality', 'phone', 'alternate_phone', 'email',
        'aadhaar_number', 'apaar_id', 'govt_id_type', 'govt_id_number',
        'address_line_1', 'address_line_2', 'country', 'state', 'district', 'block_tehsil',
        'city', 'gram_panchayat_ward', 'postal_code',
        'country_id', 'state_id', 'district_id', 'block_id', 'village_id',
        'correspondence_same_as_permanent', 'correspondence_address_line_1',
        'correspondence_address_line_2', 'correspondence_city', 'correspondence_district',
        'correspondence_state', 'correspondence_postal_code', 'correspondence_country',
    ];

    /**
     * Parent / guardian fields. They are captured INSIDE step 1 (sections C and
     * D) but keep their own validation and derivation, so the merge into one
     * page did not merge their rules.
     */
    public const PARENT_FIELDS = [
        'father_name', 'father_phone', 'father_email', 'father_occupation',
        'mother_name', 'mother_phone', 'mother_email', 'mother_occupation',
        'guardian_name', 'guardian_relation', 'guardian_phone', 'guardian_email',
        'guardian_occupation', 'guardian_address',
    ];

    /** Step-3 current-enrollment context (captured, applied only on approval). */
    public const ACADEMIC_FIELDS = [
        'academic_year_id', 'program_id', 'section_id', 'semester', 'enrollment_date', 'admission_date',
    ];

    /**
     * Validation rules for one step.
     *
     * @param  bool  $strict  true for "Next"/"Submit", false for "Save draft"
     * @return array<string, mixed>
     */
    public static function rules(int $step, bool $strict, int $collegeId): array
    {
        return match ($step) {
            self::STEP_BASIC => self::basicRules($strict, $collegeId) + self::parentRules($strict, $collegeId),
            self::STEP_ACADEMIC => self::academicRules($strict, $collegeId),
            self::STEP_DOCUMENTS => self::documentRules($strict, $collegeId),
            self::STEP_REVIEW => self::reviewRules($strict),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private static function basicRules(bool $strict, int $collegeId): array
    {
        $required = $strict ? 'required' : 'nullable';

        $rules = [
            // The canonical Student form requires the first name only; every
            // other personal field stays optional here too.
            'first_name' => [$required, 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'gender' => ['nullable', 'string', 'max:20', Rule::in(Student::GENDERS)],
            'category' => ['nullable', 'string', 'max:30', Rule::in(Student::CATEGORIES)],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'blood_group' => ['nullable', 'string', 'max:10', Rule::in(Student::BLOOD_GROUPS)],
            'nationality' => ['nullable', 'string', 'max:100'],
            'mother_tongue' => ['nullable', 'string', 'max:100'],

            // Identity. The Aadhaar checksum runs in draft mode too: a
            // half-typed number must not be stored as if it were a valid one.
            'aadhaar_number' => ['nullable', 'string', 'max:20', new ValidAadhaar()],
            'apaar_id' => ['nullable', 'string', 'max:30', 'regex:/^[0-9]{12}$/'],
            'govt_id_type' => ['nullable', 'string', 'max:30', Rule::in(Student::GOVT_ID_TYPES)],
            'govt_id_number' => ['nullable', 'string', 'max:100'],

            // Media: validated as images here, re-checked by StudentPhotoService.
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.StudentPhotoService::MAX_KB],
            'signature' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.StudentPhotoService::MAX_KB],
            'father_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.StudentPhotoService::MAX_KB],
            'mother_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.StudentPhotoService::MAX_KB],
            'guardian_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.StudentPhotoService::MAX_KB],
            'remove_aadhaar' => ['nullable', 'boolean'],
            'remove_govt_id' => ['nullable', 'boolean'],
            'remove_photo' => ['nullable', 'boolean'],
            'remove_signature' => ['nullable', 'boolean'],
            'remove_father_photo' => ['nullable', 'boolean'],
            'remove_mother_photo' => ['nullable', 'boolean'],
            'remove_guardian_photo' => ['nullable', 'boolean'],

            // Permanent address. None of these is newly mandatory: the Student
            // form has always accepted a student without an address.
            'address_line_1' => ['nullable', 'string', 'max:2000'],
            'address_line_2' => ['nullable', 'string', 'max:2000'],
            'country' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'block_tehsil' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'gram_panchayat_ward' => ['nullable', 'string', 'max:150'],
            'postal_code' => ['nullable', 'string', 'max:20'],

            // Directory references: tenant-independent geography, verified as a
            // chain in normalise() so an id from another block is rejected.
            'country_id' => ['nullable', 'integer'],
            'state_id' => ['nullable', 'integer'],
            'district_id' => ['nullable', 'integer'],
            'block_id' => ['nullable', 'integer'],
            'village_id' => ['nullable', 'integer'],

            // Correspondence address ("Same as permanent" keeps one copy).
            'correspondence_same_as_permanent' => ['nullable', 'boolean'],
            'correspondence_address_line_1' => ['nullable', 'string', 'max:2000'],
            'correspondence_address_line_2' => ['nullable', 'string', 'max:2000'],
            'correspondence_city' => ['nullable', 'string', 'max:100'],
            'correspondence_district' => ['nullable', 'string', 'max:100'],
            'correspondence_state' => ['nullable', 'string', 'max:100'],
            'correspondence_postal_code' => ['nullable', 'string', 'max:20'],
            'correspondence_country' => ['nullable', 'string', 'max:100'],
        ];

        return $rules;
    }

    /** @return array<string, mixed> */
    private static function parentRules(bool $strict, int $collegeId): array
    {
        return [
            'father_name' => ['nullable', 'string', 'max:255'],
            'father_phone' => ['nullable', 'string', 'max:30'],
            'father_email' => ['nullable', 'email', 'max:255'],
            'father_occupation' => ['nullable', 'string', 'max:150'],

            'mother_name' => ['nullable', 'string', 'max:255'],
            'mother_phone' => ['nullable', 'string', 'max:30'],
            'mother_email' => ['nullable', 'email', 'max:255'],
            'mother_occupation' => ['nullable', 'string', 'max:150'],

            'guardian_relation' => ['nullable', 'string', 'max:50', Rule::in(Student::GUARDIAN_RELATIONS)],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:30'],
            'guardian_email' => ['nullable', 'email', 'max:255'],
            'guardian_occupation' => ['nullable', 'string', 'max:150'],
            'guardian_address' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, mixed> */
    private static function academicRules(bool $strict, int $collegeId): array
    {
        return [
            // The registration flow has always required the academic context
            // (StoreStudentRegistrationRequest requires both); a draft may not
            // have chosen them yet.
            'academic_year_id' => [
                $strict ? 'required' : 'nullable', 'integer',
                Rule::exists('academic_years', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'program_id' => [
                $strict ? 'required' : 'nullable', 'integer',
                Rule::exists('programs', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'section_id' => [
                'nullable', 'integer',
                Rule::exists('sections', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
            ],
            'semester' => ['nullable', 'string', 'max:30'],
            'enrollment_date' => ['nullable', 'date'],
            'admission_date' => ['nullable', 'date'],

            // Previous qualifications: a repeating table, submitted as an array.
            'qualifications' => ['nullable', 'array', 'max:12'],
            'qualifications.*.id' => ['nullable', 'integer'],
            'qualifications.*.level' => ['nullable', 'string', 'max:30', Rule::in(StudentQualification::LEVELS)],
            // Lengths mirror the columns in student_qualifications exactly.
            'qualifications.*.qualification' => ['nullable', 'string', 'max:150'],
            'qualifications.*.examination_year' => ['nullable', 'integer', 'between:1900,2100'],
            'qualifications.*.board_university' => ['nullable', 'string', 'max:150'],
            'qualifications.*.institution' => ['nullable', 'string', 'max:200'],
            'qualifications.*.roll_number' => ['nullable', 'string', 'max:50'],
            'qualifications.*.registration_number' => ['nullable', 'string', 'max:80'],
            'qualifications.*.marks_obtained' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'qualifications.*.max_marks' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'qualifications.*.percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'qualifications.*.grade' => ['nullable', 'string', 'max:30'],
            'qualifications.*.grade_letter' => ['nullable', 'string', 'max:10'],
            'qualifications.*.result_status' => ['nullable', 'string', 'max:20', Rule::in(StudentQualification::RESULT_STATUSES)],
            'qualifications.*.remarks' => ['nullable', 'string', 'max:500'],
            'qualifications.*.subjects' => ['nullable'],
            // Free-text subject lines ("Mathematics | 100 | 88") are parsed into
            // the structured `subjects` JSON by StudentQualificationService.
            'qualifications.*.subjects_text' => ['nullable', 'string', 'max:2000'],
            // A row removed in the browser: the stored qualification is deleted
            // on the next save instead of lingering as an invisible record.
            'qualifications.*.remove' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private static function documentRules(bool $strict, int $collegeId): array
    {
        // Uploads are their own endpoint (a multipart POST per file), so the
        // step itself has nothing to validate beyond the course requirements,
        // which need the stored draft and live in the submission service.
        return [];
    }

    /** @return array<string, mixed> */
    private static function reviewRules(bool $strict): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:2000'],
            // An explicit acknowledgement, so "Submit" is a deliberate action.
            'confirm_declaration' => [$strict ? 'accepted' : 'nullable'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'apaar_id.regex' => 'The APAAR / ABC ID must be exactly 12 digits.',
            'first_name.required' => 'Enter the student\'s first name before continuing.',
            'academic_year_id.required' => 'Choose the academic year for this admission.',
            'program_id.required' => 'Choose the programme / course the student is joining.',
            'confirm_declaration.accepted' => 'Confirm that the details are correct before submitting.',
            'qualifications.*.examination_year.between' => 'Enter a valid examination year.',
            'qualifications.*.marks_obtained.max' => 'Marks obtained look too high; check the value.',
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'first_name' => 'first name',
            'date_of_birth' => 'date of birth',
            'father_phone' => "father's mobile number",
            'mother_phone' => "mother's mobile number",
            'guardian_phone' => "guardian's mobile number",
            'academic_year_id' => 'academic year',
            'program_id' => 'programme',
            'section_id' => 'section / batch',
            'qualifications.*.level' => 'qualification level',
            'qualifications.*.max_marks' => 'maximum marks',
        ];
    }

    /**
     * Server-side derivation for one step. The returned array is what gets
     * stored in the draft — never the raw request.
     *
     * @param  array<string, mixed>  $data  validated input for this step
     * @param  array<string, mixed>  $merged  the draft's data so far (all steps)
     * @return array<string, mixed>
     */
    public static function normalise(int $step, array $data, array $merged, int $collegeId, GeoLocationService $geo): array
    {
        return match ($step) {
            self::STEP_BASIC => self::normaliseBasicWithParent($data, $merged, $geo),
            self::STEP_ACADEMIC => self::normaliseAcademic($data),
            self::STEP_DOCUMENTS => [],
            self::STEP_REVIEW => ['remarks' => $data['remarks'] ?? null],
            default => [],
        };
    }

    /**
     * Step 1 holds BOTH the personal details and the parent/guardian block.
     *
     * The guardian "Self" derivation reads the student's own name/phone/email,
     * so the freshly normalised personal details are merged into the context
     * first — otherwise a first save of step 1 would derive the guardian from
     * stale (or missing) data.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $merged
     * @return array<string, mixed>
     */
    private static function normaliseBasicWithParent(array $data, array $merged, GeoLocationService $geo): array
    {
        $basic = self::normaliseBasic($data, $geo);

        return array_merge($basic, self::normaliseParent($data, array_merge($merged, $basic)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normaliseBasic(array $data, GeoLocationService $geo): array
    {
        $clean = [];

        foreach (self::BASIC_FIELDS as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                $clean[$key] = is_string($value) ? trim($value) : $value;
            }
        }

        foreach (['middle_name', 'last_name', 'date_of_birth', 'gender', 'category', 'mother_tongue',
            'blood_group', 'nationality', 'phone', 'alternate_phone', 'email', 'aadhaar_number',
            'apaar_id', 'govt_id_type', 'govt_id_number', 'address_line_1', 'address_line_2',
            'country', 'state', 'district', 'block_tehsil', 'city', 'gram_panchayat_ward',
            'postal_code', 'correspondence_address_line_1', 'correspondence_address_line_2',
            'correspondence_city', 'correspondence_district', 'correspondence_state',
            'correspondence_postal_code', 'correspondence_country'] as $nullable) {
            if (array_key_exists($nullable, $clean) && $clean[$nullable] === '') {
                $clean[$nullable] = null;
            }
        }

        // Dependent chain: the names are taken from the directory, and a
        // mismatched parent/child combination is refused (ValidationException).
        $names = $geo->resolveChain([
            'country_id' => $clean['country_id'] ?? null,
            'state_id' => $clean['state_id'] ?? null,
            'district_id' => $clean['district_id'] ?? null,
            'block_id' => $clean['block_id'] ?? null,
            'village_id' => $clean['village_id'] ?? null,
        ]);

        foreach ($names as $key => $name) {
            if ($name !== null) {
                $column = match ($key) {
                    'block' => 'block_tehsil',
                    'village' => 'city',
                    default => $key,
                };
                $clean[$column] = $name;
            }
        }

        // A cleared level must not survive as a stale id.
        foreach (['country_id', 'state_id', 'district_id', 'block_id', 'village_id'] as $key) {
            if (blank($clean[$key] ?? null)) {
                $clean[$key] = null;
            }
        }

        // "Same as permanent" means ONE copy of the address: the correspondence
        // columns are cleared so the two can never disagree.
        $same = array_key_exists('correspondence_same_as_permanent', $data)
            ? (bool) $data['correspondence_same_as_permanent']
            : (bool) ($merged['correspondence_same_as_permanent'] ?? true);

        $clean['correspondence_same_as_permanent'] = $same;

        if ($same) {
            foreach ([
                'correspondence_address_line_1', 'correspondence_address_line_2', 'correspondence_city',
                'correspondence_district', 'correspondence_state', 'correspondence_postal_code',
                'correspondence_country',
            ] as $key) {
                $clean[$key] = null;
            }
        }

        return $clean;
    }

    /**
     * Guardian derivation.
     *
     * - Father / Mother / Self: the guardian block is rebuilt from that person's
     *   own record, so an auto-filled value can never contradict its source;
     * - Other / Guardian (or no choice yet): the values are kept exactly as the
     *   user typed them, because that block is then independently maintained.
     *
     * Switching from a derived relation to "Other" is not data loss: the browser
     * submits the values it was showing (they were already auto-filled), and
     * this method stores them as ordinary guardian data.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $merged
     * @return array<string, mixed>
     */
    private static function normaliseParent(array $data, array $merged): array
    {
        $clean = [];

        foreach (self::PARENT_FIELDS as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                $clean[$key] = is_string($value) ? trim($value) : $value;
            }
        }

        foreach ($clean as $key => $value) {
            if ($value === '') {
                $clean[$key] = null;
            }
        }

        $relation = $clean['guardian_relation'] ?? null;

        $source = match ($relation) {
            'father' => [
                'name' => $clean['father_name'] ?? null,
                'phone' => $clean['father_phone'] ?? null,
                'email' => $clean['father_email'] ?? null,
                'occupation' => $clean['father_occupation'] ?? null,
            ],
            'mother' => [
                'name' => $clean['mother_name'] ?? null,
                'phone' => $clean['mother_phone'] ?? null,
                'email' => $clean['mother_email'] ?? null,
                'occupation' => $clean['mother_occupation'] ?? null,
            ],
            'self' => [
                'name' => self::fullName($merged),
                'phone' => $merged['phone'] ?? null,
                'email' => $merged['email'] ?? null,
                'occupation' => 'Student',
            ],
            default => null,
        };

        if ($source !== null) {
            $clean['guardian_name'] = $source['name'] ?: ($clean['guardian_name'] ?? null);
            $clean['guardian_phone'] = $source['phone'] ?: null;
            $clean['guardian_email'] = $source['email'] ?: null;
            $clean['guardian_occupation'] = $source['occupation'] ?: null;

            // The guardian's postal address is never derived from a parent's
            // record (none is captured for parents), so whatever was entered is
            // preserved untouched.
        }

        return $clean;
    }

    /**
     * Qualification rows + the enrollment context.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normaliseAcademic(array $data): array
    {
        $clean = [];

        foreach (self::ACADEMIC_FIELDS as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                $clean[$key] = is_string($value) ? trim($value) : $value;
            }
        }

        foreach (['section_id', 'semester', 'enrollment_date', 'admission_date'] as $key) {
            if (array_key_exists($key, $clean) && ($clean[$key] === '' || $clean[$key] === null)) {
                $clean[$key] = null;
            }
        }

        $rows = [];

        foreach ((array) ($data['qualifications'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            // An explicitly removed row is dropped from the stored list.
            if (! empty($row['remove'])) {
                continue;
            }

            if (array_key_exists('subjects_text', $row)) {
                $row['subjects'] = $row['subjects_text'];
                unset($row['subjects_text']);
            }

            $rows[] = $row;
        }

        $clean['qualifications'] = $rows;

        return $clean;
    }

    /** Full name built from the step-1 fields of the draft. */
    public static function fullName(array $merged): ?string
    {
        $parts = array_filter([
            trim((string) ($merged['first_name'] ?? '')),
            trim((string) ($merged['middle_name'] ?? '')),
            trim((string) ($merged['last_name'] ?? '')),
        ], fn (string $part) => $part !== '');

        return $parts === [] ? null : implode(' ', $parts);
    }

}
