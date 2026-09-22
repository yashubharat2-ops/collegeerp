@extends('layouts.app')
@section('title', 'Edit Salary Structure')
@section('content')<div class="panel max-w-3xl"><h2 class="panel-title">Edit salary structure</h2><form method="POST" action="{{ route('salary-structures.update', $structure) }}">@method('PUT')@include('salary_structures._form', ['submitLabel' => 'Save structure'])</form></div>@endsection
