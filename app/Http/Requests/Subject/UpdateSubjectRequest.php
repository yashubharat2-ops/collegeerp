<?php

namespace App\Http\Requests\Subject;

use App\Models\Subject;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = Subject::query()->find((int) $this->route('subject'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $ignoreId = (int) $this->route('subject');

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('subjects', 'code')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at')
                    ->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:50'],
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'subject_type' => ['nullable', 'string', 'max:50', Rule::in(Subject::TYPES)],
            'credits' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_marks' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'passing_marks' => ['nullable', 'numeric', 'min:0', 'max:10000', 'lte:max_marks'],
            'status' => ['required', 'in:active,inactive'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }
}
