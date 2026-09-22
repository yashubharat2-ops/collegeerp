<?php

namespace Tests\Feature\Transport;

use App\Domain\Transport\Services\VehicleDocumentService;
use App\Models\{College, Permission, Role, User, Vehicle, VehicleDocument};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Transport Phase 2 — Vehicle Documents: secure upload/download/replace/delete
 * on existing vehicles, with private storage, tenant isolation, path-traversal
 * defences, expiry status derivation and full audit history.
 */
class VehicleDocumentTest extends TestCase
{
    private const PERMISSIONS = [
        'vehicle_documents.view', 'vehicle_documents.create', 'vehicle_documents.update', 'vehicle_documents.delete',
    ];

    private function college(string $code): College
    {
        return College::create(['name' => $code, 'code' => $code, 'slug' => strtolower($code), 'status' => 'active']);
    }

    private function user(College $college, ?array $permissions = null): User
    {
        $user = User::create(['name' => 'Docs Staff', 'email' => Str::uuid().'@example.test', 'password' => 'password', 'is_active' => true]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $role = Role::create(['college_id' => $college->id, 'name' => 'Vehicle Docs', 'slug' => (string) Str::uuid(), 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissions ?? self::PERMISSIONS)->pluck('id'));
        $user->roles()->attach($role->id, ['college_id' => $college->id]);
        return $user;
    }

    private function login(College $college, ?array $permissions = null): User
    {
        $user = $this->user($college, $permissions);
        $this->actingAs($user)->withSession(['active_college_id' => $college->id]);
        return $user;
    }

    private function vehicle(College $college, string $registration = 'KA-01 AB-1234'): Vehicle
    {
        $vehicle = new Vehicle(['registration_number' => $registration, 'seating_capacity' => 40, 'status' => 'active']);
        $vehicle->college_id = $college->id;
        $vehicle->save();
        return $vehicle;
    }

    private function document(College $college, Vehicle $vehicle, array $extra = []): VehicleDocument
    {
        return VehicleDocument::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id,
            'vehicle_id' => $vehicle->id,
            'document_type' => 'insurance',
            'document_number' => 'POL-1',
            'file_path' => sprintf('vehicle-documents/%d/%d/%s.pdf', $college->id, $vehicle->id, Str::uuid()),
            'original_filename' => 'insurance.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
        ], $extra));
    }

    private function payload(Vehicle $vehicle, array $extra = []): array
    {
        return array_merge([
            'vehicle_id' => $vehicle->id,
            'document_type' => 'insurance',
            'document_number' => ' POL-42 ',
            'issue_date' => '2026-01-01',
            'expiry_date' => '2027-01-01',
            'file' => UploadedFile::fake()->create('insurance.pdf', 100, 'application/pdf'),
        ], $extra);
    }

    public function test_upload_stores_private_server_path_and_strips_forged_fields(): void
    {
        Storage::fake('private');
        $a = $this->college('VDOC');
        $b = $this->college('VDOC2');
        $vehicle = $this->vehicle($a);
        $user = $this->login($a);
        $this->get(route('vehicle-documents.create'))->assertOk();
        $this->post(route('vehicle-documents.store'), $this->payload($vehicle, [
            'college_id' => $b->id,
            'created_by' => 9999,
            'updated_by' => 9999,
            'uploaded_by' => 9999,
            'file_path' => '../../etc/passwd',
            'original_filename' => 'evil.pdf',
            'file_size' => 999,
        ]))->assertSessionHasNoErrors()->assertRedirect(route('vehicle-documents.index'));

        $doc = VehicleDocument::withoutGlobalScopes()->firstOrFail();
        $this->assertEquals($a->id, $doc->college_id);
        $this->assertEquals($vehicle->id, $doc->vehicle_id);
        $this->assertEquals($user->id, $doc->uploaded_by);
        $this->assertSame('POL-42', $doc->document_number);
        // Server-generated path under the owning vehicle; never the user value.
        $this->assertStringStartsWith(sprintf('vehicle-documents/%d/%d/', $a->id, $vehicle->id), $doc->file_path);
        $this->assertStringNotContainsString('..', $doc->file_path);
        $this->assertSame('insurance.pdf', $doc->original_filename);
        Storage::disk('private')->assertExists($doc->file_path);
        $this->assertDatabaseHas('audit_logs', ['college_id' => $a->id, 'user_id' => $user->id, 'action' => 'vehicle_document.uploaded', 'subject_id' => $doc->id]);
        $this->get(route('vehicle-documents.index'))->assertOk()->assertSee('insurance.pdf');
    }

    public function test_file_validation_rejects_types_and_oversize_and_bad_dates(): void
    {
        Storage::fake('private');
        $a = $this->college('VDOCVAL');
        $vehicle = $this->vehicle($a);
        $this->login($a);

        $this->post(route('vehicle-documents.store'), $this->payload($vehicle, [
            'file' => UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
        ]))->assertSessionHasErrors('file');
        $this->post(route('vehicle-documents.store'), $this->payload($vehicle, [
            'file' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'),
        ]))->assertSessionHasErrors('file');
        $this->post(route('vehicle-documents.store'), $this->payload($vehicle, [
            'expiry_date' => '2025-12-31',
        ]))->assertSessionHasErrors('expiry_date');
        $this->post(route('vehicle-documents.store'), $this->payload($vehicle, [
            'vehicle_id' => 999999,
        ]))->assertSessionHasErrors('vehicle_id');
        $this->assertDatabaseCount('vehicle_documents', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'vehicle_document.uploaded']);
    }

    public function test_service_blocks_dangerous_extensions_and_mimes_even_below_validation(): void
    {
        Storage::fake('private');
        $a = $this->college('VDOCSVC');
        $vehicle = $this->vehicle($a);
        $service = app(VehicleDocumentService::class);

        $this->expectException(ValidationException::class);
        $service->store(
            ['vehicle_id' => $vehicle->id, 'document_type' => 'other'],
            UploadedFile::fake()->create('shell.php', 1, 'text/x-php'),
            (int) $a->id,
            null,
        );
    }

    public function test_service_blocks_dangerous_mime_with_allowed_extension(): void
    {
        Storage::fake('private');
        $a = $this->college('VDOCMIME');
        $vehicle = $this->vehicle($a);
        $service = app(VehicleDocumentService::class);

        try {
            $service->store(
                ['vehicle_id' => $vehicle->id, 'document_type' => 'other'],
                UploadedFile::fake()->create('sneaky.pdf', 1, 'text/html'),
                (int) $a->id,
                null,
            );
            $this->fail('Expected a validation rejection for the dangerous MIME.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors());
        }
    }

    public function test_document_type_is_extensible_not_a_closed_enum(): void
    {
        Storage::fake('private');
        $a = $this->college('VDOCTYPE');
        $vehicle = $this->vehicle($a);
        $this->login($a);
        $this->post(route('vehicle-documents.store'), $this->payload($vehicle, [
            'document_type' => 'insurance_rider_2026',
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vehicle_documents', ['document_type' => 'insurance_rider_2026']);
        $this->get(route('vehicle-documents.index'))->assertOk()->assertSee('Insurance rider 2026');
    }

    public function test_expiry_status_derivation_and_filters(): void
    {
        $a = $this->college('VDOCST');
        $vehicle = $this->vehicle($a);
        $expired = $this->document($a, $vehicle, ['expiry_date' => now()->subDay()->toDateString(), 'document_number' => 'GONE']);
        $expiring = $this->document($a, $vehicle, ['expiry_date' => now()->addDays(10)->toDateString(), 'document_number' => 'SOON']);
        $active = $this->document($a, $vehicle, ['expiry_date' => now()->addDays(120)->toDateString(), 'document_number' => 'FINE']);
        $this->login($a);

        $this->get(route('vehicle-documents.index'))
            ->assertOk()
            ->assertViewHas('documents', fn ($documents) => $documents->pluck('id')->all() === [$active->id, $expiring->id, $expired->id] || $documents->pluck('id')->count() === 3);
        $this->get(route('vehicle-documents.index', ['document_status' => 'expired']))
            ->assertOk()
            ->assertViewHas('documents', fn ($documents) => $documents->pluck('id')->all() === [$expired->id]);
        $this->get(route('vehicle-documents.index', ['document_status' => 'expiring']))
            ->assertOk()
            ->assertViewHas('documents', fn ($documents) => $documents->pluck('id')->all() === [$expiring->id]);
        $this->assertSame('expired', $expired->fresh()->documentStatus());
        $this->assertSame('expiring', $expiring->fresh()->documentStatus());
        $this->assertSame('active', $active->fresh()->documentStatus());
    }

    public function test_download_streams_with_safe_filename_and_is_audited(): void
    {
        Storage::fake('private');
        $a = $this->college('VDOCDL');
        $vehicle = $this->vehicle($a);
        $user = $this->login($a);
        $this->post(route('vehicle-documents.store'), $this->payload($vehicle, [
            'file' => UploadedFile::fake()->create('..\\..evil name/../final.pdf', 10, 'application/pdf'),
        ]))->assertSessionHasNoErrors();

        $doc = VehicleDocument::withoutGlobalScopes()->firstOrFail();
        $response = $this->get(route('vehicle-documents.download', $doc->id));
        $response->assertOk();
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringNotContainsString('..', $disposition);
        $this->assertDatabaseHas('audit_logs', ['college_id' => $a->id, 'user_id' => $user->id, 'action' => 'vehicle_document.downloaded', 'subject_id' => $doc->id]);
        // The stored location is never echoed to the client.
        $this->assertStringNotContainsString('vehicle-documents/', $disposition);
    }

    public function test_tampered_paths_are_refused_on_read(): void
    {
        Storage::fake('private');
        $a = $this->college('VDOCTRV');
        $vehicle = $this->vehicle($a);
        $this->login($a);
        $doc = $this->document($a, $vehicle);
        Storage::disk('private')->put($doc->file_path, 'x');

        VehicleDocument::withoutGlobalScopes()->whereKey($doc->id)->update(['file_path' => '../../../etc/passwd']);
        $this->get(route('vehicle-documents.download', $doc->id))->assertForbidden();

        VehicleDocument::withoutGlobalScopes()->whereKey($doc->id)->update(['file_path' => 'vehicle-documents/999/999/x.pdf']);
        $this->get(route('vehicle-documents.download', $doc->id))->assertForbidden();

        VehicleDocument::withoutGlobalScopes()->whereKey($doc->id)->update(['file_path' => 'file:///tmp/x']);
        $this->get(route('vehicle-documents.download', $doc->id))->assertForbidden();
    }

    public function test_update_metadata_replace_file_and_vehicle_immutability(): void
    {
        Storage::fake('private');
        $a = $this->college('VDOCUP');
        $vehicle = $this->vehicle($a);
        $other = $this->vehicle($a, 'KA-02 CD-5678');
        $user = $this->login($a);
        $this->post(route('vehicle-documents.store'), $this->payload($vehicle))->assertSessionHasNoErrors();
        $doc = VehicleDocument::withoutGlobalScopes()->firstOrFail();
        $firstPath = $doc->file_path;

        // A crafted re-point to another vehicle is rejected — history and the
        // stored file path stay coherent.
        $this->put(route('vehicle-documents.update', $doc->id), $this->payload($vehicle, [
            'vehicle_id' => $other->id,
            'file' => null,
        ]))->assertSessionHasErrors('vehicle_id');
        $this->assertEquals($vehicle->id, $doc->fresh()->vehicle_id);

        // Metadata correction + optional file replacement (old path audited).
        $this->put(route('vehicle-documents.update', $doc->id), $this->payload($vehicle, [
            'document_number' => 'POL-43',
            'expiry_date' => '2028-01-01',
            'file' => UploadedFile::fake()->create('replacement.pdf', 20, 'application/pdf'),
        ]))->assertSessionHasNoErrors();
        $doc->refresh();
        $this->assertSame('POL-43', $doc->document_number);
        $this->assertSame('replacement.pdf', $doc->original_filename);
        $this->assertNotSame($firstPath, $doc->file_path);
        foreach (['vehicle_document.replaced', 'vehicle_document.updated'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['college_id' => $a->id, 'user_id' => $user->id, 'action' => $action, 'subject_id' => $doc->id]);
        }
    }

    public function test_delete_is_soft_and_keeps_the_file_for_history(): void
    {
        Storage::fake('private');
        $a = $this->college('VDOCDEL');
        $vehicle = $this->vehicle($a);
        $user = $this->login($a);
        $this->post(route('vehicle-documents.store'), $this->payload($vehicle))->assertSessionHasNoErrors();
        $doc = VehicleDocument::withoutGlobalScopes()->firstOrFail();

        $this->delete(route('vehicle-documents.destroy', $doc->id))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('vehicle_documents', ['id' => $doc->id, 'updated_by' => $user->id]);
        // History preserved: the stored file is retained and the path audited.
        Storage::disk('private')->assertExists($doc->file_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'vehicle_document.deleted', 'subject_id' => $doc->id]);
    }

    public function test_every_endpoint_is_tenant_scoped(): void
    {
        Storage::fake('private');
        $a = $this->college('VDOCTEN');
        $b = $this->college('VDOCTEN2');
        $foreign = $this->document($b, $this->vehicle($b));
        Storage::disk('private')->put($foreign->file_path, 'x');
        $this->login($a);

        $this->get(route('vehicle-documents.edit', $foreign->id))->assertNotFound();
        $this->put(route('vehicle-documents.update', $foreign->id), [])->assertNotFound();
        $this->delete(route('vehicle-documents.destroy', $foreign->id))->assertNotFound();
        $this->get(route('vehicle-documents.download', $foreign->id))->assertNotFound();
        $this->get(route('vehicle-documents.index'))->assertViewHas('documents', fn ($documents) => ! $documents->contains('id', $foreign->id));
        // Uploading onto a foreign vehicle is refused at the request boundary.
        $this->post(route('vehicle-documents.store'), $this->payload($this->vehicle($b, 'KA-03 ZZ-9999')))->assertSessionHasErrors('vehicle_id');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'vehicle_document.deleted']);
    }

    public function test_rbac_gates_every_action(): void
    {
        Storage::fake('private');
        $a = $this->college('VDOCRBAC');
        $vehicle = $this->vehicle($a);
        $doc = $this->document($a, $vehicle);
        Storage::disk('private')->put($doc->file_path, 'x');
        $this->login($a, []);

        $this->get(route('vehicle-documents.index'))->assertForbidden();
        $this->get(route('vehicle-documents.create'))->assertForbidden();
        $this->post(route('vehicle-documents.store'), [])->assertForbidden();
        $this->get(route('vehicle-documents.edit', $doc->id))->assertForbidden();
        $this->put(route('vehicle-documents.update', $doc->id), [])->assertForbidden();
        $this->delete(route('vehicle-documents.destroy', $doc->id))->assertForbidden();
        $this->get(route('vehicle-documents.download', $doc->id))->assertForbidden();

        // view can download but not mutate.
        $this->login($a, ['vehicle_documents.view']);
        $this->get(route('vehicle-documents.index'))->assertOk();
        $this->get(route('vehicle-documents.download', $doc->id))->assertOk();
        $this->get(route('vehicle-documents.edit', $doc->id))->assertForbidden();
    }
}
