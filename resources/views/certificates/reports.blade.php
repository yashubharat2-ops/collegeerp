@extends('layouts.app')
@section('title', 'Certificate Reports')
@section('content')
@include('certificates._navigation')
<div class="panel mb-6"><form method="GET" class="flex flex-wrap gap-3">
<label>Type<select class="input" name="certificate_type_id"><option value="">All types</option>@foreach($types as $type)<option value="{{ $type->id }}" @selected(request('certificate_type_id') == $type->id)>{{ $type->name }}</option>@endforeach</select></label>
<label>Status<select class="input" name="status"><option value="">All statuses</option>@foreach(['requested', 'generated', 'issued'] as $status)<option @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select></label><button class="button">Filter report</button></form><p class="mt-4">{{ $certificates->total() }} matching certificates in this college.</p></div>
<div class="panel overflow-x-auto"><table class="w-full text-left"><thead><tr><th>Number / Request</th><th>Type</th><th>Student</th><th>Status</th><th>Issued</th><th>Verification count</th><th>Last verified</th></tr></thead><tbody>
@forelse($certificates as $certificate)<tr class="border-t"><td class="py-3">{{ $certificate->number ?? '#'.$certificate->id }}</td><td>{{ $certificate->type?->name }}</td><td>{{ $certificate->student?->fullName() }}</td><td>{{ $certificate->status }}</td><td>{{ $certificate->issued_at?->toDateString() ?? '—' }}</td><td>{{ $certificate->verification_count }}</td><td>{{ $certificate->last_verified_at ?? '—' }}</td></tr>@empty<tr><td colspan="7">No matching certificates.</td></tr>@endforelse
</tbody></table>{{ $certificates->links() }}</div>
@endsection
