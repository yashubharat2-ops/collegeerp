<?php

namespace App\Http\Requests\Student;

use App\Models\StudentEnrollment;
use Illuminate\Support\Facades\Gate;

/**
 * Store request for "Convert admission to student".
 *
 * Identical rules to {@see StoreStudentRequest}, but the conversion ALSO needs
 * the enrollment permission: it writes a StudentEnrollment in the same
 * transaction. Checking it in authorize() means a user without
 * `student_enrollments.create` gets a 403 before validation or any write.
 * The direct Student create form is unchanged (it strips enrollment fields
 * instead), so this check is scoped to the conversion route only.
 */
class StoreStudentFromAdmissionRequest extends StoreStudentRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && Gate::allows('create', StudentEnrollment::class);
    }
}
