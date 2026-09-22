@extends('layouts.app')

@section('title', ($isHr ?? false) ? 'New Employee' : 'New Faculty Member')

@section('content')
<div class="panel max-w-4xl">
    <h2 class="panel-title">{{ ($isHr ?? false) ? 'New staff / employee' : 'New faculty / staff member' }}</h2>
    <p class="panel-subtitle">This record is created under the active college and is shared by Platform Faculty/Staff and HR.</p>

    <form method="POST" action="{{ route(($isHr ?? false) ? 'employees.store' : 'faculties.store') }}">
        @include('faculties._form', ['submitLabel' => 'Create employee', 'faculty' => null])
    </form>
</div>
@endsection
