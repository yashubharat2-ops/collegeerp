<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\FeeCategoryService;
use App\Domain\Finance\Support\FeeFormOptions;
use App\Http\Requests\FeeCategory\StoreFeeCategoryRequest;
use App\Http\Requests\FeeCategory\UpdateFeeCategoryRequest;
use App\Models\FeeCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fee Categories (Finance / Fees).
 *
 * Thin controller: validation lives in the Form Requests, the duplicate-code and
 * tenant rules live in FeeCategoryService, and college_id always comes from the
 * tenant context — never from request data.
 */
class FeeCategoryController extends Controller
{
    public function __construct(private readonly FeeCategoryService $categories)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', FeeCategory::class);

        $query = FeeCategory::query()
            ->withCount('feeStructureItems')
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

        if (in_array($request->input('status'), FeeCategory::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('fee_categories.index', [
            'categories' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'statuses' => FeeCategory::STATUSES,
            'feeCategories' => FeeFormOptions::feeCategories(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', FeeCategory::class);

        return view('fee_categories.create', ['statuses' => FeeCategory::STATUSES]);
    }

    public function store(StoreFeeCategoryRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $category = $this->categories->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('fee-categories.index')
            ->with('success', "Fee category \"{$category->name}\" created.");
    }

    public function edit(string $fee_category): View
    {
        $category = $this->findScoped($fee_category);
        $this->authorize('update', $category);

        return view('fee_categories.edit', [
            'category' => $category,
            'statuses' => FeeCategory::STATUSES,
        ]);
    }

    public function update(UpdateFeeCategoryRequest $request, string $fee_category): RedirectResponse
    {
        $category = $this->findScoped($fee_category);

        $category = $this->categories->update($category, $request->validated(), $request->user());

        return redirect()
            ->route('fee-categories.index')
            ->with('success', "Fee category \"{$category->name}\" updated.");
    }

    public function destroy(string $fee_category): RedirectResponse
    {
        $category = $this->findScoped($fee_category);
        $this->authorize('delete', $category);

        $name = $category->name;
        $this->categories->delete($category, request()->user());

        return redirect()
            ->route('fee-categories.index')
            ->with('success', "Fee category \"{$name}\" deleted.");
    }

    /**
     * Resolve the category INSIDE the controller, under the active tenant: the
     * project never relies on implicit route model binding for tenant-scoped
     * models (SubstituteBindings runs before the tenant middleware).
     */
    private function findScoped(string $id): FeeCategory
    {
        return FeeCategory::query()->findOrFail($id);
    }
}
