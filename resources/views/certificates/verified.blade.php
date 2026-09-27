@extends('layouts.app')
@section('title', 'Certificate Verification')
@section('content')
@include('certificates._navigation')
<div class="panel"><h2 class="panel-title">Verified — issued by this college</h2><p>{{ $certificate->number }} · {{ $certificate->data_snapshot['certificate_type'] }}</p><p>{{ $certificate->data_snapshot['student_name'] }} · {{ $certificate->data_snapshot['student_number'] }}</p><p>Issued {{ $certificate->issued_at?->toDateString() }} · Verified {{ $certificate->last_verified_at }}</p></div>
@endsection
