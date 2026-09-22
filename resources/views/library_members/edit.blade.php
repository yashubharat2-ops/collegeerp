@extends('layouts.app')

@section('title', 'Edit Library Member')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Edit Library Member</h2>
            <p class="panel-subtitle"><span class="font-mono">{{ $member->member_code }}</span> · {{ $member->studentName() }}</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-members.show', $member) }}">Back</a>
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

    <form method="POST" action="{{ route('library-members.update', $member) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @method('PUT')
        @include('library_members._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit">Update member</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-members.show', $member) }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
