<?php

namespace App\Http\Requests\Student;

use App\Domain\Student\Rules\ValidAadhaar;
use App\Domain\Student\Services\RegistrationDocumentRequirements;
use App\Domain\Student\Support\Aadhaar;
use App\Http\Requests\Student\Concerns\HandlesStudentProfile;
use App\Models\Student;
use App\Models\StudentRegistrationDraft;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Unified student registration submission.
 *
 * Reuses the full Student profile validation from StoreStudentRequest, but the
 * browser can never choose the lifecycle or approval status: the server creates
 * a pending, inactive Student and ApprovalService decides the outcome.
 */
class StoreStudentRegistrationRequest extends FormRequest
{
    use HandlesStudentProfile;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Student::class) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $rules = (new StoreStudentRequest())->rules();

        // A repeated submission of the SAME pending registration is handled
        // idempotently by StudentRegistrationService, so a pending row must not
        // trip the unique phone/Aadhaar checks. Approved/legacy rows still do.
        $operationalStudent = function ($query): void {
            // Legacy/approved students block reuse. Pending and rejected
            // registrations may be resubmitted without creating a second
            // person record; StudentRegistrationService reuses that row.
            $query->whereNull('registration_status')
                ->orWhereNotIn('registration_status', [
                    Student::REGISTRATION_PENDING,
                    Student::REGISTRATION_REJECTED,
                ]);
        };

        $rules['phone'] = [
            'nullable', 'string', 'max:30',
            function (string $attribute, mixed $value, callable $fail) use ($collegeId, $operationalStudent): void {
                if (! is_string($value) || trim($value) === '') {
                    return;
                }

                $exists = Student::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('phone', $value)
                    ->whereNull('deleted_at')
                    ->where($operationalStudent)
                    ->exists();

                if ($exists) {
                    $fail('The phone has already been taken.');
                }
            },
        ];

        $rules['aadhaar_number'] = [
            'nullable', 'string', 'max:20', new ValidAadhaar(),
            function (string $attribute, mixed $value, callable $fail) use ($collegeId, $operationalStudent): void {
                $digits = Aadhaar::normalise($value);
                if ($digits === null) {
                    return;
                }

                $exists = Student::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('aadhaar_hash', Aadhaar::hash($digits))
                    ->whereNull('deleted_at')
                    ->where($operationalStudent)
                    ->exists();

                if ($exists) {
                    $fail('This Aadhaar number is already registered.');
                }
            },
        ];

        // Status and registration timestamps are server-owned.
        $rules['status'] = ['prohibited'];
        $rules['registration_status'] = ['prohibited'];
        $rules['registration_submitted_at'] = ['prohibited'];

        // Registration captures the academic context for the approval payload;
        // no enrollment row is created until approval. The submitter does not
        // need StudentEnrollment create permission because the approver performs
        // that write through StudentService inside the approval transaction.
        $rules['academic_year_id'] = [
            'required', 'integer',
            Rule::exists('academic_years', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
        ];
        $rules['program_id'] = [
            'required', 'integer',
            Rule::exists('programs', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
        ];
        $rules['section_id'] = [
            'nullable', 'integer',
            Rule::exists('sections', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
        ];
        $rules['enrollment_date'] = ['nullable', 'date'];

        // Declarations are independent of files and must belong to the active
        // college. Nothing here marks a document received or verified.
        $rules['registration_document_type_ids'] = ['nullable', 'array'];
        $rules['registration_document_type_ids.*'] = [
            'required', 'integer', 'distinct',
            Rule::exists('admission_document_types', 'id')
                ->where('college_id', $collegeId)->where('status', 'active')->whereNull('deleted_at'),
        ];
        $rules['registration_draft_id'] = [
            'nullable', 'uuid',
            Rule::exists('student_registration_drafts', 'id')
                ->where('college_id', $collegeId)->where('created_by', $this->user()?->getKey()),
        ];

        // Preserve the existing direct/batch upload API and its hard limits.
        $rules['registration_document_type_id'] = [
            'nullable', 'integer',
            Rule::exists('admission_document_types', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
        ];
        $rules['registration_documents'] = ['nullable', 'array', 'max:10'];
        $rules['registration_documents.*'] = ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];

        return $rules;
    }

    /** Merge only the encrypted draft's sensitive values into validation data;
     * they are never echoed into the form or flashed as new request input.
     */
    public function validationData(): array
    {
        $data = parent::validationData();
        $id = $this->input('registration_draft_id');
        if (is_string($id) && Str::isUuid($id) && $this->user()) {
            $draft = StudentRegistrationDraft::query()
                ->where('college_id', app(TenantContext::class)->id())
                ->where('created_by', $this->user()->getKey())
                ->whereKey($id)->first();
            if ($draft) {
                $saved = $draft->data ?? [];
                if (! $this->boolean('registration_clear_aadhaar') && blank($data['aadhaar_number'] ?? null) && filled($saved['aadhaar_number'] ?? null)) {
                    $data['aadhaar_number'] = $saved['aadhaar_number'];
                }
                if (! $this->boolean('registration_clear_govt_id') && filled($data['govt_id_type'] ?? null)
                    && blank($data['govt_id_number'] ?? null) && filled($saved['govt_id_number'] ?? null)) {
                    $data['govt_id_number'] = $saved['govt_id_number'];
                }
                if (! $this->has('registration_declarations_present') && ! array_key_exists('registration_document_type_ids', $data)) {
                    $data['registration_document_type_ids'] = $saved['registration_document_type_ids'] ?? [];
                }
            }
        }
        if ($this->boolean('registration_clear_aadhaar')) {
            $data['aadhaar_number'] = null;
        }
        if ($this->boolean('registration_clear_govt_id')) {
            $data['govt_id_type'] = null;
            $data['govt_id_number'] = null;
        }
        if ($this->has('registration_declarations_present') && ! array_key_exists('registration_document_type_ids', $data)) {
            $data['registration_document_type_ids'] = [];
        }

        return $data;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();
            if (filled($data['govt_id_type'] ?? null) && blank($data['govt_id_number'] ?? null)) {
                $validator->errors()->add('govt_id_number', 'Enter the government ID number for the selected type.');
            }

            $selected = $data['registration_document_type_ids'] ?? [];
            if (! is_array($selected)) {
                return; // The array rule reports malformed input.
            }
            $programId = is_numeric($data['program_id'] ?? null) ? (int) $data['program_id'] : null;
            $missing = app(RegistrationDocumentRequirements::class)->missing(
                (int) app(TenantContext::class)->id(), $programId, $selected,
            );
            if ($missing->isNotEmpty()) {
                $validator->errors()->add('registration_document_type_ids',
                    'Select the required documents: '.$missing->pluck('name')->implode(', ').'.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'college_id',
            'student_number',
            'admission_application_id',
            'created_by',
            'updated_by',
            'photo_path',
            'aadhaar_last4',
            'aadhaar_hash',
            'status',
            'registration_status',
            'registration_submitted_at',
        ] as $key) {
            $this->request->remove($key);
        }
    }
}
