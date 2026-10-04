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

class StoreStudentRequest extends FormRequest
{
    use HandlesStudentProfile;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Student::class) ?? false;
    }

    /**
     * college_id and student_number are never taken from the browser: the
     * tenant is the server-side context and the number is generated
     * transactionally via GenerateStudentNumber.
     *
     * admission_application_id is NOT accepted here: the provenance link is
     * owned exclusively by ConvertApplicationToStudent, so an application can
     * only ever be linked to a student through the status-checked conversion
     * workflow.
     *
     * gender/category are plain whitelisted attributes of the student record
     * (Student::GENDERS / Student::CATEGORIES); neither can point at a
     * client-supplied master row, because neither is a foreign key.
     *
     * The profile sections follow the same principle: every field is either a
     * plain attribute (with a `Rule::in` vocabulary where the UI offers a
     * fixed list) or a tenant-scoped `Rule::exists` reference. The Aadhaar
     * columns are never accepted from the browser — the masked tail and the
     * duplicate digest are derived server-side in StudentService.
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
            'phone' => ['nullable', 'string', 'max:30', Rule::unique('students', 'phone')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'gender' => ['nullable', 'string', 'max:20', Rule::in(Student::GENDERS)],
            // Admission category: nullable attribute of the student (see
            // Student::CATEGORIES), never a client-supplied college/master id.
            'category' => ['nullable', 'string', 'max:30', Rule::in(Student::CATEGORIES)],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'admission_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(Student::STATUSES)],

            // Photograph: the file is stored by StudentPhotoService under a
            // server-generated name on the private disk; `photo_path` itself is
            // stripped from the request.
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.StudentPhotoService::MAX_KB],

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
            // `aadhaar_number` is encrypted before it is persisted; the
            // Verhoeff checksum is verified so a mistyped number never lands in
            // the database. The pair (type, number) must be supplied together.
            'aadhaar_number' => ['nullable', 'string', 'max:20', new ValidAadhaar()],
            'apaar_id' => ['nullable', 'string', 'max:30', 'regex:/^[0-9]{12}$/'],
            'govt_id_type' => ['nullable', 'string', 'max:30', 'required_with:govt_id_number', Rule::in(Student::GOVT_ID_TYPES)],
            // The reverse direction is checked in withValidator() so both
            // requests share it, and so an edit that keeps a stored number can
            // leave the field blank.
            'govt_id_number' => ['nullable', 'string', 'max:100'],

            // --- Contact ----------------------------------------------------
            'address_line_1' => ['nullable', 'string', 'max:2000'],
            'address_line_2' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],

            // --- Academic / admission --------------------------------------
            'previous_school_name' => ['nullable', 'string', 'max:255'],
            'previous_school_board' => ['nullable', 'string', 'max:100'],
            'previous_qualification' => ['nullable', 'string', 'max:100'],
            'previous_exam_year' => ['nullable', 'integer', 'between:1900,2100'],
            'previous_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Optional FIRST enrollment, created in the same transaction as the
            // student. Every id is tenant-scoped; the academic-year + program +
            // section combination is re-checked in StudentService (a section
            // belongs to exactly one year/program pair).
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where('college_id', $collegeId)->whereNull('deleted_at'), 'required_with:program_id,section_id,enrollment_date'],
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'section_id' => ['nullable', 'integer', Rule::exists('sections', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            'enrollment_date' => ['nullable', 'date'],

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
            'academic_year_id.required_with' => 'Choose an academic year for the initial enrollment, or leave the enrollment block empty.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->stripServerManagedFields();
    }
}
