@extends('layouts.app')

@section('title', 'Add Fee Category')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Add Fee Category</h2>
            <p class="panel-subtitle">Group fee components (Tuition, Admission, Examination, …) for this college.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-categories.index') }}">Back</a>
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

    <form method="POST" action="{{ route('fee-categories.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @include('fee_categories._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Save fee category</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-categories.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
