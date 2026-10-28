@extends('layouts.app')
@section('title','Upload Student Document')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/erp-student-document-upload.css') }}@if(is_file(public_path('css/erp-student-document-upload.css')))?v={{ hash_file('crc32b', public_path('css/erp-student-document-upload.css')) }}@endif">
@endpush
@section('content')
<div class="panel sd-upload">
    <h2 class="panel-title">Upload student document</h2>
    <p class="panel-subtitle">Attach a document to a student. Verification starts as pending and the file stays on the private disk.</p>
    <form method="POST" action="{{ route('student-documents.store') }}" enctype="multipart/form-data">
        @include('student_documents._form', ['submitLabel' => 'Upload Document'])
    </form>
</div>
@endsection
