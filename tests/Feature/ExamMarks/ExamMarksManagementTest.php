<?php

namespace Tests\Feature\ExamMarks;

use App\Models\AuditLog;
use App\Models\ExamMark;
use App\Models\ExamSchedule;
use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;
use Tests\TestCase;

class ExamMarksManagementTest extends TestCase
{
    use ExamAttendanceTestHelpers;

    private const PERMS = ['exam_marks.view', 'exam_marks.create', 'exam_marks.update', 'exam_marks.delete'];

    public function test_authorized_user_can_view_marks_index_and_entry_grid(): void
    {
        $college = $this->makeCollege('EM01');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM01');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM01A');

        $this->asCollege($college, $admin)
            ->get(route('exam-marks.index'))
            ->assertOk();

        $this->asCollege($college, $admin)
            ->get(route('exam-marks.index', ['exam_schedule_id' => $ctx['schedule']->id]))
            ->assertOk()
            ->assertSee($enrollment->enrollment_number)
            ->assertSee('Stu EM01A');
    }

    public function test_authorized_user_can_create_marks(): void
    {
        $college = $this->makeCollege('EM02');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM02');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM02A');

        $this->asCollege($college, $admin)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 72.5,
                'status' => 'entered',
                'remarks' => 'Good paper',
            ])
            ->assertRedirect(route('exam-marks.index', ['exam_schedule_id' => $ctx['schedule']->id]))
            ->assertSessionHas('success');

        $mark = ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($mark);
        $this->assertSame($ctx['schedule']->id, $mark->exam_schedule_id);
        $this->assertSame($enrollment->id, $mark->student_enrollment_id);
        $this->assertSame('72.50', (string) $mark->obtained_marks);
        $this->assertSame('entered', $mark->status);
        $this->assertNotNull($mark->entered_at);
        $this->assertSame($admin->id, $mark->entered_by);
        $this->assertSame($admin->id, $mark->created_by);
    }

    public function test_authorized_user_can_update_marks(): void
    {
        $college = $this->makeCollege('EM03');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM03');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM03A');

        $mark = ExamMark::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'max_marks' => 100,
            'passing_marks' => 40,
            'obtained_marks' => 55,
            'status' => 'entered',
            'entered_at' => now(),
            'entered_by' => $admin->id,
        ]);

        $this->asCollege($college, $admin)
            ->get(route('exam-marks.edit', $mark))
            ->assertOk();

        $this->asCollege($college, $admin)
            ->put(route('exam-marks.update', $mark), [
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 61,
                'status' => 'entered',
                'remarks' => 'Re-totalled',
            ])
            ->assertRedirect(route('exam-marks.index', ['exam_schedule_id' => $ctx['schedule']->id]));

        $fresh = $mark->fresh();
        $this->assertSame('61.00', (string) $fresh->obtained_marks);
        $this->assertSame('Re-totalled', $fresh->remarks);
        $this->assertSame($admin->id, $fresh->updated_by);
        // Identity fields are immutable through updates.
        $this->assertSame($ctx['schedule']->id, $fresh->exam_schedule_id);
        $this->assertSame($enrollment->id, $fresh->student_enrollment_id);
    }

    public function test_authorized_user_can_delete_marks_soft(): void
    {
        $college = $this->makeCollege('EM04');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM04');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM04A');

        $mark = ExamMark::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'max_marks' => 100,
            'passing_marks' => 40,
            'obtained_marks' => 55,
            'status' => 'entered',
        ]);

        $this->asCollege($college, $admin)
            ->delete(route('exam-marks.destroy', $mark))
            ->assertRedirect();

        $this->assertNotNull($mark->fresh()->deleted_at);
        $this->assertSame(0, ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->whereNull('deleted_at')->count());
        $this->assertSame(1, ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->withTrashed()->count());
    }

    public function test_duplicate_mark_entry_is_rejected(): void
    {
        $college = $this->makeCollege('EM05');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM05');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM05A');

        ExamMark::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'max_marks' => 100,
            'passing_marks' => 40,
            'obtained_marks' => 55,
            'status' => 'entered',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 90,
                'status' => 'entered',
            ], ['Referer' => route('exam-marks.create')])
            ->assertSessionHasErrors(['student_enrollment_id']);

        $this->assertSame(1, ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame('55.00', (string) ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->first()->obtained_marks);
    }

    public function test_obtained_marks_cannot_exceed_max_marks(): void
    {
        $college = $this->makeCollege('EM06');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM06');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM06A');

        $this->asCollege($college, $admin)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 101,
                'status' => 'entered',
            ], ['Referer' => route('exam-marks.create')])
            ->assertSessionHasErrors(['obtained_marks']);

        $this->assertSame(0, ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_negative_marks_are_rejected(): void
    {
        $college = $this->makeCollege('EM07');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM07');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM07A');

        $this->asCollege($college, $admin)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'max_marks' => 100,
                'passing_marks' => -5,
                'obtained_marks' => -1,
                'status' => 'entered',
            ], ['Referer' => route('exam-marks.create')])
            ->assertSessionHasErrors(['obtained_marks', 'passing_marks']);

        // max_marks must be strictly positive.
        $this->asCollege($college, $admin)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'max_marks' => 0,
                'passing_marks' => 0,
                'obtained_marks' => 0,
                'status' => 'entered',
            ], ['Referer' => route('exam-marks.create')])
            ->assertSessionHasErrors(['max_marks']);

        $this->assertSame(0, ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_passing_marks_cannot_exceed_max_marks(): void
    {
        $college = $this->makeCollege('EM08');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM08');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM08A');

        $this->asCollege($college, $admin)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'max_marks' => 50,
                'passing_marks' => 60,
                'obtained_marks' => 45,
                'status' => 'entered',
            ], ['Referer' => route('exam-marks.create')])
            ->assertSessionHasErrors(['passing_marks']);

        $this->assertSame(0, ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_absent_status_keeps_obtained_marks_null(): void
    {
        // Phase 2 decision (documented on the ExamMark model): absent and
        // withheld rows keep obtained_marks NULL while retaining the paper's
        // max/passing scale for later result phases.
        $college = $this->makeCollege('EM09');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM09');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM09A');

        $this->asCollege($college, $admin)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 80, // must be ignored for absent
                'status' => 'absent',
            ])
            ->assertSessionHas('success');

        $mark = ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertSame('absent', $mark->status);
        $this->assertNull($mark->getAttributes()['obtained_marks'] ?? null);
        $this->assertSame('100.00', (string) $mark->max_marks);
        $this->assertSame('40.00', (string) $mark->passing_marks);
        $this->assertNotNull($mark->entered_at);
    }

    public function test_withheld_status_keeps_obtained_marks_null(): void
    {
        $college = $this->makeCollege('EM10');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM10');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM10A');

        $this->asCollege($college, $admin)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'withheld',
                'remarks' => 'Fee clearance pending',
            ])
            ->assertSessionHas('success');

        $mark = ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertSame('withheld', $mark->status);
        $this->assertNull($mark->getAttributes()['obtained_marks'] ?? null);
    }

    public function test_entered_status_requires_obtained_marks(): void
    {
        $college = $this->makeCollege('EM11');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM11');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM11A');

        $this->asCollege($college, $admin)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'entered',
            ], ['Referer' => route('exam-marks.create')])
            ->assertSessionHasErrors(['obtained_marks']);

        // Draft may omit obtained marks.
        $this->asCollege($college, $admin)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => 'draft',
            ])
            ->assertSessionHas('success');

        $mark = ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertSame('draft', $mark->status);
        $this->assertNull($mark->entered_at);
        $this->assertNull($mark->entered_by);
    }

    public function test_bulk_marks_save_works(): void
    {
        $college = $this->makeCollege('EM12');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM12');
        [, $first] = $this->makeEnrolledStudent($college, $ctx, 'EM12A');
        [, $second] = $this->makeEnrolledStudent($college, $ctx, 'EM12B');
        [, $absent] = $this->makeEnrolledStudent($college, $ctx, 'EM12C');
        [, $untouched] = $this->makeEnrolledStudent($college, $ctx, 'EM12D');

        $this->asCollege($college, $admin)
            ->post(route('exam-marks.bulk'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'records' => [
                    ['student_enrollment_id' => $first->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => 75, 'status' => 'entered'],
                    ['student_enrollment_id' => $second->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => 39.5, 'status' => 'entered', 'remarks' => 'Borderline'],
                    ['student_enrollment_id' => $absent->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => '', 'status' => 'absent'],
                    // Untouched grid row: skipped entirely.
                    ['student_enrollment_id' => $untouched->id, 'max_marks' => '', 'passing_marks' => '', 'obtained_marks' => '', 'status' => '', 'remarks' => ''],
                ],
            ])
            ->assertRedirect(route('exam-marks.index', ['exam_schedule_id' => $ctx['schedule']->id]))
            ->assertSessionHas('success');

        $this->assertSame('75.00', (string) ExamMark::withoutGlobalScopes()->where('student_enrollment_id', $first->id)->first()->obtained_marks);
        $secondMark = ExamMark::withoutGlobalScopes()->where('student_enrollment_id', $second->id)->first();
        $this->assertSame('39.50', (string) $secondMark->obtained_marks);
        $this->assertSame('Borderline', $secondMark->remarks);

        $absentMark = ExamMark::withoutGlobalScopes()->where('student_enrollment_id', $absent->id)->first();
        $this->assertSame('absent', $absentMark->status);
        $this->assertNull($absentMark->getAttributes()['obtained_marks'] ?? null);

        $this->assertSame(0, ExamMark::withoutGlobalScopes()->where('student_enrollment_id', $untouched->id)->count());
        $this->assertSame(3, ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_bulk_marks_save_is_transactional_all_or_nothing(): void
    {
        $college = $this->makeCollege('EM13');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM13');
        [, $valid] = $this->makeEnrolledStudent($college, $ctx, 'EM13A');
        [, $invalid] = $this->makeEnrolledStudent($college, $ctx, 'EM13B');

        $this->asCollege($college, $admin)
            ->post(route('exam-marks.bulk'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'records' => [
                    ['student_enrollment_id' => $valid->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => 75, 'status' => 'entered'],
                    // Obtained exceeds max: the whole batch must fail.
                    ['student_enrollment_id' => $invalid->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => 150, 'status' => 'entered'],
                ],
            ], ['Referer' => route('exam-marks.index', ['exam_schedule_id' => $ctx['schedule']->id])])
            ->assertSessionHasErrors();

        $this->assertSame(0, ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_bulk_marks_errors_reference_the_offending_row(): void
    {
        $college = $this->makeCollege('EM14');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM14');
        [, $valid] = $this->makeEnrolledStudent($college, $ctx, 'EM14A');
        [, $invalid] = $this->makeEnrolledStudent($college, $ctx, 'EM14B');

        $this->asCollege($college, $admin)
            ->from(route('exam-marks.index', ['exam_schedule_id' => $ctx['schedule']->id]))
            ->post(route('exam-marks.bulk'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'records' => [
                    ['student_enrollment_id' => $valid->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => 75, 'status' => 'entered'],
                    ['student_enrollment_id' => $invalid->id, 'max_marks' => 100, 'passing_marks' => 120, 'obtained_marks' => 50, 'status' => 'entered'],
                ],
            ])
            ->assertSessionHasErrors(['records.1.passing_marks']);

        $this->assertSame(0, ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_bulk_repeated_save_updates_existing_rows_without_duplicates(): void
    {
        $college = $this->makeCollege('EM15');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM15');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM15A');

        $payload = fn (string $obtained) => [
            'exam_schedule_id' => $ctx['schedule']->id,
            'records' => [
                ['student_enrollment_id' => $enrollment->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => $obtained, 'status' => 'entered'],
            ],
        ];

        $this->asCollege($college, $admin)->post(route('exam-marks.bulk'), $payload('55'))->assertSessionHas('success');
        $this->asCollege($college, $admin)->post(route('exam-marks.bulk'), $payload('62'))->assertSessionHas('success');

        $rows = ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('62.00', (string) $rows->first()->obtained_marks);
    }

    public function test_invalid_student_enrollment_is_rejected(): void
    {
        $college = $this->makeCollege('EM16');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM16');

        $this->asCollege($college, $admin)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => 999999,
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 50,
                'status' => 'entered',
            ], ['Referer' => route('exam-marks.create')])
            ->assertSessionHasErrors(['student_enrollment_id']);

        // Contextually mismatched enrollment (another section, same college).
        $other = $this->makeExamContext($college, 'EM16B', ['exam_date' => '2026-10-04']);
        [, $mismatched] = $this->makeEnrolledStudent($college, $other, 'EM16X');

        $this->asCollege($college, $admin)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $mismatched->id,
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 50,
                'status' => 'entered',
            ], ['Referer' => route('exam-marks.create')])
            ->assertSessionHasErrors(['student_enrollment_id']);

        $this->assertSame(0, ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_soft_deleted_marks_can_be_reentered(): void
    {
        $college = $this->makeCollege('EM17');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM17');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM17A');

        $mark = ExamMark::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'max_marks' => 100,
            'passing_marks' => 40,
            'obtained_marks' => 55,
            'status' => 'entered',
        ]);
        $mark->delete();

        $this->asCollege($college, $admin)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 70,
                'status' => 'entered',
            ])
            ->assertSessionHas('success');

        $active = ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->whereNull('deleted_at')->get();
        $this->assertCount(1, $active);
        $this->assertSame('70.00', (string) $active->first()->obtained_marks);
        $this->assertSame(2, ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->withTrashed()->count());
    }

    public function test_audit_log_created_for_marks_lifecycle(): void
    {
        $college = $this->makeCollege('EM18');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM18');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM18A');

        $this->asCollege($college, $admin)->post(route('exam-marks.store'), [
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'max_marks' => 100,
            'passing_marks' => 40,
            'obtained_marks' => 55,
            'status' => 'entered',
        ])->assertSessionHas('success');

        $mark = ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->asCollege($college, $admin)->put(route('exam-marks.update', $mark), [
            'max_marks' => 100,
            'passing_marks' => 40,
            'obtained_marks' => 60,
            'status' => 'entered',
        ])->assertRedirect();

        $this->asCollege($college, $admin)->delete(route('exam-marks.destroy', $mark))->assertRedirect();

        foreach (['exam_marks.created', 'exam_marks.updated', 'exam_marks.deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'college_id' => $college->id,
                'user_id' => $admin->id,
                'subject_id' => $mark->id,
            ]);
        }
    }

    public function test_bulk_marks_save_is_audited_as_one_traceable_entry(): void
    {
        $college = $this->makeCollege('EM19');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM19');
        [, $first] = $this->makeEnrolledStudent($college, $ctx, 'EM19A');
        [, $second] = $this->makeEnrolledStudent($college, $ctx, 'EM19B');

        $this->asCollege($college, $admin)->post(route('exam-marks.bulk'), [
            'exam_schedule_id' => $ctx['schedule']->id,
            'records' => [
                ['student_enrollment_id' => $first->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => 75, 'status' => 'entered'],
                ['student_enrollment_id' => $second->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => 80, 'status' => 'entered'],
            ],
        ])->assertSessionHas('success');

        $audit = AuditLog::query()->where('action', 'exam_marks.bulk_saved')->where('college_id', $college->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame(2, $audit->new_values['created']);
    }

    public function test_filters_work(): void
    {
        $college = $this->makeCollege('EM20');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctxA = $this->makeExamContext($college, 'EM20A');
        $ctxB = $this->makeExamContext($college, 'EM20B', ['exam_date' => '2026-10-05']);
        [, $enrA] = $this->makeEnrolledStudent($college, $ctxA, 'EM20A1');
        [, $enrB] = $this->makeEnrolledStudent($college, $ctxB, 'EM20B1');

        ExamMark::create(['college_id' => $college->id, 'exam_schedule_id' => $ctxA['schedule']->id, 'student_enrollment_id' => $enrA->id, 'max_marks' => 100, 'passing_marks' => 40, 'obtained_marks' => 70, 'status' => 'entered']);
        ExamMark::create(['college_id' => $college->id, 'exam_schedule_id' => $ctxB['schedule']->id, 'student_enrollment_id' => $enrB->id, 'max_marks' => 100, 'passing_marks' => 40, 'status' => 'absent']);

        $this->asCollege($college, $admin)
            ->get(route('exam-marks.index', ['status' => 'absent']))
            ->assertOk()
            ->assertSee($enrB->enrollment_number)
            ->assertDontSee($enrA->enrollment_number);

        $this->asCollege($college, $admin)
            ->get(route('exam-marks.index', ['subject_id' => $ctxA['sub']->id]))
            ->assertOk()
            ->assertSee($enrA->enrollment_number)
            ->assertDontSee($enrB->enrollment_number);

        $this->asCollege($college, $admin)
            ->get(route('exam-marks.index', ['examination_id' => $ctxB['exam']->id]))
            ->assertOk()
            ->assertSee($enrB->enrollment_number)
            ->assertDontSee($enrA->enrollment_number);

        $this->asCollege($college, $admin)
            ->get(route('exam-marks.index', ['exam_date' => '2026-10-05']))
            ->assertOk()
            ->assertSee($enrB->enrollment_number)
            ->assertDontSee($enrA->enrollment_number);
    }

    public function test_pagination_is_deterministic(): void
    {
        $college = $this->makeCollege('EM21');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM21');

        $first = null;
        $last = null;
        for ($i = 1; $i <= 18; $i++) {
            [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM21'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            ExamMark::create([
                'college_id' => $college->id,
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 50 + $i,
                'status' => 'entered',
            ]);
            if ($i === 1) {
                $first = $enrollment->enrollment_number;
            }
            if ($i === 18) {
                $last = $enrollment->enrollment_number;
            }
        }

        $this->asCollege($college, $admin)
            ->get(route('exam-marks.index'))
            ->assertOk()
            ->assertSee($last)
            ->assertDontSee($first);

        $this->asCollege($college, $admin)
            ->get(route('exam-marks.index', ['page' => 2]))
            ->assertOk()
            ->assertSee($first)
            ->assertDontSee($last);
    }

    public function test_marks_cannot_be_entered_against_soft_deleted_schedule(): void
    {
        $college = $this->makeCollege('EM22');
        $admin = $this->makeUserWithPermissions($college, self::PERMS);
        $ctx = $this->makeExamContext($college, 'EM22');
        [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'EM22A');

        $ctx['schedule']->delete();

        $this->asCollege($college, $admin)
            ->post(route('exam-marks.store'), [
                'exam_schedule_id' => $ctx['schedule']->id,
                'student_enrollment_id' => $enrollment->id,
                'max_marks' => 100,
                'passing_marks' => 40,
                'obtained_marks' => 50,
                'status' => 'entered',
            ], ['Referer' => route('exam-marks.create')])
            ->assertSessionHasErrors(['exam_schedule_id']);

        $this->assertSame(0, ExamMark::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }
}
