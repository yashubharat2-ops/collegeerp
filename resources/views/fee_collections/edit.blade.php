@extends('layouts.app')

@section('title', 'Edit Collection')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Edit Collection</h2>
            <p class="panel-subtitle">Only the descriptive fields can be corrected: money that was received is never rewritten.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-collections.index') }}">Back</a>
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

    <form method="POST" action="{{ route('fee-collections.update', $payment) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PUT')
        @include('fee_collections._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Update collection</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-collections.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
