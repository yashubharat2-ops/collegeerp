<?php

namespace App\Http\Controllers;

use App\Domain\Library\Services\AuthorService;
use App\Http\Requests\Author\StoreAuthorRequest;
use App\Http\Requests\Author\UpdateAuthorRequest;
use App\Models\Author;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Authors (Library Management → Authors / Publishers).
 *
 * Thin controller: validation lives in the Form Requests, the duplicate-name,
 * in-use and tenant rules live in AuthorService, and college_id always comes
 * from the tenant context — never from request data.
 */
class AuthorController extends Controller
{
    public function __construct(private readonly AuthorService $authors)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Author::class);

        $query = Author::query()
            ->withCount('books')
            // Deterministic pagination order.
            ->orderBy('name')
            ->orderBy('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        if (in_array($request->input('status'), Author::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('authors.index', [
            'authors' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'statuses' => Author::STATUSES,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Author::class);

        return view('authors.create', ['statuses' => Author::STATUSES]);
    }

    public function store(StoreAuthorRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $author = $this->authors->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('authors.index')
            ->with('success', "Author \"{$author->name}\" created.");
    }

    public function edit(string $author): View
    {
        $model = $this->findScoped($author);
        $this->authorize('update', $model);

        return view('authors.edit', [
            'author' => $model->loadCount('books'),
            'statuses' => Author::STATUSES,
        ]);
    }

    public function update(UpdateAuthorRequest $request, string $author): RedirectResponse
    {
        $model = $this->findScoped($author);

        $model = $this->authors->update($model, $request->validated(), $request->user());

        return redirect()
            ->route('authors.index')
            ->with('success', "Author \"{$model->name}\" updated.");
    }

    public function destroy(string $author): RedirectResponse
    {
        $model = $this->findScoped($author);
        $this->authorize('delete', $model);

        $name = $model->name;
        $this->authors->delete($model, request()->user());

        return redirect()
            ->route('authors.index')
            ->with('success', "Author \"{$name}\" deleted.");
    }

    /**
     * Resolve the author INSIDE the controller, under the active tenant: the
     * project never relies on implicit route model binding for tenant-scoped
     * models (SubstituteBindings runs before the tenant middleware).
     */
    private function findScoped(string $id): Author
    {
        return Author::query()->findOrFail($id);
    }
}
