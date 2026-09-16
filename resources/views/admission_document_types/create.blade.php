@extends('layouts.app')
@section('title','New Document Type')
@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">New document type</h2>
    <p class="panel-subtitle">Create a document type for admission verification.</p>
    <form method="POST" action="{{ route('admission-document-types.store') }}">
        @include('admission_document_types._form', ['submitLabel' => 'Create type'])
    </form>
</div>
@endsection
