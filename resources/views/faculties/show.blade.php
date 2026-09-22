@extends('layouts.app')

@section('title', ($isHr ?? false) ? 'Employee Details' : 'Faculty Details')

@section('content')
@php($routePrefix = ($isHr ?? false) ? 'employees' : 'faculties')
<div class="grid gap-6 xl:grid-cols-3">
    <div class="panel xl:col-span-2">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div><h2 class="panel-title">{{ $faculty->full_name }}</h2><p class="panel-subtitle">{{ $faculty->employee_code }} · {{ $faculty->displayDesignation() ?? 'No designation' }}</p></div>
            <div class="flex gap-2">@can('update', $faculty)<a class="button" href="{{ route($routePrefix.'.edit', $faculty) }}">Edit</a>@endcan<a class="button !bg-slate-200 !text-slate-700" href="{{ route($routePrefix.'.index') }}">Back</a></div>
        </div>
        <dl class="mt-6 grid gap-4 sm:grid-cols-2">
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Status</dt><dd class="mt-1">{{ ucfirst($faculty->status) }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Department</dt><dd class="mt-1">{{ $faculty->department?->name ?? 'College level' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Email</dt><dd class="mt-1">{{ $faculty->email ?: '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Phone</dt><dd class="mt-1">{{ $faculty->phone ?: '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Employment type</dt><dd class="mt-1">{{ $faculty->employment_type ? ucwords(str_replace('_', ' ', $faculty->employment_type)) : '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Joining date</dt><dd class="mt-1">{{ $faculty->joining_date?->format('M j, Y') ?? '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Date of birth</dt><dd class="mt-1">{{ $faculty->date_of_birth?->format('M j, Y') ?? '—' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase text-slate-500">Alternate phone</dt><dd class="mt-1">{{ $faculty->alternate_phone ?: '—' }}</dd></div>
        </dl>
        @if($faculty->address_line_1 || $faculty->city || $faculty->state || $faculty->postal_code)
            <div class="mt-6 border-t border-slate-200 pt-4"><h3 class="font-semibold">Address</h3><p class="mt-2 text-sm text-slate-600">{{ $faculty->address_line_1 }}{{ $faculty->address_line_2 ? ', '.$faculty->address_line_2 : '' }}{{ $faculty->city ? ', '.$faculty->city : '' }}{{ $faculty->state ? ', '.$faculty->state : '' }}{{ $faculty->postal_code ? ' '.$faculty->postal_code : '' }}{{ $faculty->country ? ', '.$faculty->country : '' }}</p></div>
        @endif
        @if($faculty->emergency_contact_name || $faculty->emergency_contact_phone)
            <div class="mt-6 border-t border-slate-200 pt-4"><h3 class="font-semibold">Emergency contact</h3><p class="mt-2 text-sm text-slate-600">{{ $faculty->emergency_contact_name ?: '—' }} · {{ $faculty->emergency_contact_phone ?: '—' }}</p></div>
        @endif
        @if($faculty->notes)<div class="mt-6 border-t border-slate-200 pt-4"><h3 class="font-semibold">Notes</h3><p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $faculty->notes }}</p></div>@endif
    </div>

    @if($canViewEmployeeDocuments)
        <div class="panel">
            <div class="flex items-start justify-between gap-2"><div><h3 class="panel-title">Employee documents</h3><p class="panel-subtitle">Private files linked to this employee.</p></div>@if(auth()->user()?->hasPermission('employee_documents.create'))<a class="text-sm font-semibold text-indigo-600" href="{{ route('employee-documents.create', ['employee_id' => $faculty->id]) }}">+ Add</a>@endif</div>
            <div class="mt-5 space-y-3">
                @forelse($faculty->documents as $document)
                    <a class="block rounded-lg border border-slate-200 p-3 hover:border-indigo-300" href="{{ route('employee-documents.show', $document) }}"><p class="font-semibold">{{ $document->document_name }}</p><p class="mt-1 text-xs text-slate-500">{{ $document->document_type ?: 'Document' }} · {{ $document->created_at?->format('M j, Y') }}</p></a>
                @empty
                    <p class="text-sm text-slate-500">No documents uploaded.</p>
                @endforelse
            </div>
        </div>
    @endif
</div>
@endsection
