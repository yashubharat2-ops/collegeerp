@extends('layouts.app')
@section('title','Document Types')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Admission Document Types</h2>
            <p class="panel-subtitle">Manage flexible document types per college. Used for upload validation and verification workflow.</p>
        </div>
        @can('create', App\Models\AdmissionDocumentType::class)
            <a class="button" href="{{ route('admission-document-types.create') }}">+ New type</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('admission-document-types.index') }}" class="mt-6 grid gap-3 sm:grid-cols-3">
        <input class="input" type="search" name="search" value="{{ $search }}" placeholder="Search code, name">
        <select class="input" name="status">
            <option value="">All statuses</option>
            <option value="active" @selected($status === 'active')>Active</option>
            <option value="inactive" @selected($status === 'inactive')>Inactive</option>
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if($search !== '' || $status)
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('admission-document-types.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-8 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead><tr class="border-b text-slate-500"><th class="py-3">Code</th><th>Name</th><th>Required</th><th>Extensions</th><th>Max KB</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
            @forelse($documentTypes as $type)
                <tr class="border-b">
                    <td class="py-3 font-medium">{{ $type->code }}</td>
                    <td>{{ $type->name }}</td>
                    <td>{{ $type->is_required ? 'Yes' : 'No' }}</td>
                    <td class="text-xs">{{ $type->allowed_extensions }}</td>
                    <td>{{ $type->max_size_kb }}</td>
                    <td><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $type->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">{{ ucfirst($type->status) }}</span></td>
                    <td class="text-right">
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @can('update', $type)
                                <a class="text-xs font-semibold text-indigo-600 hover:underline" href="{{ route('admission-document-types.edit', $type) }}">Edit</a>
                            @endcan
                            @can('delete', $type)
                                <form method="POST" action="{{ route('admission-document-types.destroy', $type) }}" onsubmit="return confirm(@js('Delete type '.$type->name.'?'))">
                                    @csrf @method('DELETE')
                                    <button class="text-xs font-semibold text-rose-600 hover:underline" type="submit">Delete</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td class="py-6 text-slate-500" colspan="7">No document types found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4 flex items-center justify-between">
        <p class="text-xs text-slate-500">Showing {{ $documentTypes->firstItem() ?? 0 }}–{{ $documentTypes->lastItem() ?? 0 }} of {{ $documentTypes->total() }} types.</p>
        {{ $documentTypes->links() }}
    </div>
</div>
@endsection
