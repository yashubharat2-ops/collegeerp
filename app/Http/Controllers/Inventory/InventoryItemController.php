<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryItemService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryItemRequest;
use App\Http\Requests\Inventory\UpdateInventoryItemRequest;
use App\Models\InventoryItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Items / Assets (Inventory / Asset Management).
 *
 * One screen for consumables and assets. Validation (including the
 * tenant-safe category check) lives in the Form Requests; uniqueness and the
 * service-level category guard live in InventoryItemService. college_id
 * always comes from the tenant context — never from request data.
 */
class InventoryItemController extends Controller
{
    public function __construct(private readonly InventoryItemService $items)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', InventoryItem::class);

        $query = InventoryItem::query()
            ->with('category:id,name,code')
            // Deterministic pagination order.
            ->orderBy('name')
            ->orderBy('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%");
            });
        }

        if (in_array($request->input('status'), InventoryItem::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        if (in_array($request->input('item_type'), InventoryItem::TYPES, true)) {
            $query->where('item_type', $request->input('item_type'));
        }

        // The category filter goes through the tenant-scoped query, so a
        // foreign id simply yields an empty page.
        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }

        return view('inventory_items.index', [
            'items' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'statuses' => InventoryItem::STATUSES,
            'itemType' => $request->input('item_type'),
            'types' => InventoryItem::TYPES,
            'categories' => InventoryFormOptions::categories(),
            'filters' => [
                'category_id' => $request->input('category_id'),
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', InventoryItem::class);

        return view('inventory_items.create', [
            'statuses' => InventoryItem::STATUSES,
            'types' => InventoryItem::TYPES,
            'categories' => InventoryFormOptions::categories(),
        ]);
    }

    public function store(StoreInventoryItemRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $item = $this->items->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('inventory-items.index')
            ->with('success', "Item \"{$item->name}\" created.");
    }

    public function edit(string $inventory_item): View
    {
        $item = $this->findScoped($inventory_item);
        $this->authorize('update', $item);

        return view('inventory_items.edit', [
            'item' => $item->load('category:id,name,code,status'),
            'statuses' => InventoryItem::STATUSES,
            'types' => InventoryItem::TYPES,
            'categories' => InventoryFormOptions::categories(),
        ]);
    }

    public function update(UpdateInventoryItemRequest $request, string $inventory_item): RedirectResponse
    {
        $item = $this->findScoped($inventory_item);

        $item = $this->items->update($item, $request->validated(), $request->user());

        return redirect()
            ->route('inventory-items.index')
            ->with('success', "Item \"{$item->name}\" updated.");
    }

    public function destroy(string $inventory_item): RedirectResponse
    {
        $item = $this->findScoped($inventory_item);
        $this->authorize('delete', $item);

        $name = $item->name;
        $this->items->delete($item, request()->user());

        return redirect()
            ->route('inventory-items.index')
            ->with('success', "Item \"{$name}\" deleted.");
    }

    /**
     * Resolve the item INSIDE the controller, under the active tenant.
     */
    private function findScoped(string $id): InventoryItem
    {
        return InventoryItem::query()->findOrFail($id);
    }
}
