<?php

namespace App\Http\Requests\FacultySubjectAssignment;

use App\Models\FacultySubjectAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFacultySubjectAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', FacultySubjectAssignment::class) ?? false;
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $academicYearId = (int) $this->input('academic_year_id');
        $programId = $this->input('program_id');

        return [
            'faculty_id' => [
                'required',
                'integer',
                Rule::exists('faculties', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'subject_id' => [
                'required',
                'integer',
                Rule::exists('subjects', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'academic_year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'academic_term_id' => [
                'nullable',
                'integer',
                Rule::exists('academic_terms', 'id')
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYearId)
                    ->whereNull('deleted_at'),
            ],
            'program_id' => [
                'nullable',
                'integer',
                Rule::exists('programs', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'section_id' => [
                'nullable',
                'integer',
                Rule::exists('sections', 'id')
                    ->where('college_id', $collegeId)
                    ->where('academic_year_id', $academicYearId)
                    ->when($programId, fn ($q) => $q->where('program_id', $programId))
                    ->whereNull('deleted_at'),
            ],
            'status' => ['required', 'in:active,inactive'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $collegeId = app(TenantContext::class)->id();
            $facultyId = $this->input('faculty_id');
            $subjectId = $this->input('subject_id');
            $academicYearId = $this->input('academic_year_id');
            $termId = $this->input('academic_term_id');
            $programId = $this->input('program_id');
            $sectionId = $this->input('section_id');

            $query = FacultySubjectAssignment::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('college_id', $collegeId)
                ->where('faculty_id', $facultyId)
                ->where('subject_id', $subjectId)
                ->where('academic_year_id', $academicYearId);

            if ($termId) {
                $query->where('academic_term_id', $termId);
            } else {
                $query->whereNull('academic_term_id');
            }

            if ($programId) {
                $query->where('program_id', $programId);
            } else {
                $query->whereNull('program_id');
            }

            if ($sectionId) {
                $query->where('section_id', $sectionId);
            } else {
                $query->whereNull('section_id');
            }

            if ($query->exists()) {
                $v->errors()->add('faculty_id', 'This faculty member is already assigned to this subject in the selected academic context.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('college_id');
        $this->request->remove('created_by');
        $this->request->remove('updated_by');
    }
}
