<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryVendorService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryVendorRequest;
use App\Http\Requests\Inventory\UpdateInventoryVendorRequest;
use App\Models\InventoryVendor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Vendors (Inventory / Asset Management).
 *
 * Thin controller: validation lives in the Form Requests, the duplicate-code
 * and tenant rules live in InventoryVendorService, and college_id always
 * comes from the tenant context — never from request data.
 */
class InventoryVendorController extends Controller
{
    public function __construct(private readonly InventoryVendorService $vendors)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', InventoryVendor::class);

        $query = InventoryVendor::query()
            // Deterministic pagination order.
            ->orderBy('name')
            ->orderBy('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('gst_number', 'like', "%{$search}%");
            });
        }

        if (in_array($request->input('status'), InventoryVendor::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('inventory_vendors.index', [
            'vendors' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'statuses' => InventoryVendor::STATUSES,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', InventoryVendor::class);

        return view('inventory_vendors.create', ['statuses' => InventoryVendor::STATUSES]);
    }

    public function store(StoreInventoryVendorRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $vendor = $this->vendors->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('inventory-vendors.index')
            ->with('success', "Vendor \"{$vendor->name}\" created.");
    }

    public function edit(string $inventory_vendor): View
    {
        $vendor = $this->findScoped($inventory_vendor);
        $this->authorize('update', $vendor);

        return view('inventory_vendors.edit', [
            'vendor' => $vendor,
            'statuses' => InventoryVendor::STATUSES,
        ]);
    }

    public function update(UpdateInventoryVendorRequest $request, string $inventory_vendor): RedirectResponse
    {
        $vendor = $this->findScoped($inventory_vendor);

        $vendor = $this->vendors->update($vendor, $request->validated(), $request->user());

        return redirect()
            ->route('inventory-vendors.index')
            ->with('success', "Vendor \"{$vendor->name}\" updated.");
    }

    public function destroy(string $inventory_vendor): RedirectResponse
    {
        $vendor = $this->findScoped($inventory_vendor);
        $this->authorize('delete', $vendor);

        $name = $vendor->name;
        $this->vendors->delete($vendor, request()->user());

        return redirect()
            ->route('inventory-vendors.index')
            ->with('success', "Vendor \"{$name}\" deleted.");
    }

    /**
     * Resolve the vendor INSIDE the controller, under the active tenant.
     */
    private function findScoped(string $id): InventoryVendor
    {
        return InventoryVendor::query()->findOrFail($id);
    }
}
