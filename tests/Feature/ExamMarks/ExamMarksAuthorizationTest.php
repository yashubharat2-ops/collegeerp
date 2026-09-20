<?php

namespace Tests\Feature\ExamMarks;

use App\Models\ExamMark;
use App\Models\ExamSchedule;
use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;
use Tests\TestCase;

class ExamMarksAuthorizationTest extends TestCase
{
    use ExamAttendanceTestHelpers;

    private function makeMark($college, array $ctx, $enrollment): ExamMark
    {
        return ExamMark::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'max_marks' => 100,
            'passing_marks' => 40,
            'obtained_marks' => 55,
            'status' => 'entered',
        ]);
    }

    public function test_unauthorized_user_cannot_view_marks(): void
    {
        $college = $this->makeCollege('EMAU1');
        $user = $this->makeUserWithPermissions($college, []);

        $this->asCollege($college, $user)
            ->get(route('exam-marks.index'))
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_create_or_bulk_save_marks(): void
    {
        $college = $this->makeCollege('EMAU2');
        $viewer = $this->makeUserWithPermissions($college, ['exam_marks.view']);
        $ctx = $this->makeExamContext($college, 'EMAU2');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EMAU2A');

        $this->asCollege($college, $viewer)
            ->get(route('exam-marks.create'))
            ->assertForbidden();

        $this->asCollege($college, $viewer)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 50,
                'status' => 'entered',
            ])
            ->assertForbidden();

        $this->asCollege($college, $viewer)
            ->post(route('exam-marks.bulk'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'records' => [
                    ['student_enrollment_id' => $enrollment->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => 50, 'status' => 'entered'],
                ],
            ])
            ->assertForbidden();

        $this->assertSame(0, ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_viewer_cannot_update_or_delete_marks(): void
    {
        $college = $this->makeCollege('EMAU3');
        $viewer = $this->makeUserWithPermissions($college, ['exam_marks.view']);
        $ctx = $this->makeExamContext($college, 'EMAU3');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EMAU3A');
        $mark = $this->makeMark($college, $ctx, $enrollment);

        $this->asCollege($college, $viewer)
            ->put(route('exam-marks.update', $mark), [
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 99,
                'status' => 'entered',
            ])
            ->assertForbidden();

        $this->asCollege($college, $viewer)
            ->delete(route('exam-marks.destroy', $mark))
            ->assertForbidden();

        $this->assertSame('55.00', (string) $mark->fresh()->obtained_marks);
        $this->assertNull($mark->fresh()->deleted_at);
    }

    public function test_completed_schedule_marks_are_read_only_for_regular_admin(): void
    {
        $college = $this->makeCollege('EMAU4');
        $admin = $this->makeUserWithPermissions($college, ['exam_marks.view', 'exam_marks.create', 'exam_marks.update', 'exam_marks.delete']);
        $ctx = $this->makeExamContext($college, 'EMAU4', ['status' => ExamSchedule::STATUS_COMPLETED]);
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EMAU4A');
        $mark = $this->makeMark($college, $ctx, $enrollment);

        $this->asCollege($college, $admin)
            ->put(route('exam-marks.update', $mark), [
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 99,
                'status' => 'entered',
            ])
            ->assertForbidden();

        $this->asCollege($college, $admin)
            ->delete(route('exam-marks.destroy', $mark))
            ->assertForbidden();

        $this->asCollege($college, $admin)
            ->post(route('exam-marks.bulk'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'records' => [
                    ['student_enrollment_id' => $enrollment->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => 99, 'status' => 'entered'],
                ],
            ])
            ->assertForbidden();

        $this->assertSame('55.00', (string) $mark->fresh()->obtained_marks);
        $this->assertNull($mark->fresh()->deleted_at);
    }

    public function test_super_admin_can_modify_marks_on_completed_schedule(): void
    {
        $college = $this->makeCollege('EMAU5');
        $super = $this->makeSuperAdmin($college);
        $ctx = $this->makeExamContext($college, 'EMAU5', ['status' => ExamSchedule::STATUS_COMPLETED]);
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EMAU5A');
        $mark = $this->makeMark($college, $ctx, $enrollment);

        $this->asCollege($college, $super)
            ->get(route('exam-marks.index'))
            ->assertOk();

        $this->asCollege($college, $super)
            ->put(route('exam-marks.update', $mark), [
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 61,
                'status' => 'entered',
                'remarks' => 'Moderation adjustment',
            ])
            ->assertRedirect();

        $this->assertSame('61.00', (string) $mark->fresh()->obtained_marks);

        $this->asCollege($college, $super)
            ->post(route('exam-marks.bulk'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'records' => [
                    ['student_enrollment_id' => $enrollment->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => 63, 'status' => 'entered'],
                ],
            ])
            ->assertSessionHas('success');

        $this->assertSame('63.00', (string) $mark->fresh()->obtained_marks);
    }

    public function test_super_admin_can_delete_marks_on_completed_schedule(): void
    {
        $college = $this->makeCollege('EMAU6');
        $super = $this->makeSuperAdmin($college);
        $ctx = $this->makeExamContext($college, 'EMAU6', ['status' => ExamSchedule::STATUS_COMPLETED]);
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EMAU6A');
        $mark = $this->makeMark($college, $ctx, $enrollment);

        $this->asCollege($college, $super)
            ->delete(route('exam-marks.destroy', $mark))
            ->assertRedirect();

        $this->assertNotNull($mark->fresh()->deleted_at);
    }
}
