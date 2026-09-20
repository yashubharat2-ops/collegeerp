<?php

namespace Tests\Feature\ExamAttendance;

use App\Models\ExamAttendance;
use App\Models\ExamSchedule;
use Tests\TestCase;

class ExamAttendanceAuthorizationTest extends TestCase
{
    use ExamAttendanceTestHelpers;

    public function test_unauthorized_user_cannot_view_exam_attendance(): void
    {
        $college = $this->makeCollege('EAAU1');
        $user = $this->makeUserWithPermissions($college, []);

        $this->asCollege($college, $user)
            ->get(route('exam-attendance.index'))
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_create_or_bulk_mark_attendance(): void
    {
        $college = $this->makeCollege('EAAU2');
        $viewer = $this->makeUserWithPermissions($college, ['exam_attendance.view']);
        $ctx = $this->makeExamContext($college, 'EAAU2');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EAAU2A');

        $this->asCollege($college, $viewer)
            ->get(route('exam-attendance.create'))
            ->assertForbidden();

        $this->asCollege($college, $viewer)
            ->post(route('exam-attendance.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'attendance_status' => 'present',
            ])
            ->assertForbidden();

        $this->asCollege($college, $viewer)
            ->post(route('exam-attendance.bulk'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'records' => [
                    ['student_enrollment_id' => $enrollment->id, 'attendance_status' => 'present'],
                ],
            ])
            ->assertForbidden();

        $this->assertSame(0, ExamAttendance::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_viewer_cannot_update_or_delete_attendance(): void
    {
        $college = $this->makeCollege('EAAU3');
        $viewer = $this->makeUserWithPermissions($college, ['exam_attendance.view']);
        $ctx = $this->makeExamContext($college, 'EAAU3');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EAAU3A');

        $attendance = ExamAttendance::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'attendance_status' => 'present',
            'marked_at' => now(),
        ]);

        $this->asCollege($college, $viewer)
            ->put(route('exam-attendance.update', $attendance), ['attendance_status' => 'absent'])
            ->assertForbidden();

        $this->asCollege($college, $viewer)
            ->delete(route('exam-attendance.destroy', $attendance))
            ->assertForbidden();

        $this->assertSame('present', $attendance->fresh()->attendance_status);
        $this->assertNull($attendance->fresh()->deleted_at);
    }

    public function test_completed_schedule_is_read_only_for_regular_admin(): void
    {
        $college = $this->makeCollege('EAAU4');
        $admin = $this->makeUserWithPermissions($college, ['exam_attendance.view', 'exam_attendance.create', 'exam_attendance.update', 'exam_attendance.delete']);
        $ctx = $this->makeExamContext($college, 'EAAU4', ['status' => ExamSchedule::STATUS_COMPLETED]);
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EAAU4A');

        $attendance = ExamAttendance::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'attendance_status' => 'present',
            'marked_at' => now(),
        ]);

        $this->asCollege($college, $admin)
            ->put(route('exam-attendance.update', $attendance), ['attendance_status' => 'absent'])
            ->assertForbidden();

        $this->asCollege($college, $admin)
            ->delete(route('exam-attendance.destroy', $attendance))
            ->assertForbidden();

        $this->asCollege($college, $admin)
            ->post(route('exam-attendance.bulk'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'records' => [
                    ['student_enrollment_id' => $enrollment->id, 'attendance_status' => 'absent'],
                ],
            ])
            ->assertForbidden();

        $this->assertSame('present', $attendance->fresh()->attendance_status);
        $this->assertNull($attendance->fresh()->deleted_at);
    }

    public function test_super_admin_can_update_attendance_on_completed_schedule(): void
    {
        $college = $this->makeCollege('EAAU5');
        $super = $this->makeSuperAdmin($college);
        $ctx = $this->makeExamContext($college, 'EAAU5', ['status' => ExamSchedule::STATUS_COMPLETED]);
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EAAU5A');

        $attendance = ExamAttendance::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'attendance_status' => 'present',
            'marked_at' => now(),
        ]);

        $this->asCollege($college, $super)
            ->get(route('exam-attendance.index'))
            ->assertOk();

        $this->asCollege($college, $super)
            ->put(route('exam-attendance.update', $attendance), ['attendance_status' => 'excused', 'remarks' => 'Medical certificate'])
            ->assertRedirect();

        $this->assertSame('excused', $attendance->fresh()->attendance_status);

        $this->asCollege($college, $super)
            ->post(route('exam-attendance.bulk'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'records' => [
                    ['student_enrollment_id' => $enrollment->id, 'attendance_status' => 'late'],
                ],
            ])
            ->assertSessionHas('success');

        $this->assertSame('late', $attendance->fresh()->attendance_status);
    }

    public function test_super_admin_can_delete_attendance_on_completed_schedule(): void
    {
        $college = $this->makeCollege('EAAU6');
        $super = $this->makeSuperAdmin($college);
        $ctx = $this->makeExamContext($college, 'EAAU6', ['status' => ExamSchedule::STATUS_COMPLETED]);
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EAAU6A');

        $attendance = ExamAttendance::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'attendance_status' => 'present',
            'marked_at' => now(),
        ]);

        $this->asCollege($college, $super)
            ->delete(route('exam-attendance.destroy', $attendance))
            ->assertRedirect();

        $this->assertNotNull($attendance->fresh()->deleted_at);
    }
}
