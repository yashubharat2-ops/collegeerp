@extends('layouts.app')
@section('title', $autoPrint ? 'Print student list' : 'Student list report')
@section('content')
@php
    /*
     * The printable student list: the target of the export menu's PDF and Print
     * options (both were built by StudentController::exportReport from the same
     * filter pipeline as the list itself).
     *
     * PDF and Print are ONE server-rendered document. This project bundles no PDF
     * library, and the established mechanism for official documents (receipts, ID
     * card batches) is a print-safe HTML page plus the browser's native dialog —
     * so "PDF" opens this page for review and "Save as PDF", while "Print" opens
     * it with the dialog already up. Navigation, toolbar and notices are
     * `.no-print`; the report itself is `.print-area`, reset to a clean white page
     * by the print rules in resources/css/app.css.
     */
    $shown = $students->count();
    $scope = $isSelection ? 'Selected students' : 'Filtered student list';
@endphp

{{-- Toolbar: screen only, never printed. data-auto-print is the flag
     js/erp-print.js reads — "1" opens the native dialog once the page has loaded
     (the Print option), "0" leaves the PDF variant alone so it can be reviewed
     and saved first. --}}
<div class="no-print mb-4 flex flex-wrap items-start justify-between gap-3" data-auto-print="{{ $autoPrint ? '1' : '0' }}">
    <div>
        <h2 class="panel-title">Student list — {{ $shown }} {{ \Illuminate\Support\Str::plural('student', $shown) }}</h2>
        <p class="panel-subtitle">
            @if($isSelection)
                The students you selected, re-checked on the server.
            @else
                The current filtered and sorted student list — clearing a filter on the Students page changes this report.
            @endif
            Use the print dialog to print it or save it as a PDF.
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <button class="button" type="button" data-print-now>Print / save as PDF</button>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('students.export', \Illuminate\Support\Arr::except(request()->query(), ['page'])) }}">Excel</a>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('students.index', \Illuminate\Support\Arr::except(request()->query(), ['ids', 'page'])) }}">Back to students</a>
    </div>
</div>

@if($skipped > 0 || $truncated)
    <p class="no-print mb-4 text-xs text-slate-500">
        @if($skipped > 0)
            {{ $skipped }} {{ \Illuminate\Support\Str::plural('student', $skipped) }} in the requested set could not be included:
            a student from another college, or one you may not view, is skipped rather than printed. Every row is re-checked on the server.
        @endif
        @if($truncated)
            This report is capped at {{ number_format($limit) }} rows, so it shows {{ number_format($shown) }} of
            {{ number_format($total) }} matching students — narrow the filters, or use the Excel export for the complete set.
        @endif
    </p>
@endif

<div class="print-area print-report panel">
    <header class="mb-4 border-b border-slate-300 pb-3">
        <h1 class="text-lg font-bold text-slate-900">{{ $college?->name ?? config('app.name', 'College ERP') }}</h1>
        <p class="text-sm text-slate-700">{{ $scope }} — {{ number_format($shown) }} of {{ number_format($total) }} {{ \Illuminate\Support\Str::plural('student', $total) }}</p>
        <p class="text-xs text-slate-500">Generated {{ $generatedAt->format('d M Y, H:i') }}</p>
    </header>

    <table>
        <thead>
            <tr>
                <th class="w-8">#</th>
                <th>Student number</th>
                <th>Name</th>
                <th>Contact</th>
                <th>Email</th>
                <th>Current enrollment</th>
                <th>Admission</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($students as $index => $student)
                @php
                    $enrollment = $student->currentEnrollment();
                @endphp
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="font-medium">{{ $student->student_number }}</td>
                    <td>
                        {{ $student->fullName() }}
                        <span class="block text-slate-500">
                            {{ \App\Models\Student::categoryLabel($student->category) }}@if($student->gender) · {{ ucfirst(str_replace('_', ' ', $student->gender)) }}@endif
                        </span>
                    </td>
                    <td>{{ $student->phone ?? '—' }}</td>
                    <td>{{ $student->email ?? '—' }}</td>
                    <td>
                        @if($enrollment)
                            {{ $enrollment->enrollment_number }}
                            @if($enrollment->academicYear)
                                <span class="block text-slate-500">{{ $enrollment->academicYear->name }}@if($enrollment->program) · {{ $enrollment->program->name }}@endif</span>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $student->admission_date?->format('d M Y') ?? '—' }}</td>
                    <td>{{ ucfirst($student->status) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">No students match the current filters or selection.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
