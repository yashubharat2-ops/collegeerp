@extends('layouts.app')
@section('title', 'Custom Certificate — Certificate Types')
@section('content')
@include('certificates._navigation')
<div class="panel mb-6">
    <h2 class="panel-title">Create an additional certificate type</h2>
    <p class="panel-subtitle">Study, Conduct, No Dues, Internship, Fee, Scholarship, Attendance, or any college-specific certificate. Each uses the same request, generation, issuance and verification workflow.</p>
    <form method="POST" action="{{ route('certificates.types.store') }}" class="space-y-4">@csrf
        <label class="block">Name<input class="input" name="name" required maxlength="255" value="{{ old('name') }}"></label>
        <label class="block">Short code<input class="input" name="code" required maxlength="30" value="{{ old('code') }}" placeholder="STUDY"></label>
        <label class="block">Description<textarea class="input" name="description" required maxlength="2000">{{ old('description') }}</textarea></label>
        <button class="button">Create certificate type</button>
    </form>
</div>
<div class="panel overflow-x-auto"><table class="w-full text-left"><thead><tr><th>Name</th><th>Short code</th><th>Description</th><th>Templates</th><th>Origin</th></tr></thead><tbody>
@foreach($types as $type)<tr class="border-t"><td class="py-3">{{ $type->name }}</td><td>{{ $type->code }}</td><td>{{ $type->description }}</td><td>{{ $type->templates_count }}</td><td>{{ $type->builtin_key ? 'Group 1 built-in' : 'College-defined' }}</td></tr>@endforeach
</tbody></table></div>
@endsection
