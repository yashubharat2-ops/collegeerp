@extends('layouts.app')

@section('title', 'Marksheets')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Marksheets</h2>
            <p class="panel-subtitle">
                Published examination results, printable as official marksheets. Only published results are listed here — unpublished results never appear.
            </p>
        </div>
    </div>

    <form method="GET" action="{{ route('marksheets.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
        <input class="input" type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search name, student no. or enrollment no.">
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('marksheets.index') }}">Clear</a>
        </div>
    </form>

    {{-- Bulk selection: export only. A marksheet is a derived document, so the
         values below are the PUBLISHED result ids they are rendered from; the
         handler re-queries them inside the active college, applies the
         published-only rule per record and re-authorizes it through the marksheet
         policy before the CSV endpoint streams anything. --}}
    <x-list.bulk-selection-bar module="marksheets">
        @if(auth()->user()?->hasPermission('marksheets.view'))
            <button type="button" data-bulk-action="export"
                    class="button !py-2 !text-xs font-semibold">
                Export selected
            </button>
        @endif
    </x-list.bulk-selection-bar>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="w-10 py-3"><x-list.select-all /></th>
                    <th class="py-3">Enrollment No.</th>
                    <th>Student</th>
                    <th>Examination</th>
                    <th>Program / Section</th>
                    <th>Marks</th>
                    <th>Percentage</th>
                    <th>Grade</th>
                    <th>Result</th>
                    <th>Published</th>
                    <th class="text-right">Marksheet</th>
                </tr>
            </thead>
            <tbody>
                @forelse($results as $result)
                    <tr class="border-b">
                        <td class="py-3"><x-list.row-checkbox :id="$result->id" /></td>
                        <td class="py-3 font-medium">{{ $result->studentEnrollment?->enrollment_number }}</td>
                        <td>{{ $result->studentEnrollment?->student?->fullName() }}</td>
                        <td>{{ $result->examination?->name }}</td>
                        <td>{{ $result->studentEnrollment?->program?->name }} / {{ $result->studentEnrollment?->section?->name }}</td>
                        <td>{{ $result->total_obtained_marks !== null ? $result->total_obtained_marks : '—' }} / {{ $result->total_max_marks }}</td>
                        <td>{{ $result->percentage !== null ? $result->percentage.'%' : '—' }}</td>
                        <td>{{ $result->overall_grade ?? '—' }}</td>
                        <td>
                            <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $result->result_status === 'pass' ? 'bg-emerald-100 text-emerald-700' : ($result->result_status === 'fail' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">{{ ucfirst($result->result_status) }}</span>
                        </td>
                        <td>{{ $result->published_at?->format('M d, Y') ?? '—' }}</td>
                        <td class="text-right">
                            {{-- Every listed row is published and tenant-scoped, so every
                                 listed row satisfies the marksheet policy by construction. --}}
                            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('marksheets.show', $result) }}">View / print</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="py-6 text-slate-500" colspan="11">No published results found for the selected filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $results->firstItem() ?? 0 }}–{{ $results->lastItem() ?? 0 }} of {{ $results->total() }} published results.</p>
        {{ $results->links() }}
    </div>
</div>
@endsection
