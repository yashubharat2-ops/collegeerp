<?php

namespace App\Http\Requests\AcademicTerm;

use App\Models\AcademicTerm;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicTerm::class) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $academicYearId = (int) $this->input('academic_year_id');

        return [
            'academic_year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('academic_terms', 'code')
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYearId)
                    ->whereNull('deleted_at'),
            ],
            'type' => ['required', 'string', 'max:30', Rule::in(AcademicTerm::TYPES)],
            'sequence' => ['required', 'integer', 'min:1', 'max:1000'],
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
