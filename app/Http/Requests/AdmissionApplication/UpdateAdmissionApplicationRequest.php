<?php

namespace App\Http\Requests\AdmissionApplication;

use App\Domain\Admission\Services\AdmissionApplicationWorkflow;
use App\Models\AdmissionApplication;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdmissionApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $model = AdmissionApplication::query()->find((int) $this->route('admission_application'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    /**
     * applicant_id is immutable after creation: it is stripped here so an
     * application can never be re-pointed at another person via the browser,
     * preserving the applicant history chain. Academic year, program, enquiry
     * link, status and remarks may be corrected, always within the same college.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();
        $application = AdmissionApplication::query()->find((int) $this->route('admission_application'));
        $status = $this->input('status');
        $isInvalidTransition = $application
            && is_string($status)
            && ! AdmissionApplicationWorkflow::canTransition($application->status, $status);
        $requiredContext = $isInvalidTransition ? 'nullable' : 'required';

        return [
            'academic_year_id' => [$requiredContext, 'integer', Rule::exists('academic_years', 'id')->where('college_id', $collegeId)],
            'program_id' => [$requiredContext, 'integer', Rule::exists('programs', 'id')->where('college_id', $collegeId)],
            'enquiry_id' => ['nullable', 'integer', Rule::exists('admission_enquiries', 'id')->where('college_id', $collegeId)],
            'status' => ['required', 'in:draft,submitted,under_review,approved,rejected,cancelled,admitted'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $application = AdmissionApplication::query()->find((int) $this->route('admission_application'));

        // A status-only update must validate against the application's
        // persisted academic context. This keeps normal updates strict while
        // preventing a client from clearing required foreign keys merely to
        // attempt a workflow transition.
        if ($application) {
            $this->merge([
                'academic_year_id' => $this->input('academic_year_id') ?? $application->academic_year_id,
                'program_id' => $this->input('program_id') ?? $application->program_id,
            ]);
        }

        $this->request->remove('college_id');
        $this->request->remove('application_number');
        $this->request->remove('submitted_at');
        $this->request->remove('applicant_id');
    }
}
