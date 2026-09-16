<?php

namespace Tests\Feature\Admissions;

use App\Models\AcademicYear;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionMeritEntry;
use App\Models\AdmissionMeritList;
use App\Models\Program;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionMeritManagementTest extends TestCase
{
    use DepartmentTestHelpers;

    private function makeYear($college, string $code = '2026'): AcademicYear
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

    private function makeProgram($college, string $code = 'BSC'): Program
    {
        return Program::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => 'BSc',
            'code' => $code,
            'status' => 'active',
        ]);
    }

    private function makeApplicant($college): AdmissionApplicant
    {
        return AdmissionApplicant::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'first_name' => 'Merit',
            'last_name' => 'Tester',
            'status' => 'active',
        ]);
    }

    private function makeApplication($college, $applicant, $year = null, $program = null, string $status = 'submitted'): AdmissionApplication
    {
        return AdmissionApplication::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year?->id,
            'program_id' => $program?->id,
            'application_number' => 'APP-'.uniqid(),
            'status' => $status,
        ]);
    }

    public function test_admin_can_create_merit_list_scoped_to_college(): void
    {
        $college = $this->makeCollege('MERL');
        $admin = $this->makeUserWithPermissions($college, ['admission_merit.view','admission_merit.create']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $this->asCollege($college, $admin)
            ->post(route('admission-merit-lists.store'), [
                'code' => 'MERIT2026',
                'name' => 'Merit 2026 BSC',
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'description' => 'First merit list',
            ], ['Referer'=>route('admission-merit-lists.index')])
            ->assertRedirect(route('admission-merit-lists.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('admission_merit_lists', [
            'college_id' => $college->id,
            'code' => 'MERIT2026',
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'draft',
            'is_published' => false,
        ]);
    }

    public function test_server_controlled_fields_cannot_be_forged_on_merit_list(): void
    {
        $college = $this->makeCollege('MERF');
        $admin = $this->makeUserWithPermissions($college, ['admission_merit.view','admission_merit.create']);
        $year = $this->makeYear($college);

        $this->asCollege($college, $admin)
            ->post(route('admission-merit-lists.store'), [
                'code' => 'FORGE',
                'name' => 'Forge List',
                'academic_year_id' => $year->id,
                'status' => 'published',
                'is_published' => true,
                'published_at' => '2001-01-01',
                'college_id' => 999,
            ], ['Referer'=>route('admission-merit-lists.index')])
            ->assertSessionHas('success');

        $list = AdmissionMeritList::withoutGlobalScopes()->firstWhere('code','FORGE');
        $this->assertSame('draft', $list->status);
        $this->assertFalse($list->is_published);
        $this->assertNull($list->published_at);
        $this->assertSame($college->id, $list->college_id);
    }

    public function test_admin_can_add_merit_entry_with_score_and_rank(): void
    {
        $college = $this->makeCollege('MERE');
        $admin = $this->makeUserWithPermissions($college, ['admission_merit.view','admission_merit.create']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $applicant = $this->makeApplicant($college);
        $application = $this->makeApplication($college, $applicant, $year, $program);

        $list = AdmissionMeritList::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'code' => 'LIST1',
            'name' => 'List 1',
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'draft',
            'is_published' => false,
        ]);

        $this->asCollege($college, $admin)
            ->post(route('admission-merit-entries.store'), [
                'merit_list_id' => $list->id,
                'application_id' => $application->id,
                'merit_score' => 95.50,
                'rank' => 1,
                'selection_status' => 'selected',
                'remarks' => 'Topper',
            ], ['Referer'=>route('admission-merit-lists.show', $list)])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('admission_merit_entries', [
            'college_id' => $college->id,
            'merit_list_id' => $list->id,
            'application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'merit_score' => 95.50,
            'rank' => 1,
            'selection_status' => 'selected',
        ]);
    }

    public function test_duplicate_entry_prevention_per_list(): void
    {
        $college = $this->makeCollege('MERD');
        $admin = $this->makeUserWithPermissions($college, ['admission_merit.view','admission_merit.create']);
        $applicant = $this->makeApplicant($college);
        $application = $this->makeApplication($college, $applicant);
        $list = AdmissionMeritList::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'code' => 'DUPLIST',
            'name' => 'Dup List',
            'status' => 'draft',
            'is_published' => false,
        ]);

        $this->asCollege($college, $admin)->post(route('admission-merit-entries.store'), [
            'merit_list_id' => $list->id,
            'application_id' => $application->id,
            'selection_status' => 'selected',
        ], ['Referer'=>route('admission-merit-lists.show', $list)])->assertSessionHas('success');

        $this->asCollege($college, $admin)->post(route('admission-merit-entries.store'), [
            'merit_list_id' => $list->id,
            'application_id' => $application->id,
            'selection_status' => 'selected',
        ], ['Referer'=>route('admission-merit-lists.show', $list)])->assertSessionHasErrors('application_id');

        $this->assertSame(1, AdmissionMeritEntry::withoutGlobalScopes()->where('merit_list_id', $list->id)->count());
    }

    public function test_merit_list_publish_and_unpublish_with_audit(): void
    {
        $college = $this->makeCollege('MERP');
        $admin = $this->makeUserWithPermissions($college, ['admission_merit.view','admission_merit.publish']);
        $list = AdmissionMeritList::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'code' => 'PUB',
            'name' => 'Publish List',
            'status' => 'draft',
            'is_published' => false,
        ]);

        $this->asCollege($college, $admin)->post(route('admission-merit-lists.publish', $list), [], ['Referer'=>route('admission-merit-lists.index')])->assertSessionHas('success');

        $list->refresh();
        $this->assertTrue($list->is_published);
        $this->assertSame('published', $list->status);
        $this->assertNotNull($list->published_at);
        $this->assertSame($admin->id, $list->published_by);

        $this->assertDatabaseHas('audit_logs', [
            'college_id' => $college->id,
            'action' => 'admission_merit_list.published',
            'subject_id' => $list->id,
        ]);

        $this->asCollege($college, $admin)->post(route('admission-merit-lists.unpublish', $list), [], ['Referer'=>route('admission-merit-lists.index')])->assertSessionHas('success');

        $list->refresh();
        $this->assertFalse($list->is_published);
        $this->assertSame('draft', $list->status);
    }

    public function test_merit_entries_deterministic_ordering_by_rank_and_score(): void
    {
        $college = $this->makeCollege('MERO');
        $admin = $this->makeUserWithPermissions($college, ['admission_merit.view']);
        $applicant = $this->makeApplicant($college);
        $list = AdmissionMeritList::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'code' => 'ORDER',
            'name' => 'Order List',
            'status' => 'draft',
            'is_published' => false,
        ]);

        // Same rank, different scores, ensure secondary ordering by id
        foreach (range(1,5) as $i) {
            $app = $this->makeApplication($college, $applicant);
            AdmissionMeritEntry::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'merit_list_id' => $list->id,
                'application_id' => $app->id,
                'applicant_id' => $applicant->id,
                'merit_score' => 100 - $i,
                'rank' => 1,
                'selection_status' => 'selected',
            ]);
        }

        $response = $this->asCollege($college, $admin)->get(route('admission-merit-lists.show', $list));
        $response->assertOk();
        // Should be ordered by rank, then merit_score desc, then id
        $entries = AdmissionMeritEntry::query()->where('merit_list_id', $list->id)->orderBy('rank')->orderByDesc('merit_score')->orderBy('id')->get();
        $this->assertCount(5, $entries);
        $this->assertTrue($entries[0]->merit_score > $entries[1]->merit_score);
    }

    public function test_merit_list_index_filtering_and_pagination(): void
    {
        $college = $this->makeCollege('MERI');
        $admin = $this->makeUserWithPermissions($college, ['admission_merit.view']);
        foreach (range(1,16) as $i) {
            AdmissionMeritList::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'code' => sprintf('ML%03d', $i),
                'name' => sprintf('Merit %03d', $i),
                'status' => $i === 16 ? 'published' : 'draft',
                'is_published' => $i === 16,
            ]);
        }

        $this->asCollege($college, $admin)->get(route('admission-merit-lists.index'))
            ->assertSee('ML001')->assertSee('ML015')->assertDontSee('ML016');

        $this->asCollege($college, $admin)->get(route('admission-merit-lists.index', ['status'=>'published']))
            ->assertSee('ML016')->assertDontSee('ML001');
    }
}
