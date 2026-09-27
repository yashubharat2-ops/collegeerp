@extends('layouts.app')
@section('title','Certificate Dashboard')
@section('content')
<div class="panel"><h2 class="panel-title">Certificate Management (EC)</h2><p class="panel-subtitle">Group 1 certificate services. TC issuance uses the existing student transfer lifecycle and record.</p>
<div class="mt-6 grid gap-4 md:grid-cols-3"><div class="rounded-xl bg-indigo-50 p-5"><p class="text-sm text-slate-500">Issued certificates</p><p class="mt-2 text-3xl font-bold">{{ $issued }}</p></div><div class="rounded-xl bg-amber-50 p-5"><p class="text-sm text-slate-500">Pending requests</p><p class="mt-2 text-3xl font-bold">{{ $pending }}</p></div><div class="rounded-xl bg-emerald-50 p-5"><p class="text-sm text-slate-500">Certificate types · Group 1</p><ul class="mt-2">@foreach($types as $type)<li>{{ $type }}</li>@endforeach</ul></div></div>
<div class="mt-6 flex flex-wrap gap-3"><a class="button" href="{{ route('certificates.generation.index') }}">Generate certificate</a><a class="button" href="{{ route('certificates.requests.index') }}">Manage requests</a><a class="button" href="{{ route('certificates.issuance.index') }}">Issued certificates</a></div></div>
@endsection
