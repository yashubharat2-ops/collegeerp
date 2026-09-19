<?php

namespace App\Http\Requests\StudentTransfer;

use App\Models\StudentTransfer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StudentTransfer::class) ?? false;
    }

    /**
     * The workflow fields (status, tc_status, tc_number, approval stamps) are
     * server-owned: a request always starts `pending` with a `pending` TC, and
     * the TC number is minted at issue time. college_id is the tenant context.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $studentId = (int) $this->input('student_id');

        return [
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'enrollment_id' => ['nullable', 'integer', Rule::exists('student_enrollments', 'id')->where('college_id', $collegeId)->where('student_id', $studentId)->whereNull('deleted_at')],
            'transfer_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'destination_institution' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('status');
        $this->request->remove('tc_number');
        $this->request->remove('tc_issue_date');
        $this->request->remove('tc_status');
        $this->request->remove('requested_by');
        $this->request->remove('approved_by');
        $this->request->remove('approved_at');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }
}
