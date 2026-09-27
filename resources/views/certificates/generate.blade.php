@extends('layouts.app')
@section('title','Certificate Generation')
@section('content')
<div class="panel"><h2 class="panel-title">Certificate Generation</h2><p class="panel-subtitle">Bonafide and Character certificates are issued here. Generate Transfer Certificates by creating a transfer request below; do not create a second TC record.</p>
<div class="mt-4"><a class="button !bg-slate-700" href="{{ route('certificates.transfer-requests.create') }}">Start TC request / issuance workflow</a></div>
<form method="POST" action="{{ route('certificates.generation.store') }}" class="mt-6 grid gap-3 md:grid-cols-2">@csrf
<select class="input" name="type" required><option value="">Certificate type</option>@foreach($types as $key=>$label)<option value="{{ $key }}" @selected(old('type')===$key)>{{ $label }}</option>@endforeach</select>
<select class="input" name="student_id" required><option value="">Select student</option>@foreach($students as $student)<option value="{{ $student->id }}" @selected(old('student_id')==$student->id)>{{ $student->student_number }} — {{ $student->fullName() }}</option>@endforeach</select>
<select class="input" name="template_id"><option value="">Built-in template</option>@foreach($templates as $template)<option value="{{ $template->id }}">{{ $template->name }} ({{ $template->type }})</option>@endforeach</select><input class="input" type="date" name="issued_at" value="{{ old('issued_at', now()->toDateString()) }}">
<textarea class="input md:col-span-2" name="purpose" rows="2" placeholder="Purpose (optional)">{{ old('purpose') }}</textarea><button class="button md:col-span-2" type="submit">Generate and issue certificate</button></form></div>
@endsection
