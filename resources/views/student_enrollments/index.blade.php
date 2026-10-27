@extends('layouts.app')
@section('title','Enrollments')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Enrollments</h2>
            <p class="panel-subtitle">
                Periodic academic-year enrollments. A new enrollment is
                <strong>Pending approval</strong> when the college requires it, and only an approved one is
                <strong>Active</strong>. Past enrollments are preserved as history when a student moves to a new academic year.
            </p>
        </div>
        @can('create', App\Models\StudentEnrollment::class)
            <a class="button" href="{{ route('student-enrollments.create') }}">+ New enrollment</a>
        @endcan
    </div>

    <x-student-filter
        :fields="$studentFilterFields"
        :options="$studentFilterOptions"
        :values="$studentFilterValues"
        :applied="$studentFilterApplied"
        :identity-fields="$studentFilterIdentityFields"
        :identity-action="$studentFilterIdentityAction"
        :identity-return-to="$studentFilterIdentityReturnTo"
        :identity-active="$studentFilterIdentityActive"
        :identity-token="$studentFilterIdentityToken"
        :action="route('student-enrollments.index')"
        :preserve="['student_id' => request('student_id')]"
        title="Filter Students for Enrollments"
    />

    <x-list.bulk-selection-bar module="enrollments">
        @can('viewAny', App\Models\StudentEnrollment::class)
            <button type="button" data-bulk-action="export"
                    class="button !py-2 !text-xs font-semibold">
                Export
            </button>
        @endcan
        @if(auth()->user()?->hasPermission('student_enrollments.update'))
            <x-list.dropdown label="Change status" size="sm">
                @foreach(\App\Models\StudentEnrollment::MANUAL_STATUSES as $enrollmentStatus)
                    <x-list.dropdown-item
                        data-bulk-action="change_status"
                        data-bulk-param-status="{{ $enrollmentStatus }}"
                        data-confirm="Set the selected enrollments to {{ $enrollmentStatus }}? Completed, cancelled, or withdrawn enrollments cannot be reactivated; nothing is written if any row cannot be updated."
                    >Change Status → {{ ucfirst($enrollmentStatus) }}</x-list.dropdown-item>
                @endforeach
            </x-list.dropdown>
            {{-- Section assignment: the server re-checks tenant, academic year and program
                 for every selected enrollment and changes nothing if any does not match. --}}
            <select class="input !py-1 !text-xs" data-bulk-input="section_id" aria-label="Target section for the selected enrollments">
                <option value="">Choose section…</option>
                @foreach($sections as $bulkSection)
                    <option value="{{ $bulkSection->id }}">{{ $bulkSection->name }} — {{ $bulkSection->academicYear?->name ?? '—' }} / {{ $bulkSection->program?->name ?? '—' }}</option>
                @endforeach
            </select>
            <button type="button" data-bulk-action="assign_section" data-bulk-inputs="section_id"
                    class="button !py-2 !text-xs font-semibold !bg-indigo-700 hover:!bg-indigo-800"
                    data-confirm="Assign the selected enrollments to this section? Every selected enrollment must already belong to the section's academic year and program. If any does not, nothing is changed.">
                Assign section
            </button>
        @endif
    </x-list.bulk-selection-bar>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="w-10 py-3"><x-list.select-all /></th>
                    <th class="py-3">Enrollment no.</th>
                    <th>Student ID — name</th>
                    <th>Academic year</th>
                    <th>Program</th>
                    <th>Section</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($enrollments as $enrollment)
                    <tr class="border-b">
                        <td class="py-3"><x-list.row-checkbox :id="$enrollment->id" /></td>
                        <td class="font-medium">{{ $enrollment->enrollment_number }}</td>
                        <td>{{ $enrollment->student?->student_number }} — {{ $enrollment->student?->first_name }} {{ $enrollment->student?->last_name }}</td>
                        <td>{{ $enrollment->academicYear?->name ?? '—' }}</td>
                        <td>{{ $enrollment->program?->name ?? '—' }}</td>
                        <td>{{ $enrollment->section?->name ?? '—' }}</td>
                        <td>{{ $enrollment->enrollment_date?->format('d M Y') ?? '—' }}</td>
                        <td>
                            @php
                                $statusClasses = match ($enrollment->status) {
                                    \App\Models\StudentEnrollment::STATUS_ACTIVE => 'bg-emerald-100 text-emerald-700',
                                    \App\Models\StudentEnrollment::STATUS_PENDING_APPROVAL => 'bg-amber-100 text-amber-800',
                                    \App\Models\StudentEnrollment::STATUS_REJECTED => 'bg-rose-100 text-rose-700',
                                    default => 'bg-slate-200 text-slate-600',
                                };
                            @endphp
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusClasses }}">
                                {{ $enrollment->statusLabel() }}
                            </span>
                            @if($enrollment->isPendingApproval())
                                @can('approve', $enrollment)
                                    <a class="ml-1 text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('approvals.pending') }}">Review</a>
                                @endcan
                            @endif
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('update', $enrollment)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('student-enrollments.edit', $enrollment) }}">Edit</a>
                                @endcan
                                @can('delete', $enrollment)
                                    <form method="POST" action="{{ route('student-enrollments.destroy', $enrollment) }}" onsubmit="return confirm(@js('Delete enrollment '.$enrollment->enrollment_number.'? This can be undone by an administrator.'))">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-6 text-slate-500" colspan="9">No enrollments found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $enrollments->firstItem() ?? 0 }}–{{ $enrollments->lastItem() ?? 0 }} of {{ $enrollments->total() }} enrollments.</p>
        {{ $enrollments->links() }}
    </div>
</div>
@endsection
