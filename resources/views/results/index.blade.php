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

    <x-student-filter
        :fields="$studentFilterFields"
        :options="$studentFilterOptions"
        :values="$studentFilterValues"
        :applied="$studentFilterApplied"
        :action="route('results.index')"
        title="Filter Students in Results"
    >
        <x-list.filter-select name="examination_id" label="Examination" :value="$filters['examination_id']" placeholder="All examinations">
            @foreach($examinations as $exam)
                <option value="{{ $exam->id }}" @selected((int) $filters['examination_id'] === $exam->id)>{{ $exam->name }} ({{ $exam->code }})</option>
            @endforeach
        </x-list.filter-select>
        <x-list.filter-select name="result_status" label="Result status" :value="$filters['result_status']" placeholder="All result statuses">
            @foreach($resultStatuses as $status)
                <option value="{{ $status }}" @selected($filters['result_status'] === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </x-list.filter-select>
        <x-list.filter-select name="calculation_status" label="Calculation status" :value="$filters['calculation_status']" placeholder="All calculation statuses">
            @foreach($calculationStatuses as $status)
                <option value="{{ $status }}" @selected($filters['calculation_status'] === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </x-list.filter-select>
        <x-list.filter-select name="publication_status" label="Publishing status" :value="$filters['publication_status']" placeholder="All publishing states">
            @foreach($publicationStatuses as $status)
                <option value="{{ $status }}" @selected($filters['publication_status'] === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </x-list.filter-select>
        <div class="erp-list-field erp-list-filter-span-2">
            <label for="filter-result-search" class="mb-1 block text-xs font-semibold text-slate-700">Search results</label>
            <input id="filter-result-search" class="input !py-2 !text-xs" type="search" name="search" value="{{ $filters['search'] }}" placeholder="Name, student no. or enrollment no.">
        </div>
    </x-student-filter>

    {{-- Bulk selection: export only. Results are produced by the calculation
         engine and never edited by hand. The handler re-queries the ticked ids
         inside the active college and re-checks each one through the Results
         policy, so unpublished rows are exported only for holders of
         results.view_unpublished — exactly like the list above. --}}
    <x-list.bulk-selection-bar module="results">
        @if(auth()->user()?->hasPermission('results.view'))
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
                    <th>Calculation</th>
                    <th>Publishing</th>
                    <th class="text-right">View</th>
                </tr>
            </thead>
            <tbody>
                @forelse($results as $result)
                    <tr class="border-b">
                        <td class="py-3"><x-list.row-checkbox :id="$result->id" /></td>
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
                        <td class="py-6 text-slate-500" colspan="12">No results found for the selected filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $results->links() }}</div>
</div>
@endsection
