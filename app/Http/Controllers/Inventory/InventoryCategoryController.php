<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryCategoryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryCategoryRequest;
use App\Http\Requests\Inventory\UpdateInventoryCategoryRequest;
use App\Models\InventoryCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Item Categories (Inventory / Asset Management).
 *
 * Thin controller: validation lives in the Form Requests, the duplicate-code,
 * in-use and tenant rules live in InventoryCategoryService, and college_id
 * always comes from the tenant context — never from request data.
 */
class InventoryCategoryController extends Controller
{
    public function __construct(private readonly InventoryCategoryService $categories)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', InventoryCategory::class);

        $query = InventoryCategory::query()
            ->withCount('items')
            // Deterministic pagination order.
            ->orderBy('name')
            ->orderBy('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if (in_array($request->input('status'), InventoryCategory::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('inventory_categories.index', [
            'categories' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'statuses' => InventoryCategory::STATUSES,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', InventoryCategory::class);

        return view('inventory_categories.create', ['statuses' => InventoryCategory::STATUSES]);
    }

    public function store(StoreInventoryCategoryRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $category = $this->categories->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('inventory-categories.index')
            ->with('success', "Item category \"{$category->name}\" created.");
    }

    public function edit(string $inventory_category): View
    {
        $category = $this->findScoped($inventory_category);
        $this->authorize('update', $category);

        return view('inventory_categories.edit', [
            'category' => $category->loadCount('items'),
            'statuses' => InventoryCategory::STATUSES,
        ]);
    }

    public function update(UpdateInventoryCategoryRequest $request, string $inventory_category): RedirectResponse
    {
        $category = $this->findScoped($inventory_category);

        $category = $this->categories->update($category, $request->validated(), $request->user());

        return redirect()
            ->route('inventory-categories.index')
            ->with('success', "Item category \"{$category->name}\" updated.");
    }

    public function destroy(string $inventory_category): RedirectResponse
    {
        $category = $this->findScoped($inventory_category);
        $this->authorize('delete', $category);

        $name = $category->name;
        $this->categories->delete($category, request()->user());

        return redirect()
            ->route('inventory-categories.index')
            ->with('success', "Item category \"{$name}\" deleted.");
    }

    /**
     * Resolve the category INSIDE the controller, under the active tenant: the
     * project never relies on implicit route model binding for tenant-scoped
     * models (SubstituteBindings runs before the tenant middleware).
     */
    private function findScoped(string $id): InventoryCategory
    {
        return InventoryCategory::query()->findOrFail($id);
    }
}
