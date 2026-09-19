@extends('layouts.app')
@section('title','Student History')
@section('content')
<div class="grid gap-6 lg:grid-cols-3">
    <div class="panel lg:col-span-2">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="panel-title">Student History</h2>
                <p class="panel-subtitle">
                    A derived, chronological view of the student lifecycle: admission, student creation, enrollments,
                    academic progression, promotions, transfers/TC, document events and audited status changes.
                    Nothing is duplicated into a history table.
                </p>
            </div>
        </div>

        <form method="GET" action="{{ route('student-history.index') }}" class="mt-6 grid gap-3 sm:grid-cols-[1fr_auto]">
            <select class="input" name="student_id">
                <option value="">— Select a student —</option>
                @foreach($students as $s)
                    <option value="{{ $s->id }}" @selected((string) $student_id === (string) $s->id)>{{ $s->student_number }} — {{ $s->first_name }} {{ $s->last_name }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <button class="button" type="submit">Show history</button>
                @if($student_id)
                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-history.index') }}">Clear</a>
                @endif
            </div>
        </form>

        @if($student)
            <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-sm font-semibold text-slate-800">{{ $student->fullName() }}</p>
                        <p class="text-xs text-slate-500">{{ $student->student_number }} · {{ ucfirst($student->status) }}</p>
                    </div>
                    <div class="flex gap-2">
                        <a class="button !px-3 !py-2 text-xs" href="{{ route('students.show', ['student' => $student, 'tab' => 'history']) }}">Student profile</a>
                        <a class="button !px-3 !py-2 text-xs !bg-slate-200 !text-slate-700" href="{{ route('student-history.show', $student) }}">Full page</a>
                    </div>
                </div>
            </div>

            @include('student_history._timeline', ['events' => $events])
        @else
            <p class="mt-6 text-sm text-slate-500">Select a student to see their full chronological history.</p>
        @endif
    </div>

    <div class="panel">
        <h3 class="panel-title">Recent student activity</h3>
        <p class="panel-subtitle">Newest first, from the append-only audit log for the active college.</p>

        <ul class="mt-4 space-y-3">
            @forelse($recent as $event)
                <li class="border-b border-slate-100 pb-3">
                    <p class="text-sm font-medium text-slate-800">{{ $event->label }}</p>
                    <p class="text-xs text-slate-500">{{ $event->occurredAtTime() }}</p>
                    <p class="text-xs text-slate-600">{{ $event->description }}</p>
                </li>
            @empty
                <li class="text-sm text-slate-500">No student activity recorded yet.</li>
            @endforelse
        </ul>
    </div>
</div>
@endsection
