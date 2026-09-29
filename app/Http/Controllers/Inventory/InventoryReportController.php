<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryReportService;
use App\Domain\Inventory\Services\InventoryStockBalanceService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Models\InventoryAssignment;
use App\Models\InventoryIssue;
use App\Models\InventoryItem;
use App\Models\InventoryMaintenance;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryStockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Read-only Inventory / Asset Reports over the existing module records. */
class InventoryReportController extends Controller
{
    /** The fixed report workflow and required navigation order. */
    public const REPORTS = [
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

    /** Only filters supported by each report's existing source tables. */
    public const FILTERS = [
        'current-stock' => ['category_id', 'item_id', 'item_type', 'item_status', 'search'],
        'low-stock' => ['category_id', 'item_id', 'threshold', 'search'],
        'transactions' => ['category_id', 'item_id', 'purchase_order_id', 'vendor_id', 'transaction_type', 'direction', 'from', 'to'],
        'adjustments' => ['category_id', 'item_id', 'adjustment_type', 'direction', 'from', 'to'],
        'purchases' => ['vendor_id', 'purchase_order_id', 'item_id', 'purchase_status', 'from', 'to'],
        'issues' => ['category_id', 'item_id', 'issued_to_type', 'from', 'to'],
        'assets' => ['category_id', 'item_id', 'asset_status', 'custody', 'search'],
        'assignments' => ['category_id', 'item_id', 'assignment_status', 'assigned_to_type', 'from', 'to'],
        'returns' => ['category_id', 'item_id', 'from', 'to'],
        'maintenance' => ['category_id', 'item_id', 'vendor_id', 'maintenance_status', 'maintenance_type', 'from', 'to'],
        // Category roll-up and its existing threshold are retained in Summary.
        'summary' => ['category_id', 'threshold'],
    ];

    private const ID_FILTERS = ['category_id', 'item_id', 'vendor_id', 'purchase_order_id'];

    private const ENUM_FILTERS = [
        'item_type' => InventoryItem::TYPES,
        'item_status' => InventoryItem::STATUSES,
        'transaction_type' => InventoryStockMovement::TYPES,
        'direction' => InventoryStockMovement::DIRECTIONS,
        'adjustment_type' => [InventoryStockMovement::TYPE_ADJUSTMENT, InventoryStockMovement::TYPE_STOCK_OUT],
        'purchase_status' => InventoryPurchaseOrder::STATUSES,
        'issued_to_type' => InventoryIssue::RECIPIENT_TYPES,
        'asset_status' => InventoryItem::STATUSES,
        'custody' => ['assigned', 'unassigned'],
        'assignment_status' => InventoryAssignment::STATUSES,
        'assigned_to_type' => InventoryAssignment::ASSIGNEE_TYPES,
        'maintenance_status' => InventoryMaintenance::STATUSES,
        'maintenance_type' => InventoryMaintenance::TYPES,
    ];

    public function index(Request $request, InventoryReportService $reports): View
    {
        $this->authorize('viewReports', InventoryItem::class);

        $requestedReport = $request->validate([
            'report' => ['nullable', 'string', Rule::in(array_keys(self::REPORTS))],
        ])['report'] ?? 'summary';
        $filters = $this->filters($request, $requestedReport);

        $data = match ($requestedReport) {
            'current-stock' => ['items' => $reports->currentStock($filters)],
            'low-stock' => ['items' => $reports->lowStock($filters)],
            'transactions' => ['movements' => $reports->transactions($filters)],
            'adjustments' => ['movements' => $reports->adjustments($filters)],
            'purchases' => [
                'orders' => $reports->purchaseOrderLines($filters),
                'receipts' => $reports->goodsReceipts($filters),
            ],
            'issues' => ['issues' => $reports->issues($filters)],
            'assets' => ['assets' => $reports->assets($filters)],
            'assignments' => ['assignments' => $reports->assignments($filters)],
            'returns' => ['returns' => $reports->returns($filters)],
            'maintenance' => ['maintenances' => $reports->maintenance($filters)],
            'summary' => [
                'summary' => $reports->summary($filters['threshold']),
                'rows' => $reports->categories($filters['threshold'], $filters['category_id']),
            ],
        };

        return view('inventory_reports.index', array_merge($data, [
            'report' => $requestedReport,
            'reports' => self::REPORTS,
            'visible' => self::FILTERS[$requestedReport],
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($requestedReport, $filters),
        ]));
    }

    /** Validate only controls displayed for the selected report. */
    private function filters(Request $request, string $report): array
    {
        $visible = self::FILTERS[$report];
        $rules = [];

        foreach (self::ID_FILTERS as $key) {
            if (in_array($key, $visible, true)) {
                $rules[$key] = ['nullable', 'integer', 'min:1'];
            }
        }

        foreach (self::ENUM_FILTERS as $key => $values) {
            if (in_array($key, $visible, true)) {
                $rules[$key] = ['nullable', Rule::in($values)];
            }
        }

        if (in_array('search', $visible, true)) {
            $rules['search'] = ['nullable', 'string', 'max:100'];
        }
        if (in_array('threshold', $visible, true)) {
            $rules['threshold'] = ['nullable', 'numeric', 'min:0', 'max:9999999999.99'];
        }
        if (in_array('from', $visible, true)) {
            $rules['from'] = ['nullable', 'date_format:Y-m-d'];
            $rules['to'] = [
                'nullable',
                'date_format:Y-m-d',
                ...($request->filled('from') ? ['after_or_equal:from'] : []),
            ];
        }

        $validated = $request->validate($rules);
        $keys = array_unique([
            ...self::ID_FILTERS,
            ...array_keys(self::ENUM_FILTERS),
            'search', 'threshold', 'from', 'to',
        ]);
        $filters = array_fill_keys($keys, null);

        foreach ($visible as $key) {
            $filters[$key] = $validated[$key] ?? null;

            if (in_array($key, self::ID_FILTERS, true) && $filters[$key] !== null) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        if (in_array('threshold', $visible, true)) {
            $filters['threshold'] = number_format(
                (float) ($filters['threshold'] ?? InventoryStockBalanceService::DEFAULT_LOW_STOCK_THRESHOLD),
                2,
                '.',
                ''
            );
        }
        if (in_array('search', $visible, true)) {
            $filters['search'] = trim((string) ($filters['search'] ?? ''));
        }

        return $filters;
    }

    /** Tenant-scoped dropdown data, loaded only for visible filters. */
    private function filterOptions(string $report, array $filters): array
    {
        $visible = self::FILTERS[$report];
        $has = fn (string $key): bool => in_array($key, $visible, true);
        $options = [];

        if ($has('category_id')) {
            $options['categories'] = InventoryFormOptions::categories();
        }
        if ($has('item_id')) {
            $items = InventoryItem::query();

            if (in_array($report, ['assets', 'assignments', 'returns', 'maintenance'], true)) {
                $items->where('item_type', InventoryItem::TYPE_ASSET);
            } elseif ($report === 'issues' || $report === 'low-stock') {
                $items->where('item_type', InventoryItem::TYPE_CONSUMABLE);
            }
            if ($filters['item_type'] ?? null) {
                $items->where('item_type', $filters['item_type']);
            }

            $options['items'] = $items
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'category_id', 'name', 'code', 'unit', 'item_type', 'serial_number', 'status']);
        }
        if ($has('vendor_id')) {
            $options['vendors'] = InventoryFormOptions::vendors();
        }
        if ($has('item_type')) {
            $options['itemTypes'] = InventoryItem::TYPES;
        }
        if ($has('item_status')) {
            $options['itemStatuses'] = InventoryItem::STATUSES;
        }
        if ($has('transaction_type')) {
            $options['transactionTypes'] = InventoryStockMovement::TYPES;
        }
        if ($has('adjustment_type')) {
            $options['adjustmentTypes'] = [InventoryStockMovement::TYPE_ADJUSTMENT, InventoryStockMovement::TYPE_STOCK_OUT];
        }
        if ($has('direction')) {
            $options['directions'] = InventoryStockMovement::DIRECTIONS;
        }
        if ($has('purchase_status')) {
            $options['purchaseStatuses'] = InventoryPurchaseOrder::STATUSES;
        }
        if ($has('issued_to_type')) {
            $options['recipientTypes'] = InventoryIssue::RECIPIENT_TYPES;
        }
        if ($has('asset_status')) {
            $options['assetStatuses'] = InventoryItem::STATUSES;
            $options['custodyOptions'] = ['assigned', 'unassigned'];
        }
        if ($has('assignment_status')) {
            $options['assignmentStatuses'] = InventoryAssignment::STATUSES;
            $options['assigneeTypes'] = InventoryAssignment::ASSIGNEE_TYPES;
        }
        if ($has('maintenance_status')) {
            $options['maintenanceStatuses'] = InventoryMaintenance::STATUSES;
            $options['maintenanceTypes'] = InventoryMaintenance::TYPES;
        }

        return $options;
    }
}
