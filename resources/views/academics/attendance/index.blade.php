@extends('layouts.app')

@section('title', 'Attendance')

@section('content')
<p class="mb-5 text-slate-500">Marking and corrections are tenant-safe; saving an existing student, subject, date and term updates the record.</p>

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
    :action="route('academic-attendance.index')"
    title="Filter Student Attendance"
/>

<div data-bulk-scope>
    <x-list.bulk-selection-bar module="academic_attendance">
        @if(auth()->user()?->hasPermission('academic_attendance.view'))
            <button
                type="button"
                data-bulk-action="export"
                @foreach($studentFilterParameters as $filterName => $filterValue)
                    data-bulk-param-{{ str_replace('_', '-', $filterName) }}="{{ $filterValue }}"
                @endforeach
                class="button !py-2 !text-xs font-semibold"
            >Export selected</button>
        @endif
    </x-list.bulk-selection-bar>
    <div class="card overflow-x-auto">
        <table class="table">
            <tr>
                <th class="w-10"><x-list.select-all /></th>
                <th>Date</th>
                <th>Student</th>
                <th>Subject</th>
                <th>Section</th>
                <th>Status</th>
            </tr>
            @forelse($items as $i)
                <tr>
                    <td><x-list.row-checkbox :id="$i->id" /></td>
                    <td>{{ $i->attendance_date->format('d M Y') }}</td>
                    <td>
                        {{ $i->student?->fullName() }}
                        <p class="text-xs text-slate-500">{{ $i->student?->student_number }}</p>
                    </td>
                    <td>{{ $i->subject?->name }}</td>
                    <td>{{ $i->section?->name }}</td>
                    <td>{{ $i->status }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No attendance records.</td></tr>
            @endforelse
        </table>
        {{ $items->links() }}
    </div>
</div>
@endsection
