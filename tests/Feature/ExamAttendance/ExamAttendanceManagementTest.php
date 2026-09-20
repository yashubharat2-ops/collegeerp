<?php

namespace Tests\Feature\ExamAttendance;

use App\Models\AuditLog;
use App\Models\ExamAttendance;
use App\Models\ExamSchedule;
use Tests\TestCase;

class ExamAttendanceManagementTest extends TestCase
{
    use ExamAttendanceTestHelpers;

    private const PERMS = ['exam_attendance.view', 'exam_attendance.create', 'exam_attendance.update', 'exam_attendance.delete'];

    public function test_authorized_user_can_view_attendance_index_and_schedule_board(): void
    {
        $college = $this->makeCollege('EA01');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA01');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EA01A');

        $this->asCollege($college, $admin)
            ->get(route('exam-attendance.index'))
            ->assertOk();

        $this->asCollege($college, $admin)
            ->get(route('exam-attendance.index', ['exam_schedule_id' => $ctx['schedule']->id]))
            ->assertOk()
            ->assertSee($enrollment->enrollment_number)
            ->assertSee('Stu EA01A');
    }

    public function test_authorized_user_can_create_attendance(): void
    {
        $college = $this->makeCollege('EA02');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA02');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EA02A');

        $this->asCollege($college, $admin)
            ->post(route('exam-attendance.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'attendance_status' => 'present',
                'remarks' => 'On time',
            ])
            ->assertRedirect(route('exam-attendance.index', ['exam_schedule_id' => $ctx['schedule']->id]))
            ->assertSessionHas('success');

        $record = ExamAttendance::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($record);
        $this->assertSame($ctx['schedule']->id, $record->exam_schedule_id);
        $this->assertSame($enrollment->id, $record->student_enrollment_id);
        $this->assertSame('present', $record->attendance_status);
        $this->assertSame($admin->id, $record->marked_by);
        $this->assertSame($admin->id, $record->created_by);
        $this->assertNotNull($record->marked_at);
    }

    public function test_authorized_user_can_update_attendance(): void
    {
        $college = $this->makeCollege('EA03');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA03');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EA03A');

        $attendance = ExamAttendance::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'attendance_status' => 'present',
            'marked_at' => now(),
        ]);

        $this->asCollege($college, $admin)
            ->get(route('exam-attendance.edit', $attendance))
            ->assertOk();

        $this->asCollege($college, $admin)
            ->put(route('exam-attendance.update', $attendance), [
                'attendance_status' => 'late',
                'remarks' => 'Arrived 20 minutes late',
            ])
            ->assertRedirect(route('exam-attendance.index', ['exam_schedule_id' => $ctx['schedule']->id]));

        $fresh = $attendance->fresh();
        $this->assertSame('late', $fresh->attendance_status);
        $this->assertSame('Arrived 20 minutes late', $fresh->remarks);
        $this->assertSame($admin->id, $fresh->updated_by);
        // Identity fields are immutable through updates.
        $this->assertSame($ctx['schedule']->id, $fresh->exam_schedule_id);
        $this->assertSame($enrollment->id, $fresh->student_enrollment_id);
    }

    public function test_authorized_user_can_delete_attendance_soft(): void
    {
        $college = $this->makeCollege('EA04');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA04');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EA04A');

        $attendance = ExamAttendance::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'attendance_status' => 'present',
            'marked_at' => now(),
        ]);

        $this->asCollege($college, $admin)
            ->delete(route('exam-attendance.destroy', $attendance))
            ->assertRedirect();

        $this->assertNotNull($attendance->fresh()->deleted_at);
        $this->assertSame(0, ExamAttendance::withoutGlobalScopes()->where('college_id', $college->id)->whereNull('deleted_at')->count());
        // Soft delete: the historical row is preserved.
        $this->assertSame(1, ExamAttendance::withoutGlobalScopes()->where('college_id', $college->id)->withTrashed()->count());
    }

    public function test_duplicate_attendance_is_rejected(): void
    {
        $college = $this->makeCollege('EA05');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA05');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EA05A');

        ExamAttendance::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'attendance_status' => 'present',
            'marked_at' => now(),
        ]);

        $this->asCollege($college, $admin)
            ->post(route('exam-attendance.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'attendance_status' => 'absent',
            ], ['Referer' => route('exam-attendance.create')])
            ->assertSessionHasErrors(['student_enrollment_id']);

        $this->assertSame(1, ExamAttendance::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame('present', ExamAttendance::withoutGlobalScopes()->where('college_id', $college->id)->first()->attendance_status);
    }

    public function test_bulk_attendance_marks_eligible_students(): void
    {
        $college = $this->makeCollege('EA06');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA06');
        [, $first] = $this->makeEnrolledStudent($college, $ctx, 'EA06A');
        [, $second] = $this->makeEnrolledStudent($college, $ctx, 'EA06B');
        [, $untouched] = $this->makeEnrolledStudent($college, $ctx, 'EA06C');

        $this->asCollege($college, $admin)
            ->post(route('exam-attendance.bulk'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'records' => [
                    ['student_enrollment_id' => $first->id, 'attendance_status' => 'present'],
                    ['student_enrollment_id' => $second->id, 'attendance_status' => 'late', 'remarks' => 'Bus delay'],
                    // Untouched row (empty select) must be skipped, not saved.
                    ['student_enrollment_id' => $untouched->id, 'attendance_status' => ''],
                ],
            ])
            ->assertRedirect(route('exam-attendance.index', ['exam_schedule_id' => $ctx['schedule']->id]))
            ->assertSessionHas('success');

        $this->assertSame('present', ExamAttendance::withoutGlobalScopes()->where('student_enrollment_id', $first->id)->first()->attendance_status);
        $secondRecord = ExamAttendance::withoutGlobalScopes()->where('student_enrollment_id', $second->id)->first();
        $this->assertSame('late', $secondRecord->attendance_status);
        $this->assertSame('Bus delay', $secondRecord->remarks);
        $this->assertSame(0, ExamAttendance::withoutGlobalScopes()->where('student_enrollment_id', $untouched->id)->count());
    }

    public function test_bulk_attendance_is_transactional_all_or_nothing(): void
    {
        $college = $this->makeCollege('EA07');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA07');
        [, $valid] = $this->makeEnrolledStudent($college, $ctx, 'EA07A');

        // Eligible enrollment in a DIFFERENT section — rejected by the
        // eligibility rules, which must abort the whole batch.
        $otherSection = $this->makeExamContext($college, 'EA07B', ['exam_date' => '2026-10-03']);
        [, $ineligible] = $this->makeEnrolledStudent($college, $otherSection, 'EA07X');

        $this->asCollege($college, $admin)
            ->post(route('exam-attendance.bulk'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'records' => [
                    ['student_enrollment_id' => $valid->id, 'attendance_status' => 'present'],
                    ['student_enrollment_id' => $ineligible->id, 'attendance_status' => 'present'],
                ],
            ], ['Referer' => route('exam-attendance.index', ['exam_schedule_id' => $ctx['schedule']->id])])
            ->assertSessionHasErrors();

        // Nothing may be partially saved.
        $this->assertSame(0, ExamAttendance::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_bulk_repeated_marking_updates_existing_row_without_duplicates(): void
    {
        $college = $this->makeCollege('EA08');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA08');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EA08A');

        $payload = fn (string $status) => [
            'exam_schedule_id' => $ctx['schedule']->id,
            'records' => [
                ['student_enrollment_id' => $enrollment->id, 'attendance_status' => $status],
            ],
        ];

        $this->asCollege($college, $admin)->post(route('exam-attendance.bulk'), $payload('present'))->assertSessionHas('success');
        $this->asCollege($college, $admin)->post(route('exam-attendance.bulk'), $payload('absent'))->assertSessionHas('success');

        $rows = ExamAttendance::withoutGlobalScopes()->where('college_id', $college->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('absent', $rows->first()->attendance_status);
    }

    public function test_invalid_student_enrollment_is_rejected(): void
    {
        $college = $this->makeCollege('EA09');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA09');
        $this->makeEnrolledStudent($college, $ctx, 'EA09A');

        // Non-existent enrollment id.
        $this->asCollege($college, $admin)
            ->post(route('exam-attendance.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => 999999,
                'attendance_status' => 'present',
            ], ['Referer' => route('exam-attendance.create')])
            ->assertSessionHasErrors(['student_enrollment_id']);

        // Enrollment from another section of the same college: contextually
        // ineligible for this schedule.
        $other = $this->makeExamContext($college, 'EA09B', ['exam_date' => '2026-10-04']);
        [, $mismatched] = $this->makeEnrolledStudent($college, $other, 'EA09X');

        $this->asCollege($college, $admin)
            ->post(route('exam-attendance.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $mismatched->id,
                'attendance_status' => 'present',
            ], ['Referer' => route('exam-attendance.create')])
            ->assertSessionHasErrors(['student_enrollment_id']);

        $this->assertSame(0, ExamAttendance::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_subject_enrollment_module_gates_subject_level_eligibility(): void
    {
        $college = $this->makeCollege('EA10');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA10');
        [, $enrolled] = $this->makeEnrolledStudent($college, $ctx, 'EA10A');
        [, $dropped] = $this->makeEnrolledStudent($college, $ctx, 'EA10B');

        // Once subject enrollment tracking exists for this section/subject/
        // term, only actively subject-enrolled students remain eligible.
        $this->makeSubjectEnrollment($college, $ctx, $enrolled, 'active');
        $this->makeSubjectEnrollment($college, $ctx, $dropped, 'dropped');

        $this->asCollege($college, $admin)
            ->post(route('exam-attendance.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $dropped->id,
                'attendance_status' => 'present',
            ], ['Referer' => route('exam-attendance.create')])
            ->assertSessionHasErrors(['student_enrollment_id']);

        $this->asCollege($college, $admin)
            ->post(route('exam-attendance.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrolled->id,
                'attendance_status' => 'present',
            ])
            ->assertSessionHas('success');
    }

    public function test_soft_deleted_attendance_can_be_remarked(): void
    {
        $college = $this->makeCollege('EA11');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA11');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EA11A');

        $attendance = ExamAttendance::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'attendance_status' => 'present',
            'marked_at' => now(),
        ]);
        $attendance->delete();

        $this->asCollege($college, $admin)
            ->post(route('exam-attendance.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'attendance_status' => 'excused',
            ])
            ->assertSessionHas('success');

        $active = ExamAttendance::withoutGlobalScopes()->where('college_id', $college->id)->whereNull('deleted_at')->get();
        $this->assertCount(1, $active);
        $this->assertSame('excused', $active->first()->attendance_status);
        $this->assertSame(2, ExamAttendance::withoutGlobalScopes()->where('college_id', $college->id)->withTrashed()->count());
    }

    public function test_attendance_status_validation(): void
    {
        $college = $this->makeCollege('EA12');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA12');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EA12A');

        foreach ([null, 'bogus'] as $status) {
            $this->asCollege($college, $admin)
                ->post(route('exam-attendance.store'), [
                    'exam_schedule_id' => $ctx['schedule']->id,
                    'student_enrollment_id' => $enrollment->id,
                    'attendance_status' => $status,
                ], ['Referer' => route('exam-attendance.create')])
                ->assertSessionHasErrors(['attendance_status']);
        }

        $this->assertSame(0, ExamAttendance::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_audit_log_created_for_attendance_lifecycle(): void
    {
        $college = $this->makeCollege('EA13');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA13');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EA13A');

        $this->asCollege($college, $admin)->post(route('exam-attendance.store'), [
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'attendance_status' => 'present',
        ])->assertSessionHas('success');

        $record = ExamAttendance::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->asCollege($college, $admin)->put(route('exam-attendance.update', $record), [
            'attendance_status' => 'late',
        ])->assertRedirect();

        $this->asCollege($college, $admin)->delete(route('exam-attendance.destroy', $record))->assertRedirect();

        foreach (['exam_attendance.created', 'exam_attendance.updated', 'exam_attendance.deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'college_id' => $college->id,
                'user_id' => $admin->id,
                'subject_id' => $record->id,
            ]);
        }
    }

    public function test_bulk_attendance_is_audited_as_one_traceable_entry(): void
    {
        $college = $this->makeCollege('EA14');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA14');
        [, $first] = $this->makeEnrolledStudent($college, $ctx, 'EA14A');
        [, $second] = $this->makeEnrolledStudent($college, $ctx, 'EA14B');

        $this->asCollege($college, $admin)->post(route('exam-attendance.bulk'), [
            'exam_schedule_id' => $ctx['schedule']->id,
            'records' => [
                ['student_enrollment_id' => $first->id, 'attendance_status' => 'present'],
                ['student_enrollment_id' => $second->id, 'attendance_status' => 'present'],
            ],
        ])->assertSessionHas('success');

        $audit = AuditLog::query()->where('action', 'exam_attendance.bulk_marked')->where('college_id', $college->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame(2, $audit->new_values['created']);
    }

    public function test_filters_work(): void
    {
        $college = $this->makeCollege('EA15');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctxA = $this->makeExamContext($college, 'EA15A');
        $ctxB = $this->makeExamContext($college, 'EA15B', ['exam_date' => '2026-10-05']);
        [, $enrA] = $this->makeEnrolledStudent($college, $ctxA, 'EA15A1');
        [, $enrB] = $this->makeEnrolledStudent($college, $ctxB, 'EA15B1');

        ExamAttendance::create(['college_id' => $college->id, 'exam_schedule_id' => $ctxA['schedule']->id, 'student_enrollment_id' => $enrA->id, 'attendance_status' => 'present', 'marked_at' => now()]);
        ExamAttendance::create(['college_id' => $college->id, 'exam_schedule_id' => $ctxB['schedule']->id, 'student_enrollment_id' => $enrB->id, 'attendance_status' => 'absent', 'marked_at' => now()]);

        $this->asCollege($college, $admin)
            ->get(route('exam-attendance.index', ['subject_id' => $ctxA['sub']->id]))
            ->assertOk()
            ->assertSee($enrA->enrollment_number)
            ->assertDontSee($enrB->enrollment_number);

        $this->asCollege($college, $admin)
            ->get(route('exam-attendance.index', ['attendance_status' => 'absent']))
            ->assertOk()
            ->assertSee($enrB->enrollment_number)
            ->assertDontSee($enrA->enrollment_number);

        $this->asCollege($college, $admin)
            ->get(route('exam-attendance.index', ['examination_id' => $ctxA['exam']->id]))
            ->assertOk()
            ->assertSee($enrA->enrollment_number)
            ->assertDontSee($enrB->enrollment_number);

        $this->asCollege($college, $admin)
            ->get(route('exam-attendance.index', ['exam_date' => '2026-10-05']))
            ->assertOk()
            ->assertSee($enrB->enrollment_number)
            ->assertDontSee($enrA->enrollment_number);
    }

    public function test_pagination_is_deterministic(): void
    {
        $college = $this->makeCollege('EA16');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA16');

        $first = null;
        $last = null;
        for ($i = 1; $i <= 18; $i++) {
            [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EA16'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $record = ExamAttendance::create([
                'college_id' => $college->id,
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'attendance_status' => 'present',
                'marked_at' => now(),
            ]);
            if ($i === 1) {
                $first = ['record' => $record, 'number' => $enrollment->enrollment_number];
            }
            if ($i === 18) {
                $last = ['record' => $record, 'number' => $enrollment->enrollment_number];
            }
        }

        $pageOne = $this->asCollege($college, $admin)->get(route('exam-attendance.index'));
        $pageOne->assertOk()
            ->assertSee($last['number'])       // newest first (created_at desc, id desc)
            ->assertDontSee($first['number']);

        $pageTwo = $this->asCollege($college, $admin)->get(route('exam-attendance.index', ['page' => 2]));
        $pageTwo->assertOk()
            ->assertSee($first['number'])
            ->assertDontSee($last['number']);
    }

    public function test_board_not_shown_for_soft_deleted_schedule(): void
    {
        $college = $this->makeCollege('EA17');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EA17');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EA17A');

        $ctx['schedule']->delete();

        // A soft-deleted schedule cannot be selected for marking...
        $this->asCollege($college, $admin)
            ->post(route('exam-attendance.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'attendance_status' => 'present',
            ], ['Referer' => route('exam-attendance.create')])
            ->assertSessionHasErrors(['exam_schedule_id']);

        // ...and the index falls back to the records list.
        $this->asCollege($college, $admin)
            ->get(route('exam-attendance.index', ['exam_schedule_id' => $ctx['schedule']->id]))
            ->assertOk();

        $this->assertSame(0, ExamAttendance::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }
}
