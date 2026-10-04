<?php

namespace App\Http\Requests\Student;

use App\Domain\Student\Rules\ValidAadhaar;
use App\Domain\Student\Services\StudentPhotoService;
use App\Http\Requests\Student\Concerns\HandlesStudentProfile;
use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStudentRequest extends FormRequest
{
    use HandlesStudentProfile;

    public function authorize(): bool
    {
        $model = Student::query()->find((int) $this->route('student'));
        if (! $model) {
            abort(404);
        }

        return $this->user()?->can('update', $model) ?? false;
    }

    /**
     * college_id, student_number and admission_application_id are immutable
     * after creation: they are stripped here so a student can never be
     * re-pointed at another college/app via the browser, and the server-
     * generated number can never be spoofed.
     *
     * Identity semantics for an edit (the form never echoes a stored number, so
     * a blank field can never mean "delete"):
     *
     * - blank `aadhaar_number` / `govt_id_number` → the stored value is KEPT;
     * - a supplied number → it replaces the stored one (re-validated, re-encrypted);
     * - `remove_aadhaar` / `remove_govt_id` → the stored value is cleared;
     * - `remove_photo` → the stored portrait is cleared.
     *
     * The masked tail and the duplicate digest are NEVER accepted from the
     * browser: they are re-derived server-side in StudentService.
     */
    public function rules(): array
    {
        $collegeId = app(TenantContext::class)->id();

        return [
            // --- Basic information -----------------------------------------
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('students', 'phone')->where('college_id', $collegeId)->whereNull('deleted_at')->ignore((int) $this->route('student'))],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'gender' => ['nullable', 'string', 'max:20', Rule::in(Student::GENDERS)],
            // Admission category: editable, whitelisted attribute of the student
            // itself (see Student::CATEGORIES) — never a client-supplied master id.
            'category' => ['nullable', 'string', 'max:30', Rule::in(Student::CATEGORIES)],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'admission_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(Student::STATUSES)],

            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.StudentPhotoService::MAX_KB],
            'remove_photo' => ['nullable', 'boolean'],

            // --- Parent / guardian -----------------------------------------
            'father_name' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_relation' => ['nullable', 'string', 'max:50', Rule::in(Student::GUARDIAN_RELATIONS)],
            'guardian_phone' => ['nullable', 'string', 'max:30'],
            'guardian_email' => ['nullable', 'email', 'max:255'],
            'guardian_occupation' => ['nullable', 'string', 'max:150'],
            'guardian_address' => ['nullable', 'string', 'max:2000'],

            // --- Identity & government IDs ---------------------------------
            'aadhaar_number' => ['nullable', 'string', 'max:20', new ValidAadhaar()],
            'remove_aadhaar' => ['nullable', 'boolean'],
            'apaar_id' => ['nullable', 'string', 'max:30', 'regex:/^[0-9]{12}$/'],
            'govt_id_type' => ['nullable', 'string', 'max:30', 'required_with:govt_id_number', Rule::in(Student::GOVT_ID_TYPES)],
            // Blank means "keep the stored number": the requirement is checked
            // in withValidator() only when nothing is stored yet.
            'govt_id_number' => ['nullable', 'string', 'max:100'],
            'remove_govt_id' => ['nullable', 'boolean'],

            // --- Contact ----------------------------------------------------
            'address_line_1' => ['nullable', 'string', 'max:2000'],
            'address_line_2' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],

            // --- Academic / admission ---------------------------------------
            // Enrollments are NOT created or edited from the student form: the
            // Academic/Admission tab links to the Enrollment module for that.
            // Only the previous-education snapshot is editable here.
            'previous_school_name' => ['nullable', 'string', 'max:255'],
            'previous_school_board' => ['nullable', 'string', 'max:100'],
            'previous_qualification' => ['nullable', 'string', 'max:100'],
            'previous_exam_year' => ['nullable', 'integer', 'between:1900,2100'],
            'previous_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // --- Additional information ------------------------------------
            'blood_group' => ['nullable', 'string', 'max:10', Rule::in(Student::BLOOD_GROUPS)],
            'nationality' => ['nullable', 'string', 'max:100'],
            'mother_tongue' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectDuplicateAadhaar($validator);
        $this->requireGovtIdNumberForChosenType($validator);
    }

    public function messages(): array
    {
        return [
            'apaar_id.regex' => 'The APAAR / ABC ID must be exactly 12 digits.',
            'govt_id_type.required_with' => 'Choose the type of the government ID you are entering.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->stripServerManagedFields();
    }
}
