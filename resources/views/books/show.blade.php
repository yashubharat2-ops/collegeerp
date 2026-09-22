@extends('layouts.app')

@section('title', 'Book Details')

@section('content')
<div class="panel max-w-4xl">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">{{ $book->title }}</h2>
            <p class="panel-subtitle">
                <span class="font-mono">{{ $book->code }}</span>
                @if($book->edition) · {{ $book->edition }} @endif
                @if($book->publication_year) · {{ $book->publication_year }} @endif
                · <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $book->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($book->status) }}</span>
            </p>
        </div>
        <div class="flex gap-2">
            @can('update', $book)
                <a class="button" href="{{ route('books.edit', $book) }}">Edit</a>
            @endcan
            @can('delete', $book)
                <form method="POST" action="{{ route('books.destroy', $book) }}" onsubmit="return confirm('Delete the book &quot;{{ $book->title }}&quot;?');">
                    @csrf
                    @method('DELETE')
                    <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                </form>
            @endcan
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('books.index') }}">Back</a>
        </div>
    </div>

    <dl class="mt-6 grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-slate-500">ISBN</dt>
            <dd class="font-mono">{{ $book->isbn ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-slate-500">Category</dt>
            <dd>{{ $book->category?->name ?? '—' }} @if($book->category)<span class="text-xs text-slate-500">({{ $book->category->code }})</span>@endif</dd>
        </div>
        <div>
            <dt class="text-slate-500">Authors</dt>
            <dd>
                @forelse($book->authors as $author)
                    <span class="mr-1 inline-block rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ $author->name }}</span>
                @empty
                    —
                @endforelse
            </dd>
        </div>
        <div>
            <dt class="text-slate-500">Publisher</dt>
            <dd>{{ $book->publisher?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-slate-500">Edition</dt>
            <dd>{{ $book->edition ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-slate-500">Publication year</dt>
            <dd>{{ $book->publication_year ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-slate-500">Language</dt>
            <dd>{{ $book->language ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-slate-500">Status</dt>
            <dd>{{ ucfirst($book->status) }}</dd>
        </div>
    </dl>

    <div class="mt-6">
        <h3 class="font-semibold">Description</h3>
        <p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $book->description ?: 'No description.' }}</p>
    </div>

    <div class="mt-6 border-t border-slate-200 pt-4 text-xs text-slate-500">
        Catalogued {{ $book->created_at?->format('d M Y, H:i') }}@if($book->creator) by {{ $book->creator->name }}@endif
        @if($book->updated_at && $book->updated_at->ne($book->created_at))
            · Last updated {{ $book->updated_at->format('d M Y, H:i') }}@if($book->updater) by {{ $book->updater->name }}@endif
        @endif
    </div>

    @can('viewAny', App\Models\BookCopy::class)
        <div class="mt-6 border-t border-slate-200 pt-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="font-semibold">Physical copies</h3>
                <a class="text-sm text-indigo-600 hover:underline" href="{{ route('book-copies.index', ['book_id' => $book->id]) }}">View copies</a>
            </div>
            <p class="mt-2 text-sm text-slate-600">{{ $book->copies_count }} {{ \Illuminate\Support\Str::plural('copy', $book->copies_count) }} on record for this title. Copies are separate from this bibliographic record.</p>
            @can('create', App\Models\BookCopy::class)
                <a class="button mt-3 inline-block" href="{{ route('book-copies.create', ['book_id' => $book->id]) }}">+ Add copy</a>
            @endcan
        </div>
    @endcan
</div>
@endsection
