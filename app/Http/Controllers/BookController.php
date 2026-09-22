<?php

namespace App\Http\Controllers;

use App\Domain\Library\Services\BookService;
use App\Domain\Library\Support\LibraryFormOptions;
use App\Http\Requests\Book\StoreBookRequest;
use App\Http\Requests\Book\UpdateBookRequest;
use App\Models\Book;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Books (Library Management) — the bibliographic master.
 *
 * Thin controller: validation (including contextual FK checks) lives in the
 * Form Requests, the uniqueness / tenant / author-link rules live in
 * BookService, and college_id always comes from the tenant context — never
 * from request data. Physical copies are a Phase 2 concern.
 */
class BookController extends Controller
{
    public function __construct(private readonly BookService $books)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Book::class);

        $query = Book::query()
            ->with(['category:id,name,code', 'publisher:id,name', 'authors:id,name'])
            // Deterministic pagination order.
            ->orderBy('title')
            ->orderBy('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('isbn', 'like', '%'.preg_replace('/[\s\-]+/', '', $search).'%')
                    ->orWhereHas('authors', fn ($a) => $a->where('name', 'like', "%{$search}%"));
            });
        }

        if (in_array($request->input('status'), Book::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        // FK filters go through the tenant-scoped query, so a foreign id simply
        // yields an empty page.
        if ($request->filled('book_category_id')) {
            $query->where('book_category_id', (int) $request->input('book_category_id'));
        }

        if ($request->filled('publisher_id')) {
            $query->where('publisher_id', (int) $request->input('publisher_id'));
        }

        if ($request->filled('author_id')) {
            $authorId = (int) $request->input('author_id');
            $query->whereHas('authors', fn ($a) => $a->where('authors.id', $authorId));
        }

        return view('books.index', [
            'books' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'statuses' => Book::STATUSES,
            'categories' => LibraryFormOptions::categories(),
            'authors' => LibraryFormOptions::authors(),
            'publishers' => LibraryFormOptions::publishers(),
            'filters' => [
                'book_category_id' => $request->input('book_category_id'),
                'publisher_id' => $request->input('publisher_id'),
                'author_id' => $request->input('author_id'),
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Book::class);

        return view('books.create', LibraryFormOptions::forBookForm());
    }

    public function store(StoreBookRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $book = $this->books->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('books.show', $book)
            ->with('success', "Book \"{$book->title}\" created.");
    }

    public function show(string $book): View
    {
        $model = $this->findScoped($book);
        $this->authorize('view', $model);

        return view('books.show', [
            'book' => $model->load(['category', 'publisher', 'authors', 'creator', 'updater']),
        ]);
    }

    public function edit(string $book): View
    {
        $model = $this->findScoped($book);
        $this->authorize('update', $model);

        return view('books.edit', ['book' => $model->load('authors')] + LibraryFormOptions::forBookForm());
    }

    public function update(UpdateBookRequest $request, string $book): RedirectResponse
    {
        $model = $this->findScoped($book);

        $model = $this->books->update($model, $request->validated(), $request->user());

        return redirect()
            ->route('books.show', $model)
            ->with('success', "Book \"{$model->title}\" updated.");
    }

    public function destroy(string $book): RedirectResponse
    {
        $model = $this->findScoped($book);
        $this->authorize('delete', $model);

        $title = $model->title;
        $this->books->delete($model, request()->user());

        return redirect()
            ->route('books.index')
            ->with('success', "Book \"{$title}\" deleted.");
    }

    /**
     * Resolve the book INSIDE the controller, under the active tenant: the
     * project never relies on implicit route model binding for tenant-scoped
     * models (SubstituteBindings runs before the tenant middleware).
     */
    private function findScoped(string $id): Book
    {
        return Book::query()->findOrFail($id);
    }
}
