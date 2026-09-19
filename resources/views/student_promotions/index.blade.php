@extends('layouts.app')
@section('title','Student Promotion')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Student Promotion</h2>
            <p class="panel-subtitle">
                Record a promotion request, then approve it. Approval creates the new enrollment and marks the previous
                one completed — history is always preserved. No "1st year becomes 2nd year" rule is built in: the target
                year, program, term and section are your choice.
            </p>
        </div>
        @can('create', App\Models\StudentPromotion::class)
            <a class="button" href="{{ route('student-promotions.create') }}">+ New promotion</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('student-promotions.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <select class="input" name="student_id">
            <option value="">All students</option>
            @foreach($students as $s)
                <option value="{{ $s->id }}" @selected((string) $student_id === (string) $s->id)>{{ $s->student_number }} — {{ $s->first_name }} {{ $s->last_name }}</option>
            @endforeach
        </select>
        <select class="input" name="status">
            <option value="">All statuses</option>
            @foreach(App\Models\StudentPromotion::STATUSES as $option)
                <option value="{{ $option }}" @selected($status === $option)>{{ ucfirst($option) }}</option>
            @endforeach
        </select>
        <select class="input" name="target_academic_year_id">
            <option value="">All target years</option>
            @foreach($academicYears as $ay)
                <option value="{{ $ay->id }}" @selected((string) $target_academic_year_id === (string) $ay->id)>{{ $ay->name }} ({{ $ay->code }})</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($student_id || $status || $target_academic_year_id)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-promotions.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Student</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Section</th>
                    <th>Status</th>
                    <th>Requested</th>
                    <th>Approved</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($promotions as $promotion)
                <tr class="border-b">
                    <td class="py-3">
                        <a class="font-medium text-indigo-600 hover:underline" href="{{ route('students.show', ['student' => $promotion->student_id, 'tab' => 'promotion']) }}">{{ $promotion->student?->student_number }}</a>
                        <span class="block text-xs text-slate-500">{{ $promotion->student?->fullName() }}</span>
                    </td>
                    <td class="text-xs">
                        {{ $promotion->sourceAcademicYear?->name ?? '—' }}
                        <span class="block text-slate-500">{{ $promotion->sourceEnrollment?->enrollment_number ?? '' }}</span>
                    </td>
                    <td class="text-xs">
                        {{ $promotion->targetAcademicYear?->name ?? '—' }}
                        @if($promotion->targetProgram)<span class="block text-slate-500">{{ $promotion->targetProgram->name }}</span>@endif
                    </td>
                    <td>{{ $promotion->targetSection?->name ?? '—' }}</td>
                    <td>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                            @if($promotion->isPending()) bg-amber-100 text-amber-700
                            @elseif($promotion->isApproved()) bg-emerald-100 text-emerald-700
                            @else bg-slate-200 text-slate-600 @endif">{{ ucfirst($promotion->status) }}</span>
                        @if($promotion->targetEnrollment)
                            <span class="block text-xs text-slate-500">{{ $promotion->targetEnrollment->enrollment_number }}</span>
                        @endif
                    </td>
                    <td class="text-xs">{{ $promotion->created_at?->format('d M Y H:i') }}</td>
                    <td class="text-xs">{{ $promotion->approved_at?->format('d M Y H:i') ?? '—' }}</td>
                    <td class="text-right">
                        @if($promotion->isPending())
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @can('approve', $promotion)
                                    <form class="inline" method="POST" action="{{ route('student-promotions.approve', $promotion) }}"
                                          onsubmit="return confirm(@js('Approve promotion for '.$promotion->student?->fullName().'? A new enrollment is created and the current one is marked completed.'))">
                                        @csrf
                                        <button class="text-xs font-semibold text-emerald-600 hover:underline" type="submit">Approve</button>
                                    </form>
                                    <form class="inline" method="POST" action="{{ route('student-promotions.cancel', $promotion) }}"
                                          onsubmit="return confirm(@js('Cancel this pending promotion for '.$promotion->student?->fullName().'?'))">
                                        @csrf
                                        <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Cancel</button>
                                    </form>
                                @endcan
                            </div>
                        @else
                            <span class="text-xs text-slate-400">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="py-6 text-slate-500" colspan="8">No promotions recorded.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $promotions->firstItem() ?? 0 }}–{{ $promotions->lastItem() ?? 0 }} of {{ $promotions->total() }} promotions.</p>
        {{ $promotions->links() }}
    </div>
</div>
@endsection
