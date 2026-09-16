@extends('layouts.app')
@section('title','Edit Merit Entry')
@section('content')
<div class="panel max-w-3xl">
    <h2 class="panel-title">Edit merit entry</h2>
    <form method="POST" action="{{ route('admission-merit-entries.update', $entry) }}">
        @method('PUT')
        @include('admission_merit_entries._form', ['submitLabel' => 'Update entry'])
    </form>
</div>
@endsection
