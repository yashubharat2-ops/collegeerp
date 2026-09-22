@extends('layouts.app')

@section('title', 'Library Dashboard')

@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="panel-title">Library Dashboard</h2>
                <p class="panel-subtitle">Live, read-only overview of the active college's catalogue — books, categories, authors and publishers. Figures are computed from the masters themselves; nothing here is stored separately.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('viewAny', App\Models\Book::class)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('books.index') }}">Books</a>
                @endcan
                @can('viewAny', App\Models\BookCategory::class)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('book-categories.index') }}">Categories</a>
                @endcan
                @can('viewAny', App\Models\Author::class)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('authors.index') }}">Authors</a>
                @endcan
                @can('viewAny', App\Models\Publisher::class)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('publishers.index') }}">Publishers</a>
                @endcan
            </div>
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="stat-card">
                <p class="stat-label">Books</p>
                <p class="stat-value">{{ $totals['books'] }}</p>
                <p class="stat-hint">{{ $totals['active_books'] }} active · {{ $totals['inactive_books'] }} inactive</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Book Categories</p>
                <p class="stat-value">{{ $totals['categories'] }}</p>
                <p class="stat-hint">{{ $totals['active_categories'] }} active</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Authors</p>
                <p class="stat-value">{{ $totals['authors'] }}</p>
                <p class="stat-hint">{{ $totals['active_authors'] }} active</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Publishers</p>
                <p class="stat-value">{{ $totals['publishers'] }}</p>
                <p class="stat-hint">{{ $totals['active_publishers'] }} active</p>
            </div>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border p-4"><p class="text-xs text-slate-500">Added this month</p><p class="text-xl font-bold">{{ $totals['books_added_this_month'] }}</p></div>
            <div class="rounded-lg border p-4"><p class="text-xs text-slate-500">Books without ISBN</p><p class="text-xl font-bold">{{ $totals['books_without_isbn'] }}</p></div>
            <div class="rounded-lg border p-4"><p class="text-xs text-slate-500">Languages catalogued</p><p class="text-xl font-bold">{{ $booksPerLanguage->reject(fn ($row) => $row['language'] === 'Not specified')->count() }}</p></div>
            <div class="rounded-lg border p-4"><p class="text-xs text-slate-500">Categories in use</p><p class="text-xl font-bold">{{ $booksPerCategory->where('books_count', '>', 0)->count() }} / {{ $totals['categories'] }}</p></div>
        </div>
    </div>

    @if($totals['books'] === 0 && $totals['categories'] === 0 && $totals['authors'] === 0 && $totals['publishers'] === 0)
        <div class="panel">
            <h3 class="font-semibold">Getting started</h3>
            <p class="mt-2 text-sm text-slate-600">The catalogue for this college is empty. A typical set-up order is: create <strong>Book Categories</strong>, record <strong>Authors / Publishers</strong>, then catalogue <strong>Books</strong>. Physical copies, members and issue / return follow in the next phase.</p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="panel">
            <h3 class="font-semibold">Books per category</h3>
            <p class="panel-subtitle">Every category of the college, including those not yet used.</p>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b text-slate-500"><th class="py-2">Category</th><th>Code</th><th>Status</th><th class="text-right">Books</th></tr></thead>
                    <tbody>
                        @forelse($booksPerCategory as $category)
                            <tr class="border-b">
                                <td class="py-2 font-medium">{{ $category->name }}</td>
                                <td><span class="font-mono text-xs">{{ $category->code }}</span></td>
                                <td><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $category->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($category->status) }}</span></td>
                                <td class="text-right">
                                    @if($category->books_count > 0 && auth()->user()?->can('viewAny', App\Models\Book::class))
                                        <a class="text-indigo-600 hover:underline" href="{{ route('books.index', ['book_category_id' => $category->id]) }}">{{ $category->books_count }}</a>
                                    @else
                                        {{ $category->books_count }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="py-4 text-slate-500" colspan="4">No book categories yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <h3 class="font-semibold">Most-credited authors</h3>
            <p class="panel-subtitle">Authors ranked by the number of titles that credit them.</p>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b text-slate-500"><th class="py-2">Author</th><th>Status</th><th class="text-right">Books</th></tr></thead>
                    <tbody>
                        @forelse($topAuthors as $author)
                            <tr class="border-b">
                                <td class="py-2 font-medium">{{ $author->name }}</td>
                                <td><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $author->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($author->status) }}</span></td>
                                <td class="text-right">
                                    @if($author->books_count > 0 && auth()->user()?->can('viewAny', App\Models\Book::class))
                                        <a class="text-indigo-600 hover:underline" href="{{ route('books.index', ['author_id' => $author->id]) }}">{{ $author->books_count }}</a>
                                    @else
                                        {{ $author->books_count }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="py-4 text-slate-500" colspan="3">No authors yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <h3 class="font-semibold">Publishers by titles</h3>
            <p class="panel-subtitle">Publishers ranked by the number of titles on record.</p>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b text-slate-500"><th class="py-2">Publisher</th><th>Status</th><th class="text-right">Books</th></tr></thead>
                    <tbody>
                        @forelse($topPublishers as $publisher)
                            <tr class="border-b">
                                <td class="py-2 font-medium">{{ $publisher->name }}</td>
                                <td><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $publisher->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($publisher->status) }}</span></td>
                                <td class="text-right">
                                    @if($publisher->books_count > 0 && auth()->user()?->can('viewAny', App\Models\Book::class))
                                        <a class="text-indigo-600 hover:underline" href="{{ route('books.index', ['publisher_id' => $publisher->id]) }}">{{ $publisher->books_count }}</a>
                                    @else
                                        {{ $publisher->books_count }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="py-4 text-slate-500" colspan="3">No publishers yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel">
            <h3 class="font-semibold">Catalogue profile</h3>
            <p class="panel-subtitle">Where the collection sits by language and publication decade.</p>
            <div class="mt-4 grid gap-6 sm:grid-cols-2">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">By language</p>
                    <ul class="mt-2 space-y-1 text-sm">
                        @forelse($booksPerLanguage as $row)
                            <li class="flex justify-between border-b py-1"><span>{{ $row['language'] }}</span><span class="font-semibold">{{ $row['count'] }}</span></li>
                        @empty
                            <li class="text-slate-500">No books yet.</li>
                        @endforelse
                    </ul>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">By publication decade</p>
                    <ul class="mt-2 space-y-1 text-sm">
                        @forelse($booksPerDecade as $row)
                            <li class="flex justify-between border-b py-1"><span>{{ $row['decade'] }}</span><span class="font-semibold">{{ $row['count'] }}</span></li>
                        @empty
                            <li class="text-slate-500">No books yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="panel">
        <h3 class="font-semibold">Recently catalogued</h3>
        <p class="panel-subtitle">The latest titles added to this college's catalogue.</p>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-2">Title</th><th>Code</th><th>Category</th><th>Authors</th><th>Publisher</th><th>Year</th><th>Added</th></tr></thead>
                <tbody>
                    @forelse($recentBooks as $book)
                        <tr class="border-b">
                            <td class="py-2 font-medium">
                                @can('view', $book)
                                    <a class="text-indigo-700 hover:underline" href="{{ route('books.show', $book) }}">{{ $book->title }}</a>
                                @else
                                    {{ $book->title }}
                                @endcan
                            </td>
                            <td><span class="font-mono text-xs">{{ $book->code }}</span></td>
                            <td>{{ $book->category?->name ?? '—' }}</td>
                            <td class="max-w-xs truncate">{{ $book->authors->isNotEmpty() ? $book->authorNames() : '—' }}</td>
                            <td>{{ $book->publisher?->name ?? '—' }}</td>
                            <td>{{ $book->publication_year ?? '—' }}</td>
                            <td class="text-slate-500">{{ $book->created_at?->format('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-4 text-slate-500" colspan="7">No books catalogued yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
