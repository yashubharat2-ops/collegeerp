<?php

namespace Tests\Feature\Admissions;

use App\Models\AdmissionApplicant;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentType;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionDocumentAuthorizationTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_guest_cannot_access_documents(): void
    {
        $this->get(route('admission-documents.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_permission_cannot_view_or_create(): void
    {
        $college = $this->makeCollege('ADPA');
        $user = $this->makeUserWithPermissions($college, []);
        $this->asCollege($college, $user)->get(route('admission-documents.index'))->assertForbidden();
        $this->asCollege($college, $user)->get(route('admission-documents.create'))->assertForbidden();
    }

    public function test_user_without_verify_permission_cannot_verify(): void
    {
        $college = $this->makeCollege('ADPV');
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create(['college_id'=>$college->id,'first_name'=>'A','status'=>'active']);
        $type = AdmissionDocumentType::withoutGlobalScopes()->create(['college_id'=>$college->id,'code'=>'ID','name'=>'ID','status'=>'active','max_size_kb'=>5120]);
        $doc = AdmissionDocument::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$applicant->id,'document_type_id'=>$type->id,'file_path'=>'a.pdf','original_filename'=>'a.pdf','file_size'=>100,'verification_status'=>'pending']);

        $user = $this->makeUserWithPermissions($college, ['admission_documents.view']);
        $this->asCollege($college, $user)->post(route('admission-documents.verify', $doc), ['action'=>'verify'])->assertForbidden();
    }

    public function test_user_with_view_can_download_but_not_delete(): void
    {
        $college = $this->makeCollege('ADPD');
        $applicant = AdmissionApplicant::withoutGlobalScopes()->create(['college_id'=>$college->id,'first_name'=>'A','status'=>'active']);
        $type = AdmissionDocumentType::withoutGlobalScopes()->create(['college_id'=>$college->id,'code'=>'ID','name'=>'ID','status'=>'active','max_size_kb'=>5120]);
        $doc = AdmissionDocument::withoutGlobalScopes()->create(['college_id'=>$college->id,'applicant_id'=>$applicant->id,'document_type_id'=>$type->id,'file_path'=>'a.pdf','original_filename'=>'a.pdf','file_size'=>100,'verification_status'=>'pending']);

        $userView = $this->makeUserWithPermissions($college, ['admission_documents.view']);
        $this->asCollege($college, $userView)->get(route('admission-documents.download', $doc))->assertStatus(404); // file missing but auth passes -> 404 not 403
        $this->asCollege($college, $userView)->delete(route('admission-documents.destroy', $doc))->assertForbidden();
    }
}
