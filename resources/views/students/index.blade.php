@extends('layouts.app')
@section('title','Students')
@section('content')
@php
    /*
     * Every filter/sort/pagination decision is made server-side by
     * StudentListService (search + whitelisted filters through the shared
     * ListQueryBuilder); this view only renders the state it is given.
     * $listContext carries the active filter set, the sort state and the URLs
     * that keep both while paging.
     */
    /*
     * A single-value read is normalised once here: a malformed query string
     * (e.g. `?department_id[]=nested`) delivers an array where a control expects
     * one value, and casting that to string is fatal. Such a value reads as "no
     * filter" — the server already ignored it, so no option may claim it is
     * selected and the page must not crash.
     */
    $scalar = static fn ($value): string => is_scalar($value) ? (string) $value : '';
    $hasFilters = $listContext->hasActiveFilters();
    $clearUrl = $listContext->clearFiltersUrl();
    $activeSort = $scalar($listContext->filter('sort'));
    $activeDirection = $scalar($listContext->filter('direction')) ?: 'asc';
    $colspan = 9;
@endphp

<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Students</h2>
            <p class="panel-subtitle">Officially enrolled students within the active college. Each student accumulates an enrollment per academic year.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            {{-- Export honours the filters below (same pipeline as the table), so a
                 filtered list can be taken away as-is. --}}
            @if($canExport ?? false)
                <a class="button !bg-slate-700 hover:!bg-slate-800"
                   href="{{ route('students.export', request()->query()) }}">
                    Export {{ $hasFilters ? 'filtered list' : 'list' }}
                </a>
            @endif
            @can('create', App\Models\Student::class)
                <a class="button" href="{{ route('students.create') }}">+ New student</a>
            @endcan
        </div>
    </div>

    <form method="GET" action="{{ route('students.index') }}" class="mt-6 rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
        {{-- Re-applying a filter must not silently drop the sort the user chose. --}}
        @if($activeSort !== '')
            <input type="hidden" name="sort" value="{{ $activeSort }}">
            <input type="hidden" name="direction" value="{{ $activeDirection }}">
        @endif

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <div class="sm:col-span-2">
                <x-list.search-input
                    placeholder="Search name, student no., enrollment no., email or mobile"
                    :value="$listContext->filter('search')"
                />
            </div>

            <x-list.filter-select name="academic_year_id" label="Academic year" placeholder="All academic years">
                @foreach($academicYears as $year)
                    <option value="{{ $year->id }}" @selected($scalar($listContext->filter('academic_year_id')) === (string) $year->id)>{{ $year->name }} ({{ $year->code }})</option>
                @endforeach
            </x-list.filter-select>

            <x-list.filter-select name="department_id" label="Department" placeholder="All departments">
                @foreach($departments as $department)
                    <option value="{{ $department->id }}" @selected($scalar($listContext->filter('department_id')) === (string) $department->id)>{{ $department->name }} ({{ $department->code }})</option>
                @endforeach
            </x-list.filter-select>

            <x-list.filter-select name="program_id" label="Program / Course" placeholder="All programs">
                @foreach($programs as $program)
                    <option value="{{ $program->id }}" @selected($scalar($listContext->filter('program_id')) === (string) $program->id)>{{ $program->name }} ({{ $program->code }})</option>
                @endforeach
            </x-list.filter-select>

            <x-list.filter-select name="academic_term_id" label="Academic term / semester" placeholder="All terms">
                @foreach($academicTerms as $term)
                    <option value="{{ $term->id }}" @selected($scalar($listContext->filter('academic_term_id')) === (string) $term->id)>
                        {{ $term->name }} ({{ $term->code }}){{ $term->academicYear ? ' · '.$term->academicYear->name : '' }}
                    </option>
                @endforeach
            </x-list.filter-select>

            <x-list.filter-select name="section_id" label="Section / Batch" placeholder="All sections">
                @foreach($sections as $section)
                    <option value="{{ $section->id }}" @selected($scalar($listContext->filter('section_id')) === (string) $section->id)>
                        {{ $section->name }} ({{ $section->code }}){{ $section->academicYear ? ' · '.$section->academicYear->name : '' }}{{ $section->program ? ' · '.$section->program->name : '' }}
                    </option>
                @endforeach
            </x-list.filter-select>

            <x-list.filter-select name="gender" label="Gender" placeholder="All genders">
                @foreach(\App\Models\Student::GENDERS as $gender)
                    <option value="{{ $gender }}" @selected(in_array($gender, (array) $listContext->filter('gender', []), true))>{{ ucfirst(str_replace('_', ' ', $gender)) }}</option>
                @endforeach
            </x-list.filter-select>

            <x-list.filter-select name="category" label="Category" placeholder="All categories">
                @foreach(\App\Models\Student::CATEGORIES as $category)
                    <option value="{{ $category }}" @selected(in_array($category, (array) $listContext->filter('category', []), true))>{{ \App\Models\Student::categoryLabel($category) }}</option>
                @endforeach
            </x-list.filter-select>

            <x-list.filter-select name="status" label="Student status" placeholder="All statuses">
                @foreach(\App\Models\Student::STATUSES as $status)
                    <option value="{{ $status }}" @selected(in_array($status, (array) $listContext->filter('status', []), true))>{{ ucfirst($status) }}</option>
                @endforeach
            </x-list.filter-select>

            <x-list.filter-select name="enrollment_status" label="Enrollment status" placeholder="All enrollment statuses">
                @foreach(\App\Models\StudentEnrollment::STATUSES as $enrollmentStatus)
                    <option value="{{ $enrollmentStatus }}" @selected(in_array($enrollmentStatus, (array) $listContext->filter('enrollment_status', []), true))>{{ ucfirst($enrollmentStatus) }}</option>
                @endforeach
            </x-list.filter-select>

            <x-list.filter-date-range
                from-name="admission_date_from"
                to-name="admission_date_to"
                label="Admission date"
                :from-value="$listContext->filter('admission_date_from')"
                :to-value="$listContext->filter('admission_date_to')"
            />

            <x-list.filter-actions :clear-url="$clearUrl" :has-filters="$hasFilters" />
        </div>
    </form>

    {{-- Selection bar: rendered inside the panel so the shared script
         (public/js/erp-list.js) finds the table's select-all and row checkboxes,
         keeps the count in sync, shows the indeterminate state, and posts the
         selected ids to the central bulk action endpoint. --}}
    <x-list.bulk-selection-bar module="students">
        {{-- Same live permission check as the two actions below, so the button
             can only ever be hidden by the permission itself: gating it on a
             controller-supplied variable with a `?? false` fallback would hide a
             registered, authorized action with no error whenever that variable
             was absent. The bulk endpoint re-checks `students.export` (and the
             per-record Student policy) on every request regardless. --}}
        @if(auth()->user()?->hasPermission('students.export'))
            <button type="button" data-bulk-action="export"
                    class="button !py-2 !text-xs font-semibold !bg-slate-700 hover:!bg-slate-800">
                Export students
            </button>
        @endif

        @if(auth()->user()?->hasPermission('student_id_cards.generate'))
            <button type="button" data-bulk-action="id_cards"
                    class="button !py-2 !text-xs font-semibold"
                    data-confirm="Generate ID cards for the selected students? Every card is re-checked on the server.">
                Generate ID cards
            </button>
        @endif

        @if(auth()->user()?->hasPermission('student_documents.view'))
            <button type="button" data-bulk-action="documents"
                    class="button !py-2 !text-xs font-semibold !bg-sky-700 hover:!bg-sky-800">
                Bulk documents
            </button>
        @endif
    </x-list.bulk-selection-bar>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="w-10 py-3">
                        <x-list.select-all />
                    </th>
                    <th class="py-3">
                        <x-list.sort-header field="student_number" label="Number" />
                    </th>
                    <th>
                        <x-list.sort-header field="name" label="Name" />
                    </th>
                    <th>Contact</th>
                    <th>
                        <x-list.sort-header field="email" label="Email" />
                    </th>
                    <th>Current enrollment</th>
                    <th>
                        <x-list.sort-header field="admission_date" label="Admission" />
                    </th>
                    <th>
                        <x-list.sort-header field="status" label="Status" />
                    </th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($students as $student)
                    @php
                        $active = $student->currentEnrollment();
                    @endphp
                    <tr class="border-b">
                        <td class="py-3">
                            <x-list.row-checkbox :id="$student->id" />
                        </td>
                        <td class="font-medium">{{ $student->student_number }}</td>
                        <td>
                            <a href="{{ route('students.show', $student) }}" class="font-medium text-indigo-600 hover:underline">
                                {{ $student->fullName() }}
                            </a>
                            <span class="block text-xs text-slate-500">
                                {{ \App\Models\Student::categoryLabel($student->category) }}
                                @if($student->gender) · {{ ucfirst(str_replace('_', ' ', $student->gender)) }} @endif
                            </span>
                        </td>
                        <td>{{ $student->phone ?? '—' }}</td>
                        <td>{{ $student->email ?? '—' }}</td>
                        <td class="text-slate-500">
                            @if($active)
                                {{ $active->enrollment_number }}
                                @if($active->academicYear)
                                    <span class="block text-xs text-slate-500">{{ $active->academicYear->name }}@if($active->program) · {{ $active->program->name }}@endif</span>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-slate-500">{{ $student->admission_date?->format('d M Y') ?? '—' }}</td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $student->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">
                                {{ ucfirst($student->status) }}
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('view', $student)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('students.show', $student) }}">View</a>
                                @endcan
                                @can('update', $student)
                                    <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('students.edit', $student) }}">Edit</a>
                                @endcan
                                @can('delete', $student)
                                    <form method="POST" action="{{ route('students.destroy', $student) }}" onsubmit="return confirm(@js('Delete student '.$student->first_name.' '.$student->last_name.'? This can be undone by an administrator.'))">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-list.empty-state :colspan="$colspan" :has-filters="$hasFilters" :clear-url="$clearUrl" />
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">
            Showing {{ $students->firstItem() ?? 0 }}–{{ $students->lastItem() ?? 0 }} of {{ $students->total() }} students
            @if($hasFilters) (filtered from the full college list) @endif.
        </p>
        {{-- Pagination keeps search, filters and sort: ListQueryBuilder attaches
             the current query string to every page link. --}}
        {{ $students->links() }}
    </div>
</div>
@endsection
