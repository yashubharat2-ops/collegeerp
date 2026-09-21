<?php

namespace App\Http\Requests\ResultPublishing;

use App\Models\ExamResult;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate a publication / un-publication request.
 *
 * Result ids are always checked against the ACTIVE college AND against the
 * requested examination where one is supplied, so a cross-tenant or
 * cross-context id can never be published.
 */
class PublishResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('publish', ExamResult::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['college_id', 'published_by', 'published_at', 'created_by', 'updated_by'] as $field) {
            $this->request->remove($field);
        }
    }

    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            'result_ids' => ['required_without:result_id', 'array', 'min:1', 'max:500'],
            'result_ids.*' => [
                'integer',
                Rule::exists('exam_results', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'result_id' => [
                'required_without:result_ids',
                'integer',
                Rule::exists('exam_results', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
            'examination_id' => [
                'nullable',
                'integer',
                Rule::exists('examinations', 'id')
                    ->where('college_id', $collegeId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function examinationId(): ?int
    {
        $id = $this->input('examination_id');

        return $id === null ? null : (int) $id;
    }
}
