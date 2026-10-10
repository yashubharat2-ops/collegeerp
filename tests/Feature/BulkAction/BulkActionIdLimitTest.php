<?php

namespace Tests\Feature\BulkAction;

use App\Http\Requests\BulkAction\ExecuteBulkActionRequest;
use App\Models\Admission;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\College;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * Phase D (E): the POST bulk endpoint accepts at most 200 ids. A larger selection
 * is REJECTED with a validation error and nothing runs. There is no silent
 * truncation. Malformed ids are rejected too.
 */
class BulkActionIdLimitTest extends TestCase
{
    use DepartmentTestHelpers;

    private const ENDPOINT = 'bulk-actions.execute';

    private function submitBulkPost(College $college, User $user, array $ids, string $action = 'cancel'): TestResponse
    {
        return $this->asCollege($college, $user)->post(route(self::ENDPOINT), [
            'module' => 'admissions',
            'action' => $action,
            'ids' => $ids,
        ]);
    }

    private function admin(College $college): User
    {
        return $this->makeUserWithPermissions($college, ['admissions.view', 'admissions.update']);
    }

    private function admissionFor(College $college): Admission
    {
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Limit',
            'last_name' => 'Check',
            'email' => strtolower($college->code).'-'.Str::lower(Str::random(8)).'@example.test',
            'status' => 'active',
        ]);
        $application = AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'application_number' => 'APP-'.Str::upper(Str::random(8)),
            'status' => 'admitted',
        ]);

        return Admission::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'admission_number' => 'ADM-'.Str::upper(Str::random(8)),
            'admission_date' => '2026-07-01',
            'status' => 'active',
        ]);
    }

    public function test_the_limit_constant_is_200(): void
    {
        $this->assertSame(200, ExecuteBulkActionRequest::MAX_IDS);
    }

    public function test_201_ids_are_rejected_and_nothing_is_executed(): void
    {
        $college = $this->makeCollege('LIMIT201');
        $admin = $this->admin($college);
        $admission = $this->admissionFor($college);

        // The first id is a real, cancellable admission. A partial run would cancel it.
        $ids = array_merge([$admission->id], range(1000000, 1000199));
        $this->assertCount(201, $ids);

        $this->submitBulkPost($college, $admin, $ids)->assertSessionHasErrors(['ids']);

        $this->assertStringContainsString('at most 200', (string) session('errors')->first('ids'));

        $this->assertSame('active', Admission::withoutGlobalScopes()->find($admission->id)->status);
    }

    public function test_201_ids_get_a_json_validation_error(): void
    {
        $college = $this->makeCollege('LIMITJS');
        $admin = $this->admin($college);
        $ids = range(1, 201);

        $this->asCollege($college, $admin)
            ->postJson(route(self::ENDPOINT), ['module' => 'admissions', 'action' => 'export', 'ids' => $ids])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids']);
    }

    public function test_exactly_200_ids_pass_validation(): void
    {
        $college = $this->makeCollege('LIMIT200');
        $admin = $this->admin($college);
        $ids = range(1000000, 1000199);
        $this->assertCount(200, $ids);

        $response = $this->submitBulkPost($college, $admin, $ids, 'export');

        // The ids are valid: the request reaches the handler. It finds none of them in
        // this college, so the handler (not validation) refuses the action.
        $response->assertSessionDoesntHaveErrors('ids');
    }

    public function test_malformed_ids_are_rejected(): void
    {
        $college = $this->makeCollege('LIMITBAD');
        $admin = $this->admin($college);

        $this->submitBulkPost($college, $admin, ['abc'])->assertSessionHasErrors(['ids.0']);
        $this->submitBulkPost($college, $admin, [1.5])->assertSessionHasErrors(['ids.0']);
        $this->submitBulkPost($college, $admin, [0])->assertSessionHasErrors(['ids.0']);
        $this->submitBulkPost($college, $admin, [-4])->assertSessionHasErrors(['ids.0']);
        $this->submitBulkPost($college, $admin, [['nested']])->assertSessionHasErrors(['ids.0']);
    }

    public function test_an_empty_selection_is_rejected(): void
    {
        $college = $this->makeCollege('LIMITEMP');
        $admin = $this->admin($college);

        $this->submitBulkPost($college, $admin, [])->assertSessionHasErrors(['ids']);
    }
}
