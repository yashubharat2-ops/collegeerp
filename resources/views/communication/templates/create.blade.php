@extends('layouts.app')

@section('title', 'New Template')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">New SMS / Email Template</h2>
            <p class="panel-subtitle">The template is stored for the active college as a reusable definition. Saving it sends nothing.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('communication-templates.index') }}">Back</a>
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

    <form method="POST" action="{{ route('communication-templates.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @include('communication.templates._form')
        <div class="flex flex-wrap gap-2 sm:col-span-2">
            <button class="button" type="submit">Save template</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('communication-templates.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
