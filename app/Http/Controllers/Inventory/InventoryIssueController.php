<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryIssueService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryIssueRequest;
use App\Models\InventoryIssue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Item Issue / Allocation (Inventory / Asset Management, Phase 3).
 *
 * Consumable stock leaves the college here: each recorded issue reduces the
 * on-hand quantity through the existing Phase 2 stock ledger (a `stock_out`
 * movement that carries the issue number as its reference) and keeps the
 * allocation behind it — recipient, purpose, date. Issues are append-only:
 * there is no update or delete route, and stock coming back in is a new
 * incoming movement.
 *
 * Reuses InventoryStockService for every stock change; no duplicate stock
 * logic. Tenant isolation via CollegeScope + composite foreign key.
 */
class InventoryIssueController extends Controller
{
    public function __construct(private readonly InventoryIssueService $issues)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', InventoryIssue::class);

        $query = InventoryIssue::query()
            ->with(['item:id,name,code,unit', 'recipient', 'creator:id,name'])
            ->orderByDesc('movement_date')
            ->orderByDesc('id');

        if ($request->filled('item_id')) {
            $query->where('item_id', (int) $request->input('item_id'));
        }

        if (in_array($request->input('issued_to_type'), InventoryIssue::RECIPIENT_TYPES, true)) {
            $query->where('issued_to_type', $request->input('issued_to_type'));
        }

        $from = trim((string) $request->input('from'));
        $to = trim((string) $request->input('to'));

        if ($from !== '') {
            $query->whereDate('movement_date', '>=', $from);
        }

        if ($to !== '') {
            $query->whereDate('movement_date', '<=', $to);
        }

        return view('inventory_issues.index', [
            'issues' => $query->paginate(20)->withQueryString(),
            'items' => InventoryFormOptions::consumables(),
            'recipientTypes' => InventoryIssue::RECIPIENT_TYPES,
            'filters' => [
                'item_id' => $request->input('item_id'),
                'issued_to_type' => $request->input('issued_to_type'),
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', InventoryIssue::class);

        return view('inventory_issues.create', [
            'items' => InventoryFormOptions::consumables(),
            'students' => InventoryFormOptions::students(),
            'faculties' => InventoryFormOptions::faculties(),
            'selectedItem' => $request->filled('item_id') ? (int) $request->input('item_id') : null,
        ]);
    }

    public function store(StoreInventoryIssueRequest $request): RedirectResponse
    {
        $issue = $this->issues->create(
            app(\App\Support\Tenancy\TenantContext::class)->require(),
            $request->validated(),
            $request->user(),
        );

        return redirect()
            ->route('inventory-issues.index', ['item_id' => $issue->item_id])
            ->with('success', "{$issue->number}: {$issue->quantity} {$issue->item?->unit} of \"{$issue->item?->name}\" issued to {$issue->recipientName()}. On hand is now {$issue->item->fresh()->quantity} {$issue->item->unit}.");
    }
}
