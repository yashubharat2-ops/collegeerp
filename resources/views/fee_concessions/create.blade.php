@extends('layouts.app')

@section('title', 'Add Concession')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Add Fee Concession</h2>
            <p class="panel-subtitle">Raise a discount against a student fee assignment. The money amount is calculated on the server.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-concessions.index') }}">Back</a>
    </div>

    @if($errors->any())
        <div class="alert-error mt-4">
            <ul class="list-inside list-disc space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(! isset($assignment) || ! $assignment)
        <form class="mt-6 grid gap-3 rounded-2xl border border-dashed border-slate-300 p-4 sm:grid-cols-[1fr_auto]" method="GET" action="{{ route('fee-concessions.create') }}">
            <div>
                <label class="label" for="student_fee_assignment_id">Fee Assignment</label>
                <select class="input" id="student_fee_assignment_id" name="student_fee_assignment_id" required>
                    <option value="">Select the fee assignment</option>
                    @foreach($assignments as $option)
                        <option value="{{ $option->id }}" @selected((int) ($selectedAssignmentId ?? 0) === $option->id)>
                            {{ $option->studentEnrollment?->student?->fullName() ?? '—' }} · {{ $option->studentEnrollment?->enrollment_number ?? '—' }} · {{ $option->feeStructure?->name ?? '—' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <button class="button !bg-slate-200 !text-slate-700" type="submit">Load assignment</button>
            </div>
        </form>
    @endif

    <form method="POST" action="{{ route('fee-concessions.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @include('fee_concessions._form')
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit" @disabled(! isset($assignment) || ! $assignment)>Save concession</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-concessions.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
