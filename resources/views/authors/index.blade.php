@extends('layouts.app')

@section('title', 'Authors')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Authors</h2>
            <p class="panel-subtitle">Reusable author records for the active college. A book can credit several authors and an author can be credited on many books.</p>
        </div>
        @can('create', App\Models\Author::class)
            <a class="button" href="{{ route('authors.create') }}">+ Add author</a>
        @endcan
    </div>

    @include('authors._tabs', ['active' => 'authors'])

    <form class="mt-6 grid gap-3 sm:grid-cols-3" method="GET" action="{{ route('authors.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Author name">
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
                    <th>About</th>
                    <th>Books</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($authors as $author)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $author->name }}</td>
                        <td class="max-w-md truncate text-slate-600">{{ $author->description ?? '—' }}</td>
                        <td>
                            @if($author->books_count > 0 && auth()->user()?->can('viewAny', App\Models\Book::class))
                                <a class="text-indigo-600 hover:underline" href="{{ route('books.index', ['author_id' => $author->id]) }}">{{ $author->books_count }}</a>
                            @else
                                {{ $author->books_count }}
                            @endif
                        </td>
                        <td>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $author->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($author->status) }}</span>
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('update', $author)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('authors.edit', $author) }}">Edit</a>
                                @endcan
                                @can('delete', $author)
                                    <form method="POST" action="{{ route('authors.destroy', $author) }}" onsubmit="return confirm('Delete the author &quot;{{ $author->name }}&quot;?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="5">No authors recorded yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $authors->links() }}</div>
</div>
@endsection
