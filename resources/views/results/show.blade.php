@extends('layouts.app')

@section('title', 'Result Details')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Result Details</h2>
            <p class="panel-subtitle">
                {{ $result->studentEnrollment?->student?->full_name }} · {{ $result->examination?->name }}
            </p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('results.index') }}">Back to results</a>
    </div>

    @if(! $result->isPublished())
        <div class="mt-6 rounded-xl bg-amber-50 p-4 text-sm text-amber-800">
            This result is <strong>not published</strong>. It is visible to you only because you hold the unpublished-results permission — it must not be shared as a published result.
        </div>
    @endif

    <div class="mt-6 grid gap-4 md:grid-cols-2">
        <div class="rounded-2xl border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900">Student</h3>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Name</dt><dd class="font-medium">{{ $result->studentEnrollment?->student?->full_name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Student Number</dt><dd class="font-medium">{{ $result->studentEnrollment?->student?->student_number }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Enrollment Number</dt><dd class="font-medium">{{ $result->studentEnrollment?->enrollment_number }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Program</dt><dd class="font-medium">{{ $result->studentEnrollment?->program?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Section</dt><dd class="font-medium">{{ $result->studentEnrollment?->section?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Academic Year</dt><dd class="font-medium">{{ $result->academicYear?->name }}</dd></div>
            </dl>
        </div>

        <div class="rounded-2xl border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900">Examination</h3>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Examination</dt><dd class="font-medium">{{ $result->examination?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Term</dt><dd class="font-medium">{{ $result->academicTerm?->name ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Grade Scale</dt><dd class="font-medium">{{ $result->gradeScale?->name ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Calculated At</dt><dd class="font-medium">{{ $result->calculated_at?->format('M d, Y H:i') ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Calculated By</dt><dd class="font-medium">{{ $result->calculatedBy?->name ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Published At</dt><dd class="font-medium">{{ $result->published_at?->format('M d, Y H:i') ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Published By</dt><dd class="font-medium">{{ $result->publishedBy?->name ?? '—' }}</dd></div>
            </dl>
        </div>
    </div>

    <div class="mt-6 overflow-x-auto">
        <h3 class="text-sm font-semibold text-slate-900">Subject Results</h3>
        <table class="mt-3 w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Subject</th>
                    <th>Max Marks</th>
                    <th>Passing Marks</th>
                    <th>Obtained Marks</th>
                    <th>Grade</th>
                    <th>Status</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                @forelse($result->items as $item)
                    <tr class="border-b">
                        <td class="py-3">{{ $item->examSchedule?->subject?->name }}</td>
                        <td>{{ $item->max_marks }}</td>
                        <td>{{ $item->passing_marks }}</td>
                        <td>{{ $item->obtained_marks !== null ? $item->obtained_marks : '—' }}</td>
                        <td>{{ $item->grade ?? '—' }}</td>
                        <td>
                            <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $item->status === 'pass' ? 'bg-emerald-100 text-emerald-700' : ($item->status === 'fail' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">{{ ucfirst($item->status) }}</span>
                        </td>
                        <td>{{ $item->remarks ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="py-6 text-slate-500" colspan="7">No subject results were calculated.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <div class="stat-card">
            <p class="stat-label">Total Max Marks</p>
            <p class="stat-value">{{ $result->total_max_marks }}</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Total Obtained</p>
            <p class="stat-value">{{ $result->total_obtained_marks !== null ? $result->total_obtained_marks : '—' }}</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Percentage</p>
            <p class="stat-value">{{ $result->percentage !== null ? $result->percentage.'%' : '—' }}</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Overall Grade</p>
            <p class="stat-value">{{ $result->overall_grade ?? '—' }}</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Result Status</p>
            <p class="stat-value">{{ ucfirst($result->result_status) }}</p>
        </div>
        <div class="stat-card">
            <p class="stat-label">Publishing Status</p>
            <p class="stat-value">{{ ucfirst($result->publication_status) }}</p>
            <p class="stat-hint">Calculation: {{ ucfirst($result->calculation_status) }}</p>
        </div>
    </div>
</div>
@endsection
