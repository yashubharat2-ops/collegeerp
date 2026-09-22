@extends('layouts.app')

@section('title', 'Add Book Copy')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Add Book Copy</h2>
            <p class="panel-subtitle">Record a physical copy of a title already in this college's catalogue. Accession number and barcode must be unique within the college.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('book-copies.index') }}">Back</a>
    </div>

    @if($errors->any())
        <div class="alert-error mt-4">
            <ul class="list-inside list-disc space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('book-copies.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @include('book_copies._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Save copy</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('book-copies.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    const bookSelect = document.getElementById('book_id');
    const copyNumber = document.getElementById('copy_number');
    const suggest = () => {
        if (!bookSelect || !copyNumber || copyNumber.dataset.touched === '1') return;
        const next = bookSelect.selectedOptions[0]?.dataset.nextCopy;
        if (next) copyNumber.value = next;
    };
    bookSelect?.addEventListener('change', suggest);
    copyNumber?.addEventListener('input', () => { copyNumber.dataset.touched = '1'; });
    suggest();
</script>
@endpush
