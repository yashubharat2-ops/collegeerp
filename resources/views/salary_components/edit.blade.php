@extends('layouts.app')
@section('title', 'Edit Salary Component')
@section('content')<div class="panel max-w-3xl"><h2 class="panel-title">Edit salary component</h2><form method="POST" action="{{ route('salary-components.update', $component) }}">@method('PUT')@include('salary_components._form', ['submitLabel' => 'Save component', 'structures' => [$structure], 'selectedStructure' => $structure->id])</form></div>@endsection
