<?php

namespace Tests\Feature\Students;

use App\Domain\Student\Services\StudentHistoryService;
use App\Domain\Student\Support\StudentHistoryEvent;
use App\Models\AuditLog;
use App\Models\StudentDocument;
use App\Models\StudentPromotion;
use Tests\TestCase;

/**
 * The consolidated lifecycle timeline.
 *
 * History is DERIVED from the modules that own the facts plus the append-only
 * audit log — there is deliberately no student_history table — so these tests
 * pin the two things that make a derived timeline trustworthy: it is complete
 * across every lifecycle stage, and its order is deterministic and oldest-first.
 */
class StudentHistoryTest extends TestCase
{
    use StudentTestHelpers;

    public function test_index_requires_the_history_permission(): void
    {
        $college = $this->makeCollege('HSTPERM');
        $nobody = $this->makeUserWithPermissions($college, ['students.view']);
        $viewer = $this->makeUserWithPermissions($college, ['student_history.view', 'students.view']);

        $this->asCollege($college, $nobody)->get(route('student-history.index'))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('student-history.index'))->assertOk()->assertSee('Student History');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('student-history.index'))->assertRedirect(route('login'));
    }

    /**
     * Every lifecycle stage contributes an event, in timestamp order.
     */
    public function test_timeline_covers_every_lifecycle_stage_in_chronological_order(): void
    {
        $college = $this->makeCollege('HSTORDER');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $documentType = $this->makeDocumentType($college);

        $student = $this->makeStudent($college, ['student_number' => 'STU-HST-ORDER']);
        $this->stamp($student, '2026-01-01 09:00:00');

        $enrollment = $this->makeEnrollment($college, $student, $year, $program);
        $this->stamp($enrollment, '2026-02-01 09:00:00');

        $record = $this->makeAcademicRecord($college, $student, $year);
        $this->stamp($record, '2026-03-01 09:00:00');

        $promotion = StudentPromotion::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'source_enrollment_id' => $enrollment->id,
            'source_academic_year_id' => $year->id,
            'target_academic_year_id' => $this->makeNextYear($college)->id,
            'status' => 'approved',
            'approved_by' => null,
            'approved_at' => '2026-04-02 10:00:00',
        ]);
        $this->stamp($promotion, '2026-04-01 09:00:00');

        $transfer = $this->makeTransfer($college, $student, [
            'tc_number' => 'TC-2026-0007',
            'tc_status' => 'issued',
            'tc_issue_date' => '2026-05-03',
            'approved_at' => '2026-05-02 10:00:00',
            'status' => 'approved',
        ]);
        $this->stamp($transfer, '2026-05-01 09:00:00');

        $document = StudentDocument::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'document_type_id' => $documentType->id,
            'title' => 'Class 12 marksheet',
            'file_path' => sprintf('students/%d/%d/abc123.pdf', $college->id, $student->id),
            'original_filename' => 'marksheet.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 20480,
            'verification_status' => 'verified',
            'verified_at' => '2026-06-02 10:00:00',
        ]);
        $this->stamp($document, '2026-06-01 09:00:00');

        $events = app(StudentHistoryService::class)->forStudent($student->fresh());

        $this->assertSame([
            'Student record created',
            'Enrollment created',
            'Academic record',
            'Promotion requested',
            'Promotion approved',
            'Transfer / TC requested',
            'Transfer approved',
            'Transfer certificate issued',
            'Document uploaded',
            'Document verified',
        ], $events->pluck('label')->all());

        $this->assertSame([
            'student', 'enrollment', 'academic', 'promotion', 'promotion',
            'transfer', 'transfer', 'transfer', 'document', 'document',
        ], $events->pluck('category')->all());

        // Deterministic: the same data yields exactly the same sort keys.
        $keys = $events->map(fn (StudentHistoryEvent $e) => $e->sortKey())->all();
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys, 'Timeline events must already be in sortKey order.');
    }

    /**
     * Two events in the same second keep a stable order (category rank, then id),
     * so refreshing the page can never shuffle the timeline.
     */
    public function test_events_with_identical_timestamps_are_still_ordered_deterministically(): void
    {
        $college = $this->makeCollege('HSTSAME');
        $year = $this->makeYear($college);
        $student = $this->makeStudent($college);
        $enrollment = $this->makeEnrollment($college, $student, $year, $this->makeProgram($college));

        $same = '2026-07-01 08:30:00';
        $this->stamp($student, $same);
        $this->stamp($enrollment, $same);
        $this->stamp($this->makeAcademicRecord($college, $student, $year), $same);

        $events = app(StudentHistoryService::class)->forStudent($student->fresh());

        $this->assertSame(
            ['Student record created', 'Enrollment created', 'Academic record'],
            $events->pluck('label')->all(),
            'Same-timestamp events are ordered by the fixed category rank.'
        );
    }

    public function test_a_cross_college_student_is_not_found(): void
    {
        $collegeA = $this->makeCollege('HSTXA');
        $collegeB = $this->makeCollege('HSTXB');
        $studentB = $this->makeStudent($collegeB, ['student_number' => 'STU-HST-FOREIGN']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_history.view', 'students.view']);

        $this->asCollege($collegeA, $adminA)->get(route('student-history.show', $studentB))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->get(route('student-history.index', ['student_id' => $studentB->id]))->assertNotFound();

        // The foreign student never appears in the picker either.
        $this->asCollege($collegeA, $adminA)
            ->get(route('student-history.index'))
            ->assertOk()
            ->assertDontSee('STU-HST-FOREIGN');
    }

    public function test_the_history_view_is_also_gated_by_the_student_view_permission(): void
    {
        $college = $this->makeCollege('HSTGATE');
        $student = $this->makeStudent($college);
        // student_history.view opens the module, but the timeline shows student
        // records, so the Student policy must pass too (layered, never weaker).
        $historyOnly = $this->makeUserWithPermissions($college, ['student_history.view']);

        $this->asCollege($college, $historyOnly)->get(route('student-history.show', $student))->assertForbidden();
    }

    public function test_college_activity_feed_only_shows_the_active_college(): void
    {
        $collegeA = $this->makeCollege('HSTFEEDA');
        $collegeB = $this->makeCollege('HSTFEEDB');
        $cardA = $this->makeUserWithPermissions($collegeA, ['student_history.view', 'students.view', 'student_id_cards.view', 'student_id_cards.generate']);
        $cardB = $this->makeUserWithPermissions($collegeB, ['student_history.view', 'students.view', 'student_id_cards.view', 'student_id_cards.generate']);
        $studentA = $this->makeStudent($collegeA, ['student_number' => 'STU-FEED-A']);
        $studentB = $this->makeStudent($collegeB, ['student_number' => 'STU-FEED-B']);

        // Generating an ID card writes an audited, student-subject event.
        $this->asCollege($collegeA, $cardA)->get(route('student-id-cards.show', $studentA))->assertOk();
        $this->asCollege($collegeB, $cardB)->get(route('student-id-cards.show', $studentB))->assertOk();

        $this->asCollege($collegeA, $cardA)
            ->get(route('student-history.index'))
            ->assertSee('ID card generated')
            ->assertSee('STU-FEED-A')
            ->assertDontSee('STU-FEED-B');

        $this->asCollege($collegeB, $cardB)
            ->get(route('student-history.index'))
            ->assertSee('STU-FEED-B')
            ->assertDontSee('STU-FEED-A');
    }

    public function test_plain_page_views_are_not_audited(): void
    {
        $college = $this->makeCollege('HSTQUIET');
        $student = $this->makeStudent($college);
        $viewer = $this->makeUserWithPermissions($college, ['student_history.view', 'students.view', 'student_academic_records.view']);

        $this->asCollege($college, $viewer)->get(route('student-history.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('student-history.show', $student))->assertOk();
        $this->asCollege($college, $viewer)->get(route('student-academic-records.index'))->assertOk();

        $this->assertSame(
            0,
            AuditLog::query()->where('action', 'like', 'student%')->count(),
            'Read-only history pages must not write audit entries.'
        );
    }

    /**
     * Force a timestamp on an existing row (created_at is not mass-assignable).
     */
    private function stamp($model, string $timestamp): void
    {
        $model->created_at = $timestamp;
        $model->save();
    }
}
