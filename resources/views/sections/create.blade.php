@extends('layouts.app')

@section('title', 'New Section')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">New section / batch</h2>
    <p class="panel-subtitle">The section is created under the active college; you do not choose the college here.</p>

    <form method="POST" action="{{ route('sections.store') }}">
        @include('sections._form', ['submitLabel' => 'Create section', 'section' => null])
    </form>
</div>
@endsection
