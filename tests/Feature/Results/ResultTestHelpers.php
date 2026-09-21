<?php

namespace Tests\Feature\Results;

use App\Models\College;
use App\Models\ExamMark;
use App\Models\ExamSchedule;
use App\Models\GradeScale;
use App\Models\GradeScaleItem;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use Illuminate\Support\Str;
use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;

/**
 * Shared fixtures for the Examinations Phase 3A tests — Grade / Pass-Fail only.
 *
 * Reuses the Phase 2 Examinations fixtures (college, users, enrollments, exam
 * context) instead of duplicating platform masters, and adds only what Phase 3A
 * owns: grade scales and marks. No ExamResult / calculation helpers.
 */
trait ResultTestHelpers
{
    use ExamAttendanceTestHelpers;

    /**
     * A configurable grade scale.
     *
     * The boundaries below are TEST DATA chosen by the test, not a hard-coded
     * grading system: production colleges define their own bands.
     *
     * @param  array<int, array>|null  $items
     */
    private function makeGradeScale(College $college, ?array $items = null, array $overrides = []): GradeScale
    {
        $scale = GradeScale::create(array_merge([
            'college_id' => $college->id,
            'name' => 'Default Scale '.Str::upper(Str::random(4)),
            'code' => 'GS-'.Str::upper(Str::random(6)),
            'status' => GradeScale::STATUS_ACTIVE,
            'description' => 'Test grade scale',
        ], $overrides));

        foreach ($items ?? $this->defaultGradeBands() as $index => $band) {
            $scale->allItems()->create([
                'college_id' => $college->id,
                'grade' => $band['grade'],
                'min_percentage' => $band['min_percentage'],
                'max_percentage' => $band['max_percentage'],
                'grade_point' => $band['grade_point'] ?? null,
                'description' => $band['description'] ?? null,
                'sort_order' => $band['sort_order'] ?? $index + 1,
                'status' => $band['status'] ?? GradeScaleItem::STATUS_ACTIVE,
            ]);
        }

        return $scale->refresh();
    }

    /**
     * A contiguous 0–100 example band set (test data only).
     *
     * @return array<int, array>
     */
    private function defaultGradeBands(): array
    {
        return [
            ['grade' => 'F', 'min_percentage' => 0, 'max_percentage' => 39.99, 'grade_point' => 0, 'sort_order' => 1],
            ['grade' => 'C', 'min_percentage' => 40, 'max_percentage' => 59.99, 'grade_point' => 5, 'sort_order' => 2],
            ['grade' => 'B', 'min_percentage' => 60, 'max_percentage' => 79.99, 'grade_point' => 7, 'sort_order' => 3],
            ['grade' => 'A', 'min_percentage' => 80, 'max_percentage' => 100, 'grade_point' => 10, 'sort_order' => 4],
        ];
    }

    /**
     * Build an examination with N subjects, each with its own exam schedule.
     *
     * @return array<string, mixed>
     */
    private function makeExamContextWithSubjects(College $college, string $prefix, int $subjectCount = 2, array $scheduleOverrides = []): array
    {
        $ctx = $this->makeExamContext($college, $prefix, $scheduleOverrides);

        $subjects = [$ctx['sub']];
        $schedules = [$ctx['schedule']];

        for ($i = 2; $i <= $subjectCount; $i++) {
            $subject = Subject::create([
                'college_id' => $college->id,
                'name' => "Sub {$prefix} {$i}",
                'code' => "SB-{$prefix}-{$i}",
                'status' => 'active',
            ]);

            $subjects[] = $subject;

            $schedules[] = ExamSchedule::create(array_merge([
                'college_id' => $college->id,
                'examination_id' => $ctx['exam']->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $ctx['prog']->id,
                'section_id' => $ctx['sec']->id,
                'subject_id' => $subject->id,
                'exam_date' => '2026-10-0'.($i + 1),
                'start_time' => '09:00:00',
                'end_time' => '12:00:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => ExamSchedule::STATUS_SCHEDULED,
            ], $scheduleOverrides));
        }

        $ctx['subjects'] = $subjects;
        $ctx['schedules'] = $schedules;

        return $ctx;
    }

    /**
     * Record one ExamMark row (ExamMark stays the single source of truth).
     */
    private function recordMark(
        ExamSchedule $schedule,
        StudentEnrollment $enrollment,
        ?float $obtained,
        string $status = ExamMark::STATUS_ENTERED,
        array $overrides = [],
    ): ExamMark {
        return ExamMark::create(array_merge([
            'college_id' => $enrollment->college_id,
            'exam_schedule_id' => $schedule->id,
            'student_enrollment_id' => $enrollment->id,
            'max_marks' => (float) $schedule->max_marks,
            'passing_marks' => (float) $schedule->passing_marks,
            'obtained_marks' => $obtained,
            'status' => $status,
            'entered_at' => $status === ExamMark::STATUS_DRAFT ? null : now(),
            'entered_by' => null,
        ], $overrides));
    }

    /**
     * Run a callback with the tenant context bound to $college.
     *
     * Every Phase 3 model carries CollegeScope, which resolves to
     * `whereRaw('1 = 0')` when no tenant is active. Assertions that read these
     * models directly (outside an HTTP request) therefore have to pin the
     * tenant explicitly — inside a request the `tenant` middleware does it.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(College $college, callable $callback): mixed
    {
        $context = app(\App\Support\Tenancy\TenantContext::class);
        $context->set($college);

        try {
            return $callback();
        } finally {
            $context->clear();
        }
    }
}
