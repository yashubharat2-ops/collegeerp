@extends('layouts.app')
@section('title','Generate Student ID Cards')
@section('content')
@php
    $generated = count($cards);
    $skipped = max(0, ($requestedCount ?? $generated) - $generated);
@endphp

<div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="panel-title">ID cards — {{ $generated }} {{ \Illuminate\Support\Str::plural('student', $generated) }}</h2>
        <p class="panel-subtitle">
            Generated for the students you selected. Every card is read from the student record and that student's
            current enrollment — nothing is stored, so regenerating always reflects the live record.
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <button class="button" type="button" onclick="window.print()">Print / save as PDF</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('students.index') }}">Back to students</a>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-id-cards.index') }}">ID card list</a>
    </div>
</div>

<p class="no-print mb-6 text-xs text-slate-500">
    @if($skipped > 0)
        {{ $skipped }} selected {{ \Illuminate\Support\Str::plural('student', $skipped) }} could not be generated here:
        a student from another college, or one you may not view, is skipped rather than printed. Every card is re-checked
        on the server.
    @endif
    Use your browser's print dialog to print the batch or save it as a PDF — one card per page.
</p>

@foreach($cards as $card)
    <div class="mb-10 print-break last:mb-0">
        @include('student_id_cards._card', [
            'student' => $card['student'],
            'enrollment' => $card['enrollment'],
            'college' => $college,
            'campus' => $card['campus'],
            'validUntil' => $card['validUntil'],
            'payload' => $card['payload'],
        ])
    </div>
@endforeach
@endsection
