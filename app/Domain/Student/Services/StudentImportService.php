<?php

namespace App\Domain\Student\Services;

use App\Domain\Student\Rules\ValidAadhaar;
use App\Domain\Student\Support\Aadhaar;
use App\Models\Section;
use App\Models\Student;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Student bulk registration / CSV import.
 *
 * This is a dedicated create-many workflow, not a Student List bulk action.
 * Rows are streamed from disk, the whole file is validated before any write,
 * and every accepted row is created through {@see StudentService::createStudent()}
 * so student numbers, first enrollments, tenant checks and duplicate-active
 * enrollment protection stay on the existing path.
 *
 * Sensitive identity values (Aadhaar, government ID numbers) never appear in
 * validation messages, cache payloads, audit summaries or exceptions.
 */
class StudentImportService
{
    public const CACHE_PREFIX = 'student-import:';

    public const CACHE_TTL_SECONDS = 1800;

    public const MAX_KB = 10240;

    public const DISK = 'local';

    public const DIRECTORY = 'student-imports';

    /**
     * CSV headers. These are the Student Create form fields that the current
     * schema actually stores (plus the optional first-enrollment master ids).
     * Photograph is omitted: a CSV cannot carry a file upload.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'phone',
        'alternate_phone',
        'gender',
        'category',
        'date_of_birth',
        'admission_date',
        'status',
        'father_name',
        'mother_name',
        'guardian_name',
        'guardian_relation',
        'guardian_phone',
        'guardian_email',
        'guardian_occupation',
        'guardian_address',
        'aadhaar_number',
        'apaar_id',
        'govt_id_type',
        'govt_id_number',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'emergency_contact_name',
        'emergency_contact_phone',
        'previous_school_name',
        'previous_school_board',
        'previous_qualification',
        'previous_exam_year',
        'previous_percentage',
        'academic_year_id',
        'program_id',
        'section_id',
        'enrollment_date',
        'blood_group',
        'nationality',
        'mother_tongue',
        'remarks',
    ];

    /** Fields whose submitted values must never be echoed back. */
    private const SENSITIVE_FIELDS = ['aadhaar_number', 'govt_id_number'];

    public function __construct(
        private readonly StudentService $students,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * UTF-8 CSV template with a header row only.
     *
     * @return resource
     */
    public function templateHandle()
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::COLUMNS);
        rewind($handle);

        return $handle;
    }

    /**
     * Persist an uploaded CSV under a college-scoped key and return the token.
     */
    public function storeUpload(string $absolutePath, int $collegeId, int $userId): string
    {
        $token = Str::random(40);
        $relative = self::DIRECTORY.'/'.$collegeId.'/'.$token.'.csv';

        Storage::disk(self::DISK)->put($relative, file_get_contents($absolutePath));

        Cache::put($this->cacheKey($token), [
            'college_id' => $collegeId,
            'user_id' => $userId,
            'path' => $relative,
        ], self::CACHE_TTL_SECONDS);

        return $token;
    }

    /**
     * @return array{college_id: int, user_id: int, path: string}|null
     */
    public function pending(string $token): ?array
    {
        $payload = Cache::get($this->cacheKey($token));

        return is_array($payload) ? $payload : null;
    }

    public function forget(string $token): void
    {
        $pending = $this->pending($token);

        if ($pending !== null) {
            Storage::disk(self::DISK)->delete($pending['path']);
        }

        Cache::forget($this->cacheKey($token));
    }

    /**
     * Stream-validate the complete file. Nothing is written.
     *
     * @return array{ok: bool, rows: int, errors: list<array{row: int, field: string, message: string}>}
     */
    public function validatePath(string $absolutePath, int $collegeId, bool $canCreateEnrollment): array
    {
        $errors = [];
        $rows = 0;
        $seenPhones = [];
        $seenAadhaarHashes = [];

        $handle = fopen($absolutePath, 'r');

        if ($handle === false) {
            return ['ok' => false, 'rows' => 0, 'errors' => [[
                'row' => 0,
                'field' => 'file',
                'message' => 'The CSV file could not be read.',
            ]]];
        }

        try {
            $header = $this->readHeader($handle);

            if ($header['error'] !== null) {
                return ['ok' => false, 'rows' => 0, 'errors' => [[
                    'row' => 1,
                    'field' => 'file',
                    'message' => $header['error'],
                ]]];
            }

            $line = 1;

            while (($raw = fgetcsv($handle)) !== false) {
                $line++;

                if ($this->isEmptyRow($raw)) {
                    continue;
                }

                $rows++;
                $data = $this->rowToData($header['columns'], $raw);
                $data = $this->normaliseDates($data, $line, $errors);

                $rowErrors = $this->validateRow($data, $collegeId, $canCreateEnrollment, $line, $seenPhones, $seenAadhaarHashes);

                foreach ($rowErrors as $error) {
                    $errors[] = $error;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($rows === 0 && $errors === []) {
            $errors[] = [
                'row' => 0,
                'field' => 'file',
                'message' => 'The CSV file does not contain any student rows.',
            ];
        }

        return [
            'ok' => $errors === [],
            'rows' => $rows,
            'errors' => $errors,
        ];
    }

    /**
     * Re-validate then create every row inside one transaction.
     *
     * A single failing row rolls the whole import back — no partial students.
     *
     * @return array{created: int}
     */
    public function importPath(string $absolutePath, int $collegeId, bool $canCreateEnrollment, ?string $academicYearCode = null): array
    {
        $preview = $this->validatePath($absolutePath, $collegeId, $canCreateEnrollment);

        if (! $preview['ok']) {
            throw ValidationException::withMessages([
                'file' => 'The CSV file is not valid. Re-upload it and fix the reported rows before importing.',
            ]);
        }

        $created = 0;

        try {
            DB::transaction(function () use ($absolutePath, $collegeId, $canCreateEnrollment, $academicYearCode, &$created): void {
                $handle = fopen($absolutePath, 'r');

                if ($handle === false) {
                    throw ValidationException::withMessages(['file' => 'The CSV file could not be read.']);
                }

                try {
                    $header = $this->readHeader($handle);

                    if ($header['error'] !== null) {
                        throw ValidationException::withMessages(['file' => $header['error']]);
                    }

                    while (($raw = fgetcsv($handle)) !== false) {
                        if ($this->isEmptyRow($raw)) {
                            continue;
                        }

                        $data = $this->rowToData($header['columns'], $raw);
                        $data = $this->convertDates($data);

                        if (! $canCreateEnrollment) {
                            unset($data['academic_year_id'], $data['program_id'], $data['section_id'], $data['enrollment_date']);
                        }

                        $this->students->createStudent($data, $collegeId, $data['admission_date'] ?? null, $academicYearCode);
                        $created++;
                    }
                } finally {
                    fclose($handle);
                }
            });
        } catch (Throwable $e) {
            throw $e;
        }

        $this->audit->record('students.imported', null, [], [
            'created' => $created,
            'college_id' => $collegeId,
        ]);

        return ['created' => $created];
    }

    /**
     * @return array{columns: list<string>, error: string|null}
     */
    private function readHeader($handle): array
    {
        $raw = fgetcsv($handle);

        if ($raw === false) {
            return ['columns' => [], 'error' => 'The CSV file is empty.'];
        }

        if (isset($raw[0])) {
            $raw[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw[0]) ?? (string) $raw[0];
        }

        $columns = array_map(static fn ($value) => trim((string) $value), $raw);

        if ($columns !== self::COLUMNS) {
            return [
                'columns' => $columns,
                'error' => 'The CSV header does not match the official template. Download the template and keep the column names unchanged.',
            ];
        }

        return ['columns' => $columns, 'error' => null];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<mixed>  $raw
     * @return array<string, mixed>
     */
    private function rowToData(array $columns, array $raw): array
    {
        $data = [];

        foreach ($columns as $index => $column) {
            $value = $raw[$index] ?? '';
            $value = is_string($value) ? trim($value) : $value;
            $data[$column] = $value === '' ? null : $value;
        }

        return $data;
    }

    /**
     * @param  list<mixed>  $raw
     */
    private function isEmptyRow(array $raw): bool
    {
        foreach ($raw as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Convert user-facing DD/MM/YYYY dates to Y-m-d; record a row error when
     * the value is neither convention.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{row: int, field: string, message: string}>  $errors
     * @return array<string, mixed>
     */
    private function normaliseDates(array $data, int $line, array &$errors): array
    {
        foreach (['date_of_birth', 'admission_date', 'enrollment_date'] as $field) {
            if (! isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
                $data[$field] = null;

                continue;
            }

            $converted = $this->parseDate((string) $data[$field]);

            if ($converted === null) {
                $errors[] = [
                    'row' => $line,
                    'field' => $field,
                    'message' => 'Use the DD/MM/YYYY date format.',
                ];
                $data[$field] = 'invalid-date';

                continue;
            }

            $data[$field] = $converted;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function convertDates(array $data): array
    {
        foreach (['date_of_birth', 'admission_date', 'enrollment_date'] as $field) {
            if (! isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
                $data[$field] = null;

                continue;
            }

            $data[$field] = $this->parseDate((string) $data[$field]);
        }

        return $data;
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);

        foreach (['d/m/Y', 'j/n/Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, int>  $seenPhones
     * @param  array<string, int>  $seenAadhaarHashes
     * @return list<array{row: int, field: string, message: string}>
     */
    private function validateRow(
        array $data,
        int $collegeId,
        bool $canCreateEnrollment,
        int $line,
        array &$seenPhones,
        array &$seenAadhaarHashes,
    ): array {
        $errors = [];

        $validator = Validator::make($data, $this->rules($collegeId));

        $validator->after(function ($validator) use ($data, $collegeId, $canCreateEnrollment, $line, &$seenPhones, &$seenAadhaarHashes): void {
            $this->rejectDuplicateAadhaar($validator, $data, $collegeId, $seenAadhaarHashes, $line);
            $this->rejectDuplicatePhoneInFile($validator, $data, $seenPhones, $line);
            $this->requireGovtIdPair($validator, $data);
            $this->rejectEnrollmentWithoutPermission($validator, $data, $canCreateEnrollment);
            $this->rejectInvalidEnrollmentCombination($validator, $data, $collegeId);
        });

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $errors[] = [
                        'row' => $line,
                        'field' => in_array($field, self::SENSITIVE_FIELDS, true) ? $field : $field,
                        'message' => $this->redact($field, $message),
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * Same shape and tenant-scoped exists rules as StoreStudentRequest.
     *
     * @return array<string, mixed>
     */
    private function rules(int $collegeId): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('students', 'phone')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'gender' => ['nullable', 'string', 'max:20', Rule::in(Student::GENDERS)],
            'category' => ['nullable', 'string', 'max:30', Rule::in(Student::CATEGORIES)],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'admission_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(Student::STATUSES)],
            'father_name' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_relation' => ['nullable', 'string', 'max:50', Rule::in(Student::GUARDIAN_RELATIONS)],
            'guardian_phone' => ['nullable', 'string', 'max:30'],
            'guardian_email' => ['nullable', 'email', 'max:255'],
            'guardian_occupation' => ['nullable', 'string', 'max:150'],
            'guardian_address' => ['nullable', 'string', 'max:2000'],
            'aadhaar_number' => ['nullable', 'string', 'max:20', new ValidAadhaar()],
            'apaar_id' => ['nullable', 'string', 'max:30', 'regex:/^[0-9]{12}$/'],
            'govt_id_type' => ['nullable', 'string', 'max:30', 'required_with:govt_id_number', Rule::in(Student::GOVT_ID_TYPES)],
            'govt_id_number' => ['nullable', 'string', 'max:100'],
            'address_line_1' => ['nullable', 'string', 'max:2000'],
            'address_line_2' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'previous_school_name' => ['nullable', 'string', 'max:255'],
            'previous_school_board' => ['nullable', 'string', 'max:100'],
            'previous_qualification' => ['nullable', 'string', 'max:100'],
            'previous_exam_year' => ['nullable', 'integer', 'between:1900,2100'],
            'previous_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'), 'required_with:program_id,section_id,enrollment_date'],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'section_id' => ['nullable', 'integer', Rule::exists('sections', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'enrollment_date' => ['nullable', 'date'],
            'blood_group' => ['nullable', 'string', 'max:10', Rule::in(Student::BLOOD_GROUPS)],
            'nationality' => ['nullable', 'string', 'max:100'],
            'mother_tongue' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function rejectDuplicateAadhaar($validator, array $data, int $collegeId, array &$seenAadhaarHashes, int $line): void
    {
        $digits = Aadhaar::normalise($data['aadhaar_number'] ?? null);

        if ($digits === null) {
            return;
        }

        $hash = Aadhaar::hash($digits);

        if (isset($seenAadhaarHashes[$hash])) {
            $validator->errors()->add(
                'aadhaar_number',
                'This Aadhaar number is already used on row '.$seenAadhaarHashes[$hash].' of this file.'
            );

            return;
        }

        $seenAadhaarHashes[$hash] = $line;

        $exists = Student::query()->where('aadhaar_hash', $hash)->exists();

        if ($exists) {
            $validator->errors()->add(
                'aadhaar_number',
                'This Aadhaar number is already recorded for another student in this college.'
            );
        }
    }

    private function rejectDuplicatePhoneInFile($validator, array $data, array &$seenPhones, int $line): void
    {
        $phone = $data['phone'] ?? null;

        if (! is_string($phone) || $phone === '') {
            return;
        }

        if (isset($seenPhones[$phone])) {
            $validator->errors()->add(
                'phone',
                'This mobile number is already used on row '.$seenPhones[$phone].' of this file.'
            );

            return;
        }

        $seenPhones[$phone] = $line;
    }

    private function requireGovtIdPair($validator, array $data): void
    {
        if (blank($data['govt_id_type'] ?? null) || filled($data['govt_id_number'] ?? null)) {
            return;
        }

        $validator->errors()->add('govt_id_number', 'Enter the government ID number for the selected type.');
    }

    private function rejectEnrollmentWithoutPermission($validator, array $data, bool $canCreateEnrollment): void
    {
        if ($canCreateEnrollment) {
            return;
        }

        foreach (['academic_year_id', 'program_id', 'section_id', 'enrollment_date'] as $key) {
            if (! empty($data[$key])) {
                $validator->errors()->add($key, 'You do not have permission to create the initial enrollment.');
            }
        }
    }

    private function rejectInvalidEnrollmentCombination($validator, array $data, int $collegeId): void
    {
        $yearId = $data['academic_year_id'] ?? null;
        $programId = $data['program_id'] ?? null;
        $sectionId = $data['section_id'] ?? null;

        if ($sectionId === null || $sectionId === '') {
            return;
        }

        $section = Section::query()->find($sectionId);

        if (! $section) {
            return;
        }

        if ($yearId && (int) $section->academic_year_id !== (int) $yearId) {
            $validator->errors()->add('section_id', 'The selected section does not belong to the selected academic year.');
        }

        if ($programId && (int) $section->program_id !== (int) $programId) {
            $validator->errors()->add('section_id', 'The selected section does not belong to the selected program.');
        }
    }

    private function redact(string $field, string $message): string
    {
        if (in_array($field, self::SENSITIVE_FIELDS, true)) {
            return preg_replace('/\d{4,}/', '****', $message) ?? $message;
        }

        return $message;
    }

    private function cacheKey(string $token): string
    {
        $collegeId = app(TenantContext::class)->id();

        return self::CACHE_PREFIX.$collegeId.':'.$token;
    }
}
