@extends('layouts.app')
@section('title', 'Process Payroll')
@section('content')<div class="panel max-w-3xl"><h2 class="panel-title">Process monthly payroll</h2><p class="panel-subtitle">Amounts are calculated on the server from the selected active salary structure.</p><form method="POST" action="{{ route('payrolls.store') }}">@include('payrolls._form')</form></div>@endsection
