@extends('layouts.app')
@section('title','Applications')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Admission Applications</h2>
            <p class="panel-subtitle">Manage formal applications within the active college. Application numbers are generated server-side per college.</p>
        </div>
        @can('create', App\Models\AdmissionApplication::class)
            <a class="button" href="{{ route('admission-applications.create') }}">+ New application</a>
        @endcan
    </div>

    <x-student-filter
        :fields="$studentFilterFields"
        :options="$studentFilterOptions"
        :values="$studentFilterValues"
        :applied="$studentFilterApplied"
        :action="route('admission-applications.index')"
        :reset-url="route('admission-applications.index')"
        title="Filter Admission Applications"
    >
        <div class="erp-list-field erp-list-filter-span-2">
            <label for="admission-application-search" class="mb-1 block text-xs font-semibold text-slate-700">Search applications</label>
            <input id="admission-application-search" class="input !py-2 !text-xs" type="search" name="search" value="{{ $search }}" placeholder="Application number or applicant">
        </div>
        <x-list.filter-select name="status" label="Admission application status" :value="$status" placeholder="All application statuses">
            @foreach(['draft', 'submitted', 'under_review', 'approved', 'rejected', 'cancelled', 'admitted'] as $applicationStatus)
                <option value="{{ $applicationStatus }}" @selected($status === $applicationStatus)>{{ ucfirst(str_replace('_', ' ', $applicationStatus)) }}</option>
            @endforeach
        </x-list.filter-select>
    </x-student-filter>

    <x-list.bulk-selection-bar module="admission_applications">
        @can('viewAny', App\Models\AdmissionApplication::class)
            <button type="button" data-bulk-action="export"
                    data-bulk-param-search="{{ $search }}"
                    data-bulk-param-status="{{ is_scalar($status) ? $status : '' }}"
                    @foreach($studentFilterParameters as $filterName => $filterValue)
                        data-bulk-param-{{ str_replace('_', '-', $filterName) }}="{{ $filterValue }}"
                    @endforeach
                    class="button !py-2 !text-xs font-semibold">
                Export
            </button>
        @endcan
        @if(auth()->user()?->hasPermission('admission_applications.update'))
            <x-list.dropdown label="Review" size="sm">
                @foreach(['under_review' => 'Under review', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $reviewStatus => $reviewLabel)
                    <x-list.dropdown-item
                        data-bulk-action="review"
                        data-bulk-param-status="{{ $reviewStatus }}"
                        data-confirm="Move the selected applications to {{ strtolower($reviewLabel) }}? Each move must be allowed by the admission workflow; others are skipped and reported. Admission is never done in bulk."
                    >Set → {{ $reviewLabel }}</x-list.dropdown-item>
                @endforeach
            </x-list.dropdown>
        @endif
    </x-list.bulk-selection-bar>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="w-10 py-3"><x-list.select-all /></th>
                    <th class="py-3">Application No</th>
                    <th>Applicant</th>
                    <th>Academic Year</th>
                    <th>Program</th>
                    <th>Enquiry</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($applications as $application)
                    <tr class="border-b">
                        <td class="py-3"><x-list.row-checkbox :id="$application->id" /></td>
                        <td class="py-3 font-medium">{{ $application->application_number }}</td>
                        <td>
                            @if($application->applicant)
                                <span class="font-medium">{{ $application->applicant->first_name }} {{ $application->applicant->last_name }}</span>
                                <p class="text-xs text-slate-500">{{ $application->applicant->email ?? '' }} {{ $application->applicant->phone ?? '' }}</p>
                            @else
                                <span class="text-xs italic text-rose-600">Applicant record missing</span>
                            @endif
                        </td>
                        <td>{{ $application->academicYear?->name ?? '—' }}</td>
                        <td>{{ $application->program?->name ?? '—' }}</td>
                        <td class="text-xs">{{ $application->enquiry?->enquiry_number ?? '—' }}</td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                                @if($application->status === 'draft') bg-slate-200 text-slate-700
                                @elseif($application->status === 'submitted') bg-blue-100 text-blue-700
                                @elseif($application->status === 'under_review') bg-amber-100 text-amber-700
                                @elseif($application->status === 'approved') bg-emerald-100 text-emerald-700
                                @elseif($application->status === 'rejected') bg-rose-100 text-rose-700
                                @else bg-slate-800 text-white @endif
                            ">{{ ucfirst(str_replace('_',' ',$application->status)) }}</span>
                        </td>
                        <td class="text-xs">{{ $application->submitted_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('update', $application)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('admission-applications.edit', $application) }}">Edit</a>
                                @endcan
                                @can('delete', $application)
                                    <form method="POST" action="{{ route('admission-applications.destroy', $application) }}" onsubmit="return confirm(@js('Delete application '.$application->application_number.'? This can be undone by an administrator.'))">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-6 text-slate-500" colspan="9">No applications found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $applications->firstItem() ?? 0 }}–{{ $applications->lastItem() ?? 0 }} of {{ $applications->total() }} applications.</p>
        {{ $applications->links() }}
    </div>
</div>
@endsection
