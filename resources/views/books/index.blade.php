@extends('layouts.app')

@section('title', 'Books')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Books</h2>
            <p class="panel-subtitle">The bibliographic master for the active college — one record per title. Physical copies, members and issue / return arrive in later phases.</p>
        </div>
        @can('create', App\Models\Book::class)
            <a class="button" href="{{ route('books.create') }}">+ Add book</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" method="GET" action="{{ route('books.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Title, code, ISBN or author">
        </div>
        <div>
            <label class="label" for="book_category_id">Category</label>
            <select class="input" id="book_category_id" name="book_category_id">
                <option value="">All categories</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected((string) $filters['book_category_id'] === (string) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="author_id">Author</label>
            <select class="input" id="author_id" name="author_id">
                <option value="">All authors</option>
                @foreach($authors as $author)
                    <option value="{{ $author->id }}" @selected((string) $filters['author_id'] === (string) $author->id)>{{ $author->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="publisher_id">Publisher</label>
            <select class="input" id="publisher_id" name="publisher_id">
                <option value="">All publishers</option>
                @foreach($publishers as $publisher)
                    <option value="{{ $publisher->id }}" @selected((string) $filters['publisher_id'] === (string) $publisher->id)>{{ $publisher->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <div class="flex gap-2">
                <select class="input" id="status" name="status">
                    <option value="">All statuses</option>
                    @foreach($statuses as $statusOption)
                        <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ ucfirst($statusOption) }}</option>
                    @endforeach
                </select>
                <button class="button" type="submit">Filter</button>
            </div>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Title</th>
                    <th>Code</th>
                    <th>ISBN</th>
                    <th>Category</th>
                    <th>Authors</th>
                    <th>Publisher</th>
                    <th>Year</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($books as $book)
                    <tr class="border-b">
                        <td class="py-2">
                            <a class="font-medium text-indigo-700 hover:underline" href="{{ route('books.show', $book) }}">{{ $book->title }}</a>
                            @if($book->edition)
                                <div class="text-xs text-slate-500">{{ $book->edition }}</div>
                            @endif
                        </td>
                        <td><span class="font-mono text-xs">{{ $book->code }}</span></td>
                        <td><span class="font-mono text-xs">{{ $book->isbn ?? '—' }}</span></td>
                        <td>{{ $book->category?->name ?? '—' }}</td>
                        <td class="max-w-xs truncate">{{ $book->authors->isNotEmpty() ? $book->authorNames() : '—' }}</td>
                        <td>{{ $book->publisher?->name ?? '—' }}</td>
                        <td>{{ $book->publication_year ?? '—' }}</td>
                        <td>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $book->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($book->status) }}</span>
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('view', $book)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('books.show', $book) }}">View</a>
                                @endcan
                                @can('update', $book)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('books.edit', $book) }}">Edit</a>
                                @endcan
                                @can('delete', $book)
                                    <form method="POST" action="{{ route('books.destroy', $book) }}" onsubmit="return confirm('Delete the book &quot;{{ $book->title }}&quot;?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="9">No books catalogued yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $books->links() }}</div>
</div>
@endsection
