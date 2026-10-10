<?php

namespace Tests\Feature\BulkAction;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\CertificateType;
use App\Models\College;
use App\Services\Certificates\CertificateCatalog;
use App\Support\BulkAction\BulkActionRegistry;
use App\Support\BulkAction\BulkExportHandler;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CertificateManagementSeeder;
use Tests\Feature\Students\StudentTestHelpers;
use Tests\TestCase;

/**
 * Certificate bulk actions — selection, export authorization, tenant
 * isolation and the export-only guarantee.
 *
 * The Certificate family is deliberately EXPORT-ONLY, and these tests pin it
 * down: every Certificate module registers exactly one action (`export`), the
 * CSV endpoint re-queries the ticked ids inside the active college and
 * re-checks the module permission (Certificate models have no policy class —
 * the controller's permission check is the module's own convention), a
 * hand-edited URL cannot widen a download, and nothing bulk-requests,
 * generates, issues or verifies. The register CSV never carries the template
 * snapshot, the data snapshot or the free-text purpose.
 */
class CertificateBulkActionTest extends TestCase
{
    use StudentTestHelpers;

    /**
     * Every Certificate module, with the model its export handler operates on
     * and the permission that gates it.
     *
     * @return array<string, array{model: class-string, permission: string}>
     */
    private function certificateModules(): array
    {
        return [
            'certificates' => ['model' => Certificate::class, 'permission' => 'certificates.view'],
            'certificate_types' => ['model' => CertificateType::class, 'permission' => 'certificate_types.manage'],
            'certificate_templates' => ['model' => CertificateTemplate::class, 'permission' => 'certificate_templates.manage'],
        ];
    }

    private function provision(College $college): void
    {
        // The tenant context stays set for the whole test, exactly like
        // CertificateManagementTest::setUp: CertificateType is tenant-scoped
        // (CollegeScope), so direct model reads in the test body — such as
        // CertificateType::firstOrFail() — resolve only while the active
        // college is pinned. HTTP requests re-resolve it per request.
        app(TenantContext::class)->set($college);
        app(CertificateCatalog::class)->provision($college->id);
    }

    private function makeCertificate(College $college, CertificateType $type, array $overrides = []): Certificate
    {
        $year = $this->makeYear($college);
        $student = $this->makeStudent($college);
        $enrollment = $this->makeEnrollment($college, $student, $year, $this->makeProgram($college));

        return Certificate::create(array_merge([
            'college_id' => $college->id,
            'certificate_type_id' => $type->id,
            'student_id' => $student->id,
            'student_enrollment_id' => $enrollment->id,
            'status' => 'requested',
            'purpose' => 'Secret purpose — never exported',
            'number' => 'CERT-'.$college->code.'-001',
            'template_snapshot' => 'SECRET-SNAPSHOT-BODY',
            'data_snapshot' => ['aadhaar' => 'SECRET-IDENTITY-NUMBER'],
        ], $overrides));
    }

    public function test_every_certificate_bulk_module_registers_export_only(): void
    {
        $registry = app(BulkActionRegistry::class);

        foreach ($this->certificateModules() as $module => $spec) {
            $this->assertSame(
                ['export'],
                array_keys($registry->getForModule($module)),
                "Certificate module [{$module}] must expose the export action and nothing else."
            );

            $handler = $registry->get($module, 'export');

            $this->assertInstanceOf(BulkExportHandler::class, $handler, $module);
            $this->assertSame($spec['model'], $handler->modelClass(), $module);
            $this->assertSame($spec['permission'], $handler->requiredPermission(), $module);
            $this->assertNull($handler->policyAbility(), $module.' gates on the module permission only.');
        }
    }

    public function test_certificate_listings_expose_bulk_selection_controls(): void
    {
        $college = $this->makeCollege('CRUI');
        $staff = $this->makeUserWithPermissions($college, CertificateManagementSeeder::PERMISSIONS);
        $this->provision($college);

        $type = CertificateType::create([
            'college_id' => $college->id,
            'name' => 'UI Certificate',
            'code' => 'UIC',
            'description' => 'Fixture type',
        ]);
        $certificate = $this->makeCertificate($college, $type, ['number' => 'CERT-UI-001']);
        $template = CertificateTemplate::create([
            'college_id' => $college->id,
            'certificate_type_id' => $type->id,
            'name' => 'UI Template',
            'body' => 'Dear {{ student_name }}, this is your certificate.',
        ]);

        $pages = [
            ['route' => 'certificates.index', 'module' => 'certificates', 'needle' => 'CERT-UI-001'],
            ['route' => 'certificates.types', 'module' => 'certificate_types', 'needle' => 'UI Certificate'],
            ['route' => 'certificates.templates', 'module' => 'certificate_templates', 'needle' => 'UI Template'],
        ];

        foreach ($pages as $page) {
            $response = $this->asCollege($college, $staff)->get(route($page['route']))->assertOk();

            $response->assertSee('data-bulk-selection', false);
            $response->assertSee('data-module="'.$page['module'].'"', false);
            $response->assertSee('data-select-all', false);
            $response->assertSee('data-select-row', false);
            $response->assertSee('data-bulk-action="export"', false);
            $response->assertSee($page['needle']);
        }
    }

    public function test_certificate_export_requires_the_module_permission(): void
    {
        $college = $this->makeCollege('CRPERM');
        $outsider = $this->makeUserWithPermissions($college, []);
        $this->provision($college);
        $type = CertificateType::firstOrFail();
        $certificate = $this->makeCertificate($college, $type);

        $this->asCollege($college, $outsider)->postJson(route('bulk-actions.execute'), [
            'module' => 'certificates',
            'action' => 'export',
            'ids' => [$certificate->id],
        ])->assertForbidden();

        $this->asCollege($college, $outsider)
            ->get(route('certificates.export', ['ids' => [$certificate->id]]))
            ->assertForbidden();

        $this->asCollege($college, $outsider)
            ->get(route('certificates.types.export', ['ids' => [$type->id]]))
            ->assertForbidden();

        $this->asCollege($college, $outsider)
            ->get(route('certificates.templates.export', ['ids' => [999999]]))
            ->assertForbidden();
    }

    public function test_certificate_export_re_queries_ids_inside_the_active_college(): void
    {
        $collegeA = $this->makeCollege('CRA');
        $collegeB = $this->makeCollege('CRB');
        $staff = $this->makeUserWithPermissions($collegeA, CertificateManagementSeeder::PERMISSIONS);
        $this->provision($collegeA);
        $this->provision($collegeB);

        $typeA = CertificateType::withoutGlobalScopes()->where('college_id', $collegeA->id)->firstOrFail();
        $typeB = CertificateType::withoutGlobalScopes()->where('college_id', $collegeB->id)->firstOrFail();

        $mine = $this->makeCertificate($collegeA, $typeA, ['number' => 'CERT-A-001']);
        $foreign = $this->makeCertificate($collegeB, $typeB, ['number' => 'CERT-B-001']);

        $response = $this->asCollege($collegeA, $staff)->postJson(route('bulk-actions.execute'), [
            'module' => 'certificates',
            'action' => 'export',
            'ids' => [$mine->id, $foreign->id],
        ])->assertOk();

        $this->assertSame([$mine->id], $response->json('data.ids'));
        $this->assertSame(1, $response->json('skipped_unauthorized'));

        $redirect = $response->json('data.redirect');
        $this->assertIsString($redirect, 'The bulk endpoint must hand back a redirect to the CSV endpoint.');

        $csv = $this->asCollege($collegeA, $staff)->get($redirect)->assertOk();
        $this->assertStringContainsString('certificates-export-', (string) $csv->headers->get('Content-Disposition'));

        $body = $csv->streamedContent();
        $this->assertStringContainsString('CERT-A-001', $body);
        $this->assertStringNotContainsString('CERT-B-001', $body);
    }

    public function test_certificate_nonexistent_ids_are_skipped(): void
    {
        $college = $this->makeCollege('CRDEL');
        $staff = $this->makeUserWithPermissions($college, CertificateManagementSeeder::PERMISSIONS);
        $this->provision($college);

        $type = CertificateType::firstOrFail();
        $certificate = $this->makeCertificate($college, $type, ['number' => 'CERT-DEL-001']);

        $response = $this->asCollege($college, $staff)->postJson(route('bulk-actions.execute'), [
            'module' => 'certificates',
            'action' => 'export',
            'ids' => [$certificate->id, 999999],
        ])->assertOk();

        $this->assertSame([$certificate->id], $response->json('data.ids'));
        $this->assertSame(1, $response->json('skipped_unauthorized'));

        $csv = $this->asCollege($college, $staff)->get($response->json('data.redirect'))->assertOk();
        $body = $csv->streamedContent();

        $this->assertStringContainsString('CERT-DEL-001', $body);
    }

    public function test_certificate_export_streams_a_bom_prefixed_csv_without_snapshots(): void
    {
        $college = $this->makeCollege('CRCSV');
        $staff = $this->makeUserWithPermissions($college, CertificateManagementSeeder::PERMISSIONS);
        $this->provision($college);

        $type = CertificateType::firstOrFail();
        $certificate = $this->makeCertificate($college, $type, ['number' => 'CERT-CSV-001']);

        $response = $this->asCollege($college, $staff)->postJson(route('bulk-actions.execute'), [
            'module' => 'certificates',
            'action' => 'export',
            'ids' => [$certificate->id],
        ])->assertOk();

        $csv = $this->asCollege($college, $staff)->get($response->json('data.redirect'));

        $csv->assertOk();
        $this->assertStringContainsString('attachment', (string) $csv->headers->get('Content-Disposition'));
        $this->assertStringContainsString('certificates-export-', (string) $csv->headers->get('Content-Disposition'));
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));

        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'Every CSV download leads with the UTF-8 BOM.');

        // The listing columns: request id, number, type, student, status.
        $this->assertStringContainsString('CERT-CSV-001', $body);
        $this->assertStringContainsString($type->name, $body);
        $this->assertStringContainsString('requested', $body);

        // ...and never the template snapshot, the data snapshot (which may
        // hold identity numbers) or the free-text purpose.
        $this->assertStringNotContainsString('SECRET-SNAPSHOT-BODY', $body);
        $this->assertStringNotContainsString('SECRET-IDENTITY-NUMBER', $body);
        $this->assertStringNotContainsString('Secret purpose', $body);
    }

    public function test_certificate_type_and_template_exports_stream_csv(): void
    {
        $college = $this->makeCollege('CRTT');
        $staff = $this->makeUserWithPermissions($college, CertificateManagementSeeder::PERMISSIONS);
        $this->provision($college);

        $type = CertificateType::create([
            'college_id' => $college->id,
            'name' => 'Exported Type',
            'code' => 'EXPT',
            'description' => 'Fixture export type',
        ]);
        $template = CertificateTemplate::create([
            'college_id' => $college->id,
            'certificate_type_id' => $type->id,
            'name' => 'Exported Template',
            'body' => 'Body of the exported template',
        ]);

        foreach ([
            ['module' => 'certificate_types', 'route' => 'certificates.types.export', 'id' => $type->id, 'filename' => 'certificate-types-export-', 'needle' => 'Exported Type'],
            ['module' => 'certificate_templates', 'route' => 'certificates.templates.export', 'id' => $template->id, 'filename' => 'certificate-templates-export-', 'needle' => 'Body of the exported template'],
        ] as $case) {
            $response = $this->asCollege($college, $staff)->postJson(route('bulk-actions.execute'), [
                'module' => $case['module'],
                'action' => 'export',
                'ids' => [$case['id']],
            ])->assertOk();

            $this->assertSame([$case['id']], $response->json('data.ids'));

            $csv = $this->asCollege($college, $staff)->get($response->json('data.redirect'));

            $csv->assertOk();
            $this->assertStringContainsString($case['filename'], (string) $csv->headers->get('Content-Disposition'));

            $body = $csv->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
            $this->assertStringContainsString($case['needle'], $body);
        }
    }

    public function test_certificate_exports_never_mutate_the_workflow(): void
    {
        $college = $this->makeCollege('CRNOMUT');
        $staff = $this->makeUserWithPermissions($college, CertificateManagementSeeder::PERMISSIONS);
        $this->provision($college);

        $type = CertificateType::firstOrFail();
        $certificate = $this->makeCertificate($college, $type, ['number' => 'CERT-NOMUT-001']);
        $template = CertificateTemplate::create([
            'college_id' => $college->id,
            'certificate_type_id' => $type->id,
            'name' => 'Nomut Template',
            'body' => 'Body',
        ]);

        $before = [
            'certificate' => $certificate->fresh()->getAttributes(),
            'type' => $type->fresh()->getAttributes(),
            'template' => $template->fresh()->getAttributes(),
        ];

        foreach ([
            ['certificates', $certificate->id],
            ['certificate_types', $type->id],
            ['certificate_templates', $template->id],
        ] as [$module, $id]) {
            $response = $this->asCollege($college, $staff)->postJson(route('bulk-actions.execute'), [
                'module' => $module,
                'action' => 'export',
                'ids' => [$id],
            ])->assertOk();

            $this->asCollege($college, $staff)->get($response->json('data.redirect'))->assertOk();
        }

        // Read-only by construction: no request generated or issued, no type or
        // template modified, no verification recorded.
        $this->assertSame($before['certificate'], $certificate->fresh()->getAttributes());
        $this->assertSame($before['type'], $type->fresh()->getAttributes());
        $this->assertSame($before['template'], $template->fresh()->getAttributes());
        $this->assertSame('requested', $certificate->fresh()->status);
        $this->assertNull($certificate->fresh()->generated_at);
        $this->assertNull($certificate->fresh()->issued_at);
        $this->assertSame(0, $certificate->fresh()->verification_count);
    }
}
