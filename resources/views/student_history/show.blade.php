@extends('layouts.app')
@section('title','Student History')
@section('content')
<div class="panel max-w-4xl">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">{{ $student->fullName() }}</h2>
            <p class="panel-subtitle">
                {{ $student->student_number }} · {{ ucfirst($student->status) }}
                @if($student->enrollments->isNotEmpty())
                    · {{ $student->enrollments->count() }} enrollment(s)
                @endif
            </p>
        </div>
        <div class="flex gap-2">
            <a class="button !px-3 !py-2 text-xs" href="{{ route('students.show', ['student' => $student, 'tab' => 'history']) }}">Student profile</a>
            <a class="button !px-3 !py-2 text-xs !bg-slate-200 !text-slate-700" href="{{ route('student-history.index') }}">Back to history</a>
        </div>
    </div>

    <p class="mt-4 text-xs text-slate-500">
        Ordered oldest first. Events come from the records that own them plus the append-only audit log, so the timeline
        is deterministic and always consistent with the underlying modules.
    </p>

    @include('student_history._timeline', ['events' => $events])
</div>
@endsection
