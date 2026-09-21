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

        <h3 class="mt-8 text-sm font-semibold text-slate-900">Program-wise summary</h3>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
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
                            <td class="py-6 text-slate-500" colspan="8">No program data for the selected filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $programSummaries->links() }}</div>

        <h3 class="mt-8 text-sm font-semibold text-slate-900">Subject-wise summary</h3>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
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
                            <td class="py-6 text-slate-500" colspan="9">No subject data for the selected filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $subjectSummaries->links() }}</div>
    @endif
</div>
@endsection
