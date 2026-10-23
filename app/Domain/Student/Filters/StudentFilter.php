<?php

namespace App\Domain\Student\Filters;

use App\Domain\Student\Support\Aadhaar;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Canonical, server-side Student Filter.
 *
 * The service owns three things that must never drift between modules:
 * normalization/validation, query semantics, and the option/applied-filter
 * projections used by the shared Blade component. Every query remains on the
 * caller's tenant-scoped Eloquent builder and every value is bound by Eloquent.
 */
class StudentFilter
{
    public const DEFAULT_FIELDS = [
        'registration_number', 'registration_date', 'enrollment_status', 'student_status',
        'program_id', 'academic_context', 'batch', 'student_name', 'unique_student_id',
        'email', 'apaar_id', 'enrollment_number', 'mobile_number', 'aadhaar_last4',
        'father_name', 'mother_name',
    ];

    public const STUDENT_LIST_FIELDS = [
        'registration_number', 'registration_date', 'academic_year_id', 'department_id',
        'enrollment_status', 'student_status', 'program_id', 'academic_context', 'batch',
        'student_name', 'unique_student_id', 'email', 'apaar_id', 'enrollment_number',
        'mobile_number', 'aadhaar_last4', 'father_name', 'mother_name',
    ];

    public const ADMISSION_APPLICATION_FIELDS = [
        'student_name', 'email', 'mobile_number', 'academic_year_id', 'program_id',
    ];

    public const ADMISSION_FIELDS = [
        'registration_date', 'student_name', 'email', 'mobile_number', 'academic_year_id', 'program_id',
    ];

    private const IDENTITY_FIELDS = [
        'registration_number', 'registration_date_from', 'registration_date_to',
        'student_status', 'student_name', 'unique_student_id', 'email', 'apaar_id',
        'mobile_number', 'aadhaar_last4', 'father_name', 'mother_name',
    ];

    private const ENROLLMENT_FIELDS = [
        'academic_year_id', 'department_id', 'program_id', 'academic_context',
        '_legacy_academic_term_id', 'enrollment_status', 'enrollment_number',
    ];

    /** @return array<string, mixed> */
    public function fromRequest(Request $request, bool $studentListAliases = false, ?User $user = null): array
    {
        return $this->fromArray($request->query(), $studentListAliases, $user);
    }

    /**
     * Validate and normalize a filter array. Invalid standalone values are not
     * applied (the established Student List contract). A reversed, otherwise
     * valid date range is rejected because silently dropping only one edge would
     * misrepresent the requested range.
     *
     * @return array<string, mixed>
     */
    public function fromArray(array $input, bool $studentListAliases = false, ?User $user = null): array
    {
        $raw = [];

        foreach ($this->definitions() as $key => $definition) {
            if (($definition['type'] ?? null) === 'unavailable') {
                continue;
            }

            if (($definition['type'] ?? null) === 'date_range') {
                foreach (['from', 'to'] as $edge) {
                    $requestKey = $definition[$edge.'_request'];
                    $value = $this->scalar($input[$requestKey] ?? null);
                    if ($value !== null) {
                        $raw['registration_date_'.$edge] = $value;
                    }
                }

                continue;
            }

            $requestKey = $definition['request'] ?? $key;
            $value = $this->scalar($input[$requestKey] ?? null);
            if ($value !== null) {
                $raw[$key] = $value;
            }
        }

        if ($studentListAliases) {
            $this->applyStudentListAliases($input, $raw);
        }

        $filters = [];
        $rules = $this->rules();

        foreach ($raw as $key => $value) {
            if (! isset($rules[$key])) {
                continue;
            }

            $validator = Validator::make([$key => $value], [$key => $rules[$key]]);
            if ($validator->fails()) {
                continue;
            }

            $filters[$key] = match ($key) {
                'program_id', 'academic_year_id', 'department_id', '_legacy_academic_term_id' => (int) $value,
                default => $value,
            };
        }

        $this->validateDateRange($filters);

        if (isset($filters['aadhaar_last4']) && ! $this->canFilterSensitiveIdentity($user)) {
            throw new AuthorizationException('You are not authorized to filter by Aadhaar last four digits.');
        }

        return $filters;
    }

    /**
     * Apply the canonical identity and enrollment dimensions to a Student query.
     */
    public function applyToStudents(Builder $students, array $filters): Builder
    {
        $this->applyStudentIdentity($students, $filters);
        $this->applyStudentEnrollmentDimensions($students, $filters);

        return $students;
    }

    /**
     * Apply filters to a StudentEnrollment root. Identity constraints are on its
     * student; every academic dimension is applied to THIS enrollment, avoiding
     * cross-enrollment false positives and duplicate-producing joins.
     */
    public function applyToEnrollments(Builder $enrollments, array $filters): Builder
    {
        if ($this->hasIdentityFilters($filters)) {
            $enrollments->whereHas('student', fn (Builder $student) => $this->applyStudentIdentity($student, $filters));
        }

        $this->applyEnrollmentDimensionsToEnrollment($enrollments, $filters);

        return $enrollments;
    }

    /** Apply through a root model's studentEnrollment relationship. */
    public function applyThroughEnrollment(Builder $query, array $filters, string $relation = 'studentEnrollment'): Builder
    {
        if ($this->hasAny($filters)) {
            $query->whereHas($relation, fn (Builder $enrollment) => $this->applyToEnrollments($enrollment, $filters));
        }

        return $query;
    }

    /** Apply enrollment filters where the parent row already owns the term. */
    public function applyToTermScopedRecord(Builder $query, array $filters, string $termColumn, string $relation = 'studentEnrollment'): Builder
    {
        $this->applyThroughEnrollment($query, $this->exceptAcademicContext($filters, 'term'), $relation);
        [$contextType, $contextId] = $this->academicContext($filters);
        if ($contextType === 'term') {
            $query->where($termColumn, $contextId);
        }

        return $query;
    }

    /** Apply through a root model's student relationship. */
    public function applyThroughStudent(Builder $query, array $filters, string $relation = 'student'): Builder
    {
        if ($this->hasAny($filters)) {
            $query->whereHas($relation, fn (Builder $student) => $this->applyToStudents($student, $filters));
        }

        return $query;
    }

    /**
     * Apply only the canonical fields supported by an admission/application root.
     * Applicant identity is not Student identity, and workflow status stays with
     * the caller's separate admission status control.
     */
    public function applyToAdmissionRecord(Builder $query, array $filters, string $table, bool $registrationDate = false): Builder
    {
        $filters = $this->admissionOnly($filters, $registrationDate);

        if (array_intersect_key($filters, array_flip(['student_name', 'email', 'mobile_number'])) !== []) {
            $query->whereHas('applicant', fn (Builder $applicant) => $this->applyPersonIdentity($applicant, $filters, 'admission_applicants'));
        }
        if (isset($filters['academic_year_id'])) {
            $query->where($table.'.academic_year_id', $filters['academic_year_id']);
        }
        if (isset($filters['program_id'])) {
            $query->where($table.'.program_id', $filters['program_id']);
        }
        if (isset($filters['registration_date_from'])) {
            $query->whereDate($table.'.admission_date', '>=', $filters['registration_date_from']);
        }
        if (isset($filters['registration_date_to'])) {
            $query->whereDate($table.'.admission_date', '<=', $filters['registration_date_to']);
        }

        return $query;
    }

    /**
     * Student columns only. Public for report roots (admission, promotion,
     * transfer) whose own academic columns have report-specific meanings.
     */
    public function applyStudentIdentity(Builder $students, array $filters): Builder
    {
        $contains = [
            'registration_number' => 'students.student_number',
            'father_name' => 'students.father_name',
            'mother_name' => 'students.mother_name',
        ];

        foreach ($contains as $key => $column) {
            if (isset($filters[$key])) {
                $students->where($column, 'like', '%'.$filters[$key].'%');
            }
        }

        if (isset($filters['unique_student_id'])) {
            // The audit found one college-issued identifier, not two columns.
            $students->where('students.student_number', $filters['unique_student_id']);
        }
        if (isset($filters['apaar_id'])) {
            $students->where('students.apaar_id', $filters['apaar_id']);
        }
        if (isset($filters['aadhaar_last4'])) {
            if (! $this->canFilterSensitiveIdentity()) {
                throw new AuthorizationException('You are not authorized to filter by Aadhaar last four digits.');
            }
            $students->where('students.aadhaar_last4', $filters['aadhaar_last4']);
        }
        if (isset($filters['student_status'])) {
            $students->where('students.status', $filters['student_status']);
        }
        if (isset($filters['registration_date_from'])) {
            $students->whereDate('students.admission_date', '>=', $filters['registration_date_from']);
        }
        if (isset($filters['registration_date_to'])) {
            $students->whereDate('students.admission_date', '<=', $filters['registration_date_to']);
        }

        $this->applyPersonIdentity($students, $filters, 'students');

        return $students;
    }

    /** Apply enrollment dimensions to a Student root. */
    public function applyStudentEnrollmentDimensions(Builder $students, array $filters): Builder
    {
        $hasEnrollmentRecord = $this->hasEnrollmentRecordFilters($filters);

        if ($hasEnrollmentRecord) {
            $students->whereHas('enrollments', fn (Builder $enrollment) => $this->applyEnrollmentDimensionsToEnrollment($enrollment, $filters));
        } elseif ($this->termId($filters) !== null || isset($filters['_legacy_academic_term_id'])) {
            // Preserve the original Student List behavior for a term-only search:
            // old academic records may not yet have an enrollment_id link.
            $students->whereHas('academicRecords', function (Builder $record) use ($filters): void {
                $this->applyTermToAcademicRecord($record, $filters);
            });
        }

        return $students;
    }

    /** Apply all dimensions to one enrollment builder (root or correlated). */
    public function applyEnrollmentDimensionsToEnrollment(Builder $enrollment, array $filters): Builder
    {
        if (isset($filters['academic_year_id'])) {
            $enrollment->where('student_enrollments.academic_year_id', $filters['academic_year_id']);
        }
        if (isset($filters['program_id'])) {
            $enrollment->where('student_enrollments.program_id', $filters['program_id']);
        }
        if (isset($filters['department_id'])) {
            $enrollment->whereHas('program', fn (Builder $program) => $program->where('programs.department_id', $filters['department_id']));
        }
        if (isset($filters['enrollment_status'])) {
            $statuses = $this->statusList($filters['enrollment_status']);
            if ($statuses !== []) {
                $enrollment->whereIn('student_enrollments.status', $statuses);
            }
        }
        if (isset($filters['enrollment_number'])) {
            $enrollment->where('student_enrollments.enrollment_number', $filters['enrollment_number']);
        }

        [$type, $id] = $this->academicContext($filters);
        if ($type === 'section') {
            $enrollment->where('student_enrollments.section_id', $id);
        }

        if ($type === 'term' || isset($filters['_legacy_academic_term_id'])) {
            $enrollment->whereHas('academicRecords', function (Builder $record) use ($filters): void {
                $this->applyTermToAcademicRecord($record, $filters);
            });
        }

        return $enrollment;
    }

    public function hasAny(array $filters): bool
    {
        return $this->hasIdentityFilters($filters) || $this->hasEnrollmentFilters($filters);
    }

    /** @return array<string, mixed> Canonical student-column filters only. */
    public function identityOnly(array $filters): array
    {
        return array_intersect_key($filters, array_flip(self::IDENTITY_FIELDS));
    }

    /** @return array<string, mixed> Canonical enrollment/academic filters only. */
    public function enrollmentOnly(array $filters): array
    {
        return array_intersect_key($filters, array_flip(self::ENROLLMENT_FIELDS));
    }

    /** Remove a context already enforced by the parent attendance/exam/result row. */
    public function exceptAcademicContext(array $filters, string $handledType): array
    {
        if ($this->academicContext($filters)[0] === $handledType) {
            unset($filters['academic_context']);
        }

        return $filters;
    }

    public function hasIdentityFilters(array $filters): bool
    {
        foreach (self::IDENTITY_FIELDS as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    public function hasEnrollmentFilters(array $filters): bool
    {
        foreach (self::ENROLLMENT_FIELDS as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert validated canonical filters back to their public request keys.
     * Useful for hidden POST inputs and redirects; invalid/raw input never
     * reaches this projection.
     *
     * @return array<string, scalar>
     */
    public function requestParameters(array $filters): array
    {
        $parameters = [];
        foreach ($this->definitions() as $key => $definition) {
            if (($definition['type'] ?? null) === 'unavailable') {
                continue;
            }
            if (($definition['type'] ?? null) === 'date_range') {
                foreach (['from', 'to'] as $edge) {
                    $filterKey = 'registration_date_'.$edge;
                    if (isset($filters[$filterKey])) {
                        $parameters[$definition[$edge.'_request']] = $filters[$filterKey];
                    }
                }
                continue;
            }
            if (isset($filters[$key])) {
                $parameters[$definition['request'] ?? $key] = $filters[$key];
            }
        }

        return $parameters;
    }

    /**
     * Tenant-scoped fields/options and the safe applied-filter summary consumed
     * by resources/views/components/student-filter.blade.php.
     *
     * @return array{studentFilterFields: array, studentFilterOptions: array, studentFilterValues: array, studentFilterApplied: array, studentFilterParameters: array}
     */
    public function viewData(array $filters, ?array $fieldKeys = null, ?User $user = null): array
    {
        $fieldKeys ??= self::DEFAULT_FIELDS;
        $fields = [];
        $definitions = $this->definitions();

        foreach ($fieldKeys as $key) {
            if (! isset($definitions[$key])) {
                continue;
            }
            if (($definitions[$key]['sensitive'] ?? false) && ! $this->canFilterSensitiveIdentity($user)) {
                continue;
            }
            $fields[$key] = $definitions[$key];
        }

        $options = $this->options($fields);

        return [
            'studentFilterFields' => $fields,
            'studentFilterOptions' => $options,
            'studentFilterValues' => $filters,
            'studentFilterApplied' => $this->appliedSummary($filters, $fields, $options),
            'studentFilterParameters' => $this->requestParameters($filters),
        ];
    }

    /** Redact identity-filter values before writing audit context. */
    public function auditSafe(array $filters): array
    {
        if (isset($filters['aadhaar_last4'])) {
            $filters['aadhaar_last4'] = '[last-four filter supplied]';
        }

        return $filters;
    }

    public function canFilterSensitiveIdentity(?User $user = null): bool
    {
        $user ??= auth()->user();
        $permission = (string) config('student-filter.sensitive_permission', 'students.view_sensitive_identity');

        return $user?->hasPermission($permission) ?? false;
    }

    /** @return array<string, array<string, mixed>> */
    private function definitions(): array
    {
        return config('student-filter.fields', []);
    }

    /** @return array<string, mixed> */
    private function admissionOnly(array $filters, bool $registrationDate): array
    {
        $keys = self::ADMISSION_APPLICATION_FIELDS;
        if ($registrationDate) {
            $keys = [...$keys, 'registration_date_from', 'registration_date_to'];
        }

        return array_intersect_key($filters, array_flip($keys));
    }

    private function applyPersonIdentity(Builder $person, array $filters, string $table): void
    {
        if (isset($filters['student_name'])) {
            $name = '%'.$filters['student_name'].'%';
            $person->where(function (Builder $query) use ($name, $table): void {
                $query->where($table.'.first_name', 'like', $name)
                    ->orWhere($table.'.middle_name', 'like', $name)
                    ->orWhere($table.'.last_name', 'like', $name);
            });
        }
        if (isset($filters['email'])) {
            $person->where($table.'.email', 'like', '%'.$filters['email'].'%');
        }
        if (isset($filters['mobile_number'])) {
            $person->where($table.'.phone', $filters['mobile_number']);
        }
    }

    private function applyStudentListAliases(array $input, array &$raw): void
    {
        if (! isset($raw['student_status'])) {
            $status = $this->scalar($input['status'] ?? null);
            if ($status !== null) {
                $raw['student_status'] = $status;
            }
        }

        if (! isset($raw['registration_date_from']) && ! isset($raw['registration_date_to'])) {
            $exact = $this->scalar($input['admission_date'] ?? null);
            if ($exact !== null) {
                $raw['registration_date_from'] = $exact;
                $raw['registration_date_to'] = $exact;
            }
        }

        if (! isset($raw['academic_context'])) {
            $sectionId = $this->positiveId($input['section_id'] ?? null);
            $termId = $this->positiveId($input['academic_term_id'] ?? null);

            if ($sectionId !== null) {
                $raw['academic_context'] = 'section:'.$sectionId;
            }
            if ($termId !== null) {
                if ($sectionId === null) {
                    $raw['academic_context'] = 'term:'.$termId;
                } else {
                    // Legacy bookmarks could contain both controls. Preserve both
                    // constraints without putting a second canonical control back.
                    $raw['_legacy_academic_term_id'] = (string) $termId;
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        $text = static fn (int $max = 150): array => ['string', 'max:'.$max];

        return [
            'registration_number' => $text(100),
            'registration_date_from' => ['date_format:Y-m-d'],
            'registration_date_to' => ['date_format:Y-m-d'],
            'enrollment_status' => ['string', function (string $attribute, mixed $value, callable $fail): void {
                if ($this->statusList((string) $value) === []) {
                    $fail('The selected academic status is invalid.');
                }
            }],
            'student_status' => [Rule::in(Student::STATUSES)],
            'program_id' => ['integer', 'min:1'],
            'academic_year_id' => ['integer', 'min:1'],
            'department_id' => ['integer', 'min:1'],
            'academic_context' => ['regex:/^(?:section|term):[1-9][0-9]*$/D'],
            '_legacy_academic_term_id' => ['integer', 'min:1'],
            'student_name' => $text(),
            'unique_student_id' => $text(100),
            'email' => $text(),
            'apaar_id' => ['string', 'max:30', 'regex:/^[A-Za-z0-9\/-]+$/D'],
            'enrollment_number' => $text(100),
            'mobile_number' => ['string', 'max:30', 'regex:/^[0-9+().\-\s]+$/D'],
            'aadhaar_last4' => ['regex:/^[0-9]{4}$/D'],
            'father_name' => $text(),
            'mother_name' => $text(),
        ];
    }

    private function validateDateRange(array $filters): void
    {
        if (! isset($filters['registration_date_from'], $filters['registration_date_to'])) {
            return;
        }

        if ($filters['registration_date_from'] > $filters['registration_date_to']) {
            throw ValidationException::withMessages([
                'admission_date_to' => 'The REG. DATE To date must be on or after the From date.',
            ]);
        }
    }

    private function applyTermToAcademicRecord(Builder $record, array $filters): void
    {
        [$type, $contextId] = $this->academicContext($filters);
        $termIds = [];
        if ($type === 'term') {
            $termIds[] = $contextId;
        }
        if (isset($filters['_legacy_academic_term_id'])) {
            $termIds[] = (int) $filters['_legacy_academic_term_id'];
        }

        if ($termIds !== []) {
            $record->whereIn('student_academic_records.academic_term_id', array_unique($termIds));
        }
        if (isset($filters['academic_year_id'])) {
            $record->where('student_academic_records.academic_year_id', $filters['academic_year_id']);
        }
    }

    private function hasEnrollmentRecordFilters(array $filters): bool
    {
        foreach (['academic_year_id', 'department_id', 'program_id', 'enrollment_status', 'enrollment_number'] as $key) {
            if (isset($filters[$key])) {
                return true;
            }
        }

        return $this->academicContext($filters)[0] === 'section';
    }

    /** @return array{0: string|null, 1: int|null} */
    public function academicContext(array $filters): array
    {
        $value = $filters['academic_context'] ?? null;
        if (! is_string($value) || ! preg_match('/^(section|term):([1-9][0-9]*)$/D', $value, $matches)) {
            return [null, null];
        }

        return [$matches[1], (int) $matches[2]];
    }

    private function termId(array $filters): ?int
    {
        [$type, $id] = $this->academicContext($filters);

        return $type === 'term' ? $id : null;
    }

    /** @return array<int, string> */
    private function statusList(string $value): array
    {
        $items = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), fn ($item) => $item !== '')));
        if ($items === [] || count(array_diff($items, StudentEnrollment::STATUSES)) > 0) {
            return [];
        }

        return $items;
    }

    private function scalar(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function positiveId(mixed $value): ?int
    {
        $value = $this->scalar($value);

        return $value !== null && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @return array<string, array<int, array{value: string|int, label: string}>> */
    private function options(array $fields): array
    {
        $needed = array_unique(array_filter(array_map(fn (array $field) => $field['options'] ?? null, $fields)));
        $options = [];

        foreach ($needed as $key) {
            $options[$key] = match ($key) {
                'student_statuses' => $this->enumOptions(Student::STATUSES),
                'enrollment_statuses' => $this->enumOptions(StudentEnrollment::STATUSES),
                'programs' => Program::query()->orderBy('name')->orderBy('id')
                    ->get(['id', 'name', 'code'])->map(fn (Program $program) => [
                        'value' => $program->id,
                        'label' => $program->name.' ('.$program->code.')',
                    ])->values()->all(),
                'academic_years' => AcademicYear::query()->orderByDesc('starts_on')->orderByDesc('id')
                    ->get(['id', 'name', 'code'])->map(fn (AcademicYear $year) => [
                        'value' => $year->id,
                        'label' => $year->name.' ('.$year->code.')',
                    ])->values()->all(),
                'departments' => Department::query()->orderBy('name')->orderBy('id')
                    ->get(['id', 'name', 'code'])->map(fn (Department $department) => [
                        'value' => $department->id,
                        'label' => $department->name.' ('.$department->code.')',
                    ])->values()->all(),
                'academic_contexts' => $this->academicContextOptions(),
                default => [],
            };
        }

        return $options;
    }

    /** @return array<int, array{value: string, label: string, group: string}> */
    private function academicContextOptions(): array
    {
        $terms = AcademicTerm::query()->with('academicYear:id,name')
            ->orderByDesc('academic_year_id')->orderBy('sequence')->orderBy('id')
            ->get(['id', 'name', 'code', 'academic_year_id'])
            ->map(fn (AcademicTerm $term) => [
                'value' => 'term:'.$term->id,
                'label' => $term->name.' ('.$term->code.') — '.$term->academicYear?->name,
                'group' => 'Semesters',
            ]);

        $sections = Section::query()->with(['academicYear:id,name', 'program:id,name'])
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'code', 'academic_year_id', 'program_id'])
            ->map(fn (Section $section) => [
                'value' => 'section:'.$section->id,
                'label' => $section->name.' ('.$section->code.') — '.($section->academicYear?->name ?? '—').' / '.($section->program?->name ?? '—'),
                'group' => 'Sections',
            ]);

        return $terms->concat($sections)->values()->all();
    }

    /** @return array<int, array{value: string, label: string}> */
    private function enumOptions(array $values): array
    {
        return array_map(fn (string $value) => [
            'value' => $value,
            'label' => ucfirst(str_replace('_', ' ', $value)),
        ], $values);
    }

    private function appliedSummary(array $filters, array $fields, array $options): array
    {
        $summary = [];

        foreach ($fields as $key => $definition) {
            if ($key === 'registration_date') {
                $from = $filters['registration_date_from'] ?? null;
                $to = $filters['registration_date_to'] ?? null;
                if ($from || $to) {
                    $summary[] = [
                        'label' => $definition['label'],
                        'value' => ($from ? $this->displayDate($from) : '…').' – '.($to ? $this->displayDate($to) : '…'),
                    ];
                }
                continue;
            }

            if (! isset($filters[$key])) {
                continue;
            }

            $value = $filters[$key];
            if ($key === 'aadhaar_last4') {
                $value = Aadhaar::mask((string) $value);
            } elseif ($key === 'enrollment_status') {
                $value = implode(', ', array_map(fn ($status) => ucfirst($status), $this->statusList((string) $value)));
            } elseif (isset($definition['options'])) {
                $choices = $options[$definition['options']] ?? [];
                foreach ($choices as $choice) {
                    if ((string) $choice['value'] === (string) $value) {
                        $value = $choice['label'];
                        break;
                    }
                }
                if ($key === 'academic_context' && isset($filters['_legacy_academic_term_id'])) {
                    $value .= ' + Term #'.$filters['_legacy_academic_term_id'];
                }
            }

            $summary[] = ['label' => $definition['label'], 'value' => (string) $value];
        }

        return $summary;
    }

    private function displayDate(string $date): string
    {
        return Carbon::createFromFormat('!Y-m-d', $date)->format('d/m/Y');
    }
}
