@extends('layouts.app')
@section('title', ($type?->name ?? 'Certificate Management (EC)').' — '.ucfirst($stage))
@section('content')
@include('certificates._navigation')
<div class="panel mb-6">
    <h2 class="panel-title">{{ $type?->name ?? 'All certificate types' }}</h2>
    <p class="panel-subtitle">Requests → Generation → Issuance → Verification</p>
    <div class="flex flex-wrap gap-4 my-4">
        @foreach(['requests', 'generation', 'issuance', 'verification'] as $step)
            <a @if($stage === $step) aria-current="page" class="font-bold" @endif href="{{ route('certificates.'.$step.'.index', ['type' => $type?->code]) }}">{{ ucfirst($step) }}</a>
        @endforeach
    </div>
    <form method="GET" class="flex gap-3">
        <input type="hidden" name="stage" value="{{ $stage }}">
        <label>Certificate type <select name="type" class="input"><option value="">All types</option>@foreach($types as $option)<option value="{{ $option->code }}" @selected($type?->id === $option->id)>{{ $option->name }}</option>@endforeach</select></label>
        <button class="button">Filter</button>
    </form>
</div>
@if($stage === 'requests' && auth()->user()->hasPermission('certificates.request'))
<div class="panel mb-6">
    <h2 class="panel-title">New certificate request</h2>
    <form method="POST" action="{{ route('certificates.store') }}" class="space-y-4">
        @csrf
        <label class="block">Certificate type<select class="input" name="certificate_type_id" required>@foreach($types as $option)<option value="{{ $option->id }}" @selected(old('certificate_type_id', $type?->id) == $option->id)>{{ $option->name }}</option>@endforeach</select></label>
        <label class="block">Student / Enrollment<select class="input" name="student_enrollment_id" required><option value="">Select enrollment</option>@foreach($enrollments as $enrollment)<option value="{{ $enrollment->id }}" @selected(old('student_enrollment_id') == $enrollment->id)>{{ $enrollment->student?->fullName() }} — {{ $enrollment->enrollment_number }} — {{ $enrollment->program?->name }}</option>@endforeach</select></label>
        <label class="block">Approved student transfer (required only for TC)<select class="input" name="student_transfer_id"><option value="">Not applicable</option>@foreach($transfers as $transfer)<option value="{{ $transfer->id }}" @selected(old('student_transfer_id') == $transfer->id)>#{{ $transfer->id }} — {{ $transfer->student?->fullName() }} — {{ $transfer->transfer_date?->toDateString() }}</option>@endforeach</select></label>
        <p class="text-sm text-slate-500">For TC, use an approved transfer with the same enrollment. Transfer approval remains in Student Management.</p>
        <label class="block">Purpose<textarea class="input" name="purpose" maxlength="2000">{{ old('purpose') }}</textarea></label>
        <button class="button">Submit request</button>
    </form>
</div>
@endif
@if($stage === 'verification' && auth()->user()->hasPermission('certificates.verify'))
<div class="panel mb-6">
    <h2 class="panel-title">Verify an issued certificate</h2>
    <form method="POST" action="{{ route('certificates.verify') }}" class="flex gap-3">@csrf<label>Certificate number<input class="input" name="number" required maxlength="100" value="{{ old('number') }}"></label><button class="button">Verify</button></form>
    <p class="text-sm text-slate-500">Verification is restricted to your active college and recorded in the audit trail.</p>
</div>
@endif
<div class="panel overflow-x-auto">
<table class="w-full text-left"><thead><tr><th>Request</th><th>Type</th><th>Student</th><th>Status</th><th>Number</th><th></th></tr></thead><tbody>
@forelse($certificates as $certificate)<tr class="border-t"><td class="py-3">#{{ $certificate->id }}</td><td>{{ $certificate->type?->name }}</td><td>{{ $certificate->student?->fullName() }}</td><td>{{ ucfirst($certificate->status) }}</td><td>{{ $certificate->number ?? 'Not issued' }}</td><td><a href="{{ route('certificates.show', $certificate) }}">Open / {{ $stage === 'generation' ? 'Generate' : ($stage === 'issuance' ? 'Issue' : 'Review') }}</a></td></tr>
@empty<tr><td colspan="6" class="py-6">No certificates at this stage.</td></tr>@endforelse
</tbody></table>{{ $certificates->links() }}
</div>
@endsection
