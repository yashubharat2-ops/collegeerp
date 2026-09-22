@extends('layouts.app')

@section('title', 'Book Categories')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Book Categories</h2>
            <p class="panel-subtitle">How the library classifies its titles for the active college (Reference, Fiction, Journals, …). Each book belongs to exactly one category.</p>
        </div>
        @can('create', App\Models\BookCategory::class)
            <a class="button" href="{{ route('book-categories.create') }}">+ Add book category</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-3" method="GET" action="{{ route('book-categories.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Name or code">
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
                    <th class="py-2">Name</th>
                    <th>Code</th>
                    <th>Description</th>
                    <th>Books</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($categories as $category)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $category->name }}</td>
                        <td><span class="font-mono text-xs">{{ $category->code }}</span></td>
                        <td class="max-w-md truncate text-slate-600">{{ $category->description ?? '—' }}</td>
                        <td>
                            @if($category->books_count > 0 && auth()->user()?->can('viewAny', App\Models\Book::class))
                                <a class="text-indigo-600 hover:underline" href="{{ route('books.index', ['book_category_id' => $category->id]) }}">{{ $category->books_count }}</a>
                            @else
                                {{ $category->books_count }}
                            @endif
                        </td>
                        <td>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $category->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($category->status) }}</span>
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('update', $category)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('book-categories.edit', $category) }}">Edit</a>
                                @endcan
                                @can('delete', $category)
                                    <form method="POST" action="{{ route('book-categories.destroy', $category) }}" onsubmit="return confirm('Delete the book category &quot;{{ $category->name }}&quot;?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="6">No book categories configured yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $categories->links() }}</div>
</div>
@endsection
