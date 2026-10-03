<?php

namespace Tests\Feature\BulkAction;

use App\Models\AdmissionApplicant;
use App\Models\College;
use App\Models\User;
use App\Support\BulkAction\BulkActionHandler;
use App\Support\BulkAction\BulkActionRegistry;
use App\Support\BulkAction\BulkActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Tests\Feature\Students\StudentTestHelpers;
use Tests\TestCase;

class TestApplicantBulkActionHandler extends BulkActionHandler
{
    public function modelClass(): string
    {
        return AdmissionApplicant::class;
    }

    public function requiredPermission(): ?string
    {
        return 'admission_applicants.update';
    }

    public function policyAbility(): ?string
    {
        return 'update';
    }

    public function handle(Collection $records, User $user, College $college, array $parameters = []): BulkActionResult
    {
        $count = 0;
        foreach ($records as $record) {
            $record->update(['status' => 'inactive']);
            $count++;
        }

        return BulkActionResult::success("Successfully updated {$count} applicants.", $count);
    }
}

class BulkActionContractTest extends TestCase
{
    use StudentTestHelpers;

    public function test_bulk_action_registry_registration_and_retrieval(): void
    {
        $registry = new BulkActionRegistry();
        $this->assertFalse($registry->has('applicants', 'deactivate'));

        $registry->register('applicants', 'deactivate', TestApplicantBulkActionHandler::class);
        $this->assertTrue($registry->has('applicants', 'deactivate'));

        $handler = $registry->get('applicants', 'deactivate');
        $this->assertInstanceOf(TestApplicantBulkActionHandler::class, $handler);
    }

    public function test_bulk_action_rejects_missing_permission(): void
    {
        $college = $this->makeCollege('BA1');
        $applicant = AdmissionApplicant::create([
            'college_id' => $college->id,
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'status' => 'active',
        ]);

        // User without admission_applicants.update
        $user = $this->makeUserWithPermissions($college, ['admission_applicants.view']);
        $handler = new TestApplicantBulkActionHandler();

        $result = $handler->execute([$applicant->id], $user, $college);

        $this->assertFalse($result->isSuccessful());
        $this->assertTrue($result->isForbidden());
        $this->assertStringContainsString('missing required permission', $result->getMessage());
    }

    public function test_bulk_action_strictly_filters_out_cross_tenant_ids(): void
    {
        $collegeA = $this->makeCollege('BAA');
        $collegeB = $this->makeCollege('BAB');

        $applicantA = AdmissionApplicant::create([
            'college_id' => $collegeA->id,
            'first_name' => 'Alice TenantA',
            'last_name' => 'Smith',
            'status' => 'active',
        ]);
        $applicantB = AdmissionApplicant::create([
            'college_id' => $collegeB->id,
            'first_name' => 'Bob TenantB',
            'last_name' => 'Jones',
            'status' => 'active',
        ]);

        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_applicants.view', 'admission_applicants.update']);
        $handler = new TestApplicantBulkActionHandler();

        // Admin of college A attempts to operate on both applicantA and applicantB
        $result = $handler->execute([$applicantA->id, $applicantB->id], $adminA, $collegeA);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->getAffectedCount());
        // applicantB from collegeB was unauthorized/skipped
        $this->assertSame(1, $result->getSkippedUnauthorizedCount());

        // Verify college A applicant was modified, but college B applicant was completely untouched
        $this->assertSame('inactive', $applicantA->fresh()->status);
        $this->assertSame('active', $applicantB->fresh()->status);
    }

    public function test_bulk_action_endpoint_http_flow(): void
    {
        $college = $this->makeCollege('BA3');
        $admin = $this->makeUserWithPermissions($college, ['admission_applicants.view', 'admission_applicants.update']);

        $applicant = AdmissionApplicant::create([
            'college_id' => $college->id,
            'first_name' => 'Charlie',
            'last_name' => 'Brown',
            'status' => 'active',
        ]);

        /** @var BulkActionRegistry $registry */
        $registry = app(BulkActionRegistry::class);
        $registry->register('applicants', 'deactivate', TestApplicantBulkActionHandler::class);

        // HTTP JSON request
        $response = $this->asCollege($college, $admin)->postJson(route('bulk-actions.execute'), [
            'module' => 'applicants',
            'action' => 'deactivate',
            'ids' => [$applicant->id],
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'affected' => 1,
            'skipped_unauthorized' => 0,
        ]);

        $this->assertSame('inactive', $applicant->fresh()->status);
    }

    public function test_bulk_action_endpoint_rejects_unregistered_module_or_action(): void
    {
        $college = $this->makeCollege('BA4');
        $admin = $this->makeUserWithPermissions($college, ['admission_applicants.view']);

        $response = $this->asCollege($college, $admin)->postJson(route('bulk-actions.execute'), [
            'module' => 'unknown_module',
            'action' => 'unknown_action',
            'ids' => [1, 2, 3],
        ]);

        $response->assertStatus(422); // Validation fails because action is not in allowed actions
    }
}
