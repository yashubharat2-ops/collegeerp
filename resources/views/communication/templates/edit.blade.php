@extends('layouts.app')

@section('title', 'Edit Template')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Edit Template</h2>
            <p class="panel-subtitle">{{ $template->name }} · <span class="font-mono text-xs">{{ $template->code }}</span></p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('communication-templates.show', $template) }}">Back</a>
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

    <form method="POST" action="{{ route('communication-templates.update', $template) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PUT')
        @include('communication.templates._form')
        <div class="flex flex-wrap gap-2 sm:col-span-2">
            <button class="button" type="submit">Save changes</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('communication-templates.show', $template) }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
