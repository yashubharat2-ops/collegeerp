<?php

namespace Tests\Feature\Admissions;

use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentType;
use App\Models\AdmissionMeritEntry;
use App\Models\AdmissionMeritList;
use App\Models\Program;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionReportsTest extends TestCase
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

    public function test_reports_require_permission_and_are_tenant_scoped(): void
    {
        $collegeA = $this->makeCollege('REPA');
        $collegeB = $this->makeCollege('REPB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['admission_applications.view']);
        $noPerm = $this->makeUserWithPermissions($collegeA, []);

        $this->asCollege($collegeA, $noPerm)->get(route('admission-reports.index'))->assertForbidden();

        $applicantA = AdmissionApplicant::withoutGlobalScopes()->create(['college_id'=>$collegeA->id,'first_name'=>'A','status'=>'active']);
        $applicantB = AdmissionApplicant::withoutGlobalScopes()->create(['college_id'=>$collegeB->id,'first_name'=>'B','status'=>'active']);

        $yearA = $this->makeYear($collegeA);
        $progA = $this->makeProgram($collegeA);

        $appA = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$collegeA->id,'applicant_id'=>$applicantA->id,'academic_year_id'=>$yearA->id,'program_id'=>$progA->id,'application_number'=>'APP-A','status'=>'approved']);
        AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$collegeB->id,'applicant_id'=>$applicantB->id,'application_number'=>'APP-B','status'=>'approved']);

        Admission::withoutGlobalScopes()->create(['college_id'=>$collegeA->id,'applicant_id'=>$applicantA->id,'application_id'=>$appA->id,'admission_number'=>'ADM-A','status'=>'active']);

        $response = $this->asCollege($collegeA, $adminA)->get(route('admission-reports.index'));
        $response->assertOk();
        $response->assertSee('APP-A');
        $response->assertDontSee('APP-B');
        $response->assertSee('ADM-A');
    }

    public function test_reports_filter_by_year_program_status(): void
    {
        $college = $this->makeCollege('REPF');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view']);
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create(['college_id'=>$college->id,'first_name'=>'Test','status'=>'active']);
        $year1 = $this->makeYear($college, '2026');
        $year2 = $this->makeYear($college, '2027');
        $prog1 = $this->makeProgram($college, 'BSC');
        $prog2 = $this->makeProgram($college, 'MSC');

        AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$applicant->id,'academic_year_id'=>$year1->id,'program_id'=>$prog1->id,'application_number'=>'APP-1','status'=>'approved']);
        AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$applicant->id,'academic_year_id'=>$year2->id,'program_id'=>$prog2->id,'application_number'=>'APP-2','status'=>'rejected']);

        $this->asCollege($college, $admin)->get(route('admission-reports.index', ['academic_year_id'=>$year1->id]))
            ->assertSee('APP-1')->assertDontSee('APP-2');

        $this->asCollege($college, $admin)->get(route('admission-reports.index', ['program_id'=>$prog2->id]))
            ->assertSee('APP-2')->assertDontSee('APP-1');

        $this->asCollege($college, $admin)->get(route('admission-reports.index', ['status'=>'approved']))
            ->assertSee('APP-1')->assertDontSee('APP-2');
    }

    public function test_reports_include_document_and_merit_counts(): void
    {
        $college = $this->makeCollege('REPD');
        $admin = $this->makeUserWithPermissions($college, ['admission_applications.view']);
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create(['college_id'=>$college->id,'first_name'=>'Test','status'=>'active']);
        $year = $this->makeYear($college);
        $prog = $this->makeProgram($college);
        $app = AdmissionApplication::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$applicant->id,'academic_year_id'=>$year->id,'program_id'=>$prog->id,'application_number'=>'APP-D','status'=>'approved']);

        $type = AdmissionDocumentType::withoutGlobalScopes()->create(['college_id'=>$college->id,'code'=>'ID','name'=>'ID','status'=>'active','max_size_kb'=>5120]);
        AdmissionDocument::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$applicant->id,'application_id'=>$app->id,'document_type_id'=>$type->id,'file_path'=>'a.pdf','original_filename'=>'a.pdf','file_size'=>100,'verification_status'=>'pending']);
        AdmissionDocument::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$applicant->id,'application_id'=>$app->id,'document_type_id'=>$type->id,'file_path'=>'b.pdf','original_filename'=>'b.pdf','file_size'=>100,'verification_status'=>'verified']);

        $list = AdmissionMeritList::withoutGlobalScopes()->create(['college_id'=>$college->id,'code'=>'ML','name'=>'ML','academic_year_id'=>$year->id,'program_id'=>$prog->id,'status'=>'draft','is_published'=>false]);
        AdmissionMeritEntry::withoutGlobalScopes()->create(['college_id'=>$college->id,'merit_list_id'=>$list->id,'application_id'=>$app->id,'applicant_id'=>$applicant->id,'merit_score'=>90,'rank'=>1,'selection_status'=>'selected']);

        $response = $this->asCollege($college, $admin)->get(route('admission-reports.index'));
        $response->assertOk();
        $response->assertViewHas('docStatusCounts');
        $response->assertViewHas('selectionCounts');
    }
}
