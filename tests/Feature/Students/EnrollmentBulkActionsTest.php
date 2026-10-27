<?php

namespace Tests\Feature\Students;

use App\Domain\Student\Actions\GenerateEnrollmentNumber;
use App\Domain\Student\Actions\GenerateRollNumber;
use App\Domain\Student\Actions\GenerateStudentNumber;
use App\Domain\Student\BulkActions\EnrollmentBulkStatusHandler;
use App\Domain\Student\Services\StudentPhotoService;
use App\Domain\Student\Services\StudentService;
use App\Models\College;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\BulkAction\BulkActionRegistry;
use App\Support\Tenancy\TenantContext;
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
            ->assertSee('data-bulk-action="change_status"', false)
            ->assertSee('Change Status → Active')
            ->assertSee('Change Status → Completed')
            ->assertSee('Change Status → Cancelled')
            ->assertSee('Change Status → Withdrawn')
            ->assertSee('data-select-row', false)
            ->assertSee('data-select-all', false);
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

    public function test_bulk_status_action_rejects_completed_cancelled_and_withdrawn_reactivation(): void
    {
        $college = $this->makeCollege('ENDP');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.update']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $enrollments = [];

        foreach (['completed', 'cancelled', 'withdrawn'] as $index => $status) {
            $student = $this->makeStudent($college, ['student_number' => 'STU-END-'.$index]);
            $enrollments[] = $this->makeEnrollment($college, $student, $year, $program, [
                'enrollment_number' => 'ENR-END-'.$index,
                'status' => $status,
            ]);
        }

        $this->execute($college, $admin, 'change_status', array_map(fn ($e) => $e->id, $enrollments), ['status' => 'active'])
            ->assertSessionHasErrors('bulk');

        foreach ($enrollments as $index => $enrollment) {
            $this->assertSame(['completed', 'cancelled', 'withdrawn'][$index], $enrollment->fresh()->status);
            $this->assertDatabaseMissing('audit_logs', [
                'action' => 'student_enrollment.updated',
                'subject_id' => $enrollment->id,
            ]);
        }
    }

    public function test_bulk_reactivation_returns_the_central_lifecycle_validation_error(): void
    {
        $college = $this->makeCollege('ENBSTA');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.update']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $enrollment = $this->makeEnrollment($college, $student, $year, $program, [
            'enrollment_number' => 'ENR-ENB-TERM',
            'status' => 'completed',
        ]);

        $this->execute($college, $admin, 'change_status', [$enrollment->id], ['status' => 'active'])
            ->assertSessionHasErrors('bulk');

        $this->assertSame('completed', $enrollment->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'student_enrollment.updated',
            'subject_id' => $enrollment->id,
        ]);
    }

    public function test_bulk_handler_requeries_stale_status_and_processes_locks_in_stable_student_order(): void
    {
        $college = $this->makeCollege('ENORDER');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.update']);
        $year = $this->makeYear($college);
        $firstStudent = $this->makeStudent($college, ['student_number' => 'STU-ORDER-1']);
        $secondStudent = $this->makeStudent($college, ['student_number' => 'STU-ORDER-2']);

        // Insert the enrollment rows in the opposite order to the student lock
        // order, and make one caller model stale to exercise the in-transaction
        // re-read rather than trusting request-time status.
        $secondEnrollment = $this->makeEnrollment($college, $secondStudent, $year, null, [
            'enrollment_number' => 'ENR-ORDER-2',
        ]);
        $firstEnrollment = $this->makeEnrollment($college, $firstStudent, $year, null, [
            'enrollment_number' => 'ENR-ORDER-1',
        ]);
        $secondEnrollment->status = 'completed';

        $students = new class(
            app(GenerateStudentNumber::class),
            app(GenerateEnrollmentNumber::class),
            app(GenerateRollNumber::class),
            app(StudentPhotoService::class),
            app(AuditLogService::class),
        ) extends StudentService {
            /** @var list<int> */
            public array $updatedStudentIds = [];

            public function updateEnrollment(StudentEnrollment $enrollment, array $data, int $collegeId): StudentEnrollment
            {
                $this->updatedStudentIds[] = (int) $enrollment->student_id;

                return parent::updateEnrollment($enrollment, $data, $collegeId);
            }
        };

        app(TenantContext::class)->set($college);

        $result = (new EnrollmentBulkStatusHandler($students))->handle(
            new \Illuminate\Database\Eloquent\Collection([$secondEnrollment, $firstEnrollment]),
            $admin,
            $college,
            ['status' => 'completed'],
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertSame([$firstStudent->id, $secondStudent->id], $students->updatedStudentIds);
        $this->assertSame('completed', $firstEnrollment->fresh()->status);
        $this->assertSame('completed', $secondEnrollment->fresh()->status);
    }

    public function test_bulk_handler_rolls_back_prior_updates_when_a_later_update_fails(): void
    {
        $college = $this->makeCollege('ENROLLBACK');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.update']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $first = $this->makeEnrollment($college, $this->makeStudent($college), $year, $program, ['status' => 'active']);
        $second = $this->makeEnrollment($college, $this->makeStudent($college), $year, $program, ['status' => 'active']);

        // Fail after the first real service update/audit has run. The handler's
        // transaction must roll that work back along with the later failure.
        $students = new class(
            app(GenerateStudentNumber::class),
            app(GenerateEnrollmentNumber::class),
            app(GenerateRollNumber::class),
            app(StudentPhotoService::class),
            app(AuditLogService::class),
        ) extends StudentService {
            private int $updateCalls = 0;

            public function updateEnrollment(StudentEnrollment $enrollment, array $data, int $collegeId): StudentEnrollment
            {
                if (++$this->updateCalls === 2) {
                    throw new \RuntimeException('Simulated second-record failure.');
                }

                return parent::updateEnrollment($enrollment, $data, $collegeId);
            }
        };

        app(TenantContext::class)->set($college);

        $result = (new EnrollmentBulkStatusHandler($students))->handle(
            new \Illuminate\Database\Eloquent\Collection([$first, $second]),
            $admin,
            $college,
            ['status' => 'completed'],
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('active', $first->fresh()->status);
        $this->assertSame('active', $second->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'student_enrollment.updated',
            'subject_id' => $first->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'student_enrollment.updated',
            'subject_id' => $second->id,
        ]);
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
