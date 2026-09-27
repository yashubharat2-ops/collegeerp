@extends('layouts.app')
@section('title','Certificate Verification')
@section('content')
<div class="panel"><h2 class="panel-title">Certificate Verification</h2><p class="panel-subtitle">Verify a certificate number against the current college's issuance records.</p><form class="mt-5 flex max-w-xl gap-3" method="GET" action="{{ route('certificates.verification.index') }}"><input class="input" name="number" value="{{ $number }}" placeholder="Certificate number" required><button class="button">Verify</button></form>
@if($number !== '')<div class="mt-6 rounded-xl {{ $certificate ? 'bg-emerald-50' : 'bg-rose-50' }} p-5">@if($certificate)<h3 class="font-semibold text-emerald-800">Valid certificate</h3><p class="mt-2">{{ $certificate instanceof \App\Models\StudentTransfer ? 'Transfer Certificate (TC)' : \App\Domain\Certificates\CertificateTypes::label($certificate->type) }}</p><p>{{ $certificate->tc_number ?? $certificate->certificate_number }}</p><p>{{ $certificate->student?->student_number }} — {{ $certificate->student?->fullName() }}</p>@else<p class="font-semibold text-rose-800">No issued certificate found for {{ $number }}.</p>@endif</div>@endif</div>
@endsection
