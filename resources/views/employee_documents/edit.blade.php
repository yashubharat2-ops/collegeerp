@extends('layouts.app')
@section('title', 'Edit Employee Document')
@section('content')<div class="panel max-w-4xl"><h2 class="panel-title">Edit employee document</h2><p class="panel-subtitle">Updating {{ $document->document_name }} for {{ $document->employee?->full_name }}.</p><form method="POST" action="{{ route('employee-documents.update', $document) }}" enctype="multipart/form-data">@method('PUT')@include('employee_documents._form', ['submitLabel' => 'Save changes'])</form></div>@endsection
