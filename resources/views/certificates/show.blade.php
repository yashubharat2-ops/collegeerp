@extends('layouts.app')
@section('title', $certificate->type->name.' — #'.$certificate->id)
@section('content')
@include('certificates._navigation')
<div class="panel mb-6 no-print">
    <h2 class="panel-title">{{ ucfirst($certificate->status) }} · {{ $certificate->number ?? 'Not numbered until issuance' }}</h2>
    <p>{{ $certificate->student?->fullName() }} · Enrollment {{ $certificate->enrollment?->enrollment_number }}</p>
    @if($certificate->student_transfer_id)<p>Linked StudentTransfer #{{ $certificate->student_transfer_id }} · {{ $certificate->transfer?->status }}</p>@endif
    <p>Requested {{ $certificate->created_at }} · Generated {{ $certificate->generated_at ?? '—' }} · Issued {{ $certificate->issued_at ?? '—' }}</p>
    <p>Verifications: {{ $certificate->verification_count }} · Last verified {{ $certificate->last_verified_at ?? 'Never' }}</p>
    @if($certificate->status === 'requested' && auth()->user()->hasPermission('certificates.generate'))
    <form method="POST" action="{{ route('certificates.generate', $certificate) }}" class="space-y-3 mt-4">@csrf
        <label>Template<select class="input" name="certificate_template_id" required><option value="">Select template</option>@foreach($templates as $template)<option value="{{ $template->id }}">{{ $template->name }}</option>@endforeach</select></label>
        @if($templates->isEmpty())<p>Add a template under Certificate Templates first.</p>@endif
        <button class="button" @disabled($templates->isEmpty())>Generate draft</button>
    </form>
    @endif
    @if($certificate->status === 'generated' && auth()->user()->hasPermission('certificates.issue'))
    <form method="POST" action="{{ route('certificates.issue', $certificate) }}" class="mt-4">@csrf<button class="button">Issue certificate</button><p class="text-sm">Issuance is permanent. TC also completes the linked student transfer; other types do not change student status.</p></form>
    @endif
    @if($certificate->status === 'issued')
    <button type="button" class="button mt-4" onclick="window.print()">Print certificate</button>
    @if(auth()->user()->hasPermission('certificates.verify'))<form method="POST" action="{{ route('certificates.verify') }}" class="mt-4">@csrf<input type="hidden" name="number" value="{{ $certificate->number }}"><button class="button">Verify certificate</button></form>@endif
    @endif
</div>
@if($certificate->generated_at)
<article class="panel">
    <h2 class="panel-title">{{ $certificate->data_snapshot['certificate_type'] }}</h2>
    <p>{{ $certificate->data_snapshot['college_name'] }}</p>
    <p>{{ $certificate->number ?? 'DRAFT — NOT VALID UNTIL ISSUED' }} · {{ $certificate->issued_at?->toDateString() }}</p>
    <div class="whitespace-pre-wrap mt-6">{{ $certificate->renderedBody() }}</div>
</article>
@endif
@endsection
