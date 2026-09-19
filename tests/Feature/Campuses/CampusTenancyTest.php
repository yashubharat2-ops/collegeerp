<?php

namespace Tests\Feature\Campuses;

use App\Models\Campus;
use Tests\TestCase;

class CampusTenancyTest extends TestCase
{
    use CampusTestHelpers;

    public function test_tenant_isolation_index_only_shows_active_college_campuses(): void
    {
        $collegeA = $this->makeCollege('CTENA');
        $collegeB = $this->makeCollege('CTENB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['campuses.view']);

        Campus::withoutGlobalScopes()->create(['college_id' => $collegeA->id, 'name' => 'A Campus', 'code' => 'A1', 'status' => 'active']);
        Campus::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'name' => 'B Campus', 'code' => 'B1', 'status' => 'active']);

        $this->asCollege($collegeA, $adminA)->get(route('campuses.index'))->assertSee('A Campus')->assertDontSee('B Campus');
    }

    public function test_cross_college_access_denied_for_edit_update_delete(): void
    {
        $collegeA = $this->makeCollege('CCROSA');
        $collegeB = $this->makeCollege('CCROSB');
        $campusB = Campus::withoutGlobalScopes()->create(['college_id' => $collegeB->id, 'name' => 'B Campus', 'code' => 'BCAMP', 'status' => 'active']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['campuses.view', 'campuses.update', 'campuses.delete']);

        $this->asCollege($collegeA, $adminA)->get(route('campuses.edit', $campusB))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->put(route('campuses.update', $campusB), ['name' => 'Hacked', 'code' => 'BCAMP', 'status' => 'active'], ['Referer' => route('campuses.edit', $campusB)])->assertNotFound();
        $this->asCollege($collegeA, $adminA)->delete(route('campuses.destroy', $campusB), [], ['Referer' => route('campuses.index')])->assertNotFound();

        $this->assertDatabaseHas('campuses', ['id' => $campusB->id, 'name' => 'B Campus']);
    }

    public function test_user_cannot_create_campus_in_another_college_context(): void
    {
        $collegeA = $this->makeCollege('CCREAA');
        $collegeB = $this->makeCollege('CCREAB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['campuses.create']);

        $this->actingAs($adminA)->withSession(['active_college_id' => $collegeB->id])
            ->post(route('campuses.store'), ['name' => 'B Campus', 'code' => 'BCAMP', 'status' => 'active'], ['Referer' => route('campuses.index')])
            ->assertForbidden();

        $this->assertSame(0, Campus::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());
    }

    public function test_college_id_spoofing_does_not_create_in_other_college(): void
    {
        $collegeA = $this->makeCollege('CSPFA');
        $collegeB = $this->makeCollege('CSPFB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['campuses.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('campuses.store'), [
                'name' => 'Spoofed',
                'code' => 'SPOOF',
                'college_id' => $collegeB->id,
                'status' => 'active',
            ], ['Referer' => route('campuses.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('campuses', ['college_id' => $collegeA->id, 'code' => 'SPOOF']);
        $this->assertDatabaseMissing('campuses', ['college_id' => $collegeB->id, 'code' => 'SPOOF']);
    }
}
