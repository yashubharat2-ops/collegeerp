<?php

namespace Tests\Feature\BulkAction;

use App\Models\College;
use App\Models\InventoryAssignment;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryIssue;
use App\Models\InventoryMaintenance;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryStockMovement;
use App\Models\InventoryVendor;
use App\Support\BulkAction\BulkActionRegistry;
use App\Support\BulkAction\BulkExportHandler;
use Tests\Feature\Inventory\InventoryTestHelpers;
use Tests\TestCase;

/**
 * Inventory / Asset Management bulk actions — selection, export authorization,
 * policy enforcement, tenant isolation and the export-only guarantee.
 *
 * The Inventory family is deliberately EXPORT-ONLY, and these tests pin it
 * down: every Inventory module registers exactly one action (`export`), the
 * CSV endpoint re-queries the ticked ids inside the active college and
 * re-authorizes the module and the record, a hand-edited URL cannot widen a
 * download, and nothing bulk-adjusts stock, issues, assigns, returns,
 * purchases or maintains. The three stock-movement modules share the ledger
 * model but keep their own permission, and each handler accepts only the
 * movement types its listing shows; the Asset Return export accepts only
 * ACTIVE assignments, exactly like its listing.
 */
class InventoryBulkActionTest extends TestCase
{
    use InventoryTestHelpers;

    /**
     * Every Inventory module, with the model its export handler operates on,
     * the permission that gates it and the per-record policy ability.
     *
     * @return array<string, array{model: class-string, permission: string, policy: ?string}>
     */
    private function inventoryModules(): array
    {
        return [
            'inventory_categories' => ['model' => InventoryCategory::class, 'permission' => 'inventory_categories.view', 'policy' => 'view'],
            'inventory_items' => ['model' => InventoryItem::class, 'permission' => 'inventory_items.view', 'policy' => 'view'],
            'inventory_vendors' => ['model' => InventoryVendor::class, 'permission' => 'inventory_vendors.view', 'policy' => 'view'],
            'inventory_purchase_orders' => ['model' => InventoryPurchaseOrder::class, 'permission' => 'inventory_purchase_orders.view', 'policy' => 'view'],
            'inventory_goods_receipts' => ['model' => InventoryStockMovement::class, 'permission' => 'inventory_goods_receipts.view', 'policy' => 'view'],
            'inventory_stock_adjustments' => ['model' => InventoryStockMovement::class, 'permission' => 'inventory_stock_adjustments.view', 'policy' => 'view'],
            'inventory_transactions' => ['model' => InventoryStockMovement::class, 'permission' => 'inventory_transactions.view', 'policy' => 'view'],
            'inventory_issues' => ['model' => InventoryIssue::class, 'permission' => 'inventory_issues.view', 'policy' => 'view'],
            'inventory_assignments' => ['model' => InventoryAssignment::class, 'permission' => 'inventory_assignments.view', 'policy' => 'view'],
            'inventory_asset_returns' => ['model' => InventoryAssignment::class, 'permission' => 'inventory_asset_returns.view', 'policy' => null],
            'inventory_maintenance' => ['model' => InventoryMaintenance::class, 'permission' => 'inventory_maintenance.view', 'policy' => 'view'],
        ];
    }

    private function makeMovement(College $college, InventoryItem $item, string $type, string $direction, string $quantity, string $balance): InventoryStockMovement
    {
        return InventoryStockMovement::create([
            'college_id' => $college->id,
            'item_id' => $item->id,
            'type' => $type,
            'direction' => $direction,
            'quantity' => $quantity,
            'balance_after' => $balance,
            'movement_date' => '2026-09-20',
        ]);
    }

    public function test_every_inventory_bulk_module_registers_export_only(): void
    {
        $registry = app(BulkActionRegistry::class);

        foreach ($this->inventoryModules() as $module => $spec) {
            $this->assertSame(
                ['export'],
                array_keys($registry->getForModule($module)),
                "Inventory module [{$module}] must expose the export action and nothing else."
            );

            $handler = $registry->get($module, 'export');

            $this->assertInstanceOf(BulkExportHandler::class, $handler, $module);
            $this->assertSame($spec['model'], $handler->modelClass(), $module);
            $this->assertSame($spec['permission'], $handler->requiredPermission(), $module);
            $this->assertSame($spec['policy'], $handler->policyAbility(), $module);
        }
    }

    public function test_inventory_listings_expose_bulk_selection_controls(): void
    {
        $college = $this->makeCollege('INUI');
        $manager = $this->makeUserWithPermissions($college, [
            'inventory_categories.view', 'inventory_items.view', 'inventory_vendors.view',
            'inventory_purchase_orders.view', 'inventory_goods_receipts.view', 'inventory_stock_adjustments.view',
            'inventory_transactions.view', 'inventory_issues.view', 'inventory_assignments.view',
            'inventory_asset_returns.view', 'inventory_maintenance.view',
        ]);

        $category = $this->makeInventoryCategory($college, ['code' => 'CAT-UI-01']);
        $vendor = $this->makeInventoryVendor($college, ['code' => 'VEN-UI-01']);
        $item = $this->makeInventoryItem($college, ['code' => 'ITM-UI-01', 'category_id' => $category->id]);
        $order = $this->makeInventoryPurchaseOrder($college, ['number' => 'PO-UI-001', 'vendor_id' => $vendor->id]);
        $receipt = $this->makeMovement($college, $item, InventoryStockMovement::TYPE_STOCK_IN, 'in', '5.00', '15.00');
        $adjustment = $this->makeMovement($college, $item, InventoryStockMovement::TYPE_ADJUSTMENT, 'out', '1.00', '14.00');
        $issue = $this->makeInventoryIssue($college, $item, ['number' => 'ISS-UI-001']);
        $asset = $this->makeAsset($college, ['code' => 'AST-UI-01']);
        $assignment = $this->makeInventoryAssignment($college, $asset);
        $maintenance = $this->makeInventoryMaintenance($college, $asset, ['title' => 'UI service']);

        $pages = [
            ['route' => 'inventory-categories.index', 'module' => 'inventory_categories', 'needle' => 'CAT-UI-01'],
            ['route' => 'inventory-items.index', 'module' => 'inventory_items', 'needle' => 'ITM-UI-01'],
            ['route' => 'inventory-vendors.index', 'module' => 'inventory_vendors', 'needle' => 'VEN-UI-01'],
            ['route' => 'inventory-purchase-orders.index', 'module' => 'inventory_purchase_orders', 'needle' => 'PO-UI-001'],
            ['route' => 'inventory-goods-receipts.index', 'module' => 'inventory_goods_receipts', 'needle' => 'ITM-UI-01'],
            ['route' => 'inventory-stock-adjustments.index', 'module' => 'inventory_stock_adjustments', 'needle' => 'ITM-UI-01'],
            ['route' => 'inventory-transactions.index', 'module' => 'inventory_transactions', 'needle' => 'ITM-UI-01'],
            ['route' => 'inventory-issues.index', 'module' => 'inventory_issues', 'needle' => 'ISS-UI-001'],
            ['route' => 'inventory-assignments.index', 'module' => 'inventory_assignments', 'needle' => 'AST-UI-01'],
            ['route' => 'inventory-asset-returns.index', 'module' => 'inventory_asset_returns', 'needle' => 'AST-UI-01'],
            ['route' => 'inventory-maintenances.index', 'module' => 'inventory_maintenance', 'needle' => 'UI service'],
        ];

        foreach ($pages as $page) {
            $response = $this->asCollege($college, $manager)->get(route($page['route']))->assertOk();

            $response->assertSee('data-bulk-selection', false);
            $response->assertSee('data-module="'.$page['module'].'"', false);
            $response->assertSee('data-select-all', false);
            $response->assertSee('data-select-row', false);
            $response->assertSee('data-bulk-action="export"', false);
            $response->assertSee($page['needle']);
        }
    }

    public function test_inventory_export_requires_the_module_permission(): void
    {
        $college = $this->makeCollege('INPERM');
        $outsider = $this->makeUserWithPermissions($college, []);
        $category = $this->makeInventoryCategory($college, ['code' => 'CAT-PERM-01']);

        $this->asCollege($college, $outsider)->postJson(route('bulk-actions.execute'), [
            'module' => 'inventory_categories',
            'action' => 'export',
            'ids' => [$category->id],
        ])->assertForbidden();

        $this->asCollege($college, $outsider)
            ->get(route('inventory-categories.export', ['ids' => [$category->id]]))
            ->assertForbidden();
    }

    public function test_inventory_export_re_queries_ids_inside_the_active_college(): void
    {
        $collegeA = $this->makeCollege('INA');
        $collegeB = $this->makeCollege('INB');
        $manager = $this->makeUserWithPermissions($collegeA, ['inventory_vendors.view']);

        $mine = $this->makeInventoryVendor($collegeA, ['code' => 'VEN-MINE-01']);
        $foreign = $this->makeInventoryVendor($collegeB, ['code' => 'VEN-FOREIGN-01']);

        $response = $this->asCollege($collegeA, $manager)->postJson(route('bulk-actions.execute'), [
            'module' => 'inventory_vendors',
            'action' => 'export',
            'ids' => [$mine->id, $foreign->id],
        ])->assertOk();

        $this->assertSame([$mine->id], $response->json('data.ids'));
        $this->assertSame(1, $response->json('skipped_unauthorized'));

        $redirect = $response->json('data.redirect');
        $this->assertIsString($redirect, 'The bulk endpoint must hand back a redirect to the CSV endpoint.');

        $csv = $this->asCollege($collegeA, $manager)->get($redirect)->assertOk();
        $this->assertStringContainsString('inventory-vendors-export-', (string) $csv->headers->get('Content-Disposition'));

        $body = $csv->streamedContent();
        $this->assertStringContainsString('VEN-MINE-01', $body);
        $this->assertStringNotContainsString('VEN-FOREIGN-01', $body);
    }

    public function test_inventory_deleted_and_nonexistent_ids_are_skipped(): void
    {
        $college = $this->makeCollege('INDEL');
        $manager = $this->makeUserWithPermissions($college, ['inventory_items.view']);

        $live = $this->makeInventoryItem($college, ['code' => 'ITM-LIVE-01']);
        $deleted = $this->makeInventoryItem($college, ['code' => 'ITM-GONE-01']);
        $deleted->delete();

        $response = $this->asCollege($college, $manager)->postJson(route('bulk-actions.execute'), [
            'module' => 'inventory_items',
            'action' => 'export',
            'ids' => [$live->id, $deleted->id, 999999],
        ])->assertOk();

        $this->assertSame([$live->id], $response->json('data.ids'));
        $this->assertSame(2, $response->json('skipped_unauthorized'));

        $csv = $this->asCollege($college, $manager)->get($response->json('data.redirect'))->assertOk();
        $body = $csv->streamedContent();

        $this->assertStringContainsString('ITM-LIVE-01', $body);
        $this->assertStringNotContainsString('ITM-GONE-01', $body);
    }

    public function test_item_export_streams_a_bom_prefixed_csv_with_stored_quantity(): void
    {
        $college = $this->makeCollege('INCSV');
        $manager = $this->makeUserWithPermissions($college, ['inventory_items.view']);
        $item = $this->makeInventoryItem($college, ['code' => 'ITM-CSV-01', 'quantity' => '7.00']);

        $response = $this->asCollege($college, $manager)->postJson(route('bulk-actions.execute'), [
            'module' => 'inventory_items',
            'action' => 'export',
            'ids' => [$item->id],
        ])->assertOk();

        $csv = $this->asCollege($college, $manager)->get($response->json('data.redirect'));

        $csv->assertOk();
        $this->assertStringContainsString('attachment', (string) $csv->headers->get('Content-Disposition'));
        $this->assertStringContainsString('inventory-items-export-', (string) $csv->headers->get('Content-Disposition'));
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));

        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'Every CSV download leads with the UTF-8 BOM.');
        $this->assertStringContainsString('ITM-CSV-01', $body);
        $this->assertStringContainsString('7.00', $body);
    }

    public function test_goods_receipt_export_never_accepts_adjustment_ids(): void
    {
        $college = $this->makeCollege('INGR');
        $manager = $this->makeUserWithPermissions($college, ['inventory_goods_receipts.view']);
        $item = $this->makeInventoryItem($college, ['code' => 'ITM-GR-01']);

        $receipt = $this->makeMovement($college, $item, InventoryStockMovement::TYPE_STOCK_IN, 'in', '5.00', '15.00');
        $adjustment = $this->makeMovement($college, $item, InventoryStockMovement::TYPE_ADJUSTMENT, 'out', '1.00', '14.00');

        // The handler accepts only purchase_receipt / stock_in movements.
        $response = $this->asCollege($college, $manager)->postJson(route('bulk-actions.execute'), [
            'module' => 'inventory_goods_receipts',
            'action' => 'export',
            'ids' => [$receipt->id, $adjustment->id],
        ])->assertOk();

        $this->assertSame([$receipt->id], $response->json('data.ids'));
        $this->assertSame(1, $response->json('skipped_unauthorized'));

        // The endpoint re-applies the same narrowing: a hand-edited URL that
        // carries an adjustment id cannot widen the download past receipts.
        $csv = $this->asCollege($college, $manager)
            ->get(route('inventory-goods-receipts.export', ['ids' => [$adjustment->id]]));

        $csv->assertOk();
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $csv->streamedContent()) ?? '';
        $lines = array_values(array_filter(explode("\n", trim($body))));
        $this->assertCount(1, $lines, 'Only the header row may be exported.');
    }

    public function test_asset_return_export_never_accepts_returned_assignments(): void
    {
        $college = $this->makeCollege('INRET');
        $manager = $this->makeUserWithPermissions($college, ['inventory_asset_returns.view']);
        $asset = $this->makeAsset($college, ['code' => 'AST-RET-01']);

        $active = $this->makeInventoryAssignment($college, $asset);
        $returned = $this->makeInventoryAssignment($college, $asset, [
            'status' => InventoryAssignment::STATUS_RETURNED,
            'returned_on' => '2026-09-25',
        ]);

        // The Asset Return listing shows only assets currently out.
        $response = $this->asCollege($college, $manager)->postJson(route('bulk-actions.execute'), [
            'module' => 'inventory_asset_returns',
            'action' => 'export',
            'ids' => [$active->id, $returned->id],
        ])->assertOk();

        $this->assertSame([$active->id], $response->json('data.ids'));
        $this->assertSame(1, $response->json('skipped_unauthorized'));

        $csv = $this->asCollege($college, $manager)->get($response->json('data.redirect'))->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('AST-RET-01', $body);
    }

    public function test_inventory_exports_never_mutate_stock_assignments_or_maintenance(): void
    {
        $college = $this->makeCollege('INNOMUT');
        $manager = $this->makeUserWithPermissions($college, [
            'inventory_items.view', 'inventory_transactions.view', 'inventory_issues.view',
            'inventory_assignments.view', 'inventory_asset_returns.view', 'inventory_maintenance.view',
        ]);

        $item = $this->makeInventoryItem($college, ['code' => 'ITM-NOMUT-01', 'quantity' => '10.00']);
        $movement = $this->makeMovement($college, $item, InventoryStockMovement::TYPE_STOCK_IN, 'in', '10.00', '10.00');
        $issue = $this->makeInventoryIssue($college, $item, ['number' => 'ISS-NOMUT-001']);
        $asset = $this->makeAsset($college, ['code' => 'AST-NOMUT-01']);
        $assignment = $this->makeInventoryAssignment($college, $asset);
        $maintenance = $this->makeInventoryMaintenance($college, $asset, ['title' => 'Nomut service']);

        $before = [
            'item' => $item->fresh()->getAttributes(),
            'movement' => $movement->fresh()->getAttributes(),
            'issue' => $issue->fresh()->getAttributes(),
            'assignment' => $assignment->fresh()->getAttributes(),
            'maintenance' => $maintenance->fresh()->getAttributes(),
        ];

        foreach ([
            ['inventory_items', $item->id],
            ['inventory_transactions', $movement->id],
            ['inventory_issues', $issue->id],
            ['inventory_assignments', $assignment->id],
            ['inventory_asset_returns', $assignment->id],
            ['inventory_maintenance', $maintenance->id],
        ] as [$module, $id]) {
            $response = $this->asCollege($college, $manager)->postJson(route('bulk-actions.execute'), [
                'module' => $module,
                'action' => 'export',
                'ids' => [$id],
            ])->assertOk();

            $this->asCollege($college, $manager)->get($response->json('data.redirect'))->assertOk();
        }

        // Read-only by construction: no stock adjusted, no issue reversed, no
        // asset returned, no work order completed, no quantity recomputed.
        $this->assertSame($before['item'], $item->fresh()->getAttributes());
        $this->assertSame($before['movement'], $movement->fresh()->getAttributes());
        $this->assertSame($before['issue'], $issue->fresh()->getAttributes());
        $this->assertSame($before['assignment'], $assignment->fresh()->getAttributes());
        $this->assertSame($before['maintenance'], $maintenance->fresh()->getAttributes());
        $this->assertSame('10.00', (string) $item->fresh()->quantity);
        $this->assertSame(InventoryAssignment::STATUS_ACTIVE, $assignment->fresh()->status);
        $this->assertNull($assignment->fresh()->returned_on);
        $this->assertSame(InventoryMaintenance::STATUS_SCHEDULED, $maintenance->fresh()->status);
    }
}
