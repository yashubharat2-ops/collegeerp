@extends('layouts.app')
@section('title','Student ID Card')
@section('content')
@php
    $validUntil = $enrollment?->academicYear?->ends_on;
@endphp

<div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="panel-title">ID card — {{ $student->fullName() }}</h2>
        <p class="panel-subtitle">Generated from the student record and current enrollment. Nothing is stored; regenerate at any time.</p>
    </div>
    <div class="flex gap-2">
        <button class="button" type="button" onclick="window.print()">Print / save as PDF</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-id-cards.index') }}">Back to list</a>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('students.show', ['student' => $student, 'tab' => 'profile']) }}">Student profile</a>
    </div>
</div>

{{-- The card itself lives in one partial: the bulk batch view renders the
     same file, so a change to the card can never apply to only one of them. --}}
@include('student_id_cards._card')

    <p class="no-print mt-4 text-xs text-slate-500">
        Card content is read from the student's profile and their current enrollment. To correct anything shown here,
        edit the <a class="text-indigo-600 hover:underline" href="{{ route('students.edit', $student) }}">student profile</a>
        or the enrollment — the card follows the record.
    </p>
@endsection
