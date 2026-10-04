@extends('layouts.app')
@section('title','Bulk Documents')
@section('content')
@php
    $generated = count($pack);
    $skipped = max(0, ($requestedCount ?? $generated) - $generated);
@endphp

<div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="panel-title">Document pack — {{ $generated }} {{ \Illuminate\Support\Str::plural('student', $generated) }}</h2>
        <p class="panel-subtitle">
            A live summary of the documents already on file for the selected students, with their verification status
            and any required document type that is still missing. Nothing is generated or stored: this is a view of the
            existing document records, ready to print.
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <button class="button" type="button" onclick="window.print()">Print / save as PDF</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('students.index') }}">Back to students</a>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-documents.index') }}">Documents list</a>
    </div>
</div>

<p class="no-print mb-6 text-xs text-slate-500">
    @if($skipped > 0)
        {{ $skipped }} selected {{ \Illuminate\Support\Str::plural('student', $skipped) }} could not be included:
        a student from another college, or one you may not view, is skipped rather than listed. Every student is re-checked
        on the server.
    @endif
    File contents are never embedded in this pack — open a document from the student's profile to download it.
</p>

@forelse($pack as $row)
    @php
        $student = $row['student'];
        // The model's own deterministic "current enrollment" rule — the view
        // never re-implements which enrollment is current.
        $enrollment = $student->currentEnrollment();
    @endphp

    <div class="mb-8 print-break last:mb-0">
        <div class="panel">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-4">
                <div>
                    <h3 class="text-base font-semibold text-slate-900">
                        {{ $student->fullName() }}
                        <span class="text-slate-400">·</span>
                        <span class="font-mono text-sm text-slate-600">{{ $student->student_number }}</span>
                    </h3>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $enrollment?->enrollment_number ?? 'No active enrollment' }}
                        @if($enrollment?->academicYear) · {{ $enrollment->academicYear->name }} @endif
                        @if($enrollment?->program) · {{ $enrollment->program->name }} @endif
                        @if($enrollment?->section) · {{ $enrollment->section->name }} @endif
                        · {{ \App\Models\Student::categoryLabel($student->category) }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs font-semibold">
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{{ $row['documents']->count() }} on file</span>
                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-emerald-700">{{ $row['verified'] }} verified</span>
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-700">{{ $row['pending'] }} pending</span>
                    @if($row['rejected'] > 0)
                        <span class="rounded-full bg-rose-100 px-2 py-0.5 text-rose-700">{{ $row['rejected'] }} rejected</span>
                    @endif
                </div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b text-slate-500">
                            <th class="py-2">Document type</th>
                            <th>Title</th>
                            <th>File</th>
                            <th>Issued</th>
                            <th>Expiry</th>
                            <th>Verification</th>
                            <th>Verified by</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($row['documents'] as $document)
                        @php
                            $badge = match ($document->verification_status) {
                                'verified' => 'bg-emerald-100 text-emerald-700',
                                'rejected' => 'bg-rose-100 text-rose-700',
                                default => 'bg-amber-100 text-amber-700',
                            };
                        @endphp
                        <tr class="border-b">
                            <td class="py-2">{{ $document->documentType?->name ?? '—' }}</td>
                            <td>{{ $document->title }}</td>
                            <td class="text-xs text-slate-500">{{ $document->original_filename ?? '—' }}</td>
                            <td>{{ $document->issue_date?->format('d M Y') ?? '—' }}</td>
                            <td>{{ $document->expiry_date?->format('d M Y') ?? '—' }}</td>
                            <td>
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $badge }}">
                                    {{ ucfirst($document->verification_status) }}
                                </span>
                            </td>
                            <td class="text-xs text-slate-500">
                                {{ $document->verifiedBy?->name ?? '—' }}
                                @if($document->verified_at)
                                    <span class="block text-slate-400">{{ $document->verified_at->format('d M Y') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td class="py-3 text-slate-500" colspan="7">No documents uploaded for this student yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-xs text-slate-500">
                <span class="font-semibold text-slate-600">Required types still missing:</span>
                @if($row['missingTypes']->isEmpty())
                    <span class="text-emerald-700">none — every required document type is on file.</span>
                @else
                    <span class="text-amber-700">{{ $row['missingTypes']->pluck('name')->implode(', ') }}</span>
                @endif
            </p>
        </div>
    </div>
@empty
    <div class="panel">
        <p class="py-6 text-center text-sm text-slate-500">No students could be included in this pack.</p>
    </div>
@endforelse
@endsection
