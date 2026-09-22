@extends('layouts.app')

@section('title', 'Library Members')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Library Members</h2>
            <p class="panel-subtitle">Memberships for the active college. Each member is an existing student enrollment — names and student numbers are not stored again here.</p>
        </div>
        @can('create', App\Models\LibraryMember::class)
            <a class="button" href="{{ route('library-members.create') }}">+ Add member</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-3" method="GET" action="{{ route('library-members.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Code, student name or enrollment">
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <div class="flex gap-2">
                <select class="input" id="status" name="status">
                    <option value="">All statuses</option>
                    @foreach($statuses as $statusOption)
                        <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ ucfirst($statusOption) }}</option>
                    @endforeach
                </select>
                <button class="button" type="submit">Filter</button>
            </div>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Code</th>
                    <th>Student</th>
                    <th>Enrollment</th>
                    <th>Membership</th>
                    <th>Expiry</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($members as $member)
                    <tr class="border-b">
                        <td class="py-2"><a class="font-mono text-xs font-medium text-indigo-700 hover:underline" href="{{ route('library-members.show', $member) }}">{{ $member->member_code }}</a></td>
                        <td>
                            <div class="font-medium">{{ $member->studentName() }}</div>
                            <div class="text-xs text-slate-500">{{ $member->studentEnrollment?->student?->student_number ?? '—' }}</div>
                        </td>
                        <td>
                            <div>{{ $member->studentEnrollment?->enrollment_number ?? '—' }}</div>
                            <div class="text-xs text-slate-500">{{ $member->studentEnrollment?->academicYear?->name ?? '—' }} · {{ $member->studentEnrollment?->program?->name ?? '—' }}</div>
                        </td>
                        <td>{{ $member->membership_date?->format('d M Y') }}</td>
                        <td>{{ $member->expiry_date?->format('d M Y') ?? '—' }}</td>
                        <td>@include('library.status', ['status' => $member->status])</td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('view', $member)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-members.show', $member) }}">View</a>
                                @endcan
                                @can('update', $member)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-members.edit', $member) }}">Edit</a>
                                @endcan
                                @can('delete', $member)
                                    <form method="POST" action="{{ route('library-members.destroy', $member) }}" onsubmit="return confirm('Delete membership {{ $member->member_code }}? Members with circulation history cannot be deleted.');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="7">No library members yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $members->links() }}</div>
</div>
@endsection
