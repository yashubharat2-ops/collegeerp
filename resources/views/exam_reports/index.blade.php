@extends('layouts.app')

@section('title', 'Exam Reports')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Exam Reports</h2>
            <p class="panel-subtitle">
                Published-result summaries by examination, program and subject. All figures count published results only.
            </p>
        </div>
    </div>

    <form method="GET" action="{{ route('exam-reports.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <select class="input" name="examination_id">
            @foreach($examinations as $exam)
                <option value="{{ $exam->id }}" @selected($examinationId === $exam->id)>{{ $exam->name }} ({{ $exam->code }})</option>
            @endforeach
        </select>
        <select class="input" name="program_id">
            <option value="">All programs</option>
            @foreach($programs as $prog)
                <option value="{{ $prog->id }}" @selected($programId === $prog->id)>{{ $prog->name }} ({{ $prog->code }})</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Show report</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('exam-reports.index') }}">Reset</a>
        </div>
    </form>

    @if($examinations->isEmpty())
        <p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm text-slate-500">No examinations exist yet, so there is nothing to report on.</p>
    @elseif($summary['total'] === 0)
        <p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm text-slate-500">No published results found for the selected filters.</p>
    @else
        <div class="mt-6 grid gap-4 sm:grid-cols-3 lg:grid-cols-7">
            <div class="stat-card">
                <p class="stat-label">Published Results</p>
                <p class="stat-value">{{ $summary['total'] }}</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Pass</p>
                <p class="stat-value">{{ $summary['by_status']['pass'] }}</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Fail</p>
                <p class="stat-value">{{ $summary['by_status']['fail'] }}</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Absent</p>
                <p class="stat-value">{{ $summary['by_status']['absent'] }}</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Withheld</p>
                <p class="stat-value">{{ $summary['by_status']['withheld'] }}</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Incomplete</p>
                <p class="stat-value">{{ $summary['by_status']['incomplete'] }}</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Pass Rate</p>
                <p class="stat-value">{{ $summary['pass_rate'] !== null ? $summary['pass_rate'].'%' : '—' }}</p>
            </div>
        </div>

        {{-- Program-wise summary. Every figure is a live COUNT over published
             results, so a line is keyed by the real Program row it summarises: the
             checkbox value is that program id, which the handler re-queries inside
             the active college before the export endpoint re-aggregates exactly
             those programs. The screen's own filters travel with the action as
             bulk parameters and are re-validated server-side, so the CSV reports
             the same scope as the line on screen. --}}
        <div class="panel mt-8">
            <h3 class="text-sm font-semibold text-slate-900">Program-wise summary</h3>

            <x-list.bulk-selection-bar module="exam_reports">
                @if(auth()->user()?->hasPermission('exam_reports.view'))
                    <button type="button" data-bulk-action="export"
                            data-bulk-param-examination-id="{{ $examinationId }}"
                            data-bulk-param-program-id="{{ $programId }}"
                            class="button !py-2 !text-xs font-semibold">
                        Export selected
                    </button>
                @endif
            </x-list.bulk-selection-bar>

        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="w-10 py-3"><x-list.select-all /></th>
                        <th class="py-3">Program</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Pass</th>
                        <th class="text-right">Fail</th>
                        <th class="text-right">Absent</th>
                        <th class="text-right">Withheld</th>
                        <th class="text-right">Incomplete</th>
                        <th class="text-right">Pass Rate</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($programSummaries as $program)
                        <tr class="border-b">
                            <td class="py-3"><x-list.row-checkbox :id="$program->id" /></td>
                            <td class="py-3 font-medium">{{ $program->name }} ({{ $program->code }})</td>
                            <td class="text-right">{{ $program->total }}</td>
                            <td class="text-right">{{ $program->pass_count }}</td>
                            <td class="text-right">{{ $program->fail_count }}</td>
                            <td class="text-right">{{ $program->absent_count }}</td>
                            <td class="text-right">{{ $program->withheld_count }}</td>
                            <td class="text-right">{{ $program->incomplete_count }}</td>
                            <td class="text-right">{{ $program->pass_rate !== null ? $program->pass_rate.'%' : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-6 text-slate-500" colspan="9">No program data for the selected filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $programSummaries->links() }}</div>
        </div>

        {{-- Subject-wise summary: the same contract as above, keyed by the
             Subject rows it summarises and posted as its OWN module, so a
             program id and a subject id can never end up in one selection. --}}
        <div class="panel mt-8">
            <h3 class="text-sm font-semibold text-slate-900">Subject-wise summary</h3>

            <x-list.bulk-selection-bar module="exam_report_subjects">
                @if(auth()->user()?->hasPermission('exam_reports.view'))
                    <button type="button" data-bulk-action="export"
                            data-bulk-param-examination-id="{{ $examinationId }}"
                            data-bulk-param-program-id="{{ $programId }}"
                            class="button !py-2 !text-xs font-semibold">
                        Export selected
                    </button>
                @endif
            </x-list.bulk-selection-bar>

        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="w-10 py-3"><x-list.select-all /></th>
                        <th class="py-3">Subject</th>
                        <th class="text-right">Max Marks</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Pass</th>
                        <th class="text-right">Fail</th>
                        <th class="text-right">Absent</th>
                        <th class="text-right">Withheld</th>
                        <th class="text-right">Incomplete</th>
                        <th class="text-right">Pass Rate</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($subjectSummaries as $subject)
                        <tr class="border-b">
                            <td class="py-3"><x-list.row-checkbox :id="$subject->id" /></td>
                            <td class="py-3 font-medium">{{ $subject->name }} ({{ $subject->code }})</td>
                            <td class="text-right">{{ $subject->max_marks ?? '—' }}</td>
                            <td class="text-right">{{ $subject->total }}</td>
                            <td class="text-right">{{ $subject->pass_count }}</td>
                            <td class="text-right">{{ $subject->fail_count }}</td>
                            <td class="text-right">{{ $subject->absent_count }}</td>
                            <td class="text-right">{{ $subject->withheld_count }}</td>
                            <td class="text-right">{{ $subject->incomplete_count }}</td>
                            <td class="text-right">{{ $subject->pass_rate !== null ? $subject->pass_rate.'%' : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-6 text-slate-500" colspan="10">No subject data for the selected filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $subjectSummaries->links() }}</div>
        </div>
    @endif
</div>
@endsection
