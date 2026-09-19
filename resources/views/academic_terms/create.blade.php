@extends('layouts.app')

@section('title', 'New Academic Term')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">New academic term / semester</h2>
    <p class="panel-subtitle">The academic term is created under the active college; you do not choose the college here.</p>

    <form method="POST" action="{{ route('academic-terms.store') }}">
        @include('academic_terms._form', ['submitLabel' => 'Create academic term', 'academicTerm' => null])
    </form>
</div>
@endsection
