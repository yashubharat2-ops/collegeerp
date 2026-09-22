<?php

namespace App\Http\Controllers;

use App\Domain\Library\Services\BookCategoryService;
use App\Http\Requests\BookCategory\StoreBookCategoryRequest;
use App\Http\Requests\BookCategory\UpdateBookCategoryRequest;
use App\Models\BookCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Book Categories (Library Management).
 *
 * Thin controller: validation lives in the Form Requests, the duplicate-code,
 * in-use and tenant rules live in BookCategoryService, and college_id always
 * comes from the tenant context — never from request data.
 */
class BookCategoryController extends Controller
{
    public function __construct(private readonly BookCategoryService $categories)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', BookCategory::class);

        $query = BookCategory::query()
            ->withCount('books')
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

        if (in_array($request->input('status'), BookCategory::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('book_categories.index', [
            'categories' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'statuses' => BookCategory::STATUSES,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', BookCategory::class);

        return view('book_categories.create', ['statuses' => BookCategory::STATUSES]);
    }

    public function store(StoreBookCategoryRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $category = $this->categories->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('book-categories.index')
            ->with('success', "Book category \"{$category->name}\" created.");
    }

    public function edit(string $book_category): View
    {
        $category = $this->findScoped($book_category);
        $this->authorize('update', $category);

        return view('book_categories.edit', [
            'category' => $category->loadCount('books'),
            'statuses' => BookCategory::STATUSES,
        ]);
    }

    public function update(UpdateBookCategoryRequest $request, string $book_category): RedirectResponse
    {
        $category = $this->findScoped($book_category);

        $category = $this->categories->update($category, $request->validated(), $request->user());

        return redirect()
            ->route('book-categories.index')
            ->with('success', "Book category \"{$category->name}\" updated.");
    }

    public function destroy(string $book_category): RedirectResponse
    {
        $category = $this->findScoped($book_category);
        $this->authorize('delete', $category);

        $name = $category->name;
        $this->categories->delete($category, request()->user());

        return redirect()
            ->route('book-categories.index')
            ->with('success', "Book category \"{$name}\" deleted.");
    }

    /**
     * Resolve the category INSIDE the controller, under the active tenant: the
     * project never relies on implicit route model binding for tenant-scoped
     * models (SubstituteBindings runs before the tenant middleware).
     */
    private function findScoped(string $id): BookCategory
    {
        return BookCategory::query()->findOrFail($id);
    }
}
