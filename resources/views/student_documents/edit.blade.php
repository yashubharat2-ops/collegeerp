@extends('layouts.app')
@section('title','Edit Student Document')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Edit document: {{ $document->title }}</h2>
    <p class="panel-subtitle">Metadata can be corrected at any time; replacing the file resets verification.</p>
    <form method="POST" action="{{ route('student-documents.update', $document) }}" enctype="multipart/form-data">
        @method('PUT')
        @include('student_documents._form', ['submitLabel' => 'Update document'])
    </form>
</div>
@endsection
