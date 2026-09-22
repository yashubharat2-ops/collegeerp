@extends('layouts.app')
@section('title', 'Edit Vehicle Document')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Edit document: {{ $document->typeLabel() }} — {{ $document->vehicle?->registration_number ?? '—' }}</h2>
    <p class="panel-subtitle">Metadata can be corrected at any time; replacing the file keeps the previous path in the audit history.</p>
    @if($errors->any())<div class="alert-error mt-4">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('vehicle-documents.update', $document->id) }}" enctype="multipart/form-data">
        @method('PUT')
        @include('transport.vehicle_documents._form', ['submitLabel' => 'Update document'])
    </form>
</div>
@endsection
