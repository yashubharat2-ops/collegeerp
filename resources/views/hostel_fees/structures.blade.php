@extends('layouts.app')
@section('title', 'Hostel Fee Structures')
@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Hostel Fee Structures</h2>
            <p class="panel-subtitle">Hostel-side pricing master. Amounts are configured per college — nothing is hard-coded and no money is collected here.</p>
        </div>
        <div class="flex gap-2">
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-fees.index') }}">Hostel fees</a>
            @can('create', App\Models\HostelFeeStructure::class)
                <a class="button" href="{{ route('hostel-fee-structures.create') }}">+ Add structure</a>
            @endcan
        </div>
    </div>

    <form method="GET" action="{{ route('hostel-fee-structures.index') }}" class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <input class="input" type="search" name="search" value="{{ $selected['search'] }}" placeholder="Name or code">
        <select class="input" name="academic_year_id">
            <option value="">All academic years</option>
            @foreach($years as $year)
                <option value="{{ $year->id }}" @selected((string) $selected['academic_year_id'] === (string) $year->id)>{{ $year->name }}</option>
            @endforeach
        </select>
        <select class="input" name="status">
            <option value="">All statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status }}" @selected($selected['status'] === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <button class="button" type="submit">Filter</button>
            @if(array_filter($selected))
                <a class="button !bg-slate-200 !text-slate-700" href="{{ route('hostel-fee-structures.index') }}">Clear</a>
            @endif
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b bg-slate-50 text-slate-500">
                <tr>
                    <th class="whitespace-nowrap px-3 py-3">Name</th>
                    <th class="whitespace-nowrap px-3 py-3">Code</th>
                    <th class="whitespace-nowrap px-3 py-3">Academic Year</th>
                    <th class="whitespace-nowrap px-3 py-3">Amount</th>
                    <th class="whitespace-nowrap px-3 py-3">Frequency</th>
                    <th class="whitespace-nowrap px-3 py-3">Period</th>
                    <th class="whitespace-nowrap px-3 py-3">Status</th>
                    <th class="px-3 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($structures as $structure)
                    <tr>
                        <td class="whitespace-nowrap px-3 py-3 font-medium">{{ $structure->name }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $structure->code }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $structure->academicYear?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ number_format((float) $structure->amount, 2) }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $structure->frequency ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3 text-xs">{{ $structure->effective_from?->format('d M Y') ?? '—' }} {{ $structure->effective_until ? '→ '.$structure->effective_until->format('d M Y') : '' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $structure->isActive() ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">{{ ucfirst($structure->status) }}</span>
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex gap-2">
                                @can('update', $structure)<a class="button" href="{{ route('hostel-fee-structures.edit', $structure->id) }}">Edit</a>@endcan
                                @can('delete', $structure)
                                    <form method="POST" action="{{ route('hostel-fee-structures.destroy', $structure->id) }}" onsubmit="return confirm('Delete this hostel fee structure? In-use structures are rejected.')">@csrf @method('DELETE')<button class="button !bg-red-600">Delete</button></form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-8 text-center text-slate-500">No hostel fee structures found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $structures->links() }}</div>
</div>
@endsection
