<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryStockService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryStockMovementRequest;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Stock (Inventory / Asset Management, Phase 2).
 *
 * The ledger of every change of on-hand quantity for the active college, plus
 * the screen that records a manual stock in, stock out or correction.
 * Movements are immutable, so there are no edit or delete routes — a
 * correction is a new movement. college_id always comes from the tenant
 * context, never from request data.
 */
class InventoryStockController extends Controller
{
    public function __construct(private readonly InventoryStockService $stock)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', InventoryStockMovement::class);

        $query = InventoryStockMovement::query()
            ->with('item:id,name,code,unit')
            // Newest movement first; ids break ties within one day.
            ->orderByDesc('movement_date')
            ->orderByDesc('id');

        // The item filter goes through the tenant-scoped query, so a foreign
        // id simply yields an empty page.
        if ($request->filled('item_id')) {
            $query->where('item_id', (int) $request->input('item_id'));
        }

        if (in_array($request->input('type'), InventoryStockMovement::TYPES, true)) {
            $query->where('type', $request->input('type'));
        }

        if (in_array($request->input('direction'), InventoryStockMovement::DIRECTIONS, true)) {
            $query->where('direction', $request->input('direction'));
        }

        $from = trim((string) $request->input('from'));
        $to = trim((string) $request->input('to'));

        if ($from !== '') {
            $query->whereDate('movement_date', '>=', $from);
        }

        if ($to !== '') {
            $query->whereDate('movement_date', '<=', $to);
        }

        return view('inventory_stock.index', [
            'movements' => $query->paginate(20)->withQueryString(),
            'items' => InventoryFormOptions::items(),
            'types' => InventoryStockMovement::TYPES,
            'directions' => InventoryStockMovement::DIRECTIONS,
            'filters' => [
                'item_id' => $request->input('item_id'),
                'type' => $request->input('type'),
                'direction' => $request->input('direction'),
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    /**
     * The recording form is reachable with the ledger permission or with any
     * of the three recording abilities, so a storekeeper who may book stock in
     * is never locked out of the screen that does it.
     */
    public function create(Request $request): View
    {
        abort_unless(
            $request->user()?->can('viewAny', InventoryStockMovement::class) || $this->mayRecord($request),
            403,
        );

        return view('inventory_stock.create', [
            'items' => InventoryFormOptions::items(),
            'types' => InventoryStockMovement::MANUAL_TYPES,
            'directions' => InventoryStockMovement::DIRECTIONS,
            'selectedItem' => $request->filled('item_id') ? (int) $request->input('item_id') : null,
        ]);
    }

    public function store(StoreInventoryStockMovementRequest $request): RedirectResponse
    {
        $item = InventoryItem::query()->findOrFail($request->validated('item_id'));

        $movement = $this->stock->record($item, $request->validated(), $request->user());

        $verb = $movement->isIncoming() ? 'added to' : 'removed from';

        return redirect()
            ->route('inventory-stock.index', ['item_id' => $item->getKey()])
            ->with('success', "{$movement->quantity} {$item->unit} {$verb} \"{$item->name}\". On hand is now {$movement->balance_after} {$item->unit}.");
    }

    private function mayRecord(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        return $user->can('in', InventoryStockMovement::class)
            || $user->can('out', InventoryStockMovement::class)
            || $user->can('adjust', InventoryStockMovement::class);
    }
}
