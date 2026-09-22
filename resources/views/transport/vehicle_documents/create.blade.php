@extends('layouts.app')
@section('title', 'Upload Vehicle Document')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Upload vehicle document</h2>
    <p class="panel-subtitle">Attach a document to an existing vehicle. The file stays on the private disk and is only reachable through the authorized download action.</p>
    @if($errors->any())<div class="alert-error mt-4">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('vehicle-documents.store') }}" enctype="multipart/form-data">
        @include('transport.vehicle_documents._form', ['submitLabel' => 'Upload document'])
    </form>
</div>
@endsection
