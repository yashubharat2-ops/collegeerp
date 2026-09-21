@extends('layouts.app')

@section('title', 'Results')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Results</h2>
            <p class="panel-subtitle">
                Calculated examination results. Results are derived from exam marks — this screen only displays them and never edits marks.
            </p>
        </div>
        @can('viewPublishing', App\Models\ExamResult::class)
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('result-publishing.index') }}">Result Publishing</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('results.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <select class="input" name="examination_id">
            <option value="">All examinations</option>
            @foreach($examinations as $exam)
                <option value="{{ $exam->id }}" @selected((int) $filters['examination_id'] === $exam->id)>{{ $exam->name }} ({{ $exam->code }})</option>
            @endforeach
        </select>
        <select class="input" name="academic_year_id">
            <option value="">All academic years</option>
            @foreach($academicYears as $year)
                <option value="{{ $year->id }}" @selected((int) $filters['academic_year_id'] === $year->id)>{{ $year->name }}</option>
            @endforeach
        </select>
        <select class="input" name="academic_term_id">
            <option value="">All academic terms</option>
            @foreach($academicTerms as $term)
                <option value="{{ $term->id }}" @selected((int) $filters['academic_term_id'] === $term->id)>{{ $term->name }} ({{ $term->code }})</option>
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
        <select class="input" name="result_status">
            <option value="">All result statuses</option>
            @foreach($resultStatuses as $status)
                <option value="{{ $status }}" @selected($filters['result_status'] === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <select class="input" name="calculation_status">
            <option value="">All calculation statuses</option>
            @foreach($calculationStatuses as $status)
                <option value="{{ $status }}" @selected($filters['calculation_status'] === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <select class="input" name="publication_status">
            <option value="">All publishing states</option>
            @foreach($publicationStatuses as $status)
                <option value="{{ $status }}" @selected($filters['publication_status'] === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <input class="input" type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search name, student no. or enrollment no.">
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('results.index') }}">Clear</a>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Enrollment No.</th>
                    <th>Student</th>
                    <th>Examination</th>
                    <th>Program / Section</th>
                    <th>Marks</th>
                    <th>Percentage</th>
                    <th>Grade</th>
                    <th>Result</th>
                    <th>Calculation</th>
                    <th>Publishing</th>
                    <th class="text-right">View</th>
                </tr>
            </thead>
            <tbody>
                @forelse($results as $result)
                    <tr class="border-b">
                        <td class="py-3 font-medium">{{ $result->studentEnrollment?->enrollment_number }}</td>
                        <td>{{ $result->studentEnrollment?->student?->full_name }}</td>
                        <td>{{ $result->examination?->name }}</td>
                        <td>{{ $result->studentEnrollment?->program?->name }} / {{ $result->studentEnrollment?->section?->name }}</td>
                        <td>{{ $result->total_obtained_marks !== null ? $result->total_obtained_marks : '—' }} / {{ $result->total_max_marks }}</td>
                        <td>{{ $result->percentage !== null ? $result->percentage.'%' : '—' }}</td>
                        <td>{{ $result->overall_grade ?? '—' }}</td>
                        <td>
                            <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $result->result_status === 'pass' ? 'bg-emerald-100 text-emerald-700' : ($result->result_status === 'fail' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">{{ ucfirst($result->result_status) }}</span>
                        </td>
                        <td>
                            <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $result->calculation_status === 'calculated' ? 'bg-emerald-100 text-emerald-700' : ($result->calculation_status === 'failed' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">{{ ucfirst($result->calculation_status) }}</span>
                        </td>
                        <td>
                            <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $result->publication_status === 'published' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($result->publication_status) }}</span>
                        </td>
                        <td class="text-right">
                            @can('view', $result)
                                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('results.show', $result) }}">Open</a>
                            @else
                                <span class="text-xs text-slate-400">Not available</span>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="py-6 text-slate-500" colspan="11">No results found for the selected filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $results->links() }}</div>
</div>
@endsection
