@extends('layouts.app')

@section('title', 'Student Result History')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Student Result History</h2>
            <p class="panel-subtitle">
                Each student's published examination timeline across academic years and terms. Only published results ever appear in a timeline.
            </p>
        </div>
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
        :action="route('student-result-history.index')"
        title="Filter Students for Result History"
    >
        <div class="erp-list-field erp-list-filter-span-2">
            <label for="filter-history-search" class="mb-1 block text-xs font-semibold text-slate-700">Search students</label>
            <input id="filter-history-search" class="input !py-2 !text-xs" type="search" name="search" value="{{ $search }}" placeholder="Number or name">
        </div>
    </x-student-filter>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Student</th>
                    <th>Current enrollment</th>
                    <th>Academic year</th>
                    <th>Program</th>
                    <th>Section</th>
                    <th class="text-right">History</th>
                </tr>
            </thead>
            <tbody>
                @forelse($students as $student)
                    @php($current = $student->currentEnrollment())
                    <tr class="border-b">
                        <td class="py-3">
                            <span class="font-medium">{{ $student->student_number }}</span>
                            <span class="block text-xs text-slate-500">{{ $student->fullName() }}</span>
                        </td>
                        <td>{{ $current?->enrollment_number ?? '—' }}</td>
                        <td>{{ $current?->academicYear?->name ?? '—' }}</td>
                        <td>{{ $current?->program?->name ?? '—' }}</td>
                        <td>{{ $current?->section?->name ?? '—' }}</td>
                        <td class="text-right">
                            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-result-history.show', $student) }}">View history</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="py-6 text-slate-500" colspan="6">No students found for the selected filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $students->firstItem() ?? 0 }}–{{ $students->lastItem() ?? 0 }} of {{ $students->total() }} students.</p>
        {{ $students->links() }}
    </div>
</div>
@endsection
