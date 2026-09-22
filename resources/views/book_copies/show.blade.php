@extends('layouts.app')

@section('title', 'Book Copy')

@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="panel-title">{{ $copy->accession_number }}</h2>
                <p class="panel-subtitle">{{ $copy->book?->title ?? 'Book' }} · copy {{ $copy->copy_number }} · @include('library.status', ['status' => $copy->status])</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('update', $copy)
                    <a class="button" href="{{ route('book-copies.edit', $copy) }}">Edit</a>
                @endcan
                @if($copy->status === 'available')
                    @can('create', App\Models\LibraryTransaction::class)
                        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-transactions.create', ['book_copy_id' => $copy->id]) }}">Issue</a>
                    @endcan
                @endif
                @can('delete', $copy)
                    <form method="POST" action="{{ route('book-copies.destroy', $copy) }}" onsubmit="return confirm('Delete copy {{ $copy->accession_number }}?');">
                        @csrf
                        @method('DELETE')
                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                    </form>
                @endcan
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('book-copies.index', ['book_id' => $copy->book_id]) }}">Back</a>
            </div>
        </div>

        <dl class="mt-6 grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-slate-500">Book</dt>
                <dd>
                    @if($copy->book)
                        @can('view', $copy->book)
                            <a class="text-indigo-700 hover:underline" href="{{ route('books.show', $copy->book) }}">{{ $copy->book->title }}</a>
                        @else
                            {{ $copy->book->title }}
                        @endcan
                    @else
                        —
                    @endif
                    <span class="font-mono text-xs text-slate-500">{{ $copy->book?->code }}</span>
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">ISBN</dt>
                <dd class="font-mono">{{ $copy->book?->isbn ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Barcode</dt>
                <dd class="font-mono">{{ $copy->barcode ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Copy number</dt>
                <dd>{{ $copy->copy_number }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Location</dt>
                <dd>{{ $copy->location ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Condition</dt>
                <dd>{{ ucfirst($copy->condition) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Acquired on</dt>
                <dd>{{ $copy->acquired_on?->format('d M Y') ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Status</dt>
                <dd>@include('library.status', ['status' => $copy->status])</dd>
            </div>
        </dl>

        <div class="mt-6">
            <h3 class="font-semibold">Remarks</h3>
            <p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $copy->remarks ?: 'No remarks.' }}</p>
        </div>

        <div class="mt-6 border-t border-slate-200 pt-4 text-xs text-slate-500">
            Recorded {{ $copy->created_at?->format('d M Y, H:i') }}@if($copy->creator) by {{ $copy->creator->name }}@endif
            @if($copy->updated_at && $copy->updated_at->ne($copy->created_at))
                · Last updated {{ $copy->updated_at->format('d M Y, H:i') }}@if($copy->updater) by {{ $copy->updater->name }}@endif
            @endif
        </div>
    </div>

    <div class="panel">
        <h3 class="font-semibold">Circulation history</h3>
        <p class="panel-subtitle">Every issue of this copy. History is kept; it is not deleted when the copy is returned.</p>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-2">Member</th>
                        <th>Issued</th>
                        <th>Due</th>
                        <th>Returned</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($copy->transactions as $transaction)
                        <tr class="border-b">
                            <td class="py-2">
                                @can('view', $transaction)
                                    <a class="text-indigo-700 hover:underline" href="{{ route('library-transactions.show', $transaction) }}">{{ $transaction->libraryMember?->label() ?? 'Member' }}</a>
                                @else
                                    {{ $transaction->libraryMember?->label() ?? 'Member' }}
                                @endcan
                            </td>
                            <td>{{ $transaction->issued_on?->format('d M Y') }}</td>
                            <td>{{ $transaction->due_on?->format('d M Y') }}</td>
                            <td>{{ $transaction->returned_on?->format('d M Y') ?? '—' }}</td>
                            <td>@include('library.status', ['status' => $transaction->status])</td>
                        </tr>
                    @empty
                        <tr><td class="py-4 text-slate-500" colspan="5">This copy has not been issued.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
