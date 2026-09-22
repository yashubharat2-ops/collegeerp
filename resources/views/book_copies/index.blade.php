@extends('layouts.app')

@section('title', 'Book Copies')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Book Copies</h2>
            <p class="panel-subtitle">Physical copies of titles catalogued for the active college. Each copy has its own accession number; the book master is not duplicated.</p>
        </div>
        @can('create', App\Models\BookCopy::class)
            <a class="button" href="{{ route('book-copies.create', array_filter(['book_id' => $filters['book_id']])) }}">+ Add copy</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" method="GET" action="{{ route('book-copies.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Accession, barcode, location or title">
        </div>
        <div>
            <label class="label" for="book_id">Book</label>
            <select class="input" id="book_id" name="book_id">
                <option value="">All titles</option>
                @foreach($books as $book)
                    <option value="{{ $book->id }}" @selected((string) $filters['book_id'] === (string) $book->id)>{{ $book->title }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <select class="input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ ucfirst($statusOption) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="condition">Condition</label>
            <select class="input" id="condition" name="condition">
                <option value="">All conditions</option>
                @foreach($conditions as $conditionOption)
                    <option value="{{ $conditionOption }}" @selected($condition === $conditionOption)>{{ ucfirst($conditionOption) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end">
            <button class="button" type="submit">Filter</button>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Accession</th>
                    <th>Book</th>
                    <th>Copy</th>
                    <th>Barcode</th>
                    <th>Location</th>
                    <th>Condition</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($copies as $copy)
                    <tr class="border-b">
                        <td class="py-2"><a class="font-mono text-xs font-medium text-indigo-700 hover:underline" href="{{ route('book-copies.show', $copy) }}">{{ $copy->accession_number }}</a></td>
                        <td>{{ $copy->book?->title ?? '—' }}</td>
                        <td>{{ $copy->copy_number }}</td>
                        <td><span class="font-mono text-xs">{{ $copy->barcode ?? '—' }}</span></td>
                        <td class="max-w-xs truncate">{{ $copy->location ?? '—' }}</td>
                        <td>{{ ucfirst($copy->condition) }}</td>
                        <td>@include('library.status', ['status' => $copy->status])</td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('view', $copy)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('book-copies.show', $copy) }}">View</a>
                                @endcan
                                @can('update', $copy)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('book-copies.edit', $copy) }}">Edit</a>
                                @endcan
                                @can('delete', $copy)
                                    <form method="POST" action="{{ route('book-copies.destroy', $copy) }}" onsubmit="return confirm('Delete copy {{ $copy->accession_number }}? Copies with circulation history cannot be deleted.');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="8">No book copies recorded yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $copies->links() }}</div>
</div>
@endsection
