<?php

namespace Tests\Feature\Admissions;

use App\Models\AdmissionDocumentType;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionDocumentTypeManagementTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_admin_can_create_document_type_scoped_to_college(): void
    {
        $college = $this->makeCollege('DCTY');
        $admin = $this->makeUserWithPermissions($college, ['admission_documents.view','admission_documents.create','admission_document_types.view','admission_document_types.create']);

        $this->asCollege($college, $admin)
            ->post(route('admission-document-types.store'), [
                'code' => 'IDPROOF',
                'name' => 'ID Proof',
                'description' => 'Government ID',
                'is_required' => true,
                'allowed_extensions' => 'pdf,jpg',
                'allowed_mimes' => 'application/pdf,image/jpeg',
                'max_size_kb' => 2048,
                'status' => 'active',
            ], ['Referer' => route('admission-document-types.index')])
            ->assertRedirect(route('admission-document-types.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('admission_document_types', [
            'college_id' => $college->id,
            'code' => 'IDPROOF',
            'name' => 'ID Proof',
            'is_required' => true,
            'max_size_kb' => 2048,
            'status' => 'active',
        ]);
    }

    public function test_validation_requires_code_name_and_valid_status(): void
    {
        $college = $this->makeCollege('DCTV');
        $admin = $this->makeUserWithPermissions($college, ['admission_document_types.view','admission_document_types.create']);

        $this->asCollege($college, $admin)
            ->post(route('admission-document-types.store'), [
                'name' => '',
                'status' => 'invalid',
                'max_size_kb' => 0,
            ], ['Referer' => route('admission-document-types.index')])
            ->assertSessionHasErrors(['code','name','status','max_size_kb']);

        $this->assertSame(0, AdmissionDocumentType::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_code_unique_per_college(): void
    {
        $college = $this->makeCollege('DCTU');
        $admin = $this->makeUserWithPermissions($college, ['admission_document_types.view','admission_document_types.create']);
        AdmissionDocumentType::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'code' => 'DUPLICATE',
            'name' => 'First',
            'status' => 'active',
            'max_size_kb' => 5120,
        ]);

        $this->asCollege($college, $admin)
            ->post(route('admission-document-types.store'), [
                'code' => 'DUPLICATE',
                'name' => 'Second',
                'status' => 'active',
                'max_size_kb' => 5120,
            ], ['Referer' => route('admission-document-types.index')])
            ->assertSessionHasErrors('code');
    }

    public function test_admin_can_update_and_soft_delete(): void
    {
        $college = $this->makeCollege('DCTD');
        $admin = $this->makeUserWithPermissions($college, ['admission_document_types.view','admission_document_types.update','admission_document_types.delete']);
        $type = AdmissionDocumentType::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'code' => 'EDIT',
            'name' => 'Old Name',
            'status' => 'active',
            'max_size_kb' => 5120,
        ]);

        $this->asCollege($college, $admin)->get(route('admission-document-types.edit', $type))->assertOk()->assertSee('Old Name');

        $this->asCollege($college, $admin)
            ->put(route('admission-document-types.update', $type), [
                'code' => 'EDIT',
                'name' => 'New Name',
                'status' => 'inactive',
                'max_size_kb' => 1024,
                'is_required' => false,
            ], ['Referer' => route('admission-document-types.edit', $type)])
            ->assertSessionHas('success');

        $type->refresh();
        $this->assertSame('New Name', $type->name);
        $this->assertSame('inactive', $type->status);

        $this->asCollege($college, $admin)
            ->delete(route('admission-document-types.destroy', $type), [], ['Referer' => route('admission-document-types.index')])
            ->assertSessionHas('success');

        $this->assertSoftDeleted('admission_document_types', ['id' => $type->id]);
    }

    public function test_index_supports_search_and_pagination_and_deterministic_ordering(): void
    {
        $college = $this->makeCollege('DCTI');
        $admin = $this->makeUserWithPermissions($college, ['admission_document_types.view']);
        foreach (range(1,16) as $i) {
            AdmissionDocumentType::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'code' => sprintf('TYPE%03d', $i),
                'name' => sprintf('Type %03d', $i),
                'status' => 'active',
                'max_size_kb' => 5120,
            ]);
        }

        $this->asCollege($college, $admin)->get(route('admission-document-types.index'))
            ->assertSee('TYPE001')->assertSee('TYPE015')->assertDontSee('TYPE016');

        $this->asCollege($college, $admin)->get(route('admission-document-types.index', ['page'=>2]))
            ->assertSee('TYPE016');

        $this->asCollege($college, $admin)->get(route('admission-document-types.index', ['search'=>'TYPE007']))
            ->assertSee('TYPE007')->assertDontSee('TYPE001');
    }

    public function test_audit_logs_record_document_type_crud(): void
    {
        $college = $this->makeCollege('DCTA');
        $admin = $this->makeUserWithPermissions($college, ['admission_document_types.view','admission_document_types.create','admission_document_types.update','admission_document_types.delete']);
        $this->asCollege($college, $admin)->post(route('admission-document-types.store'), [
            'code' => 'AUDIT',
            'name' => 'Audit Type',
            'status' => 'active',
            'max_size_kb' => 5120,
        ], ['Referer' => route('admission-document-types.index')]);

        $type = AdmissionDocumentType::withoutGlobalScopes()->firstWhere('code','AUDIT');

        $this->asCollege($college, $admin)->put(route('admission-document-types.update', $type), [
            'code' => 'AUDIT',
            'name' => 'Audit Updated',
            'status' => 'active',
            'max_size_kb' => 5120,
        ], ['Referer' => route('admission-document-types.edit', $type)]);

        $this->asCollege($college, $admin)->delete(route('admission-document-types.destroy', $type), [], ['Referer' => route('admission-document-types.index')]);

        $base = ['college_id'=>$college->id,'user_id'=>$admin->id,'subject_type'=>AdmissionDocumentType::class,'subject_id'=>$type->id];
        $this->assertDatabaseHas('audit_logs', $base + ['action'=>'admission_document_type.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action'=>'admission_document_type.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action'=>'admission_document_type.deleted']);
    }

    public function test_xss_safe_rendering(): void
    {
        $college = $this->makeCollege('DCTX');
        $admin = $this->makeUserWithPermissions($college, ['admission_document_types.view']);
        AdmissionDocumentType::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'code' => 'XSS',
            'name' => '<script>alert(1)</script>',
            'status' => 'active',
            'max_size_kb' => 5120,
        ]);

        $response = $this->asCollege($college, $admin)->get(route('admission-document-types.index'));
        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee(e('<script>alert(1)</script>'), false);
    }
}
