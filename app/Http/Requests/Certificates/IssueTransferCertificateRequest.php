<?php

namespace App\Http\Requests\Certificates;

use App\Models\StudentTransfer;
use Illuminate\Foundation\Http\FormRequest;

class IssueTransferCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tenant-scoped lookup: a foreign college's request 404s here.
        $model = StudentTransfer::query()->find((int) $this->route('student_transfer'));

        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('issue', $model) ?? false;
    }

    /**
     * The TC number is never accepted: it is minted server-side by
     * Certificate Management. An optional scanned copy of the signed TC may be
     * attached (PDF/JPG/PNG only, hard 5 MB ceiling).
     */
    public function rules(): array
    {
        return [
            'tc_issue_date' => ['nullable', 'date'],
            'template_id' => ['nullable', 'integer', 'exists:certificate_templates,id'],
            'tc_file' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('tc_number');
        $this->request->remove('tc_status');
        $this->request->remove('tc_file_path');
    }
}
