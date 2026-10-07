@extends('layouts.app')
@section('title', 'Salary Structures')
@section('content')<div class="panel" data-bulk-scope><div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="panel-title">Salary structures</h2><p class="panel-subtitle">Tenant-scoped component definitions used to calculate monthly payroll.</p></div>@can('create', App\Models\SalaryStructure::class)<a class="button" href="{{ route('salary-structures.create') }}">+ New structure</a>@endcan</div><form method="GET" class="mt-6 flex flex-wrap gap-3"><input class="input" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search name or code"><select class="input" name="status"><option value="">All statuses</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select><button class="button" type="submit">Filter</button></form>
    {{-- Bulk selection over the filtered salary structures list. Export only — the shared
         handler re-queries every ticked id inside the active college and re-authorizes
         each record before the CSV endpoint streams. --}}
    <x-list.bulk-selection-bar module="salary_structures">
        @if(auth()->user()?->hasPermission('salary_structures.view'))
            <button type="button" data-bulk-action="export"
                    class="button !py-2 !text-xs font-semibold">
                Export selected
            </button>
        @endif
    </x-list.bulk-selection-bar>

<div class="mt-8 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-slate-500"><th class="w-10 py-3"><x-list.select-all /></th><th class="py-3">Name</th><th>Code</th><th>Effective</th><th>Components</th><th>Status</th><th class="text-right">Actions</th></tr></thead><tbody>@forelse($structures as $structure)<tr class="border-b"><td class="py-3"><x-list.row-checkbox :id="$structure->id" /></td><td class="py-3 font-medium">{{ $structure->name }}</td><td>{{ $structure->code }}</td><td>{{ $structure->effective_from?->format('d M Y') ?? 'Any' }} – {{ $structure->effective_to?->format('d M Y') ?? 'Open' }}</td><td>{{ $structure->components_count }}</td><td>{{ ucfirst($structure->status) }}</td><td class="text-right"><div class="flex justify-end gap-2"><a class="text-xs text-slate-600" href="{{ route('salary-structures.show', $structure) }}">View</a>@can('update', $structure)<a class="text-xs text-indigo-600" href="{{ route('salary-structures.edit', $structure) }}">Edit</a>@endcan @can('delete', $structure)<form method="POST" action="{{ route('salary-structures.destroy', $structure) }}">@csrf @method('DELETE')<button class="text-xs text-rose-600" type="submit">Delete</button></form>@endcan</div></td></tr>@empty<tr><td colspan="7" class="py-6 text-slate-500">No salary structures found.</td></tr>@endforelse</tbody></table></div><div class="mt-4">{{ $structures->links() }}</div></div>@endsection
