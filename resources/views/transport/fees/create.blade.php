@extends('layouts.app')
@section('title', 'Assign Transport Fee')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Assign transport fee</h2>
    <p class="panel-subtitle">Charges an existing ACTIVE transport assignment under an existing transport fee structure. The amount is snapshotted from the structure on the server — it is never typed in.</p>
    @if($errors->any())<div class="alert-error mt-4"><ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <form method="POST" action="{{ route('transport-fees.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        <label class="block text-sm font-medium text-slate-700 sm:col-span-2">
            Student transport assignment *
            <select class="input mt-1" name="student_transport_assignment_id" required>
                <option value="">— Select active transport assignment —</option>
                @foreach($transportAssignments as $transport)
                    <option value="{{ $transport->id }}" @selected((int) old('student_transport_assignment_id') === (int) $transport->id)>
                        {{ $transport->studentEnrollment?->student?->student_number }} — {{ $transport->studentEnrollment?->student?->first_name }} {{ $transport->studentEnrollment?->student?->last_name }}
                        · {{ $transport->transportRoute?->name }} / {{ $transport->transportStop?->name }} · {{ $transport->academicYear?->name }}
                    </option>
                @endforeach
            </select>
            @error('student_transport_assignment_id')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block text-sm font-medium text-slate-700">
            Transport fee structure *
            <select class="input mt-1" name="transport_fee_structure_id" required>
                <option value="">— Select fee structure —</option>
                @foreach($structures as $structure)
                    <option value="{{ $structure->id }}" @selected((int) old('transport_fee_structure_id') === (int) $structure->id)>{{ $structure->name }} ({{ $structure->code }}) — {{ number_format((float) $structure->amount, 2) }}</option>
                @endforeach
            </select>
            @error('transport_fee_structure_id')<span class="text-red-600">{{ $message }}</span>@enderror
            <span class="mt-1 block text-xs text-slate-500">The structure must match the assignment's academic year (and route/stop when narrowed).</span>
        </label>
        <div class="rounded-lg border border-dashed border-slate-300 p-3 text-sm text-slate-600">
            <span class="font-semibold">Amount:</span> snapshotted server-side from the selected structure at save time. Later structure edits never re-price this student.
        </div>
        <label class="block text-sm font-medium text-slate-700">
            Effective from *
            <input class="input mt-1" type="date" name="effective_from" value="{{ old('effective_from', now()->format('Y-m-d')) }}" required>
            @error('effective_from')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block text-sm font-medium text-slate-700">
            Effective until
            <input class="input mt-1" type="date" name="effective_until" value="{{ old('effective_until') }}">
            @error('effective_until')<span class="text-red-600">{{ $message }}</span>@enderror
            <span class="mt-1 block text-xs text-slate-500">Leave empty for open-ended. Active periods may not overlap on the same transport assignment.</span>
        </label>
        <label class="block text-sm font-medium text-slate-700">
            Status *
            <select class="input mt-1" name="status" required>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
            @error('status')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <label class="block text-sm font-medium text-slate-700 sm:col-span-2">
            Remarks
            <textarea class="input mt-1" name="remarks" maxlength="2000" rows="2">{{ old('remarks') }}</textarea>
            @error('remarks')<span class="text-red-600">{{ $message }}</span>@enderror
        </label>
        <div class="flex gap-2 sm:col-span-2">
            <button class="button" type="submit">Assign transport fee</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('transport-fees.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
