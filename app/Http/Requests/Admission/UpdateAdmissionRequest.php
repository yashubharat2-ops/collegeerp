<?php

namespace App\Http\Requests\Admission;

use App\Models\Admission;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = Admission::query()->find((int) $this->route('admission'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where('college_id', $collegeId)],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')->where('college_id', $collegeId)],
            'admission_date' => ['nullable', 'date'],
            'status' => ['required', 'in:active,cancelled,completed'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('admission_number');
        $this->request->remove('application_id');
        $this->request->remove('applicant_id');
    }
}
