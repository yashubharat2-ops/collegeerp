<?php

namespace App\Http\Requests\Student;

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
        $this->rejectDuplicateAadhaar($validator);
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
