@extends('layouts.app')
@section('title','Upload Document')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Upload admission document</h2>
    <p class="panel-subtitle">Secure private storage. File path is server-generated, never trust original filename.</p>
    <form method="POST" action="{{ route('admission-documents.store') }}" enctype="multipart/form-data">
        @include('admission_documents._form', ['submitLabel' => 'Upload'])
    </form>
</div>
@endsection
