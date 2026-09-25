<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryPurchaseOrderService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ReceiveInventoryPurchaseOrderRequest;
use App\Http\Requests\Inventory\StoreInventoryPurchaseOrderRequest;
use App\Http\Requests\Inventory\UpdateInventoryPurchaseOrderRequest;
use App\Models\InventoryPurchaseOrder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Purchase Orders (Inventory / Asset Management, Phase 2).
 *
 * Lifecycle actions are separate routes rather than form fields: submitting,
 * receiving and cancelling each have their own ability, so a user can be
 * allowed to raise orders without being allowed to book goods in. Validation
 * lives in the Form Requests; status transitions, line rules and the total
 * live in InventoryPurchaseOrderService. college_id always comes from the
 * tenant context — never from request data.
 */
class InventoryPurchaseOrderController extends Controller
{
    public function __construct(private readonly InventoryPurchaseOrderService $orders)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', InventoryPurchaseOrder::class);

        $query = InventoryPurchaseOrder::query()
            ->with('vendor:id,name,code')
            // Deterministic pagination order: newest order first.
            ->orderByDesc('po_date')
            ->orderByDesc('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('vendor', fn ($vendor) => $vendor->where('name', 'like', "%{$search}%"));
            });
        }

        if (in_array($request->input('status'), InventoryPurchaseOrder::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        // The vendor filter goes through the tenant-scoped query, so a foreign
        // id simply yields an empty page.
        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', (int) $request->input('vendor_id'));
        }

        return view('inventory_purchase_orders.index', [
            'orders' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'statuses' => InventoryPurchaseOrder::STATUSES,
            'vendors' => InventoryFormOptions::vendors(),
            'filters' => [
                'vendor_id' => $request->input('vendor_id'),
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', InventoryPurchaseOrder::class);

        return view('inventory_purchase_orders.create', [
            'vendors' => InventoryFormOptions::vendors(),
            'items' => InventoryFormOptions::items(),
        ]);
    }

    public function store(StoreInventoryPurchaseOrderRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $order = $this->orders->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('inventory-purchase-orders.show', $order)
            ->with('success', "Purchase order \"{$order->number}\" saved as a draft.");
    }

    public function show(string $purchase_order): View
    {
        $order = $this->findScoped($purchase_order);
        $this->authorize('view', $order);

        return view('inventory_purchase_orders.show', [
            'order' => $order->load([
                'vendor:id,name,code,status',
                'lines' => fn ($lines) => $lines->orderBy('id'),
                'lines.item:id,name,code,unit,item_type',
                'movements' => fn ($movements) => $movements->orderBy('id'),
            ]),
        ]);
    }

    public function edit(string $purchase_order): View
    {
        $order = $this->findScoped($purchase_order);
        $this->authorize('update', $order);

        return view('inventory_purchase_orders.edit', [
            'order' => $order->load('lines'),
            'vendors' => InventoryFormOptions::vendors(),
            'items' => InventoryFormOptions::items(),
        ]);
    }

    public function update(UpdateInventoryPurchaseOrderRequest $request, string $purchase_order): RedirectResponse
    {
        $order = $this->findScoped($purchase_order);

        $order = $this->orders->update($order, $request->validated(), $request->user());

        return redirect()
            ->route('inventory-purchase-orders.show', $order)
            ->with('success', "Purchase order \"{$order->number}\" updated.");
    }

    public function destroy(string $purchase_order): RedirectResponse
    {
        $order = $this->findScoped($purchase_order);
        $this->authorize('delete', $order);

        $number = $order->number;
        $this->orders->delete($order, request()->user());

        return redirect()
            ->route('inventory-purchase-orders.index')
            ->with('success', "Purchase order \"{$number}\" deleted.");
    }

    public function submit(string $purchase_order): RedirectResponse
    {
        $order = $this->findScoped($purchase_order);
        $this->authorize('update', $order);

        $order = $this->orders->submit($order, request()->user());

        return redirect()
            ->route('inventory-purchase-orders.show', $order)
            ->with('success', "Purchase order \"{$order->number}\" submitted to the vendor.");
    }

    public function cancel(string $purchase_order): RedirectResponse
    {
        $order = $this->findScoped($purchase_order);
        $this->authorize('update', $order);

        $order = $this->orders->cancel($order, request()->user());

        return redirect()
            ->route('inventory-purchase-orders.show', $order)
            ->with('success', "Purchase order \"{$order->number}\" cancelled.");
    }

    public function receiveForm(string $purchase_order): View
    {
        $order = $this->findScoped($purchase_order);
        $this->authorize('receive', $order);

        return view('inventory_purchase_orders.receive', [
            'order' => $order->load([
                'vendor:id,name,code',
                'lines' => fn ($lines) => $lines->orderBy('id'),
                'lines.item:id,name,code,unit',
            ]),
        ]);
    }

    public function receive(ReceiveInventoryPurchaseOrderRequest $request, string $purchase_order): RedirectResponse
    {
        $order = $this->findScoped($purchase_order);

        $order = $this->orders->receive($order, $request->validated(), $request->user());

        return redirect()
            ->route('inventory-purchase-orders.show', $order)
            ->with('success', "Goods receipt booked against \"{$order->number}\". The order is now {$order->status}.");
    }

    /**
     * Resolve the order INSIDE the controller, under the active tenant.
     */
    private function findScoped(string $id): InventoryPurchaseOrder
    {
        return InventoryPurchaseOrder::query()->findOrFail($id);
    }
}
