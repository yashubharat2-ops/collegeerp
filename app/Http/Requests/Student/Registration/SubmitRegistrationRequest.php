<?php

namespace App\Http\Requests\Student\Registration;

use App\Domain\Student\Support\RegistrationStepRules;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 5 — the actual submission.
 *
 * Only the review-step fields are accepted from the browser: the substantive
 * data is re-read from the stored draft and re-validated server-side by
 * RegistrationSubmissionService. A crafted POST therefore cannot submit fields
 * the applicant never entered through the wizard.
 */
class SubmitRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Student::class) ?? false;
    }

    public function rules(): array
    {
        return RegistrationStepRules::rules(RegistrationStepRules::STEP_REVIEW, true, 0);
    }

    protected function redirectTo(): string
    {
        return route('students.registration.step', ['step' => 5]);
    }

    public function messages(): array
    {
        return RegistrationStepRules::messages();
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'college_id', 'user_id', 'student_id', 'student_number', 'status',
            'registration_status', 'registration_submitted_at', 'created_by', 'updated_by',
            'aadhaar_last4', 'aadhaar_hash', 'photo_path', 'signature_path',
            'father_photo_path', 'mother_photo_path', 'guardian_photo_path',
        ] as $key) {
            $this->request->remove($key);
        }
    }
}
