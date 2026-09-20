<?php

namespace Tests\Feature\ExamMarks;

use App\Models\ExamMark;
use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;
use Tests\TestCase;

class ExamMarksTenancyTest extends TestCase
{
    use ExamAttendanceTestHelpers;

    private const PERMS = ['exam_marks.view', 'exam_marks.create', 'exam_marks.update', 'exam_marks.delete'];

    public function test_marks_list_only_shows_active_college_records(): void
    {
        $collegeA = $this->makeCollege('EMTA1');
        $collegeB = $this->makeCollege('EMTA2');
        $adminA = $this->makeUserWithPermissions($collegeA, self::PERMS);

        $ctxA = $this->makeExamContext($collegeA, 'EMTA1');
        $ctxB = $this->makeExamContext($collegeB, 'EMTA2');
        [, $enrA] = $this->makeEnrolledStudent($collegeA, $ctxA, 'EMTA1A');
        [, $enrB] = $this->makeEnrolledStudent($collegeB, $ctxB, 'EMTA2A');

        ExamMark::create(['college_id' => $collegeA->id, 'exam_schedule_id' => $ctxA['schedule']->id, 'student_enrollment_id' => $enrA->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => 70, 'status' => 'entered']);
        ExamMark::create(['college_id' => $collegeB->id, 'exam_schedule_id' => $ctxB['schedule']->id, 'student_enrollment_id' => $enrB->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => 80, 'status' => 'entered']);

        $this->asCollege($collegeA, $adminA)
            ->get(route('exam-marks.index'))
            ->assertOk()
            ->assertSee($enrA->enrollment_number)
            ->assertDontSee($enrB->enrollment_number);

        // A foreign schedule id does not resolve in college A's context.
        $this->asCollege($collegeA, $adminA)
            ->get(route('exam-marks.index', ['exam_schedule_id' => $ctxB['schedule']->id]))
            ->assertOk()
            ->assertDontSee($enrB->enrollment_number);
    }

    public function test_cross_tenant_exam_schedule_rejected(): void
    {
        $collegeA = $this->makeCollege('EMTB1');
        $collegeB = $this->makeCollege('EMTB2');
        $adminA = $this->makeUserWithPermissions($collegeA, self::PERMS);

        $ctxA = $this->makeExamContext($collegeA, 'EMTB1');
        $ctxB = $this->makeExamContext($collegeB, 'EMTB2');
        [, $enrA] = $this->makeEnrolledStudent($collegeA, $ctxA, 'EMTB1A');

        $this->asCollege($collegeA, $adminA)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctxB['schedule']->id, // foreign schedule
                'student_enrollment_id' => $enrA->id,
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 50,
                'status' => 'entered',
            ], ['Referer' => route('exam-marks.create')])
            ->assertSessionHasErrors(['exam_schedule_id']);

        $this->assertSame(0, ExamMark::withoutGlobalScopes()->whereIn('college_id', [$collegeA->id, $collegeB->id])->count());
    }

    public function test_cross_tenant_student_enrollment_rejected(): void
    {
        $collegeA = $this->makeCollege('EMTC1');
        $collegeB = $this->makeCollege('EMTC2');
        $adminA = $this->makeUserWithPermissions($collegeA, self::PERMS);

        $ctxA = $this->makeExamContext($collegeA, 'EMTC1');
        $ctxB = $this->makeExamContext($collegeB, 'EMTC2');
        [, $enrB] = $this->makeEnrolledStudent($collegeB, $ctxB, 'EMTC2A');

        $this->asCollege($collegeA, $adminA)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctxA['schedule']->id,
                'student_enrollment_id' => $enrB->id, // foreign enrollment
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 50,
                'status' => 'entered',
            ], ['Referer' => route('exam-marks.create')])
            ->assertSessionHasErrors(['student_enrollment_id']);

        $this->assertSame(0, ExamMark::withoutGlobalScopes()->whereIn('college_id', [$collegeA->id, $collegeB->id])->count());
    }

    public function test_college_a_schedule_cannot_be_combined_with_college_b_enrollment_in_bulk(): void
    {
        $collegeA = $this->makeCollege('EMTD1');
        $collegeB = $this->makeCollege('EMTD2');
        $adminA = $this->makeUserWithPermissions($collegeA, self::PERMS);

        $ctxA = $this->makeExamContext($collegeA, 'EMTD1');
        $ctxB = $this->makeExamContext($collegeB, 'EMTD2');
        [, $enrA] = $this->makeEnrolledStudent($collegeA, $ctxA, 'EMTD1A');
        [, $enrB] = $this->makeEnrolledStudent($collegeB, $ctxB, 'EMTD2A');

        // Foreign schedule id in bulk.
        $this->asCollege($collegeA, $adminA)
            ->post(route('exam-marks.bulk'), [
                'exam_schedule_id' => $ctxB['schedule']->id,
                'records' => [
                    ['student_enrollment_id' => $enrA->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => 50, 'status' => 'entered'],
                ],
            ], ['Referer' => route('exam-marks.index')])
            ->assertSessionHasErrors(['exam_schedule_id']);

        // Foreign enrollment row in bulk.
        $this->asCollege($collegeA, $adminA)
            ->post(route('exam-marks.bulk'), [
                'exam_schedule_id' => $ctxA['schedule']->id,
                'records' => [
                    ['student_enrollment_id' => $enrB->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => 50, 'status' => 'entered'],
                ],
            ], ['Referer' => route('exam-marks.index', ['exam_schedule_id' => $ctxA['schedule']->id])])
            ->assertSessionHasErrors();

        $this->assertSame(0, ExamMark::withoutGlobalScopes()->whereIn('college_id', [$collegeA->id, $collegeB->id])->count());
    }

    public function test_cannot_update_or_delete_foreign_college_marks(): void
    {
        $collegeA = $this->makeCollege('EMTE1');
        $collegeB = $this->makeCollege('EMTE2');
        $adminA = $this->makeUserWithPermissions($collegeA, self::PERMS);

        $ctxB = $this->makeExamContext($collegeB, 'EMTE2');
        [, $enrB] = $this->makeEnrolledStudent($collegeB, $ctxB, 'EMTE2A');

        $foreign = ExamMark::create([
            'college_id' => $collegeB->id,
            'exam_schedule_id' => $ctxB['schedule']->id,
            'student_enrollment_id' => $enrB->id,
            'max_marks' => 100,
            'passing_marks' => 40,
            'obtained_marks' => 88,
            'status' => 'entered',
        ]);

        $this->asCollege($collegeA, $adminA)
            ->get(route('exam-marks.edit', $foreign))
            ->assertNotFound();

        $this->asCollege($collegeA, $adminA)
            ->put(route('exam-marks.update', $foreign), [
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 10,
                'status' => 'entered',
            ])
            ->assertNotFound();

        $this->asCollege($collegeA, $adminA)
            ->delete(route('exam-marks.destroy', $foreign))
            ->assertNotFound();

        $this->assertSame('88.00', (string) $foreign->fresh()->obtained_marks);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_college_id_from_client_input_is_never_trusted(): void
    {
        $collegeA = $this->makeCollege('EMTF1');
        $collegeB = $this->makeCollege('EMTF2');
        $adminA = $this->makeUserWithPermissions($collegeA, self::PERMS);

        $ctxA = $this->makeExamContext($collegeA, 'EMTF1');
        $this->makeExamContext($collegeB, 'EMTF2');
        [, $enrA] = $this->makeEnrolledStudent($collegeA, $ctxA, 'EMTF1A');

        $this->asCollege($collegeA, $adminA)
            ->post(route('exam-marks.store'), [
                'college_id' => $collegeB->id, // spoofing attempt
                'exam_schedule_id' => $ctxA['schedule']->id,
                'student_enrollment_id' => $enrA->id,
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 50,
                'status' => 'entered',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('exam_marks', [
            'college_id' => $collegeA->id,
            'student_enrollment_id' => $enrA->id,
        ]);
        $this->assertDatabaseMissing('exam_marks', [
            'college_id' => $collegeB->id,
            'student_enrollment_id' => $enrA->id,
        ]);
    }
}
