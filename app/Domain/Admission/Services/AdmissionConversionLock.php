<?php

namespace App\Domain\Admission\Services;

use App\Models\AdmissionApplication;
use App\Models\Student;

/**
 * Single serialization point for admission → student conversion.
 *
 * Both conversion entry points (the application route and the admission route)
 * lock the owning AdmissionApplication row first, then lock College before
 * checking Admission/Student/Enrollment rows. They re-check for an existing
 * Student under the shared application lock, so two concurrent conversions of
 * the same application cannot both observe "no student yet": the second one
 * blocks until the first commits, then sees the committed student.
 *
 * The serialization guarantee depends on the production engine honoring
 * SELECT … FOR UPDATE with row-level locking; under InnoDB, these locking reads
 * use the latest committed row state. SQLite test defaults do not prove this
 * behavior, so production engine/isolation concurrency verification remains
 * required. No database constraint is added; `students.admission_application_id`
 * stays non-unique (no migration) because this lock is the enforcement point.
 *
 * Tenant scope is explicit: every query filters on the caller's college_id, so
 * a foreign-college id can never be locked, read or linked.
 */
final class AdmissionConversionLock
{
    /**
     * Lock and return the tenant's application. Missing or foreign ids 404.
     */
    public function lockApplication(int $applicationId, int $collegeId): AdmissionApplication
    {
        return AdmissionApplication::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->whereKey($applicationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * The student already linked to this application, if any (tenant-scoped).
     *
     * Deliberately includes soft-deleted rows, preserving the existing rule that
     * a soft-deleted linked student still blocks re-conversion (no silent
     * re-admission). Call only while holding {@see lockApplication()} and, in
     * conversion workflows, after acquiring the College row lock.
     */
    public function existingStudentFor(int $applicationId, int $collegeId): ?Student
    {
        return Student::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->where('admission_application_id', $applicationId)
            ->lockForUpdate()
            ->first();
    }
}
