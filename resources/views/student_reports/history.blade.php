@extends('layouts.app')
@section('title', 'Student History Report')
@section('content')
<div class="print-area space-y-5">
    <div class="panel">
        <h2 class="panel-title">Student History Report</h2>
        <p class="panel-subtitle">{{ $student->student_number }} · {{ $student->fullName() }} · {{ ucfirst($student->status) }}</p>
        @include('student_reports._navigation', ['selected' => 'history'])
        <div class="no-print mt-4 flex gap-4 text-sm font-semibold text-indigo-700">
            <a class="hover:underline" href="{{ route('student-reports.index', ['report' => 'history']) }}">Choose another student</a>
            <a class="hover:underline" href="{{ route('student-reports.profile', $student) }}">Profile report</a>
        </div>
    </div>
    <div class="panel">
        <h3 class="panel-title">Lifecycle timeline</h3>
        <p class="panel-subtitle">Derived from the existing admission, student, enrollment, academic, promotion, transfer and document records and the append-only audit log. Oldest first; no separate history records are created.</p>
        <form method="GET" action="{{ route('student-reports.history', $student) }}" class="no-print mt-5 grid gap-3 border-t border-slate-200 pt-5 sm:grid-cols-4">
            <label class="text-sm text-slate-700">Event category
                <select class="input mt-1" name="category">
                    <option value="">All categories</option>
                    @foreach(['admission', 'student', 'enrollment', 'academic', 'promotion', 'transfer', 'document', 'audit'] as $category)
                        <option value="{{ $category }}" @selected(($filters['category'] ?? null) === $category)>{{ ucfirst($category) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm text-slate-700">Event date from
                <input class="input mt-1" type="date" name="from" value="{{ $filters['from'] ?? '' }}">
            </label>
            <label class="text-sm text-slate-700">Event date to
                <input class="input mt-1" type="date" name="to" value="{{ $filters['to'] ?? '' }}">
            </label>
            <div class="flex items-end gap-3"><button type="submit" class="button">Filter</button><a class="pb-3 text-sm font-semibold text-indigo-700 hover:underline" href="{{ route('student-reports.history', $student) }}">Clear</a></div>
        </form>
        @include('student_history._timeline', ['events' => $events])
        @include('student_reports._pagination', ['rows' => $events, 'subject' => 'events'])
    </div>
</div>
@endsection
