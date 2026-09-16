<?php

namespace Tests\Feature\Admissions;

use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionMeritEntry;
use App\Models\AdmissionMeritList;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionMeritTenancyTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_list_only_shows_merit_lists_of_active_college(): void
    {
        $collegeA = $this->makeCollege('MTLA');
        $collegeB = $this->makeCollege('MTLB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_merit.view']);

        AdmissionMeritList::withoutGlobalScopes()->create(['college_id'=>$collegeA->id,'code'=>'A','name'=>'A','status'=>'draft','is_published'=>false]);
        AdmissionMeritList::withoutGlobalScopes()->create(['college_id'=>$collegeB->id,'code'=>'B','name'=>'B','status'=>'draft','is_published'=>false]);

        $this->asCollege($collegeA, $adminA)->get(route('admission-merit-lists.index'))->assertSee('>A<', false)->assertDontSee('>B<', false);
    }

    public function test_cross_college_merit_list_cannot_be_accessed(): void
    {
        $collegeA = $this->makeCollege('MTLC');
        $collegeB = $this->makeCollege('MTLD');
        $listB = AdmissionMeritList::withoutGlobalScopes()->create(['college_id'=>$collegeB->id,'code'=>'B','name'=>'B','status'=>'draft','is_published'=>false]);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_merit.view','admission_merit.update','admission_merit.delete','admission_merit.publish']);

        $this->asCollege($collegeA, $adminA)->get(route('admission-merit-lists.show', $listB))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->get(route('admission-merit-lists.edit', $listB))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->post(route('admission-merit-lists.publish', $listB))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->delete(route('admission-merit-lists.destroy', $listB))->assertNotFound();
    }

    public function test_cross_college_application_cannot_be_added_to_merit_list(): void
    {
        $collegeA = $this->makeCollege('MTLE');
        $collegeB = $this->makeCollege('MTLF');
        $applicantB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id'=>$collegeB->id,'first_name'=>'B','status'=>'active']);
        $appB = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$collegeB->id,'applicant_id'=>$applicantB->id,'application_number'=>'APP-B','status'=>'submitted']);
        $listA = AdmissionMeritList::withoutGlobalScopes()->create(['college_id'=>$collegeA->id,'code'=>'A','name'=>'A','status'=>'draft','is_published'=>false]);
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_merit.view','admission_merit.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('admission-merit-entries.store'), [
                'merit_list_id' => $listA->id,
                'application_id' => $appB->id,
                'selection_status' => 'selected',
            ], ['Referer'=>route('admission-merit-lists.show', $listA)])
            ->assertSessionHasErrors('application_id');

        $this->assertSame(0, AdmissionMeritEntry::withoutGlobalScopes()->where('college_id',$collegeA->id)->count());
    }
}
