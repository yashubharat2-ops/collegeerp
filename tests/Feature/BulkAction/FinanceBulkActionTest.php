<?php

namespace Tests\Feature\BulkAction;

use App\Models\FeeCategory;
use App\Models\FeeConcession;
use App\Models\FeePayment;
use App\Models\FeeRefund;
use App\Models\FeeStructure;
use App\Models\StudentFeeAssignment;
use App\Support\BulkAction\BulkActionRegistry;
use App\Support\BulkAction\BulkExportHandler;
use Tests\Feature\Finance\FeeStructureTestHelpers;
use Tests\TestCase;

/**
 * Finance bulk actions — selection, export authorization, policy enforcement,
 * tenant isolation and the export-only guarantee.
 *
 * The whole Finance family is deliberately EXPORT-ONLY. These tests pin that
 * down: every Finance module registers exactly one action (`export`), and the
 * CSV endpoint
 *  - re-queries the ticked ids inside the active college, so a foreign college's
 *    row is dropped instead of exported;
 *  - re-authorizes the module (and the record, where a policy ability applies)
 *    before a single byte is streamed;
 *  - cannot be widened by hand-editing its URL;
 *  - never mutates a payment, receipt, refund, concession or fee assignment.
 *
 * Nothing here collects a fee, issues a receipt, approves a refund, changes a
 * concession or touches a ledger — those remain single-record workflows.
 */
class FinanceBulkActionTest extends TestCase
{
    use FeeStructureTestHelpers;

    /**
     * Every Finance module, with the model its export handler operates on and
     * the permission that gates it.
     *
     * @return array<string, array{model: class-string, permission: string}>
     */
    private function financeModules(): array
    {
        return [
            'fee_structures' => ['model' => FeeStructure::class, 'permission' => 'fee_structures.view'],
            'fee_categories' => ['model' => FeeCategory::class, 'permission' => 'fee_categories.view'],
            'student_fee_assignments' => ['model' => StudentFeeAssignment::class, 'permission' => 'student_fee_assignments.view'],
            'fee_collections' => ['model' => FeePayment::class, 'permission' => 'fee_collections.view'],
            'receipts' => ['model' => FeePayment::class, 'permission' => 'receipts.view'],
            'fee_dues' => ['model' => StudentFeeAssignment::class, 'permission' => 'fee_dues.view'],
            'fee_concessions' => ['model' => FeeConcession::class, 'permission' => 'fee_concessions.view'],
            'refunds' => ['model' => FeeRefund::class, 'permission' => 'refunds.view'],
        ];
    }

    public function test_every_finance_bulk_module_registers_export_only(): void
    {
        $registry = app(BulkActionRegistry::class);

        foreach ($this->financeModules() as $module => $spec) {
            $this->assertSame(
                ['export'],
                array_keys($registry->getForModule($module)),
                "Finance module [{$module}] must expose the export action and nothing else."
            );

            $handler = $registry->get($module, 'export');

            $this->assertInstanceOf(BulkExportHandler::class, $handler, $module);
            $this->assertSame($spec['model'], $handler->modelClass(), $module);
            $this->assertSame($spec['permission'], $handler->requiredPermission(), $module);
            $this->assertSame('view', $handler->policyAbility(), $module);
        }
    }

    public function test_export_re_queries_ids_inside_the_active_college(): void
    {
        $collegeA = $this->makeCollege('FINBULKA');
        $collegeB = $this->makeCollege('FINBULKB');
        $admin = $this->makeUserWithPermissions($collegeA, ['fee_categories.view']);

        $mine = $this->makeFeeCategory($collegeA, ['name' => 'Tuition A', 'code' => 'FIN-A-100']);
        $foreign = $this->makeFeeCategory($collegeB, ['name' => 'Tuition B', 'code' => 'FIN-B-200']);

        $response = $this->asCollege($collegeA, $admin)->postJson(route('bulk-actions.execute'), [
            'module' => 'fee_categories',
            'action' => 'export',
            'ids' => [$mine->id, $foreign->id],
        ])->assertOk();

        // Only the id the active college owns survives the re-query.
        $this->assertSame([$mine->id], $response->json('data.ids'));
        $this->assertSame(1, $response->json('affected'));

        $csv = $this->asCollege($collegeA, $admin)->get($response->json('data.redirect'));
        $csv->assertOk();

        $body = $csv->streamedContent();
        $this->assertStringContainsString('FIN-A-100', $body);
        $this->assertStringNotContainsString('FIN-B-200', $body);
    }

    public function test_export_requires_the_module_permission(): void
    {
        $college = $this->makeCollege('FINPERM');
        $outsider = $this->makeUserWithPermissions($college, []);
        $category = $this->makeFeeCategory($college);

        $this->asCollege($college, $outsider)->postJson(route('bulk-actions.execute'), [
            'module' => 'fee_categories',
            'action' => 'export',
            'ids' => [$category->id],
        ])->assertForbidden();

        $this->asCollege($college, $outsider)
            ->get(route('fee-categories.export', ['ids' => [$category->id]]))
            ->assertForbidden();
    }

    public function test_an_empty_selection_is_rejected_by_the_shared_endpoint(): void
    {
        $college = $this->makeCollege('FINEMPTY');
        $admin = $this->makeUserWithPermissions($college, ['fee_categories.view']);

        $this->asCollege($college, $admin)->postJson(route('bulk-actions.execute'), [
            'module' => 'fee_categories',
            'action' => 'export',
            'ids' => [],
        ])->assertStatus(422);
    }

    public function test_hand_edited_export_url_cannot_widen_the_download(): void
    {
        $collegeA = $this->makeCollege('FINURLA');
        $collegeB = $this->makeCollege('FINURLB');
        $admin = $this->makeUserWithPermissions($collegeA, ['fee_categories.view']);

        $this->makeFeeCategory($collegeA, ['name' => 'Mine', 'code' => 'FIN-URL-A']);
        $foreign = $this->makeFeeCategory($collegeB, ['name' => 'Theirs', 'code' => 'FIN-URL-B']);

        // A hand-crafted URL asking for the other college's row (and an id that
        // does not exist) must stream the header row only.
        $csv = $this->asCollege($collegeA, $admin)
            ->get(route('fee-categories.export', ['ids' => [$foreign->id, 999999]]));

        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringNotContainsString('FIN-URL-B', $body);
        $this->assertSame(1, substr_count(trim($body), "\n") + 1, 'Only the header row may be exported.');
    }

    public function test_fee_structure_export_carries_each_component(): void
    {
        $college = $this->makeCollege('FINFSBULK');
        $admin = $this->makeUserWithPermissions($college, ['fee_structures.view']);
        $ctx = $this->makeFinanceContext($college, 'FSBLK');

        $structure = $this->makeFeeStructure($college, $ctx, [
            'name' => 'Bulk Plan',
            'code' => 'FIN-FS-BULK',
        ]);

        $response = $this->asCollege($college, $admin)->postJson(route('bulk-actions.execute'), [
            'module' => 'fee_structures',
            'action' => 'export',
            'ids' => [$structure->id],
        ])->assertOk();

        $redirect = $response->json('data.redirect');
        $this->assertIsString($redirect, 'The bulk endpoint must hand back a redirect to the CSV endpoint.');

        $csv = $this->asCollege($college, $admin)->get($redirect)->assertOk();
        $this->assertStringContainsString('fee-structures-export-', (string) $csv->headers->get('Content-Disposition'));

        $body = $csv->streamedContent();

        // The shared CsvStreamExport leads every download with a UTF-8 BOM for
        // Excel — the project-wide convention StudentExportTest and
        // CsvStreamExportTest pin — and fputcsv quotes every field containing a
        // space (Excel-safe, not a defect). The header is therefore validated as
        // PARSED fields, with the BOM removed before str_getcsv() so it can never
        // end up inside the first field.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        // Remove that BOM at the byte level BEFORE parsing — the same read-side
        // convention StudentImportService uses — so it can never leak into the
        // first parsed field.
        $csvBody = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        $lines = array_values(array_filter(explode("\n", trim($csvBody))));

        // One line per configured component, exactly as the card shows them.
        $this->assertCount($structure->items()->count() + 1, $lines);
        $this->assertSame(
            ['Fee structure', 'Code', 'Academic year', 'Program', 'Term', 'Structure status', '#', 'Fee category / Name', 'Amount', 'Description', 'Component status'],
            str_getcsv(rtrim($lines[0], "\r"), ',', '"', '\\')
        );

        foreach ($structure->items as $item) {
            $this->assertStringContainsString($item->name, $body);
        }
    }

    public function test_fee_dues_export_reuses_the_ledger_and_never_mutates_it(): void
    {
        $college = $this->makeCollege('FINDUES');
        $admin = $this->makeUserWithPermissions($college, [
            'fee_dues.view',
            'student_fee_assignments.view',
            'fee_collections.view',
            'fee_collections.create',
        ]);
        $ctx = $this->makeFinanceContext($college, 'DUBLK');
        $structure = $this->makeFeeStructure($college, $ctx);
        ['student' => $student, 'enrollment' => $enrollment] = $this->makeFinanceEnrollment($college, $ctx, 'DU1');
        $assignment = $this->assignFeeStructure($college, $admin, $enrollment, $structure);
        $this->collectFee($college, $admin, $assignment, 1000.0);

        $ledgerBefore = $this->ledgerOf($college, $assignment);
        $paymentsBefore = FeePayment::withoutGlobalScopes()->count();

        $response = $this->asCollege($college, $admin)->postJson(route('bulk-actions.execute'), [
            'module' => 'fee_dues',
            'action' => 'export',
            'ids' => [$assignment->id],
        ])->assertOk();

        $redirect = $response->json('data.redirect');
        $this->assertIsString($redirect, 'The bulk endpoint must hand back a redirect to the CSV endpoint.');

        $csv = $this->asCollege($college, $admin)->get($redirect)->assertOk();
        $this->assertStringContainsString('fee-dues-export-', (string) $csv->headers->get('Content-Disposition'));

        $body = $csv->streamedContent();
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;

        $this->assertStringContainsString('Outstanding', $body);
        $this->assertStringContainsString($enrollment->enrollment_number, $body);
        $this->assertStringContainsString($student->fullName(), $body);

        // Read-only by construction: the ledger, the payments and the assignment
        // are exactly what they were before the export.
        $this->assertSame($ledgerBefore, $this->ledgerOf($college, $assignment));
        $this->assertSame($paymentsBefore, FeePayment::withoutGlobalScopes()->count());
        $this->assertSame(1, FeePayment::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('status', FeePayment::STATUS_COMPLETED)
            ->count());
    }

    public function test_a_selection_never_changes_a_finance_record(): void
    {
        $college = $this->makeCollege('FINNOMUT');
        $admin = $this->makeUserWithPermissions($college, ['fee_categories.view', 'refunds.view']);

        $category = $this->makeFeeCategory($college, ['name' => 'Untouched', 'code' => 'FIN-NOMUT']);
        $before = $category->fresh()->getAttributes();
        $paymentsBefore = FeePayment::withoutGlobalScopes()->count();
        $refundsBefore = FeeRefund::withoutGlobalScopes()->count();
        $concessionsBefore = FeeConcession::withoutGlobalScopes()->count();

        $this->asCollege($college, $admin)->postJson(route('bulk-actions.execute'), [
            'module' => 'fee_categories',
            'action' => 'export',
            'ids' => [$category->id],
        ])->assertOk();

        $this->assertSame($before, $category->fresh()->getAttributes());

        // No money table grew a row: an export never collects, refunds or
        // concedes anything.
        $this->assertSame($refundsBefore, FeeRefund::withoutGlobalScopes()->count());
        $this->assertSame($concessionsBefore, FeeConcession::withoutGlobalScopes()->count());
        $this->assertSame($paymentsBefore, FeePayment::withoutGlobalScopes()->count());
    }
}
