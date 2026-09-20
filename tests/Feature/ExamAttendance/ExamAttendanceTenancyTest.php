<?php

namespace Tests\Feature\ExamAttendance;

use App\Models\ExamAttendance;
use Tests\TestCase;

class ExamAttendanceTenancyTest extends TestCase
{
    use ExamAttendanceTestHelpers;

    private const PERMS = ['exam_attendance.view', 'exam_attendance.create', 'exam_attendance.update', 'exam_attendance.delete'];

    public function test_attendance_list_only_shows_active_college_records(): void
    {
        $collegeA = $this->makeCollege('EATA1');
        $collegeB = $this->makeCollege('EATA2');
        $adminA = $this->makeUserWithPermissions($collegeA, self::PERMS);

        $ctxA = $this->makeExamContext($collegeA, 'EATA1');
        $ctxB = $this->makeExamContext($collegeB, 'EATA2');
        [, $enrA] = $this->makeEnrolledStudent($collegeA, $ctxA, 'EATA1A');
        [, $enrB] = $this->makeEnrolledStudent($collegeB, $ctxB, 'EATA2A');

        ExamAttendance::create(['college_id' => $collegeA->id, 'exam_schedule_id' => $ctxA['schedule']->id, 'student_enrollment_id' => $enrA->id, 'attendance_status' => 'present', 'marked_at' => now()]);
        ExamAttendance::create(['college_id' => $collegeB->id, 'exam_schedule_id' => $ctxB['schedule']->id, 'student_enrollment_id' => $enrB->id, 'attendance_status' => 'present', 'marked_at' => now()]);

        $this->asCollege($collegeA, $adminA)
            ->get(route('exam-attendance.index'))
            ->assertOk()
            ->assertSee($enrA->enrollment_number)
            ->assertDontSee($enrB->enrollment_number);

        // College B's schedule board is unreachable from college A's context:
        // the foreign schedule id does not resolve, so the records list shows.
        $this->asCollege($collegeA, $adminA)
            ->get(route('exam-attendance.index', ['exam_schedule_id' => $ctxB['schedule']->id]))
            ->assertOk()
            ->assertDontSee($enrB->enrollment_number);
    }

    public function test_cross_tenant_exam_schedule_rejected(): void
    {
        $collegeA = $this->makeCollege('EATB1');
        $collegeB = $this->makeCollege('EATB2');
        $adminA = $this->makeUserWithPermissions($collegeA, self::PERMS);

        $ctxA = $this->makeExamContext($collegeA, 'EATB1');
        $ctxB = $this->makeExamContext($collegeB, 'EATB2');
        [, $enrA] = $this->makeEnrolledStudent($collegeA, $ctxA, 'EATB1A');

        $this->asCollege($collegeA, $adminA)
            ->post(route('exam-attendance.store'), [
                'exam_schedule_id' => $ctxB['schedule']->id, // foreign schedule
                'student_enrollment_id' => $enrA->id,
                'attendance_status' => 'present',
            ], ['Referer' => route('exam-attendance.create')])
            ->assertSessionHasErrors(['exam_schedule_id']);

        $this->assertSame(0, ExamAttendance::withoutGlobalScopes()->whereIn('college_id', [$collegeA->id, $collegeB->id])->count());
    }

    public function test_cross_tenant_student_enrollment_rejected(): void
    {
        $collegeA = $this->makeCollege('EATC1');
        $collegeB = $this->makeCollege('EATC2');
        $adminA = $this->makeUserWithPermissions($collegeA, self::PERMS);

        $ctxA = $this->makeExamContext($collegeA, 'EATC1');
        $ctxB = $this->makeExamContext($collegeB, 'EATC2');
        [, $enrB] = $this->makeEnrolledStudent($collegeB, $ctxB, 'EATC2A');

        $this->asCollege($collegeA, $adminA)
            ->post(route('exam-attendance.store'), [
                'exam_schedule_id' => $ctxA['schedule']->id,
                'student_enrollment_id' => $enrB->id, // foreign enrollment
                'attendance_status' => 'present',
            ], ['Referer' => route('exam-attendance.create')])
            ->assertSessionHasErrors(['student_enrollment_id']);

        $this->assertSame(0, ExamAttendance::withoutGlobalScopes()->whereIn('college_id', [$collegeA->id, $collegeB->id])->count());
    }

    public function test_college_a_schedule_cannot_be_combined_with_college_b_enrollment_in_bulk(): void
    {
        $collegeA = $this->makeCollege('EATD1');
        $collegeB = $this->makeCollege('EATD2');
        $adminA = $this->makeUserWithPermissions($collegeA, self::PERMS);

        $ctxA = $this->makeExamContext($collegeA, 'EATD1');
        $ctxB = $this->makeExamContext($collegeB, 'EATD2');
        [, $enrA] = $this->makeEnrolledStudent($collegeA, $ctxA, 'EATD1A');
        [, $enrB] = $this->makeEnrolledStudent($collegeB, $ctxB, 'EATD2A');

        // Foreign schedule id in bulk.
        $this->asCollege($collegeA, $adminA)
            ->post(route('exam-attendance.bulk'), [
                'exam_schedule_id' => $ctxB['schedule']->id,
                'records' => [
                    ['student_enrollment_id' => $enrA->id, 'attendance_status' => 'present'],
                ],
            ], ['Referer' => route('exam-attendance.index')])
            ->assertSessionHasErrors(['exam_schedule_id']);

        // Foreign enrollment row in bulk.
        $this->asCollege($collegeA, $adminA)
            ->post(route('exam-attendance.bulk'), [
                'exam_schedule_id' => $ctxA['schedule']->id,
                'records' => [
                    ['student_enrollment_id' => $enrB->id, 'attendance_status' => 'present'],
                ],
            ], ['Referer' => route('exam-attendance.index', ['exam_schedule_id' => $ctxA['schedule']->id])])
            ->assertSessionHasErrors();

        $this->assertSame(0, ExamAttendance::withoutGlobalScopes()->whereIn('college_id', [$collegeA->id, $collegeB->id])->count());
    }

    public function test_cannot_update_or_delete_foreign_college_attendance(): void
    {
        $collegeA = $this->makeCollege('EATE1');
        $collegeB = $this->makeCollege('EATE2');
        $adminA = $this->makeUserWithPermissions($collegeA, self::PERMS);

        $ctxB = $this->makeExamContext($collegeB, 'EATE2');
        [, $enrB] = $this->makeEnrolledStudent($collegeB, $ctxB, 'EATE2A');

        $foreign = ExamAttendance::create([
            'college_id' => $collegeB->id,
            'exam_schedule_id' => $ctxB['schedule']->id,
            'student_enrollment_id' => $enrB->id,
            'attendance_status' => 'present',
            'marked_at' => now(),
        ]);

        $this->asCollege($collegeA, $adminA)
            ->get(route('exam-attendance.edit', $foreign))
            ->assertNotFound();

        $this->asCollege($collegeA, $adminA)
            ->put(route('exam-attendance.update', $foreign), ['attendance_status' => 'absent'])
            ->assertNotFound();

        $this->asCollege($collegeA, $adminA)
            ->delete(route('exam-attendance.destroy', $foreign))
            ->assertNotFound();

        $this->assertSame('present', $foreign->fresh()->attendance_status);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_college_id_from_client_input_is_never_trusted(): void
    {
        $collegeA = $this->makeCollege('EATF1');
        $collegeB = $this->makeCollege('EATF2');
        $adminA = $this->makeUserWithPermissions($collegeA, self::PERMS);

        $ctxA = $this->makeExamContext($collegeA, 'EATF1');
        $this->makeExamContext($collegeB, 'EATF2');
        [, $enrA] = $this->makeEnrolledStudent($collegeA, $ctxA, 'EATF1A');

        $this->asCollege($collegeA, $adminA)
            ->post(route('exam-attendance.store'), [
                'college_id' => $collegeB->id, // spoofing attempt
                'exam_schedule_id' => $ctxA['schedule']->id,
                'student_enrollment_id' => $enrA->id,
                'attendance_status' => 'present',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('exam_attendances', [
            'college_id' => $collegeA->id,
            'student_enrollment_id' => $enrA->id,
        ]);
        $this->assertDatabaseMissing('exam_attendances', [
            'college_id' => $collegeB->id,
            'student_enrollment_id' => $enrA->id,
        ]);
    }
}
