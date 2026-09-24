@extends('layouts.app')

@section('title', 'Vendors')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Vendors</h2>
            <p class="panel-subtitle">Suppliers for the active college. Contact details are for procurement follow-up; purchase orders are not recorded here.</p>
        </div>
        @can('create', App\Models\InventoryVendor::class)
            <a class="button" href="{{ route('inventory-vendors.create') }}">+ Add vendor</a>
        @endcan
    </div>

    <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" method="GET" action="{{ route('inventory-vendors.index') }}">
        <div>
            <label class="label" for="search">Search</label>
            <input class="input" id="search" name="search" type="text" value="{{ $search }}" placeholder="Name, code, contact, phone, email or GST">
        </div>
        <div>
            <label class="label" for="status">Status</label>
            <div class="flex flex-wrap gap-2">
                <select class="input" id="status" name="status">
                    <option value="">All statuses</option>
                    @foreach($statuses as $statusOption)
                        <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ ucfirst($statusOption) }}</option>
                    @endforeach
                </select>
                <button class="button" type="submit">Filter</button>
            </div>
        </div>
    </form>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[48rem] text-left text-sm">
            <thead>
                <tr class="border-b text-slate-500">
                    <th class="py-2">Name</th>
                    <th>Code</th>
                    <th>Contact</th>
                    <th>GST</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($vendors as $vendor)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $vendor->name }}</td>
                        <td><span class="font-mono text-xs">{{ $vendor->code }}</span></td>
                        <td class="text-slate-600">
                            <div>{{ $vendor->contact_person ?? '—' }}</div>
                            <div class="text-xs text-slate-500">{{ $vendor->phone ?? '' }}</div>
                            <div class="text-xs text-slate-500">{{ $vendor->email ?? '' }}</div>
                        </td>
                        <td class="font-mono text-xs text-slate-600">{{ $vendor->gst_number ?? '—' }}</td>
                        <td>
                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $vendor->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($vendor->status) }}</span>
                        </td>
                        <td class="py-2">
                            <div class="flex justify-end gap-2">
                                @can('update', $vendor)
                                    <a class="button !bg-slate-200 !text-slate-700" href="{{ route('inventory-vendors.edit', $vendor) }}">Edit</a>
                                @endcan
                                @can('delete', $vendor)
                                    <form method="POST" action="{{ route('inventory-vendors.destroy', $vendor) }}" onsubmit="return confirm('Delete the vendor &quot;{{ $vendor->name }}&quot;?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button !bg-rose-100 !text-rose-700" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-slate-500" colspan="6">No vendors recorded yet for this college.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $vendors->links() }}</div>
</div>
@endsection
