<?php

namespace Tests\Feature\Students;

use App\Models\College;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\BulkAction\BulkActionRegistry;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EnrollmentBulkActionsTest extends TestCase
{
    use StudentTestHelpers;

    private const ENDPOINT = 'bulk-actions.execute';

    private function execute(College $college, User $user, string $action, array $ids, array $parameters = []): TestResponse
    {
        $payload = [
            'module' => 'enrollments',
            'action' => $action,
            'ids' => $ids,
        ];

        if ($parameters !== []) {
            $payload['parameters'] = $parameters;
        }

        return $this->asCollege($college, $user)->post(route(self::ENDPOINT), $payload);
    }

    public function test_actions_are_registered(): void
    {
        $registry = app(BulkActionRegistry::class);

        $this->assertTrue($registry->has('enrollments', 'export'));
        $this->assertTrue($registry->has('enrollments', 'change_status'));
        $this->assertFalse($registry->has('enrollments', 'delete'));
        $this->assertFalse($registry->has('enrollments', 'import'));
    }

    public function test_listing_exposes_bulk_controls(): void
    {
        $college = $this->makeCollege('ENLI');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.update']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college);
        $this->makeEnrollment($college, $student, $year);

        $this->asCollege($college, $admin)
            ->get(route('student-enrollments.index'))
            ->assertOk()
            ->assertSee('data-module="enrollments"', false)
            ->assertSee('data-bulk-action="export"', false)
            ->assertSee('data-bulk-action="change_status"', false);
    }

    public function test_permission_is_required(): void
    {
        $college = $this->makeCollege('ENPM');
        $viewer = $this->makeUserWithPermissions($college, ['student_enrollments.view']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college);
        $enrollment = $this->makeEnrollment($college, $student, $year);

        $this->execute($college, $viewer, 'change_status', [$enrollment->id], ['status' => 'completed'])
            ->assertSessionHasErrors('bulk');

        $this->assertSame('active', $enrollment->fresh()->status);
    }

    public function test_guest_is_redirected(): void
    {
        $this->post(route(self::ENDPOINT), [
            'module' => 'enrollments',
            'action' => 'export',
            'ids' => [1],
        ])->assertRedirect(route('login'));
    }

    public function test_foreign_ids_are_skipped_on_export(): void
    {
        $collegeA = $this->makeCollege('ENXA');
        $collegeB = $this->makeCollege('ENXB');
        $operator = $this->makeUserWithPermissions($collegeA, ['student_enrollments.view']);
        $own = $this->makeEnrollment($collegeA, $this->makeStudent($collegeA), $this->makeYear($collegeA));
        $foreign = $this->makeEnrollment($collegeB, $this->makeStudent($collegeB), $this->makeYear($collegeB));

        $response = $this->execute($collegeA, $operator, 'export', [$own->id, $foreign->id, 999999]);
        $response->assertRedirect();
        $this->assertStringStartsWith(route('student-enrollments.export'), (string) $response->headers->get('Location'));

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame([(string) $own->id], array_map('strval', (array) ($query['ids'] ?? [])));
    }

    public function test_export_csv_omits_identity_fields(): void
    {
        $college = $this->makeCollege('ENEX');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view']);
        $student = $this->makeStudent($college, ['student_number' => 'STU-ENEX']);
        $enrollment = $this->makeEnrollment($college, $student, $this->makeYear($college), null, [
            'enrollment_number' => 'ENR-CSV',
        ]);

        $csv = $this->asCollege($college, $admin)
            ->get(route('student-enrollments.export', ['ids' => [$enrollment->id]]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('ENR-CSV', $csv);
        $this->assertStringContainsString('STU-ENEX', $csv);
        $this->assertStringNotContainsString('aadhaar', strtolower($csv));
        $this->assertStringNotContainsString('govt_id', strtolower($csv));
    }

    public function test_bulk_status_update_uses_existing_service_path(): void
    {
        $college = $this->makeCollege('ENST');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.update']);
        $year = $this->makeYear($college);
        $one = $this->makeEnrollment($college, $this->makeStudent($college, ['student_number' => 'STU-E1']), $year);
        $two = $this->makeEnrollment($college, $this->makeStudent($college, ['student_number' => 'STU-E2']), $year);

        $this->execute($college, $admin, 'change_status', [$one->id, $two->id], ['status' => 'completed'])
            ->assertSessionHas('success');

        $this->assertSame('completed', $one->fresh()->status);
        $this->assertSame('completed', $two->fresh()->status);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $college = $this->makeCollege('ENIV');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.update']);
        $enrollment = $this->makeEnrollment($college, $this->makeStudent($college), $this->makeYear($college));

        $this->execute($college, $admin, 'change_status', [$enrollment->id], ['status' => 'not-a-status'])
            ->assertSessionHasErrors('bulk');

        $this->assertSame('active', $enrollment->fresh()->status);
    }

    public function test_reactivating_a_duplicate_rolls_back_the_batch(): void
    {
        $college = $this->makeCollege('ENDP');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.update']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $other = $this->makeStudent($college, ['student_number' => 'STU-OTH']);

        $keepActive = $this->makeEnrollment($college, $student, $year, $program, [
            'enrollment_number' => 'ENR-KEEP',
            'status' => 'active',
        ]);
        $duplicate = $this->makeEnrollment($college, $student, $year, $program, [
            'enrollment_number' => 'ENR-DUP',
            'status' => 'cancelled',
        ]);
        $unrelated = $this->makeEnrollment($college, $other, $year, $program, [
            'enrollment_number' => 'ENR-OTH',
            'status' => 'cancelled',
        ]);

        $this->execute($college, $admin, 'change_status', [$unrelated->id, $duplicate->id], ['status' => 'active'])
            ->assertSessionHasErrors('bulk');

        $this->assertSame('active', $keepActive->fresh()->status);
        $this->assertSame('cancelled', $duplicate->fresh()->status);
        $this->assertSame('cancelled', $unrelated->fresh()->status, 'The batch must roll back so the unrelated row is not left active.');
    }

    public function test_foreign_enrollment_is_not_updated(): void
    {
        $collegeA = $this->makeCollege('ENFA');
        $collegeB = $this->makeCollege('ENFB');
        $admin = $this->makeUserWithPermissions($collegeA, ['student_enrollments.view', 'student_enrollments.update']);
        $own = $this->makeEnrollment($collegeA, $this->makeStudent($collegeA), $this->makeYear($collegeA));
        $foreign = $this->makeEnrollment($collegeB, $this->makeStudent($collegeB), $this->makeYear($collegeB));

        $this->execute($collegeA, $admin, 'change_status', [$own->id, $foreign->id], ['status' => 'withdrawn'])
            ->assertSessionHas('success');

        $this->assertSame('withdrawn', $own->fresh()->status);
        $this->assertSame('active', $foreign->fresh()->status);
    }
}
