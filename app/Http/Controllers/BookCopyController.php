<?php

namespace App\Http\Controllers;

use App\Domain\Library\Services\BookCopyService;
use App\Domain\Library\Support\LibraryFormOptions;
use App\Http\Requests\BookCopy\StoreBookCopyRequest;
use App\Http\Requests\BookCopy\UpdateBookCopyRequest;
use App\Models\BookCopy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Book Copies (Library Management) — physical items of an existing book.
 *
 * Thin controller. college_id always comes from the tenant context.
 */
class BookCopyController extends Controller
{
    public function __construct(private readonly BookCopyService $copies)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', BookCopy::class);

        $query = BookCopy::query()
            ->with('book:id,title,code')
            ->orderBy('accession_number')
            ->orderBy('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $needle = '%'.$search.'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('accession_number', 'like', $needle)
                    ->orWhere('barcode', 'like', $needle)
                    ->orWhere('location', 'like', $needle)
                    ->orWhereHas('book', fn ($book) => $book->where('title', 'like', $needle)->orWhere('code', 'like', $needle));
            });
        }

        if (in_array($request->input('status'), BookCopy::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        if (in_array($request->input('condition'), BookCopy::CONDITIONS, true)) {
            $query->where('condition', $request->input('condition'));
        }

        if ($request->filled('book_id')) {
            $query->where('book_id', (int) $request->input('book_id'));
        }

        return view('book_copies.index', [
            'copies' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'condition' => $request->input('condition'),
            'statuses' => BookCopy::STATUSES,
            'conditions' => BookCopy::CONDITIONS,
            'books' => LibraryFormOptions::books(),
            'filters' => ['book_id' => $request->input('book_id')],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', BookCopy::class);

        return view('book_copies.create', [
            'books' => LibraryFormOptions::books(),
            'conditions' => BookCopy::CONDITIONS,
            'statuses' => BookCopy::MANUAL_STATUSES,
            'nextCopyNumbers' => LibraryFormOptions::nextCopyNumbers(),
            'selectedBookId' => $request->input('book_id'),
        ]);
    }

    public function store(StoreBookCopyRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();
        $copy = $this->copies->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('book-copies.show', $copy)
            ->with('success', "Copy {$copy->accession_number} created.");
    }

    public function show(string $book_copy): View
    {
        $copy = $this->findScoped($book_copy);
        $this->authorize('view', $copy);

        $copy->load([
            'book:id,title,code,isbn',
            'creator:id,name',
            'updater:id,name',
            'transactions.libraryMember.studentEnrollment.student',
        ]);

        return view('book_copies.show', ['copy' => $copy]);
    }

    public function edit(string $book_copy): View
    {
        $copy = $this->findScoped($book_copy);
        $this->authorize('update', $copy);

        return view('book_copies.edit', [
            'copy' => $copy->load('book:id,title,code'),
            'conditions' => BookCopy::CONDITIONS,
            'statuses' => BookCopy::MANUAL_STATUSES,
        ]);
    }

    public function update(UpdateBookCopyRequest $request, string $book_copy): RedirectResponse
    {
        $copy = $this->findScoped($book_copy);
        $copy = $this->copies->update($copy, $request->validated(), $request->user());

        return redirect()
            ->route('book-copies.show', $copy)
            ->with('success', "Copy {$copy->accession_number} updated.");
    }

    public function destroy(string $book_copy): RedirectResponse
    {
        $copy = $this->findScoped($book_copy);
        $this->authorize('delete', $copy);

        $accession = $copy->accession_number;
        $this->copies->delete($copy, request()->user());

        return redirect()
            ->route('book-copies.index')
            ->with('success', "Copy {$accession} deleted.");
    }

    private function findScoped(string $id): BookCopy
    {
        return BookCopy::query()->findOrFail($id);
    }
}
