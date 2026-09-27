@extends('layouts.app')
@section('title','Certificate Reports')
@section('content')
<div class="panel"><h2 class="panel-title">Certificate Reports</h2><p class="panel-subtitle">Group 1 issuance totals for the active college.</p><div class="mt-5 grid gap-4 md:grid-cols-3">@foreach($counts as $type=>$count)<div class="rounded-xl bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">{{ $type }}</p><p class="mt-2 text-3xl font-bold">{{ $count }}</p></div>@endforeach</div></div>
@endsection
