@extends('layouts.app')

@section('title', 'New student')

@section('content')
<div class="panel max-w-6xl p-4 md:p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 class="panel-title">New student</h2>
        <p class="text-xs text-slate-500">Sections marked with <span class="text-rose-500">*</span> are required.</p>
    </div>
    <p class="panel-subtitle">
        Created under the active college; the student number is generated server-side. Only the basic information is required —
        the remaining sections may be completed now or later from this same form.
    </p>
    {{-- multipart: the Basic information section uploads the portrait. --}}
    <form method="POST" action="{{ route('students.store') }}" enctype="multipart/form-data">
        @include('students._form', ['submitLabel' => 'Create student'])
    </form>
</div>
@endsection
