@extends('layouts.app')

@section('title', 'Grade / Pass-Fail')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Grade / Pass-Fail</h2>
            <p class="panel-subtitle">
                College-level grading configuration. Nothing is hard-coded here — each college defines its own percentage bands and grades.
            </p>
        </div>
        @can('create', App\Models\GradeScale::class)
            <a class="button" href="{{ route('grade-scales.create') }}">+ Add grade scale</a>
        @endcan
    </div>

    <div class="mt-6 space-y-4">
        @forelse($scales as $scale)
            <div class="rounded-2xl border border-slate-200 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-base font-semibold text-slate-900">{{ $scale->name }} <span class="text-sm font-normal text-slate-500">({{ $scale->code }})</span></p>
                        <p class="mt-1 text-xs text-slate-500">{{ $scale->description }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $scale->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ ucfirst($scale->status) }}</span>
                        @can('update', $scale)
                            <a class="button !bg-slate-200 !text-slate-700" href="{{ route('grade-scales.edit', $scale) }}">Edit</a>
                        @endcan
                        @can('delete', $scale)
                            <form method="POST" action="{{ route('grade-scales.destroy', $scale) }}" onsubmit="return confirm('Delete the grade scale &quot;{{ $scale->name }}&quot;? Results already calculated keep their own snapshot.');">
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
                                <th>Grade</th>
                                <th>Min %</th>
                                <th>Max %</th>
                                <th>Grade Point</th>
                                <th>Description</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($scale->items as $item)
                                <tr class="border-b">
                                    <td class="py-2">{{ $item->sort_order }}</td>
                                    <td class="font-medium">{{ $item->grade }}</td>
                                    <td>{{ $item->min_percentage }}</td>
                                    <td>{{ $item->max_percentage }}</td>
                                    <td>{{ $item->grade_point ?? '—' }}</td>
                                    <td>{{ $item->description ?? '—' }}</td>
                                    <td>{{ ucfirst($item->status) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="py-4 text-slate-500" colspan="7">No grade bands configured.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <p class="py-6 text-slate-500">No grade scales configured yet for this college.</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $scales->links() }}</div>
</div>
@endsection
