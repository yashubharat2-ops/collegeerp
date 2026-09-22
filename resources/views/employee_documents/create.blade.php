@extends('layouts.app')
@section('title', 'Upload Employee Document')
@section('content')<div class="panel max-w-4xl"><h2 class="panel-title">Upload employee document</h2><p class="panel-subtitle">Files are stored on the private disk and can only be downloaded through an authorized route.</p><form method="POST" action="{{ route('employee-documents.store') }}" enctype="multipart/form-data">@include('employee_documents._form', ['document' => null, 'submitLabel' => 'Upload document'])</form></div>@endsection
