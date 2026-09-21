@extends('layouts.app')

@section('title', 'Fee Structures')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Fee Structures</h2>
            <p class="panel-subtitle">
                Fee plans for the active college, per academic year, program and (optionally) term. Amounts are configuration only — collection and receipts belong to later phases.
            </p>
        </div>
        @can('create', App\Models\FeeStructure::class)
            <a class="button" href="{{ route('fee-structures.create') }}">+ Add fee structure</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" method="GET" action="{{ route('fee-structures.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Name or code">
        </div>
        <div>
            <label class="label" for="filter_academic_year_id">Academic Year</label>
            <select class="input" id="filter_academic_year_id" name="academic_year_id">
                <option value="">All years</option>
                @foreach($academicYears as $year)
                    <option value="{{ $year->id }}" @selected((int) $academic_year_id === $year->id)>{{ $year->name }} ({{ $year->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="filter_program_id">Program</label>
            <select class="input" id="filter_program_id" name="program_id">
                <option value="">All programs</option>
                @foreach($programs as $program)
                    <option value="{{ $program->id }}" @selected((int) $program_id === $program->id)>{{ $program->name }} ({{ $program->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="filter_academic_term_id">Term</label>
            <select class="input" id="filter_academic_term_id" name="academic_term_id">
                <option value="">All terms</option>
                @foreach($academicTerms as $term)
                    <option value="{{ $term->id }}" @selected((int) $academic_term_id === $term->id)>{{ $term->name }} ({{ $term->code }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="filter_status">Status</label>
            <div class="flex gap-2">
                <select class="input" id="filter_status" name="status">
                    <option value="">All statuses</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}" @selected($status === request('status'))>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
                <button class="button" type="submit">Filter</button>
            </div>
        </div>
    </form>

    <div class="mt-6 space-y-4">
        @forelse($structures as $structure)
            <div class="rounded-2xl border border-slate-200 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-base font-semibold text-slate-900">
                            {{ $structure->name }} <span class="text-sm font-normal text-slate-500">({{ $structure->code }})</span>
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $structure->academicYear?->name ?? '—' }} ·
                            {{ $structure->program?->name ?? '—' }} ·
                            {{ $structure->academicTerm?->name ?? 'Whole academic year' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">{{ $structure->description }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $structure->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($structure->status) }}</span>
                        @can('update', $structure)
                            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('fee-structures.edit', $structure) }}">Edit</a>
                        @endcan
                        @can('delete', $structure)
                            <form method="POST" action="{{ route('fee-structures.destroy', $structure) }}" onsubmit="return confirm('Delete the fee structure &quot;{{ $structure->name }}&quot;?');">
                                @csrf
                                @method('DELETE')
                                <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                            </form>
                        @endcan
                    </div>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b text-slate-500">
                                <th class="py-2">#</th>
                                <th>Fee Category / Name</th>
                                <th>Amount</th>
                                <th>Description</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($structure->items as $item)
                                <tr class="border-b">
                                    <td class="py-2">{{ $item->sort_order }}</td>
                                    <td class="font-medium">{{ $item->name }}</td>
                                    <td>{{ number_format((float) $item->amount, 2) }}</td>
                                    <td>{{ $item->description ?? '—' }}</td>
                                    <td>{{ ucfirst($item->status) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="py-4 text-slate-500" colspan="5">No fee components configured.</td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="py-2 font-semibold" colspan="2">Total (active components)</td>
                                <td class="py-2 font-semibold">{{ number_format((float) $structure->items->where('status', 'active')->sum('amount'), 2) }}</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @empty
            <p class="py-6 text-slate-500">No fee structures configured yet for this college.</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $structures->links() }}</div>
</div>
@endsection
