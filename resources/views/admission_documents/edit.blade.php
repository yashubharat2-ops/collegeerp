@extends('layouts.app')
@section('title','Edit Document')
@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">Edit document: {{ $document->original_filename }}</h2>
    <form method="POST" action="{{ route('admission-documents.update', $document) }}" enctype="multipart/form-data">
        @method('PUT')
        @include('admission_documents._form', ['submitLabel' => 'Update'])
    </form>
</div>
@endsection
