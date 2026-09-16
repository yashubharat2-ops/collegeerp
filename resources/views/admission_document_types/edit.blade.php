@extends('layouts.app')
@section('title','Edit Document Type')
@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Edit document type: {{ $documentType->name }}</h2>
    <form method="POST" action="{{ route('admission-document-types.update', $documentType) }}">
        @method('PUT')
        @include('admission_document_types._form', ['submitLabel' => 'Update type'])
    </form>
</div>
@endsection
