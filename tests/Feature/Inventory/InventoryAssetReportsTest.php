<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Services\InventoryReportService;
use App\Domain\Inventory\Services\InventoryStockService;
use App\Http\Controllers\Inventory\InventoryReportController;
use App\Models\College;
use App\Models\InventoryAssignment;
use App\Models\InventoryItem;
use App\Models\InventoryMaintenance;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryStockMovement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Focused acceptance coverage for the read-only Inventory / Asset Reports layer. */
class InventoryAssetReportsTest extends TestCase
{
    use InventoryTestHelpers;

    private const EXPECTED_REPORTS = [
        'current-stock' => 'Current Stock Report',
        'low-stock' => 'Low Stock Report',
        'transactions' => 'Inventory Transaction Report',
        'adjustments' => 'Stock Adjustment Report',
        'purchases' => 'Purchase / Goods Receipt Report',
        'issues' => 'Item Issue / Allocation Report',
        'assets' => 'Asset Register',
        'assignments' => 'Asset Assignment Report',
        'returns' => 'Asset Return Report',
        'maintenance' => 'Asset Maintenance Report',
        'summary' => 'Inventory Summary',
    ];

    /**
     * @param  array<string, mixed>  $filters
     */
    private function report(College $college, User $user, string $report, array $filters = []): TestResponse
    {
        return $this->asCollege($college, $user)->get(route('inventory-reports.index', array_merge(
            ['report' => $report],
            $filters,
        )));
    }

    /** Write a fixture movement through the existing operational stock service. */
    private function move(
        College $college,
        InventoryItem $item,
        User $actor,
        string $type,
        string $quantity,
        string $direction,
        string $date,
        array $meta = [],
    ): InventoryStockMovement {
        return $this->withTenant($college, fn () => app(InventoryStockService::class)->apply(
            $item,
            $type,
            $quantity,
            $direction,
            $actor,
            array_merge($meta, ['movement_date' => $date]),
        ));
    }

    public function test_all_eleven_reports_render_in_the_required_order_from_existing_sources(): void
    {
        $college = $this->makeCollege('IAR11');
        $user = $this->makeUserWithPermissions($college, ['inventory_reports.view']);
        $category = $this->makeInventoryCategory($college, ['name' => 'Lab Equipment', 'code' => 'LAB-EQ']);
        $item = $this->makeInventoryItem($college, [
            'category_id' => $category->id,
            'name' => 'Blue Cable',
            'code' => 'CAB-01',
            'unit' => 'm',
            'quantity' => '0.00',
        ]);
        $asset = $this->makeAsset($college, [
            'category_id' => $category->id,
            'name' => 'Lab Laptop',
            'code' => 'LAP-01',
            'serial_number' => 'SN-LAP-001',
            'brand' => 'Example',
            'model' => 'Book 14',
        ]);
        $vendor = $this->makeInventoryVendor($college, ['name' => 'Campus Supplier', 'code' => 'VEN-LAB']);
        $order = $this->makeInventoryPurchaseOrder($college, [
            'vendor_id' => $vendor->id,
            'number' => 'PO-LAB-01',
            'po_date' => '2026-09-20',
            'status' => InventoryPurchaseOrder::STATUS_PARTIALLY_RECEIVED,
        ]);
        $line = $this->addPurchaseOrderLine($order, $item, [
            'quantity' => '10.00',
            'unit_price' => '25.00',
            'received_quantity' => '4.00',
        ]);

        $this->move($college, $item, $user, InventoryStockMovement::TYPE_PURCHASE_RECEIPT, '4.00', 'in', '2026-09-20', [
            'purchase_order_id' => $order->id,
            'reference' => 'GRN-LAB-01',
        ]);
        $this->move($college, $item, $user, InventoryStockMovement::TYPE_ADJUSTMENT, '2.00', 'out', '2026-09-22', [
            'reason' => 'Damaged cable',
            'reference' => 'ADJ-LAB-01',
        ]);

        $student = $this->makeStudent($college, ['first_name' => 'Asha', 'last_name' => 'Student']);
        $issue = $this->makeInventoryIssue($college, $item, [
            'number' => 'ISS-LAB-01',
            'quantity' => '1.00',
            'issued_to_type' => 'student',
            'issued_to_id' => $student->id,
            'movement_date' => '2026-09-23',
            'purpose' => 'Workshop practical',
        ]);
        $this->move($college, $item, $user, InventoryStockMovement::TYPE_STOCK_OUT, '1.00', 'out', '2026-09-23', [
            'reference' => $issue->number,
        ]);
        $this->move($college, $item, $user, InventoryStockMovement::TYPE_STOCK_IN, '3.00', 'in', '2026-09-24', [
            'reference' => 'MANUAL-GRN-01',
        ]);

        // A cached catalogue quantity that disagrees with the ledger must not
        // change Current Stock, Low Stock, or Summary.
        InventoryItem::withoutGlobalScopes()->whereKey($item->id)->update(['quantity' => '999.00']);

        $returned = $this->makeInventoryAssignment($college, $asset, [
            'assigned_to_type' => 'student',
            'assigned_to_id' => $student->id,
            'assigned_on' => '2026-09-01',
            'returned_on' => '2026-09-10',
            'returned_by' => $user->id,
            'return_notes' => 'Returned in good condition',
            'status' => InventoryAssignment::STATUS_RETURNED,
        ]);
        $faculty = $this->makeFaculty($college, ['first_name' => 'Devi', 'last_name' => 'Rao']);
        $active = $this->makeInventoryAssignment($college, $asset, [
            'assigned_to_type' => 'faculty',
            'assigned_to_id' => $faculty->id,
            'assigned_on' => '2026-09-21',
            'status' => InventoryAssignment::STATUS_ACTIVE,
        ]);
        $this->makeInventoryMaintenance($college, $asset, [
            'vendor_id' => $vendor->id,
            'title' => 'Laptop repair',
            'maintenance_type' => InventoryMaintenance::TYPE_REPAIR,
            'status' => InventoryMaintenance::STATUS_COMPLETED,
            'scheduled_on' => '2026-09-11',
            'completed_on' => '2026-09-12',
            'cost' => '125.50',
            'performed_by' => 'Campus Service Desk',
        ]);
        $this->makeInventoryMaintenance($college, $asset, [
            'title' => 'Quarterly inspection',
            'status' => InventoryMaintenance::STATUS_SCHEDULED,
            'scheduled_on' => '2026-09-25',
        ]);

        $this->assertSame(self::EXPECTED_REPORTS, InventoryReportController::REPORTS);
        $this->assertFalse(Schema::hasTable('inventory_reports'));
        $this->assertFalse(Schema::hasTable('assets'));

        $summaryPage = $this->asCollege($college, $user)->get(route('inventory-reports.index'))
            ->assertOk()
            ->assertViewHas('report', 'summary')
            ->assertViewHas('summary', function (array $summary): bool {
                $metrics = $summary['metrics'];
                $this->assertSame(2, (int) $metrics['total_items']);
                $this->assertSame(1, (int) $metrics['total_assets']);
                $this->assertSame(1, (int) $metrics['total_categories']);
                $this->assertSame(1, (int) $metrics['low_stock_items']);
                $this->assertSame(4, (int) $metrics['stock_transactions']);
                $this->assertSame(1, (int) $metrics['purchase_orders']);
                $this->assertEquals(250, $metrics['purchase_order_value']);
                $this->assertSame(2, (int) $metrics['goods_receipts']);
                $this->assertSame(1, (int) $metrics['item_issues']);
                $this->assertSame(1, (int) $metrics['assets_assigned']);
                $this->assertSame(1, (int) $metrics['assets_returned']);
                $this->assertSame(1, (int) $metrics['assets_under_maintenance']);

                $stockByUnit = collect($summary['stock_by_unit'])->keyBy('unit');
                $this->assertEquals(4, $stockByUnit->get('m')->stock_quantity);
                $this->assertEquals(0, $stockByUnit->get('nos')->stock_quantity);

                return true;
            })
            ->assertViewHas('rows', function ($page) use ($category): bool {
                $this->assertCount(1, $page->items());
                $row = $page->items()[0];
                $this->assertSame($category->id, $row->id);
                $this->assertSame(2, (int) $row->items_count);
                $this->assertSame(1, (int) $row->assets_count);
                $this->assertSame(1, (int) $row->assigned_assets_count);
                $this->assertSame(1, (int) $row->open_maintenance_assets_count);
                $this->assertSame(1, (int) $row->low_count);

                return true;
            });

        $html = $summaryPage->getContent();
        $this->assertStringContainsString('Inventory Summary', $html);
        $cursor = -1;
        foreach (self::EXPECTED_REPORTS as $key => $label) {
            $href = 'href="'.route('inventory-reports.index', ['report' => $key]).'"';
            $position = strpos($html, $href);
            $this->assertNotFalse($position, "Missing report navigation link: {$label}");
            $this->assertGreaterThan($cursor, $position, "Report navigation order changed at {$label}.");
            $cursor = $position;
        }

        $this->report($college, $user, 'current-stock')
            ->assertOk()
            ->assertSee('Blue Cable')
            ->assertSee('Lab Laptop')
            ->assertViewHas('items', function ($page) use ($item, $asset): bool {
                $rows = collect($page->items())->keyBy('id');
                $this->assertEquals(4, $rows[$item->id]->on_hand);
                $this->assertEquals(0, $rows[$asset->id]->on_hand);

                return true;
            });

        $this->report($college, $user, 'low-stock')
            ->assertOk()
            ->assertSee('Blue Cable')
            ->assertDontSee('Lab Laptop');

        $this->report($college, $user, 'transactions')
            ->assertOk()
            ->assertSee('GRN-LAB-01')
            ->assertSee('PO-LAB-01')
            ->assertSee('Damaged cable');

        $this->report($college, $user, 'adjustments')
            ->assertOk()
            ->assertSee('Damaged cable')
            ->assertSee('ISS-LAB-01')
            ->assertViewHas('movements', fn ($page) => $page->total() === 2);

        $this->report($college, $user, 'purchases')
            ->assertOk()
            ->assertSee('PO-LAB-01')
            ->assertSee('Campus Supplier')
            ->assertSee('GRN-LAB-01')
            ->assertSee('Purchase receipt')
            ->assertSee('Manual stock in')
            ->assertViewHas('orders', function ($page) use ($line): bool {
                $this->assertCount(1, $page->items());
                $this->assertSame($line->id, $page->items()[0]->id);
                $this->assertTrue($page->items()[0]->relationLoaded('purchaseOrder'));
                $this->assertTrue($page->items()[0]->relationLoaded('item'));

                return true;
            })
            ->assertViewHas('receipts', fn ($page) => $page->total() === 2);

        $this->report($college, $user, 'issues')
            ->assertOk()
            ->assertSee('ISS-LAB-01')
            ->assertSee('Asha Student')
            ->assertSee('Workshop practical');

        $this->report($college, $user, 'assets')
            ->assertOk()
            ->assertSee('Lab Laptop')
            ->assertSee('SN-LAP-001')
            ->assertSee('Devi Rao')
            ->assertViewHas('assets', function ($page) use ($asset, $active, $returned): bool {
                $row = collect($page->items())->firstWhere('id', $asset->id);
                $this->assertNotNull($row);
                $this->assertSame('2026-09-10', substr((string) $row->last_returned_on, 0, 10));
                $this->assertSame('2026-09-12', substr((string) $row->last_service_on, 0, 10));
                $this->assertSame(1, (int) $row->open_maintenance_count);
                $this->assertSame($active->id, $row->activeAssignment->id);
                $this->assertNotSame($returned->id, $row->activeAssignment->id);

                return true;
            });

        $this->report($college, $user, 'assignments')
            ->assertOk()
            ->assertSee('Devi Rao')
            ->assertSee('Asha Student')
            ->assertViewHas('assignments', fn ($page) => $page->total() === 2);

        $this->report($college, $user, 'returns')
            ->assertOk()
            ->assertSee('Returned in good condition')
            ->assertSee('Asha Student')
            ->assertDontSee('Devi Rao')
            ->assertViewHas('returns', function ($page) use ($returned, $active): bool {
                $this->assertCount(1, $page->items());
                $this->assertSame($returned->id, $page->items()[0]->id);
                $this->assertNotSame($active->id, $page->items()[0]->id);

                return true;
            });

        $this->report($college, $user, 'maintenance')
            ->assertOk()
            ->assertSee('Laptop repair')
            ->assertSee('Quarterly inspection')
            ->assertSee('125.50')
            ->assertSee('Campus Supplier')
            ->assertViewHas('maintenances', fn ($page) => $page->total() === 2);
    }

    public function test_report_permission_is_separate_and_seeded_for_super_admin_and_college_admin(): void
    {
        $permission = Permission::query()->where('slug', 'inventory_reports.view')->firstOrFail();
        $this->assertSame('inventory_reports', $permission->module);
        $this->assertSame('view', $permission->action);
        $this->assertSame(1, Permission::query()->where('module', 'inventory_reports')->count());
        $this->assertFalse(Permission::query()->whereIn('slug', [
            'inventory_reports.create', 'inventory_reports.update', 'inventory_reports.delete', 'inventory_reports.export',
        ])->exists());

        $college = College::query()->where('code', 'DEMO')->firstOrFail();
        $collegeAdmin = Role::query()->where('college_id', $college->id)->where('slug', 'college-admin')->firstOrFail();
        $superAdmin = Role::query()->whereNull('college_id')->where('slug', Role::SUPER_ADMIN_SLUG)->firstOrFail();
        $this->assertTrue($collegeAdmin->permissions()->whereKey($permission->id)->exists());
        $this->assertTrue($superAdmin->permissions()->whereKey($permission->id)->exists());

        $reportOnly = $this->makeUserWithPermissions($college, ['inventory_reports.view']);
        $this->asCollege($college, $reportOnly)->get(route('inventory-reports.index'))->assertOk();
        $this->asCollege($college, $reportOnly)->get(route('inventory-items.index'))->assertForbidden();

        $bystander = $this->makeBystanderUser($college);
        $this->asCollege($college, $bystander)->get(route('inventory-reports.index'))->assertForbidden();
    }

    public function test_foreign_college_rows_and_foreign_filter_ids_return_no_data(): void
    {
        $college = $this->makeCollege('IARLOCAL');
        $other = $this->makeCollege('IARFOREIGN');
        $viewer = $this->makeUserWithPermissions($college, ['inventory_reports.view']);
        $foreignActor = $this->makeUserWithPermissions($other, []);

        $foreignCategory = $this->makeInventoryCategory($other, ['name' => 'Foreign category']);
        $foreignItem = $this->makeInventoryItem($other, [
            'category_id' => $foreignCategory->id,
            'name' => 'Foreign consumable',
            'quantity' => '0.00',
        ]);
        $foreignAsset = $this->makeAsset($other, [
            'category_id' => $foreignCategory->id,
            'name' => 'Foreign asset',
        ]);
        $foreignVendor = $this->makeInventoryVendor($other, ['name' => 'Foreign vendor']);
        $foreignOrder = $this->makeInventoryPurchaseOrder($other, [
            'vendor_id' => $foreignVendor->id,
            'number' => 'PO-FOREIGN-01',
        ]);
        $this->addPurchaseOrderLine($foreignOrder, $foreignItem);
        $this->move($other, $foreignItem, $foreignActor, InventoryStockMovement::TYPE_PURCHASE_RECEIPT, '3.00', 'in', '2026-09-10', [
            'purchase_order_id' => $foreignOrder->id,
            'reference' => 'GRN-FOREIGN-01',
        ]);
        $this->move($other, $foreignItem, $foreignActor, InventoryStockMovement::TYPE_ADJUSTMENT, '1.00', 'out', '2026-09-11');
        $this->makeInventoryIssue($other, $foreignItem, ['number' => 'ISS-FOREIGN-01']);
        $this->makeInventoryAssignment($other, $foreignAsset, [
            'status' => InventoryAssignment::STATUS_RETURNED,
            'returned_on' => '2026-09-12',
        ]);
        $this->makeInventoryMaintenance($other, $foreignAsset, [
            'vendor_id' => $foreignVendor->id,
            'title' => 'Foreign maintenance',
        ]);

        $cases = [
            'current-stock' => [['item_id' => $foreignItem->id], 'items'],
            'low-stock' => [['item_id' => $foreignItem->id], 'items'],
            'transactions' => [['item_id' => $foreignItem->id], 'movements'],
            'adjustments' => [['item_id' => $foreignItem->id], 'movements'],
            'purchases' => [['item_id' => $foreignItem->id], 'orders'],
            'issues' => [['item_id' => $foreignItem->id], 'issues'],
            'assets' => [['item_id' => $foreignAsset->id], 'assets'],
            'assignments' => [['item_id' => $foreignAsset->id], 'assignments'],
            'returns' => [['item_id' => $foreignAsset->id], 'returns'],
            'maintenance' => [['item_id' => $foreignAsset->id], 'maintenances'],
            'summary' => [['category_id' => $foreignCategory->id], 'rows'],
        ];

        foreach ($cases as $report => [$filters, $viewKey]) {
            $response = $this->report($college, $viewer, $report, $filters)->assertOk();
            $response->assertViewHas($viewKey, fn ($page) => $page->isEmpty());
            $response->assertDontSee('Foreign category')->assertDontSee('Foreign consumable')->assertDontSee('Foreign asset');
        }

        $this->report($college, $viewer, 'purchases', ['purchase_order_id' => $foreignOrder->id])
            ->assertOk()
            ->assertViewHas('orders', fn ($page) => $page->isEmpty())
            ->assertViewHas('receipts', fn ($page) => $page->isEmpty())
            ->assertDontSee('PO-FOREIGN-01');
        $this->report($college, $viewer, 'purchases', ['vendor_id' => $foreignVendor->id])
            ->assertOk()
            ->assertViewHas('orders', fn ($page) => $page->isEmpty())
            ->assertViewHas('receipts', fn ($page) => $page->isEmpty());
        $this->report($college, $viewer, 'transactions', ['vendor_id' => $foreignVendor->id])
            ->assertOk()->assertViewHas('movements', fn ($page) => $page->isEmpty());
        $this->report($college, $viewer, 'maintenance', ['vendor_id' => $foreignVendor->id])
            ->assertOk()->assertViewHas('maintenances', fn ($page) => $page->isEmpty());

        $this->assertFalse(Schema::hasTable('inventory_reports'));
    }

    public function test_empty_filters_are_safe_and_invalid_visible_filters_are_rejected(): void
    {
        $college = $this->makeCollege('IAREMPTY');
        $user = $this->makeUserWithPermissions($college, ['inventory_reports.view']);
        $category = $this->makeInventoryCategory($college);
        $item = $this->makeInventoryItem($college, ['category_id' => $category->id, 'name' => 'Zero balance item', 'quantity' => '0.00']);

        $this->report($college, $user, 'current-stock', [
            'item_id' => '',
            'search' => '   ',
            'transaction_type' => 'not-a-current-stock-filter',
        ])->assertOk()
            ->assertViewHas('items', function ($page) use ($item): bool {
                $this->assertSame(1, $page->total());
                $this->assertSame($item->id, $page->items()[0]->id);
                $this->assertEquals(0, $page->items()[0]->on_hand);

                return true;
            });

        $this->report($college, $user, 'low-stock', ['threshold' => '-0.01'])->assertSessionHasErrors('threshold');
        $this->report($college, $user, 'low-stock', ['threshold' => '10000000000'])->assertSessionHasErrors('threshold');
        $this->report($college, $user, 'current-stock', ['item_id' => 'not-an-id'])->assertSessionHasErrors('item_id');
        $this->report($college, $user, 'current-stock', ['item_type' => 'equipment'])->assertSessionHasErrors('item_type');
        $this->report($college, $user, 'transactions', ['transaction_type' => 'invented'])->assertSessionHasErrors('transaction_type');
        $this->report($college, $user, 'transactions', ['from' => '2026-10-02', 'to' => '2026-10-01'])->assertSessionHasErrors('to');
        $this->report($college, $user, 'transactions', ['from' => 'not-a-date'])->assertSessionHasErrors('from');
        $this->asCollege($college, $user)->get(route('inventory-reports.index', ['report' => 'unlisted-report']))
            ->assertSessionHasErrors('report');
    }

    public function test_inactive_and_archived_records_follow_existing_lifecycle_rules(): void
    {
        $college = $this->makeCollege('IARARCH');
        $user = $this->makeUserWithPermissions($college, ['inventory_reports.view']);
        $inactive = $this->makeInventoryItem($college, [
            'name' => 'Inactive consumable',
            'quantity' => '0.00',
            'status' => InventoryItem::STATUS_INACTIVE,
        ]);
        $this->move($college, $inactive, $user, InventoryStockMovement::TYPE_STOCK_IN, '2.00', 'in', '2026-09-01');

        $archived = $this->makeInventoryItem($college, [
            'name' => 'Archived consumable',
            'quantity' => '0.00',
        ]);
        $this->move($college, $archived, $user, InventoryStockMovement::TYPE_STOCK_IN, '1.00', 'in', '2026-09-02');
        $this->withTenant($college, fn () => $archived->delete());

        $inactiveAsset = $this->makeAsset($college, [
            'name' => 'Inactive asset',
            'status' => InventoryItem::STATUS_INACTIVE,
        ]);
        $asset = $this->makeAsset($college, ['name' => 'Maintenance asset']);
        $visibleMaintenance = $this->makeInventoryMaintenance($college, $asset, ['title' => 'Visible service']);
        $deletedMaintenance = $this->makeInventoryMaintenance($college, $asset, ['title' => 'Archived service']);
        $this->withTenant($college, fn () => $deletedMaintenance->delete());

        $this->report($college, $user, 'current-stock')
            ->assertOk()->assertSee('Inactive consumable')->assertSee('Inactive asset')->assertDontSee('Archived consumable');
        $this->report($college, $user, 'low-stock')
            ->assertOk()->assertDontSee('Inactive consumable')->assertDontSee('Archived consumable');
        $this->report($college, $user, 'transactions')
            ->assertOk()->assertSee('Inactive consumable')->assertSee('Archived consumable')->assertSee('(archived)');
        $this->report($college, $user, 'assets')
            ->assertOk()->assertSee('Inactive asset')->assertSee('Maintenance asset');
        $this->report($college, $user, 'maintenance')
            ->assertOk()->assertSee('Visible service')->assertDontSee('Archived service')
            ->assertViewHas('maintenances', fn ($page) => $page->total() === 1);

        $this->assertNotNull($visibleMaintenance);
        $this->assertNotNull($inactiveAsset);
    }

    public function test_reports_are_get_only_and_large_pages_use_eager_loading_and_stable_pagination(): void
    {
        $college = $this->makeCollege('IARPAGE');
        $user = $this->makeUserWithPermissions($college, ['inventory_reports.view']);
        $item = $this->makeInventoryItem($college, ['name' => 'Many transaction item', 'quantity' => '0.00']);

        $movements = [];
        for ($index = 1; $index <= 22; $index++) {
            $movements[] = $this->move($college, $item, $user, InventoryStockMovement::TYPE_STOCK_IN, '1.00', 'in', '2026-09-29');
        }

        $route = Route::getRoutes()->getByName('inventory-reports.index');
        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        foreach (['create', 'store', 'update', 'destroy', 'export'] as $action) {
            $this->assertFalse(Route::has("inventory-reports.{$action}"));
        }

        $this->report($college, $user, 'transactions')
            ->assertOk()
            ->assertViewHas('movements', function ($page) use ($movements): bool {
                $this->assertSame(22, $page->total());
                $this->assertCount(20, $page->items());
                $this->assertSame($movements[21]->id, $page->items()[0]->id);
                $this->assertSame($movements[2]->id, $page->items()[19]->id);

                return true;
            });
        $this->report($college, $user, 'transactions', ['page' => 2])
            ->assertOk()
            ->assertViewHas('movements', function ($page) use ($movements): bool {
                $this->assertSame(22, $page->total());
                $this->assertCount(2, $page->items());
                $this->assertSame($movements[1]->id, $page->items()[0]->id);
                $this->assertSame($movements[0]->id, $page->items()[1]->id);

                return true;
            });

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $page = $this->withTenant($college, fn () => app(InventoryReportService::class)->transactions([]));
        $this->assertLessThanOrEqual(10, count($queries), 'Transaction page should use bounded eager-loaded queries, not one query per row.');
        $first = $page->items()[0];
        $this->assertTrue($first->relationLoaded('item'));
        $this->assertTrue($first->item->relationLoaded('category'));
        $this->assertTrue($first->relationLoaded('purchaseOrder'));
        $this->assertTrue($first->relationLoaded('creator'));

        $before = [
            DB::table('inventory_items')->where('college_id', $college->id)->count(),
            DB::table('inventory_stock_movements')->where('college_id', $college->id)->count(),
            DB::table('inventory_purchase_orders')->where('college_id', $college->id)->count(),
            DB::table('inventory_issues')->where('college_id', $college->id)->count(),
            DB::table('inventory_assignments')->where('college_id', $college->id)->count(),
            DB::table('inventory_maintenances')->where('college_id', $college->id)->count(),
        ];

        $writes = [];
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        foreach (array_keys(self::EXPECTED_REPORTS) as $report) {
            $this->report($college, $user, $report)->assertOk();
        }

        $after = [
            DB::table('inventory_items')->where('college_id', $college->id)->count(),
            DB::table('inventory_stock_movements')->where('college_id', $college->id)->count(),
            DB::table('inventory_purchase_orders')->where('college_id', $college->id)->count(),
            DB::table('inventory_issues')->where('college_id', $college->id)->count(),
            DB::table('inventory_assignments')->where('college_id', $college->id)->count(),
            DB::table('inventory_maintenances')->where('college_id', $college->id)->count(),
        ];
        $this->assertSame([], $writes, 'All Inventory / Asset Reports routes must remain read-only.');
        $this->assertSame($before, $after, 'GET reports must not change Inventory data.');
        $this->post(route('inventory-reports.index'))->assertStatus(405);
    }
}
