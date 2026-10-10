<?php

namespace Tests\Feature\Admissions;

use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\College;
use App\Models\Program;
use App\Models\User;
use App\Support\BulkAction\BulkActionRegistry;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionBulkActionsTest extends TestCase
{
    use DepartmentTestHelpers;

    private const ENDPOINT = 'bulk-actions.execute';

    private function makeYear(College $college, string $code = '2026'): AcademicYear
    {
        return AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => '2026-27',
            'code' => $code,
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
    }

    private function makeProgram(College $college, string $code = 'BSC'): Program
    {
        return Program::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => 'BSc',
            'code' => $code,
            'status' => 'active',
        ]);
    }

    private function makeAdmission(College $college, array $overrides = []): Admission
    {
        $year = $overrides['academic_year_id'] ?? $this->makeYear($college, 'Y'.substr(uniqid(), -4))->id;
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Admit',
            'last_name' => $college->code,
            'email' => strtolower($college->code).'-'.uniqid().'@example.test',
            'status' => 'active',
        ]);
        $application = AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year,
            'application_number' => 'APP-'.uniqid(),
            'status' => 'admitted',
        ]);

        return Admission::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id,
            'academic_year_id' => $year,
            'application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'admission_number' => 'ADM-'.uniqid(),
            'admission_date' => '2026-07-01',
            'status' => 'active',
        ], $overrides));
    }

    private function execute(College $college, User $user, string $action, array $ids): TestResponse
    {
        return $this->asCollege($college, $user)->post(route(self::ENDPOINT), [
            'module' => 'admissions',
            'action' => $action,
            'ids' => $ids,
        ]);
    }

    public function test_actions_are_registered(): void
    {
        $registry = app(BulkActionRegistry::class);

        $this->assertTrue($registry->has('admissions', 'export'));
        $this->assertTrue($registry->has('admissions', 'cancel'));
        $this->assertTrue($registry->has('admissions', 'complete'));
        $this->assertFalse($registry->has('admissions', 'delete'));
    }

    public function test_listing_shows_bulk_bar_for_authorized_users(): void
    {
        $college = $this->makeCollege('ABLI');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view', 'admissions.update']);
        $this->makeAdmission($college);

        $this->asCollege($college, $admin)
            ->get(route('admissions.index'))
            ->assertOk()
            ->assertSee('data-module="admissions"', false)
            ->assertSee('data-bulk-action="export"', false)
            ->assertSee('data-bulk-action="complete"', false)
            ->assertSee('data-bulk-action="cancel"', false)
            ->assertSee('data-bulk-selection', false)
            ->assertSee('data-select-all', false)
            ->assertSee('data-select-row', false);
    }

    public function test_viewer_cannot_cancel_or_complete(): void
    {
        $college = $this->makeCollege('ABVW');
        $viewer = $this->makeUserWithPermissions($college, ['admissions.view']);
        $admission = $this->makeAdmission($college);

        $this->execute($college, $viewer, 'cancel', [$admission->id])
            ->assertSessionHasErrors('bulk');
        $this->execute($college, $viewer, 'complete', [$admission->id])
            ->assertSessionHasErrors('bulk');

        $this->assertSame('active', $admission->fresh()->status);
    }

    public function test_guest_is_redirected(): void
    {
        $this->post(route(self::ENDPOINT), [
            'module' => 'admissions',
            'action' => 'export',
            'ids' => [1],
        ])->assertRedirect(route('login'));
    }

    public function test_foreign_and_deleted_ids_are_skipped_on_export(): void
    {
        $collegeA = $this->makeCollege('ABXA');
        $collegeB = $this->makeCollege('ABXB');
        $operator = $this->makeUserWithPermissions($collegeA, ['admissions.view']);
        $own = $this->makeAdmission($collegeA, ['admission_number' => 'ADM-OWN']);
        $deleted = $this->makeAdmission($collegeA, ['admission_number' => 'ADM-DEL']);
        $deleted->delete();
        $foreign = $this->makeAdmission($collegeB, ['admission_number' => 'ADM-FOR']);

        // Only well-formed integer ids here: a malformed id ('x') is rejected by
        // validation for the whole request (see the malformed-id test below).
        // Deleted, foreign and nonexistent ids are valid integers and are skipped.
        $response = $this->execute($collegeA, $operator, 'export', [$own->id, $deleted->id, $foreign->id, 999999]);
        $response->assertRedirect();

        $target = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(route('admissions.export'), $target);
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        $this->assertSame([(string) $own->id], array_map('strval', (array) ($query['ids'] ?? [])));

        // Follow the redirect: the CSV holds only the authorized admission.
        $csv = $this->asCollege($collegeA, $operator)->get($target)->assertOk()->streamedContent();
        $this->assertStringContainsString('ADM-OWN', $csv);
        $this->assertStringNotContainsString('ADM-DEL', $csv);
        $this->assertStringNotContainsString('ADM-FOR', $csv);
    }

    public function test_a_malformed_id_rejects_the_whole_export_selection(): void
    {
        $college = $this->makeCollege('ABXM');
        $operator = $this->makeUserWithPermissions($college, ['admissions.view']);
        $own = $this->makeAdmission($college, ['admission_number' => 'ADM-MAL']);

        // Phase D rule: a malformed id is a validation error for the whole request.
        // Nothing is silently dropped and no export is prepared.
        $response = $this->execute($college, $operator, 'export', [$own->id, 'x']);

        $response->assertSessionHasErrors(['ids.1']);
        $this->assertStringNotContainsString(
            route('admissions.export'),
            (string) $response->headers->get('Location')
        );
    }

    public function test_export_streams_authorized_rows_without_identity_numbers(): void
    {
        $college = $this->makeCollege('ABEX');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view']);
        $admission = $this->makeAdmission($college, ['admission_number' => 'ADM-CSV']);

        $csv = $this->asCollege($college, $admin)
            ->get(route('admissions.export', ['ids' => [$admission->id]]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('ADM-CSV', $csv);
        $this->assertStringContainsString('Admission number', $csv);
        $this->assertStringNotContainsString('aadhaar', strtolower($csv));
    }

    public function test_bulk_cancel_updates_authorized_records(): void
    {
        $college = $this->makeCollege('ABCN');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view', 'admissions.update']);
        $one = $this->makeAdmission($college, ['admission_number' => 'ADM-C1']);
        $two = $this->makeAdmission($college, ['admission_number' => 'ADM-C2']);

        $this->execute($college, $admin, 'cancel', [$one->id, $two->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('cancelled', $one->fresh()->status);
        $this->assertSame('cancelled', $two->fresh()->status);
    }

    public function test_complete_refuses_cancelled_records_and_writes_nothing(): void
    {
        $college = $this->makeCollege('ABCP');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view', 'admissions.update']);
        $active = $this->makeAdmission($college, ['admission_number' => 'ADM-ACT', 'status' => 'active']);
        $cancelled = $this->makeAdmission($college, ['admission_number' => 'ADM-CAN', 'status' => 'cancelled']);

        $this->execute($college, $admin, 'complete', [$active->id, $cancelled->id])
            ->assertSessionHasErrors('bulk');

        $this->assertSame('active', $active->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    public function test_complete_succeeds_for_active_admissions(): void
    {
        $college = $this->makeCollege('ABOK');
        $admin = $this->makeUserWithPermissions($college, ['admissions.view', 'admissions.update']);
        $admission = $this->makeAdmission($college);

        $this->execute($college, $admin, 'complete', [$admission->id])
            ->assertSessionHas('success');

        $this->assertSame('completed', $admission->fresh()->status);
    }

    public function test_mixed_foreign_ids_do_not_mutate_other_colleges(): void
    {
        $collegeA = $this->makeCollege('ABMA');
        $collegeB = $this->makeCollege('ABMB');
        $admin = $this->makeUserWithPermissions($collegeA, ['admissions.view', 'admissions.update']);
        $own = $this->makeAdmission($collegeA);
        $foreign = $this->makeAdmission($collegeB);

        $this->execute($collegeA, $admin, 'cancel', [$own->id, $foreign->id])
            ->assertSessionHas('success');

        $this->assertSame('cancelled', $own->fresh()->status);
        $this->assertSame('active', $foreign->fresh()->status);
    }
}
