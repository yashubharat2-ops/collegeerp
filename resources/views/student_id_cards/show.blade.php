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

<div class="print-area mx-auto max-w-2xl">
    <div class="overflow-hidden rounded-2xl border-2 border-indigo-200 bg-white shadow-sm" data-card-payload="{{ $payload }}">
        {{-- Card header --}}
        <div class="bg-indigo-700 px-6 py-4 text-white">
            <p class="text-lg font-bold leading-tight">{{ $college?->name ?? config('app.name', 'College ERP') }}</p>
            <p class="text-xs text-indigo-100">
                {{ $campus?->name ?? 'Main campus' }}
                @if($college?->address) · {{ $college->address }} @endif
            </p>
            <p class="mt-1 text-[11px] uppercase tracking-widest text-indigo-200">Student Identity Card</p>
        </div>

        {{-- Card body --}}
        <div class="flex gap-5 px-6 py-5">
            <div class="grid h-28 w-24 shrink-0 place-items-center overflow-hidden rounded-xl border border-slate-200 bg-slate-100 text-2xl font-bold text-slate-400">
                @if($student->photo_path)
                    <img class="h-full w-full object-cover" src="{{ route('students.photo', $student) }}" alt="Photo of {{ $student->fullName() }}">
                @else
                    {{ strtoupper(substr($student->first_name, 0, 1).substr((string) $student->last_name, 0, 1)) }}
                @endif
            </div>

            <dl class="grid flex-1 grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <div class="col-span-2">
                    <dt class="text-[11px] uppercase tracking-wide text-slate-500">Name</dt>
                    <dd class="text-base font-semibold text-slate-900">{{ $student->fullName() }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wide text-slate-500">Student number</dt>
                    <dd class="font-medium">{{ $student->student_number }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wide text-slate-500">Enrollment number</dt>
                    <dd class="font-medium">{{ $enrollment?->enrollment_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wide text-slate-500">Program</dt>
                    <dd class="font-medium">{{ $enrollment?->program?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wide text-slate-500">Academic year</dt>
                    <dd class="font-medium">{{ $enrollment?->academicYear?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wide text-slate-500">Section</dt>
                    <dd class="font-medium">{{ $enrollment?->section?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wide text-slate-500">Date of birth</dt>
                    <dd class="font-medium">{{ $student->date_of_birth?->format('d M Y') ?? '—' }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-[11px] uppercase tracking-wide text-slate-500">Contact</dt>
                    <dd class="font-medium">
                        {{ $student->phone ?? '—' }}@if($student->email) · {{ $student->email }}@endif
                    </dd>
                </div>
            </dl>
        </div>

        {{-- Card footer --}}
        <div class="flex flex-wrap items-end justify-between gap-3 border-t border-dashed border-slate-300 bg-slate-50 px-6 py-4">
            <div>
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Valid until</p>
                <p class="text-sm font-semibold text-slate-800">{{ $validUntil?->format('d M Y') ?? 'End of the current academic session' }}</p>
                <p class="mt-1 text-[11px] text-slate-500">
                    This card remains the property of {{ $college?->name ?? 'the college' }}.
                    If found, please return to the college office.
                </p>
            </div>
            <div class="text-right">
                <p class="text-[11px] uppercase tracking-wide text-slate-500">Verification code</p>
                {{-- No QR/barcode library is bundled in this project, so the payload is
                     rendered as text (and exposed via data-card-payload above). Adding a
                     QR library later renders from the same value with no other change. --}}
                <p class="font-mono text-xs tracking-tight text-slate-700">{{ $payload }}</p>
            </div>
        </div>
    </div>

    <p class="no-print mt-4 text-xs text-slate-500">
        Card content is read from the student's profile and their current enrollment. To correct anything shown here,
        edit the <a class="text-indigo-600 hover:underline" href="{{ route('students.edit', $student) }}">student profile</a>
        or the enrollment — the card follows the record.
    </p>
</div>
@endsection
