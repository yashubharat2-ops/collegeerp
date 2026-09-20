<?php

namespace App\Services\Examinations;

use App\Models\AcademicSubjectEnrollment;
use App\Models\ExamSchedule;
use App\Models\StudentEnrollment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single eligibility source of truth shared by Exam Attendance and Marks
 * Entry (Examinations Phase 2).
 *
 * Architecture:
 *
 *   ExamSchedule → eligible StudentEnrollments → ExamAttendance / ExamMark
 *
 * Base eligibility comes from the enrollment's own academic context: the
 * enrollment must sit in the schedule's academic year and section, must be
 * active, and (when the enrollment carries a program) in the schedule's
 * program. A section is always valid for exactly one academic year + program
 * pair, so the section match is the strong academic anchor.
 *
 * Subject-level eligibility reuses the Academics Student Subject Enrollment
 * module instead of duplicating it: when subject enrollment tracking exists
 * for the schedule's section + subject + term, only students with an ACTIVE
 * subject enrollment are eligible. When no tracking exists (the module is
 * not in use for that subject yet), every active enrollment of the section
 * is eligible.
 *
 * Tenant safety: StudentEnrollment and AcademicSubjectEnrollment both carry
 * the CollegeScope global scope, so under an active tenant context every
 * query here resolves within the active college only. Callers must load the
 * ExamSchedule through a tenant-scoped query first (the controllers do).
 */
class ExamEligibilityService
{
    /**
     * Tenant-scoped builder over the enrollments academically eligible for
     * the given exam schedule.
     */
    public function eligibleEnrollments(ExamSchedule $schedule): Builder
    {
        $query = StudentEnrollment::query()
            ->where('academic_year_id', $schedule->academic_year_id)
            ->where('section_id', $schedule->section_id)
            ->where('status', 'active')
            ->where(function (Builder $q) use ($schedule): void {
                // Enrollments may predate per-enrollment program capture; the
                // section itself is program-bound, so a NULL program on the
                // enrollment does not exclude the student.
                $q->where('program_id', $schedule->program_id)
                    ->orWhereNull('program_id');
            });

        $subjectTracking = AcademicSubjectEnrollment::query()
            ->where('section_id', $schedule->section_id)
            ->where('subject_id', $schedule->subject_id)
            ->where('academic_term_id', $schedule->academic_term_id);

        if ((clone $subjectTracking)->exists()) {
            $query->whereIn(
                'id',
                $subjectTracking->where('status', 'active')->pluck('student_enrollment_id')->all()
            );
        }

        return $query;
    }

    /**
     * Whether a specific student enrollment is academically eligible for the
     * given exam schedule.
     */
    public function isEligible(ExamSchedule $schedule, int|string $studentEnrollmentId): bool
    {
        return $this->eligibleEnrollments($schedule)
            ->whereKey($studentEnrollmentId)
            ->exists();
    }
}
