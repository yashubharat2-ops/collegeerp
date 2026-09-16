<?php

namespace Tests\Feature\Admissions;

use App\Models\AdmissionApplicant;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionApplicantTenancyTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_list_only_shows_applicants_of_active_college(): void
    {
        $collegeA = $this->makeCollege('ISOA');
        $collegeB = $this->makeCollege('ISOB');
        AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'first_name' => 'Alpha', 'last_name' => 'A', 'status' => 'active']);
        AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'Bravo', 'last_name' => 'B', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_applicants.view']);

        $this->asCollege($collegeA, $adminA)->get(route('admission-applicants.index'))
            ->assertSee('Alpha')
            ->assertDontSee('Bravo');
    }

    public function test_cross_college_records_cannot_be_read_edited_or_deleted(): void
    {
        $collegeA = $this->makeCollege('XSCA');
        $collegeB = $this->makeCollege('XSCB');
        $foreign = AdmissionApplicant::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'first_name' => 'Foreign', 'last_name' => 'Applicant', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_applicants.view', 'admission_applicants.update', 'admission_applicants.delete']);

        $this->asCollege($collegeA, $adminA)->get(route('admission-applicants.edit', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->put(route('admission-applicants.update', $foreign), [
            'first_name' => 'Hijacked',
            'last_name' => 'Applicant',
            'status' => 'active',
        ], ['Referer' => route('admission-applicants.index')])->assertNotFound();
        $this->asCollege($collegeA, $adminA)->delete(route('admission-applicants.destroy', $foreign), [], ['Referer' => route('admission-applicants.index')])->assertNotFound();

        $this->assertSame('Foreign', $foreign->fresh()->first_name);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_college_id_from_browser_is_never_trusted(): void
    {
        $collegeA = $this->makeCollege('SPYA');
        $collegeB = $this->makeCollege('SPYB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_applicants.view', 'admission_applicants.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('admission-applicants.store'), [
                'college_id' => $collegeB->id,
                'first_name' => 'Sneaky',
                'last_name' => 'Applicant',
                'status' => 'active',
            ], ['Referer' => route('admission-applicants.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admission_applicants', ['college_id' => $collegeA->id, 'first_name' => 'Sneaky']);
        $this->assertDatabaseMissing('admission_applicants', ['college_id' => $collegeB->id, 'first_name' => 'Sneaky']);
    }

    public function test_xss_safe_rendering(): void
    {
        $college = $this->makeCollege('XSSA');
        $admin = $this->makeUserWithPermissions($college, ['admission_applicants.view', 'admission_applicants.create']);
        $xssName = '<script>alert(1)</script>';

        AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => $xssName,
            'last_name' => 'Test',
            'status' => 'active',
        ]);

        $response = $this->asCollege($college, $admin)->get(route('admission-applicants.index'));
        $response->assertOk();
        // Should be escaped, not raw script
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee(e($xssName), false);
    }
}
