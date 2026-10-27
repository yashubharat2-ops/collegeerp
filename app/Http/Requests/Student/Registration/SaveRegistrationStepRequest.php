<?php

namespace App\Http\Requests\Student\Registration;

use App\Domain\Student\Support\RegistrationStepRules;
use App\Models\Student;
use App\Models\StudentRegistrationDraft;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One wizard step, saved either as a draft or by moving to the next step.
 *
 * The two intents deliberately share a class: the difference is only how strict
 * the rules are (see RegistrationStepRules::rules()), so a field can never be
 * accepted on "Next" and rejected on "Save draft" or vice versa.
 */
class SaveRegistrationStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Student::class) ?? false;
    }

    /** Step number from the route (`/students/registration/step/{step}`). */
    public function step(): int
    {
        return (int) $this->route('step');
    }

    /** True when the action is "Next" (or Submit), false for "Save draft". */
    public function isStrict(): bool
    {
        return ! $this->routeIs('students.registration.step.draft');
    }

    public function rules(): array
    {
        $step = $this->step();

        if ($step < 1 || $step > StudentRegistrationDraft::LAST_STEP) {
            return [];
        }

        return RegistrationStepRules::rules($step, $this->isStrict(), (int) app(TenantContext::class)->id());
    }

    /**
     * A validation failure always returns to the step that owns the field —
     * never to whatever page the browser came from.
     */
    protected function redirectTo(): string
    {
        $step = $this->step();

        if ($step < 1 || $step > StudentRegistrationDraft::LAST_STEP) {
            return route('students.registration.create');
        }

        return route('students.registration.step', ['step' => $step]);
    }

    public function messages(): array
    {
        return RegistrationStepRules::messages();
    }

    public function attributes(): array
    {
        return RegistrationStepRules::attributes();
    }

    /**
     * Everything the server owns is removed before validation, so it cannot be
     * mass-assigned later even by a hand-crafted POST. Media paths in particular
     * are only ever written by the wizard controller after the file has been
     * stored under a generated name.
     */
    protected function prepareForValidation(): void
    {
        foreach ([
            'college_id', 'user_id', 'student_id', 'student_number', 'admission_application_id',
            'status', 'registration_status', 'registration_submitted_at', 'created_by', 'updated_by',
            'aadhaar_last4', 'aadhaar_hash', 'approved_at', 'verified_by',
            'photo_path', 'signature_path', 'father_photo_path', 'mother_photo_path',
            'guardian_photo_path', 'document_id',
        ] as $key) {
            $this->request->remove($key);
        }
    }
}
