<?php

namespace App\Domain\Admission\Services;

use App\Models\AdmissionApplication;
use App\Models\Student;

/**
 * Single serialization point for admission → student conversion.
 *
 * Both conversion entry points (the application route and the admission route)
 * lock the owning AdmissionApplication row first, then re-check for an existing
 * Student under that lock. Because every path takes the SAME row lock before it
 * reads or writes the student link, two concurrent conversions of the same
 * application cannot both observe "no student yet": the second one blocks until
 * the first commits, then sees the committed student.
 *
 * Why application-level locking is sufficient on MySQL/MariaDB (InnoDB): the
 * lock is a locking read (SELECT … FOR UPDATE) on the application row, which
 * always reads the latest committed state. The student existence check is also
 * a locking read, so it does not depend on a snapshot taken before the lock.
 * No database constraint is required; `students.admission_application_id` stays
 * non-unique (no migration) because this lock is the enforcement point.
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
     * re-admission). Call only while holding {@see lockApplication()}.
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
