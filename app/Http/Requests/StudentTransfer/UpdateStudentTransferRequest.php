<?php

namespace App\Http\Requests\StudentTransfer;

use App\Models\StudentTransfer;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tenant-scoped lookup: a foreign college's request 404s here.
        $model = StudentTransfer::query()->find((int) $this->route('student_transfer'));

        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    /**
     * student_id and the workflow state are immutable here; the service
     * additionally refuses to edit anything that is no longer pending.
     */
    public function rules(): array
    {
        return [
            'transfer_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'destination_institution' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('student_id');
        $this->request->remove('enrollment_id');
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
