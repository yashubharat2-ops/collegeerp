<?php

namespace App\Http\Requests\AdmissionMeritEntry;

use App\Models\AdmissionMeritEntry;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAdmissionMeritEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = AdmissionMeritEntry::query()->find((int) $this->route('admission_merit_entry'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    public function rules(): array
    {
        return [
            'merit_score' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'rank' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'selection_status' => ['required', 'in:pending,selected,waitlisted,rejected'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('merit_list_id');
        $this->request->remove('application_id');
        $this->request->remove('applicant_id');
    }
}
