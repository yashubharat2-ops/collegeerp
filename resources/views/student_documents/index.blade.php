@extends('layouts.app')
@section('title','Student Documents')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Student Documents</h2>
            <p class="panel-subtitle">Private, tenant-scoped storage with a verification workflow. Files are never served by URL and are downloaded through an authorized action.</p>
        </div>
        @can('create', App\Models\StudentDocument::class)
            <a class="button" href="{{ route('student-documents.create') }}">+ Upload document</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('student-documents.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search title, file or student">
        <select class="input" name="student_id">
            <option value="">All students</option>
            @foreach($students as $s)
                <option value="{{ $s->id }}" @selected((string) $student_id === (string) $s->id)>{{ $s->student_number }} — {{ $s->first_name }} {{ $s->last_name }}</option>
            @endforeach
        </select>
        <select class="input" name="document_type_id">
            <option value="">All types</option>
            @foreach($documentTypes as $type)
                <option value="{{ $type->id }}" @selected((string) $document_type_id === (string) $type->id)>{{ $type->name }}</option>
            @endforeach
        </select>
        <select class="input" name="verification_status">
            <option value="">All verification</option>
            @foreach(App\Models\StudentDocument::VERIFICATION_STATUSES as $status)
                <option value="{{ $status }}" @selected($verification_status === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $student_id || $document_type_id || $verification_status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('student-documents.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-3">Title</th>
                    <th>Student</th>
                    <th>Type</th>
                    <th>Validity</th>
                    <th>Verification</th>
                    <th>Size</th>
                    <th>Uploaded</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($documents as $doc)
                <tr class="border-b">
                    <td class="py-3 font-medium">
                        {{ $doc->title }}
                        <span class="block text-xs text-slate-500">{{ $doc->original_filename }}</span>
                    </td>
                    <td>
                        <a class="text-indigo-600 hover:underline" href="{{ route('students.show', ['student' => $doc->student_id, 'tab' => 'documents']) }}">{{ $doc->student?->student_number }}</a>
                        <span class="block text-xs text-slate-500">{{ $doc->student?->fullName() }}</span>
                    </td>
                    <td>{{ $doc->documentType?->name ?? '—' }}</td>
                    <td class="text-xs">
                        {{ $doc->issue_date?->format('d M Y') ?? '—' }} → {{ $doc->expiry_date?->format('d M Y') ?? '—' }}
                        @if($doc->isExpired())<span class="block text-rose-600">Expired</span>@endif
                    </td>
                    <td>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                            @if($doc->isPending()) bg-amber-100 text-amber-700
                            @elseif($doc->isVerified()) bg-emerald-100 text-emerald-700
                            @else bg-rose-100 text-rose-700 @endif">{{ ucfirst($doc->verification_status) }}</span>
                        @if($doc->verifiedBy)<span class="block text-xs text-slate-500">by {{ $doc->verifiedBy->name }}</span>@endif
                        @if($doc->rejection_remarks)<span class="block text-xs text-rose-600">{{ $doc->rejection_remarks }}</span>@endif
                    </td>
                    <td class="text-xs">{{ $doc->sizeInKb() }} KB</td>
                    <td class="text-xs">{{ $doc->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="text-right">
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @can('download', $doc)
                                <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('student-documents.download', $doc) }}">Download</a>
                            @endcan
                            @can('update', $doc)
                                <a class="text-xs font-semibold text-slate-600 hover:underline" href="{{ route('student-documents.edit', $doc) }}">Edit</a>
                            @endcan
                            @can('verify', $doc)
                                @unless($doc->isVerified())
                                    <form class="inline" method="POST" action="{{ route('student-documents.verify', $doc) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="verify">
                                        <button class="text-xs font-semibold text-emerald-600 hover:underline" type="submit">Verify</button>
                                    </form>
                                @endunless
                                @unless($doc->isRejected())
                                    <form class="inline" method="POST" action="{{ route('student-documents.verify', $doc) }}"
                                          onsubmit="const reason = prompt('Rejection remarks (required):'); if (!reason) return false; this.querySelector('[name=rejection_remarks]').value = reason; return true;">
                                        @csrf
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="rejection_remarks" value="">
                                        <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Reject</button>
                                    </form>
                                @endunless
                            @endcan
                            @can('delete', $doc)
                                <form method="POST" action="{{ route('student-documents.destroy', $doc) }}"
                                      onsubmit="return confirm(@js('Delete document "'.$doc->title.'"? The record is soft-deleted and the action is audited.'))">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td class="py-6 text-slate-500" colspan="8">No student documents found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-slate-500">Showing {{ $documents->firstItem() ?? 0 }}–{{ $documents->lastItem() ?? 0 }} of {{ $documents->total() }} documents.</p>
        {{ $documents->links() }}
    </div>
</div>
@endsection
