@extends('layouts.app')

@section('title', 'Edit student')

@section('content')
<div class="panel max-w-6xl p-4 md:p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 class="panel-title">Edit student: {{ $student->first_name }} {{ $student->last_name }}</h2>
        <p class="text-xs text-slate-500">Student number {{ $student->student_number }} is server-generated.</p>
    </div>
    <p class="panel-subtitle">
        Identity numbers are shown masked only — leave a field blank to keep the stored value, or tick the box to remove it.
    </p>
    {{-- multipart: the Basic information section can replace or remove the portrait. --}}
    <form method="POST" action="{{ route('students.update', $student) }}" enctype="multipart/form-data">
        @method('PUT')
        @include('students._form', ['submitLabel' => 'Update student'])
    </form>
</div>
@endsection
