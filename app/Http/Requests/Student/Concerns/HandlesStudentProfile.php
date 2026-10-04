<?php

namespace App\Http\Requests\Student\Concerns;

use App\Domain\Student\Support\Aadhaar;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Validation\Validator;

/**
 * Behaviour the Student Store and Update requests share, defined once so the
 * two forms cannot drift apart:
 *
 * - {@see stripServerManagedFields()} removes everything the server owns. The
 *   profile form added more of those: the Aadhaar masked tail, the Aadhaar
 *   duplicate digest, the photo path, and the optional initial-enrollment block
 *   for users who may not create enrollments.
 * - {@see rejectDuplicateAadhaar()} enforces "one live student per Aadhaar
 *   number per college" without a hard unique index (the table is
 *   soft-deletable), using the same deterministic HMAC the service stores.
 *
 * Both requests stay the authority for shape and scope; the service re-checks
 * the enrollable combination because server-side validation is authoritative.
 */
trait HandlesStudentProfile
{
    /**
     * Fields the browser may never set: the tenant, the server-generated
     * student number, the admission provenance link, the audit stamps, the
     * derived Aadhaar columns and the stored photo path.
     *
     * The optional initial-enrollment block is only accepted from a user who
     * may create enrollments; for anyone else the fields are dropped here, so
     * the Student policy is never used to smuggle an Enrollment write past
     * its own policy.
     */
    protected function stripServerManagedFields(): void
    {
        foreach ([
            'college_id',
            'student_number',
            'admission_application_id',
            'created_by',
            'updated_by',
            'photo_path',
            'aadhaar_last4',
            'aadhaar_hash',
        ] as $key) {
            $this->request->remove($key);
        }

        if (! $this->user()?->can('create', StudentEnrollment::class)) {
            foreach (['academic_year_id', 'program_id', 'section_id', 'enrollment_date'] as $key) {
                $this->request->remove($key);
            }
        }
    }

    /**
     * True when the student being edited already holds a government ID number.
     *
     * The form never echoes a stored number back into the page, so on an edit a
     * BLANK number means "keep the stored one" and must not be reported as a
     * missing field — that is the difference this helper expresses, and it is
     * read from the raw column so no decryption is needed to answer it.
     */
    protected function storedGovtIdExists(): bool
    {
        $id = $this->route('student');

        if ($id === null) {
            return false;
        }

        $student = Student::query()->find((int) $id);

        return $student !== null && $student->getRawOriginal('govt_id_number') !== null;
    }

    /**
     * A government ID is a type + number PAIR, so a chosen type must come with
     * a number — except on an edit where a number is already stored, because
     * the form never echoes the stored number back and a blank field therefore
     * means "keep it". Keeps the human message in one place for both requests.
     */
    protected function requireGovtIdNumberForChosenType(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (blank($this->input('govt_id_type'))
                || filled($this->input('govt_id_number'))
                || $this->storedGovtIdExists()) {
                return;
            }

            $validator->errors()->add('govt_id_number', 'Enter the government ID number for the selected type.');
        });
    }

    /**
     * Aadhaar is unique among the LIVE students of a college.
     *
     * The lookup is deliberately college-scoped: the tenant boundary is
     * absolute in this platform, so another college's rows are never read even
     * to compare identity numbers. Soft-deleted students are excluded by the
     * model's global scope + soft delete, so a removed record never blocks a
     * legitimate re-registration.
     */
    protected function rejectDuplicateAadhaar(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $digits = Aadhaar::normalise($this->input('aadhaar_number'));

            if ($digits === null) {
                return;
            }

            $query = Student::query()->where('aadhaar_hash', Aadhaar::hash($digits));

            $currentId = $this->route('student');
            if ($currentId !== null) {
                $query->whereKeyNot((int) $currentId);
            }

            if ($query->exists()) {
                $validator->errors()->add(
                    'aadhaar_number',
                    'This Aadhaar number is already recorded for another student in this college.'
                );
            }
        });
    }
}
