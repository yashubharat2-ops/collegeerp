@extends('layouts.app')

@section('title', 'New Examination')

@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">New examination</h2>
    <p class="panel-subtitle">The examination is created under the active college; you do not choose the college here.</p>

    <form method="POST" action="{{ route('examinations.store') }}">
        @include('examinations._form', ['submitLabel' => 'Create examination', 'examination' => null])
    </form>
</div>
@endsection
