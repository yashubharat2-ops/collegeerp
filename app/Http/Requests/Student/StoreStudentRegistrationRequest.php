<?php

namespace App\Http\Requests\Student;

use App\Domain\Student\Rules\ValidAadhaar;
use App\Domain\Student\Support\Aadhaar;
use App\Http\Requests\Student\Concerns\HandlesStudentProfile;
use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
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
        $rules['semester'] = ['nullable', 'string', 'max:30'];
        $rules['enrollment_date'] = ['nullable', 'date'];

        // Optional registration documents go straight into the existing
        // student_documents store, using the shared admission document types.
        $rules['registration_document_type_id'] = [
            'nullable', 'integer',
            Rule::exists('admission_document_types', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'),
        ];
        $rules['registration_documents'] = ['nullable', 'array', 'max:10'];
        $rules['registration_documents.*'] = ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        // Aadhaar uniqueness is enforced by the custom rule above so a repeated
        // pending submission can be returned idempotently by the service.
        $this->requireGovtIdNumberForChosenType($validator);
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
