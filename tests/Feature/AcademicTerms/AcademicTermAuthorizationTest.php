<?php

namespace Tests\Feature\AcademicTerms;

use App\Models\{AcademicTerm, AcademicYear, Permission, Role, User};
use Illuminate\Support\Str;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AcademicTermAuthorizationTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_authenticated_users_without_permission_forbidden(): void
    {
        $college = $this->makeCollege('ATFB');
        $outsider = $this->makeUserWithPermissions($college, []);
        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $term = AcademicTerm::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => 'Semester 1',
            'code' => 'SEM1',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $this->asCollege($college, $outsider)->get(route('academic-terms.index'))->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('academic-terms.create'))->assertForbidden();
        $this->asCollege($college, $outsider)->post(route('academic-terms.store'), [
            'academic_year_id' => $year->id,
            'name' => 'Semester 2',
            'code' => 'SEM2',
            'type' => 'semester',
            'sequence' => 2,
            'status' => 'active',
        ])->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('academic-terms.edit', $term))->assertForbidden();
        $this->asCollege($college, $outsider)->put(route('academic-terms.update', $term), [
            'academic_year_id' => $year->id,
            'name' => 'Semester 1 Mod',
            'code' => 'SEM1',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ])->assertForbidden();
        $this->asCollege($college, $outsider)->delete(route('academic-terms.destroy', $term))->assertForbidden();
    }

    public function test_each_action_requires_its_own_permission(): void
    {
        $college = $this->makeCollege('ATEX');
        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $term = AcademicTerm::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => 'Term 1',
            'code' => 'T1',
            'type' => 'term',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $creator = $this->makeUserWithPermissions($college, ['academic_terms.create']);
        $this->asCollege($college, $creator)->get(route('academic-terms.index'))->assertForbidden();
        $this->asCollege($college, $creator)->post(route('academic-terms.store'), [
            'academic_year_id' => $year->id,
            'name' => 'Term 2',
            'code' => 'T2',
            'type' => 'term',
            'sequence' => 2,
            'status' => 'active',
        ], ['Referer' => route('academic-terms.index')])->assertSessionHas('success');

        $viewer = $this->makeUserWithPermissions($college, ['academic_terms.view']);
        $this->asCollege($college, $viewer)->get(route('academic-terms.index'))->assertOk()->assertSee('Term 1');
        $this->asCollege($college, $viewer)->get(route('academic-terms.create'))->assertForbidden();

        $editor = $this->makeUserWithPermissions($college, ['academic_terms.update']);
        $this->asCollege($college, $editor)->get(route('academic-terms.edit', $term))->assertOk();
        $this->asCollege($college, $editor)->put(route('academic-terms.update', $term), [
            'academic_year_id' => $year->id,
            'name' => 'Term 1 Renamed',
            'code' => 'T1',
            'type' => 'term',
            'sequence' => 1,
            'status' => 'active',
        ], ['Referer' => route('academic-terms.edit', $term)])->assertSessionHas('success');

        $deleter = $this->makeUserWithPermissions($college, ['academic_terms.delete']);
        $this->asCollege($college, $deleter)->delete(route('academic-terms.destroy', $term), [], ['Referer' => route('academic-terms.index')])->assertSessionHas('success');
        $this->assertSoftDeleted('academic_terms', ['id' => $term->id]);
    }
}
