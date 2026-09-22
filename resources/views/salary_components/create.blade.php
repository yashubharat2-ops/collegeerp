@extends('layouts.app')
@section('title', 'New Salary Component')
@section('content')<div class="panel max-w-3xl"><h2 class="panel-title">New salary component</h2><form method="POST" action="{{ route('salary-components.store') }}">@include('salary_components._form', ['component' => null, 'structure' => null, 'selectedStructure' => $selectedStructure, 'structures' => $structures, 'submitLabel' => 'Create component'])</form></div>@endsection
