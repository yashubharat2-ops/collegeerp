@extends('layouts.app')

@section('title', 'Student Result History')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Result History — {{ $student->fullName() }}</h2>
            <p class="panel-subtitle">
                {{ $student->student_number }} · every published examination result, oldest first.
            </p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-result-history.index') }}">Back to students</a>
    </div>

    @forelse($results as $result)
        <div class="mt-6 rounded-2xl border border-slate-200 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-slate-900">{{ $result->examination?->name }}</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ $result->academicYear?->name ?? '—' }}{{ $result->academicTerm ? ' · '.$result->academicTerm->name : '' }} · {{ $result->studentEnrollment?->program?->name ?? '—' }}{{ $result->studentEnrollment?->section ? ' / '.$result->studentEnrollment->section->name : '' }} · {{ $result->studentEnrollment?->enrollment_number }}
                    </p>
                </div>
                <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $result->result_status === 'pass' ? 'bg-emerald-100 text-emerald-700' : ($result->result_status === 'fail' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">{{ ucfirst($result->result_status) }}</span>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b text-slate-500">
                            <th class="py-2">Subject</th>
                            <th class="text-right">Max</th>
                            <th class="text-right">Obtained</th>
                            <th class="text-center">Grade</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($result->items as $item)
                            <tr class="border-b border-slate-100">
                                <td class="py-2">{{ $item->examSchedule?->subject?->name ?? $item->subject?->name ?? '—' }}</td>
                                <td class="text-right">{{ $item->max_marks }}</td>
                                <td class="text-right font-medium">{{ $item->obtained_marks !== null ? $item->obtained_marks : '—' }}</td>
                                <td class="text-center">{{ $item->grade ?? '—' }}</td>
                                <td class="text-center">{{ ucfirst($item->status) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="py-4 text-slate-500" colspan="5">No subject results were calculated.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-500">
                <span>Total: <strong class="text-slate-700">{{ $result->total_obtained_marks !== null ? $result->total_obtained_marks : '—' }} / {{ $result->total_max_marks }}</strong></span>
                <span>Percentage: <strong class="text-slate-700">{{ $result->percentage !== null ? $result->percentage.'%' : '—' }}</strong></span>
                <span>Grade: <strong class="text-slate-700">{{ $result->overall_grade ?? '—' }}</strong></span>
                <span>Published: <strong class="text-slate-700">{{ $result->published_at?->format('M d, Y') ?? '—' }}</strong></span>
            </div>
        </div>
    @empty
        <p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm text-slate-500">This student has no published results yet.</p>
    @endforelse
</div>
@endsection
