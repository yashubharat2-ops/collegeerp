@extends('layouts.app')

@section('title', 'Record Collection')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Record Fee Collection</h2>
            <p class="panel-subtitle">A collection is recorded against a fee assignment. The payment number is generated server-side and the amount can never exceed the outstanding balance.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-collections.index') }}">Back</a>
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
        <form class="mt-6 grid gap-3 rounded-2xl border border-dashed border-slate-300 p-4 sm:grid-cols-[1fr_auto]" method="GET" action="{{ route('fee-collections.create') }}">
            <div>
                <label class="label" for="student_fee_assignment_id">Fee Assignment</label>
                <select class="input" id="student_fee_assignment_id" name="student_fee_assignment_id" required>
                    <option value="">Select the fee assignment to collect against</option>
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

    <form method="POST" action="{{ route('fee-collections.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @include('fee_collections._form', [
            'ledger' => isset($assignment) && $assignment
                ? app(App\Domain\Finance\Services\FeeDuesService::class)->summaryFor($assignment)
                : null,
        ])
        <div class="sm:col-span-2 flex gap-2">
            <button class="button" type="submit" @disabled(! isset($assignment) || ! $assignment)>Save collection</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-collections.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
