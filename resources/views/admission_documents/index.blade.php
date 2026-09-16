@extends('layouts.app')
@section('title','Documents')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Admission Documents</h2>
            <p class="panel-subtitle">Private file storage with verification workflow. Access is tenant-scoped and permission controlled.</p>
        </div>
        @can('create', App\Models\AdmissionDocument::class)
            <a class="button" href="{{ route('admission-documents.create') }}">+ Upload document</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('admission-documents.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search file, applicant">
        <select class="input" name="verification_status">
            <option value="">All verification</option>
            <option value="pending" @selected($verification_status === 'pending')>Pending</option>
            <option value="verified" @selected($verification_status === 'verified')>Verified</option>
            <option value="rejected" @selected($verification_status === 'rejected')>Rejected</option>
        </select>
        <select class="input" name="document_type_id">
            <option value="">All types</option>
            @foreach($documentTypes as $type)
                <option value="{{ $type->id }}" @selected((string)$document_type_id === (string)$type->id)>{{ $type->name }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $verification_status || $document_type_id)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-documents.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th class="py-3">File</th><th>Applicant</th><th>Application</th><th>Type</th><th>Status</th><th>Size</th><th>Uploaded</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
            @forelse($documents as $doc)
                <tr class="border-b">
                    <td class="py-3 font-medium">{{ $doc->original_filename }}<p class="text-xs text-slate-500">{{ $doc->mime_type }}</p></td>
                    <td>{{ $doc->applicant->first_name }} {{ $doc->applicant->last_name }}</td>
                    <td class="text-xs">{{ $doc->application?->application_number ?? '—' }}</td>
                    <td>{{ $doc->documentType->name }}</td>
                    <td>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                            @if($doc->verification_status === 'pending') bg-amber-100 text-amber-700
                            @elseif($doc->verification_status === 'verified') bg-emerald-100 text-emerald-700
                            @else bg-rose-100 text-rose-700 @endif
                        ">{{ ucfirst($doc->verification_status) }}</span>
                        @if($doc->rejection_remarks)<p class="text-xs text-rose-600 mt-1">{{ $doc->rejection_remarks }}</p>@endif
                    </td>
                    <td class="text-xs">{{ number_format($doc->file_size/1024,1) }} KB</td>
                    <td class="text-xs">{{ $doc->created_at->format('Y-m-d H:i') }}</td>
                    <td class="text-right">
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @can('view', $doc)
                                <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('admission-documents.download', $doc) }}">Download</a>
                            @endcan
                            @can('update', $doc)
                                <a class="text-xs font-semibold text-slate-600 hover:underline" href="{{ route('admission-documents.edit', $doc) }}">Edit</a>
                            @endcan
                            @can('verify', $doc)
                                @if($doc->verification_status !== 'verified')
                                <form method="POST" action="{{ route('admission-documents.verify', $doc) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="action" value="verify">
                                    <button class="text-xs font-semibold text-emerald-600 hover:underline" type="submit">Verify</button>
                                </form>
                                @endif
                                @if($doc->verification_status !== 'rejected')
                                <form method="POST" action="{{ route('admission-documents.verify', $doc) }}" class="inline" onsubmit="const r=prompt('Rejection remarks:'); if(!r) return false; this.querySelector('[name=rejection_remarks]').value=r;">
                                    @csrf
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="rejection_remarks" value="">
                                    <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Reject</button>
                                </form>
                                @endif
                            @endcan
                            @can('delete', $doc)
                                <form method="POST" action="{{ route('admission-documents.destroy', $doc) }}" onsubmit="return confirm(@js('Delete document '.$doc->original_filename.'?'))">
                                    @csrf @method('DELETE')
                                    <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td class="py-6 text-slate-500" colspan="8">No documents found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4 flex items-center justify-between">
        <p class="text-xs text-slate-500">Showing {{ $documents->firstItem() ?? 0 }}–{{ $documents->lastItem() ?? 0 }} of {{ $documents->total() }} documents.</p>
        {{ $documents->links() }}
    </div>
</div>
@endsection
