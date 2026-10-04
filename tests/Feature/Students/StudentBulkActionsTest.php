<?php

namespace Tests\Feature\Students;

use App\Models\College;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\BulkAction\BulkActionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Step 2 of the Students module: the bulk actions of the Student list — the
 * Export menu (Excel, PDF, Print), Generate ID cards and Bulk documents — all
 * driven through the ONE shared endpoint, `bulk-actions.execute`.
 *
 * The security model under test — identical to the shared contract:
 *  - every action has its own permission AND a per-record Student policy check,
 *  - the client's ids are only a REQUEST to re-query: a foreign college's id, a
 *    soft-deleted student and a student the user may not view are skipped,
 *  - the follow-up URL (CSV download / printable batch) is built server-side
 *    from the ids the handler authorized, and the target re-checks everything,
 *  - no action creates a record: ID cards are rendered, the document pack is a
 *    view of existing documents, and the export is a stream.
 */
class StudentBulkActionsTest extends TestCase
{
    use StudentTestHelpers;

    private const ENDPOINT = 'bulk-actions.execute';

    /**
     * @return array{0: College, 1: User}
     */
    private function collegeWithActions(string $code, array $permissions): array
    {
        $college = $this->makeCollege($code);

        return [$college, $this->makeUserWithPermissions($college, $permissions)];
    }

    private function execute(College $college, User $user, string $action, array $ids, array $extra = []): TestResponse
    {
        return $this->asCollege($college, $user)->post(route(self::ENDPOINT), array_merge([
            'module' => 'students',
            'action' => $action,
            'ids' => $ids,
        ], $extra));
    }

    private function idsFromRedirect(TestResponse $response, string $routeName): array
    {
        $response->assertRedirect();
        $target = $response->headers->get('Location');
        $expected = route($routeName);

        $this->assertStringStartsWith($expected, (string) $target, "Expected a redirect to {$routeName}.");

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        return array_map('intval', (array) ($query['ids'] ?? []));
    }

    public function test_the_actions_are_registered_for_the_students_module(): void
    {
        $registry = app(BulkActionRegistry::class);

        $this->assertTrue($registry->has('students', 'export'));
        $this->assertTrue($registry->has('students', 'id_cards'));
        $this->assertTrue($registry->has('students', 'documents'));
        // The Export menu's two report formats: the same export in a printable
        // form (Excel stays `export`), so every dropdown entry is a registered
        // action the shared endpoint validates against — never a free-form name.
        $this->assertTrue($registry->has('students', 'export_pdf'));
        $this->assertTrue($registry->has('students', 'export_print'));

        // A module that was never registered must stay unknown (no wildcard).
        $this->assertFalse($registry->has('students', 'import'));
        $this->assertFalse($registry->has('students', 'delete'));

        $this->assertSame(
            ['export', 'id_cards', 'documents', 'export_pdf', 'export_print'],
            array_keys($registry->getForModule('students'))
        );
    }

    public function test_each_action_demands_its_own_permission(): void
    {
        [$college] = $this->collegeWithActions('SBA1', []);
        $student = $this->makeStudent($college, ['first_name' => 'Guarded', 'student_number' => 'STU-GUARD']);

        // students.view alone opens the listing but none of the three actions.
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        foreach (['export', 'id_cards', 'documents'] as $action) {
            $this->execute($college, $viewer, $action, [$student->id])
                ->assertRedirect()
                ->assertSessionHasErrors('bulk');
        }

        // Each action succeeds only with its own permission on top of view.
        $this->execute($college, $this->makeUserWithPermissions($college, ['students.view', 'students.export']), 'export', [$student->id])
            ->assertRedirect(route('students.export', ['ids' => [$student->id]]));

        $this->execute($college, $this->makeUserWithPermissions($college, ['students.view', 'student_id_cards.generate']), 'id_cards', [$student->id])
            ->assertRedirect(route('student-id-cards.batch', ['ids' => [$student->id]]));

        $this->execute($college, $this->makeUserWithPermissions($college, ['students.view', 'student_documents.view']), 'documents', [$student->id])
            ->assertRedirect(route('student-documents.batch', ['ids' => [$student->id]]));
    }

    public function test_an_unauthenticated_or_unknown_action_request_is_refused(): void
    {
        [$college, $user] = $this->collegeWithActions('SBA2', ['students.view', 'students.export']);
        $student = $this->makeStudent($college, ['student_number' => 'STU-UNAUTH']);

        // No session: the shared endpoint is behind auth.
        $this->post(route(self::ENDPOINT), ['module' => 'students', 'action' => 'export', 'ids' => [$student->id]])
            ->assertRedirect(route('login'));

        // An action that is not registered for the module fails validation
        // before any handler can run.
        $this->execute($college, $user, 'import', [$student->id])->assertSessionHasErrors('action');
        $this->execute($college, $user, 'export', [])->assertSessionHasErrors('ids');
    }

    public function test_ids_are_requeried_server_side_and_unauthorized_records_are_skipped(): void
    {
        [$collegeA, $operator] = $this->collegeWithActions('SBA3A', ['students.view', 'students.export']);
        $collegeB = $this->makeCollege('SBA3B');

        $own = $this->makeStudent($collegeA, ['first_name' => 'Mine', 'student_number' => 'STU-MINE']);
        $deleted = $this->makeStudent($collegeA, ['first_name' => 'Deleted', 'student_number' => 'STU-DELETED']);
        $deleted->delete();
        $foreign = $this->makeStudent($collegeB, ['first_name' => 'Foreign', 'student_number' => 'STU-THEIRS']);

        // The client claims all three, plus junk.
        $response = $this->execute($collegeA, $operator, 'export', [$own->id, $deleted->id, $foreign->id, 'DROP TABLE students', '999999']);

        $authorized = $this->idsFromRedirect($response, 'students.export');

        $this->assertSame([$own->id], $authorized);

        // The skipped records are reported, not silently swallowed.
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame([(string) $own->id], array_map('strval', (array) $query['ids']));
        $response->assertSessionHas('success', fn ($message) => str_contains((string) $message, '1 student'));
    }

    public function test_a_user_without_the_per_record_policy_ability_authorizes_nothing(): void
    {
        [$college, $operator] = $this->collegeWithActions('SBA4', ['students.export']);
        // Deliberately NOT students.view: the action permission is present, but
        // the per-record policy check must still refuse every student.
        $student = $this->makeStudent($college, ['student_number' => 'STU-NOPOLICY']);

        $this->execute($college, $operator, 'export', [$student->id])
            ->assertRedirect()
            ->assertSessionHasErrors('bulk');
    }

    public function test_bulk_export_hands_back_a_link_and_the_export_only_contains_those_students(): void
    {
        [$college, $operator] = $this->collegeWithActions('SBA5', ['students.view', 'students.export']);

        $selected = $this->makeStudent($college, ['first_name' => 'Wanted', 'student_number' => 'STU-BULK-WANT']);
        $other = $this->makeStudent($college, ['first_name' => 'Unwanted', 'student_number' => 'STU-BULK-UNWANT']);

        $response = $this->execute($college, $operator, 'export', [$selected->id]);
        $ids = $this->idsFromRedirect($response, 'students.export');

        $this->assertSame([$selected->id], $ids);

        // Follow the link the handler produced: the CSV contains the selection.
        $csv = $this->asCollege($college, $operator)
            ->get(route('students.export', ['ids' => $ids]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('STU-BULK-WANT', $csv);
        $this->assertStringNotContainsString('STU-BULK-UNWANT', $csv);

        $this->assertDatabaseHas('audit_logs', ['action' => 'students.exported', 'college_id' => $college->id]);
    }

    public function test_bulk_id_cards_render_every_authorized_student_and_persist_nothing(): void
    {
        [$college, $operator] = $this->collegeWithActions('SBA6', ['students.view', 'student_id_cards.generate']);
        $year = $this->makeYear($college, '2026', '2026-27');
        $program = $this->makeProgram($college, 'BSC');

        $first = $this->makeStudent($college, ['first_name' => 'Card', 'last_name' => 'One', 'student_number' => 'STU-CARD-1']);
        $second = $this->makeStudent($college, ['first_name' => 'Card', 'last_name' => 'Two', 'student_number' => 'STU-CARD-2']);
        $this->makeEnrollment($college, $first, $year, $program, ['enrollment_number' => 'ENR-CARD-1']);
        $this->makeEnrollment($college, $second, $year, $program, ['enrollment_number' => 'ENR-CARD-2']);

        $before = Student::withoutGlobalScopes()->count();

        $response = $this->execute($college, $operator, 'id_cards', [$first->id, $second->id]);
        $ids = $this->idsFromRedirect($response, 'student-id-cards.batch');
        $this->assertSame([$first->id, $second->id], $ids);

        $html = $this->asCollege($college, $operator)
            ->get(route('student-id-cards.batch', ['ids' => $ids]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('STU-CARD-1', $html);
        $this->assertStringContainsString('STU-CARD-2', $html);
        $this->assertStringContainsString('ENR-CARD-1', $html);
        $this->assertStringContainsString('ENR-CARD-2', $html);
        // One audit entry per generated card, and no record anywhere else.
        $this->assertSame(2, DB::table('audit_logs')
            ->where('action', 'student_id_card.generated')
            ->where('college_id', $college->id)
            ->count());
        $this->assertSame($before, Student::withoutGlobalScopes()->count());
        $this->assertSame(2, Student::withoutGlobalScopes()->whereIn('student_number', ['STU-CARD-1', 'STU-CARD-2'])->count());
    }

    public function test_bulk_documents_renders_the_pack_for_authorized_students_only(): void
    {
        [$college, $operator] = $this->collegeWithActions('SBA7', ['students.view', 'student_documents.view']);
        $collegeB = $this->makeCollege('SBA7B');

        $mine = $this->makeStudent($college, ['first_name' => 'Pack', 'student_number' => 'STU-PACK-MINE']);
        $foreign = $this->makeStudent($collegeB, ['first_name' => 'Pack', 'student_number' => 'STU-PACK-THEIRS']);

        $response = $this->execute($college, $operator, 'documents', [$mine->id, $foreign->id]);
        $ids = $this->idsFromRedirect($response, 'student-documents.batch');

        $this->assertSame([$mine->id], $ids);

        $html = $this->asCollege($college, $operator)
            ->get(route('student-documents.batch', ['ids' => [$mine->id, $foreign->id]]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('STU-PACK-MINE', $html);
        $this->assertStringNotContainsString('STU-PACK-THEIRS', $html);
        $this->assertStringContainsString('No documents uploaded for this student yet.', $html);
    }

    public function test_the_batch_endpoints_refuse_ids_outside_the_active_college(): void
    {
        [$college, $operator] = $this->collegeWithActions('SBA8', [
            'students.view', 'student_id_cards.generate', 'student_documents.view', 'students.export',
        ]);
        $collegeB = $this->makeCollege('SBA8B');

        $foreign = $this->makeStudent($collegeB, ['first_name' => 'Intruder', 'student_number' => 'STU-INTRUDER']);

        // A hand-edited URL carrying only a foreign id: both printable batches
        // must find nothing to show rather than fall back to something wider.
        $this->asCollege($college, $operator)
            ->get(route('student-id-cards.batch', ['ids' => [$foreign->id]]))
            ->assertForbidden();

        $this->asCollege($college, $operator)
            ->get(route('student-documents.batch', ['ids' => [$foreign->id]]))
            ->assertForbidden();

        // And the export simply has no such student to write.
        $csv = $this->asCollege($college, $operator)
            ->get(route('students.export', ['ids' => [$foreign->id]]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString('STU-INTRUDER', $csv);
    }

    public function test_a_json_client_receives_the_structured_result(): void
    {
        [$college, $operator] = $this->collegeWithActions('SBA9', ['students.view', 'students.export']);
        $student = $this->makeStudent($college, ['student_number' => 'STU-JSON']);

        $this->asCollege($college, $operator)
            ->postJson(route(self::ENDPOINT), [
                'module' => 'students',
                'action' => 'export',
                'ids' => [$student->id],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('affected', 1)
            ->assertJsonPath('skipped_unauthorized', 0)
            ->assertJsonPath('data.ids', [$student->id]);
    }

    public function test_every_action_is_idempotent_and_creates_no_duplicate_records(): void
    {
        [$college, $operator] = $this->collegeWithActions('SBA10', [
            'students.view', 'students.export', 'student_id_cards.generate', 'student_documents.view',
        ]);
        $student = $this->makeStudent($college, ['student_number' => 'STU-IDEMPOTENT']);

        $counts = fn () => [
            'students' => Student::withoutGlobalScopes()->count(),
            'enrollments' => StudentEnrollment::withoutGlobalScopes()->count(),
            'documents' => StudentDocument::withoutGlobalScopes()->count(),
        ];
        $before = $counts();

        foreach (['export', 'id_cards', 'documents'] as $action) {
            $this->execute($college, $operator, $action, [$student->id])->assertRedirect();
            $this->execute($college, $operator, $action, [$student->id])->assertRedirect();
        }

        $this->assertSame($before, $counts());
    }
}
