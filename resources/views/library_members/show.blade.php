@extends('layouts.app')

@section('title', 'Library Member')

@section('content')
<div class="space-y-6">
    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="panel-title">{{ $member->member_code }}</h2>
                <p class="panel-subtitle">{{ $member->studentName() }} · @include('library.status', ['status' => $member->status])</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('update', $member)
                    <a class="button" href="{{ route('library-members.edit', $member) }}">Edit</a>
                @endcan
                @if($member->canBorrow())
                    @can('create', App\Models\LibraryTransaction::class)
                        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-transactions.create', ['library_member_id' => $member->id]) }}">Issue a book</a>
                    @endcan
                @endif
                @can('delete', $member)
                    <form method="POST" action="{{ route('library-members.destroy', $member) }}" onsubmit="return confirm('Delete membership {{ $member->member_code }}?');">
                        @csrf
                        @method('DELETE')
                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                    </form>
                @endcan
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('library-members.index') }}">Back</a>
            </div>
        </div>

        <dl class="mt-6 grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-slate-500">Student</dt>
                <dd>{{ $member->studentName() }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Student number</dt>
                <dd class="font-mono">{{ $member->studentEnrollment?->student?->student_number ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Enrollment</dt>
                <dd>{{ $member->studentEnrollment?->enrollment_number ?? '—' }} ({{ ucfirst($member->studentEnrollment?->status ?? '—') }})</dd>
            </div>
            <div>
                <dt class="text-slate-500">Academic year / program</dt>
                <dd>{{ $member->studentEnrollment?->academicYear?->name ?? '—' }} · {{ $member->studentEnrollment?->program?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Membership date</dt>
                <dd>{{ $member->membership_date?->format('d M Y') }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Expiry date</dt>
                <dd>
                    {{ $member->expiry_date?->format('d M Y') ?? '—' }}
                    @if($member->isPastExpiry())
                        <span class="ml-1 text-xs font-semibold text-amber-700">Past expiry</span>
                    @endif
                </dd>
            </div>
        </dl>

        <div class="mt-6">
            <h3 class="font-semibold">Remarks</h3>
            <p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $member->remarks ?: 'No remarks.' }}</p>
        </div>

        <div class="mt-6 border-t border-slate-200 pt-4 text-xs text-slate-500">
            Recorded {{ $member->created_at?->format('d M Y, H:i') }}@if($member->creator) by {{ $member->creator->name }}@endif
        </div>
    </div>

    <div class="panel">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold">Circulation</h3>
                <p class="panel-subtitle">Issues recorded against this membership. History is not deleted.</p>
            </div>
            @can('viewAny', App\Models\LibraryTransaction::class)
                <a class="text-sm text-indigo-600 hover:underline" href="{{ route('library-transactions.index', ['library_member_id' => $member->id]) }}">Open in Issue / Return</a>
            @endcan
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-2">Copy</th>
                        <th>Issued</th>
                        <th>Due</th>
                        <th>Returned</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($member->transactions as $transaction)
                        <tr class="border-b">
                            <td class="py-2">
                                @can('view', $transaction)
                                    <a class="text-indigo-700 hover:underline" href="{{ route('library-transactions.show', $transaction) }}">{{ $transaction->bookCopy?->label() ?? 'Copy' }}</a>
                                @else
                                    {{ $transaction->bookCopy?->accession_number ?? 'Copy' }}
                                @endcan
                            </td>
                            <td>{{ $transaction->issued_on?->format('d M Y') }}</td>
                            <td>{{ $transaction->due_on?->format('d M Y') }}</td>
                            <td>{{ $transaction->returned_on?->format('d M Y') ?? '—' }}</td>
                            <td>@include('library.status', ['status' => $transaction->status])</td>
                        </tr>
                    @empty
                        <tr><td class="py-4 text-slate-500" colspan="5">No issues recorded for this member.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
