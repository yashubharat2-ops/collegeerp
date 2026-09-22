@extends('layouts.app')
@section('title', 'New Salary Structure')
@section('content')<div class="panel max-w-3xl"><h2 class="panel-title">New salary structure</h2><form method="POST" action="{{ route('salary-structures.store') }}">@include('salary_structures._form', ['structure' => null, 'submitLabel' => 'Create structure'])</form></div>@endsection
