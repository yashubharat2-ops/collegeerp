@extends('layouts.app')

@section('title', 'Publishers')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Publishers</h2>
            <p class="panel-subtitle">Reusable publisher records for the active college. Each book may reference one publisher; contact details help with acquisitions and enquiries.</p>
        </div>
        @can('create', App\Models\Publisher::class)
            <a class="button" href="{{ route('publishers.create') }}">+ Add publisher</a>
        @endcan
    </div>

    @include('authors._tabs', ['active' => 'publishers'])

    <form class="mt-6 grid gap-3 sm:grid-cols-3" method="GET" action="{{ route('publishers.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Publisher name or email">
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
                    <th>Contact</th>
                    <th>Website</th>
                    <th>Books</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($publishers as $publisher)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $publisher->name }}</td>
                        <td class="text-slate-600">
                            @if($publisher->email || $publisher->phone)
                                <div>{{ $publisher->email ?? '' }}</div>
                                <div class="text-xs text-slate-500">{{ $publisher->phone ?? '' }}</div>
                            @else
                                —
                            @endif
                        </td>
                        <td class="max-w-xs truncate text-slate-600">
                            @if($publisher->website)
                                <a class="text-indigo-600 hover:underline" href="{{ $publisher->website }}" target="_blank" rel="noopener noreferrer">{{ $publisher->website }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if($publisher->books_count > 0 && auth()->user()?->can('viewAny', App\Models\Book::class))
                                <a class="text-indigo-600 hover:underline" href="{{ route('books.index', ['publisher_id' => $publisher->id]) }}">{{ $publisher->books_count }}</a>
                            @else
                                {{ $publisher->books_count }}
                            @endif
                        </td>
                        <td>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $publisher->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($publisher->status) }}</span>
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('update', $publisher)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('publishers.edit', $publisher) }}">Edit</a>
                                @endcan
                                @can('delete', $publisher)
                                    <form method="POST" action="{{ route('publishers.destroy', $publisher) }}" onsubmit="return confirm('Delete the publisher &quot;{{ $publisher->name }}&quot;?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="6">No publishers recorded yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $publishers->links() }}</div>
</div>
@endsection
