@extends('layouts.app')

@section('title', 'Result Calculation')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Result Calculation</h2>
            <p class="panel-subtitle">
                Calculate results from captured exam marks. Calculation is re-runnable and transaction-safe — it never duplicates results and never publishes anything.
            </p>
        </div>
    </div>

    @if($errors->any())
        <div class="alert-error mt-4">
            <ul class="list-inside list-disc space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="GET" action="{{ route('result-calculation.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <select class="input" name="examination_id" onchange="this.form.submit()">
            <option value="">Select examination…</option>
            @foreach($examinations as $exam)
                <option value="{{ $exam->id }}" @selected((int) $filters['examination_id'] === $exam->id)>{{ $exam->name }} ({{ $exam->code }})</option>
            @endforeach
        </select>
        <select class="input" name="grade_scale_id">
            <option value="">Grade / Pass-Fail scale…</option>
            @foreach($gradeScales as $scale)
                <option value="{{ $scale->id }}" @selected((int) $filters['grade_scale_id'] === $scale->id)>{{ $scale->name }} ({{ $scale->code }})</option>
            @endforeach
        </select>
        <select class="input" name="program_id">
            <option value="">All programs</option>
            @foreach($programs as $prog)
                <option value="{{ $prog->id }}" @selected((int) $filters['program_id'] === $prog->id)>{{ $prog->name }} ({{ $prog->code }})</option>
            @endforeach
        </select>
        <select class="input" name="section_id">
            <option value="">All sections</option>
            @foreach($sections as $sec)
                <option value="{{ $sec->id }}" @selected((int) $filters['section_id'] === $sec->id)>{{ $sec->name }} ({{ $sec->code }})</option>
            @endforeach
        </select>
        <select class="input" name="student_enrollment_id">
            <option value="">All eligible students</option>
            @foreach($enrollments as $enrollment)
                <option value="{{ $enrollment->id }}" @selected((int) $filters['student_enrollment_id'] === $enrollment->id)>
                    {{ $enrollment->enrollment_number }} — {{ $enrollment->student?->full_name }}
                </option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Apply scope</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('result-calculation.index') }}">Clear</a>
        </div>
    </form>

    @if($examination)
        <div class="mt-6 rounded-2xl border border-indigo-100 bg-indigo-50/50 p-4">
            <p class="text-sm font-semibold text-slate-900">Ready to calculate for {{ $examination->name }}</p>
            <p class="mt-1 text-xs text-slate-500">
                Exam schedules: {{ $summary['schedules'] }} ·
                Results stored: {{ $summary['total'] }} ·
                Calculated: {{ $summary['calculated'] }} ·
                Incomplete: {{ $summary['incomplete'] }} ·
                Failed: {{ $summary['failed'] }} ·
                Published: {{ $summary['published'] }}
            </p>
        </div>

        {{--
            Confirmation is handled by an inline onsubmit confirm(): no new JS
            dependency is introduced for this screen.
        --}}
        <form method="POST" action="{{ route('result-calculation.calculate') }}" class="mt-4">
            @csrf
            <input type="hidden" name="examination_id" value="{{ $examination->id }}">
            <input type="hidden" name="grade_scale_id" value="{{ $filters['grade_scale_id'] }}">
            <input type="hidden" name="program_id" value="{{ $filters['program_id'] }}">
            <input type="hidden" name="section_id" value="{{ $filters['section_id'] }}">
            <input type="hidden" name="student_enrollment_id" value="{{ $filters['student_enrollment_id'] }}">
            <button class="button" type="submit" onclick="return confirm('Calculate results for this scope? Existing calculated results are updated in place — no duplicates are created and nothing is published.')">
                Calculate results
            </button>
        </form>

        @can('recalculate', App\Models\ExamResult::class)
            <form method="POST" action="{{ route('result-calculation.recalculate') }}" class="mt-3">
                @csrf
                <input type="hidden" name="examination_id" value="{{ $examination->id }}">
                <input type="hidden" name="grade_scale_id" value="{{ $filters['grade_scale_id'] }}">
                <input type="hidden" name="program_id" value="{{ $filters['program_id'] }}">
                <input type="hidden" name="section_id" value="{{ $filters['section_id'] }}">
                <input type="hidden" name="student_enrollment_id" value="{{ $filters['student_enrollment_id'] }}">
                <button class="button !bg-slate-700" type="submit" onclick="return confirm('Recalculate results for this scope? Existing calculated results are refreshed in place — no duplicates are created.')">
                    Recalculate results
                </button>
            </form>
        @endcan
    @else
        <p class="mt-6 text-sm text-slate-500">Select an examination to see the calculation summary and actions.</p>
    @endif
</div>
@endsection
