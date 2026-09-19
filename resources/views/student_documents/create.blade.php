@extends('layouts.app')
@section('title','Upload Student Document')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Upload student document</h2>
    <p class="panel-subtitle">Attach a document to a student. Verification starts as pending and the file stays on the private disk.</p>
    <form method="POST" action="{{ route('student-documents.store') }}" enctype="multipart/form-data">
        @include('student_documents._form', ['submitLabel' => 'Upload document'])
    </form>
</div>
@endsection
