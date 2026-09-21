@extends('layouts.app')

@section('title', 'Result Publishing')

@section('content')
<div class="panel">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="panel-title">Result Publishing</h2>
            <p class="panel-subtitle">
                Calculated → Ready → Published. Only calculated results with a valid grading configuration can be published; draft, incomplete and failed results are blocked.
            </p>
        </div>
    </div>

    @if($errors->any())
        <div class="alert-error mt-4">
            <ul class="list-inside list-disc space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="GET" action="{{ route('result-publishing.index') }}" class="mt-6 grid gap-3 sm:grid-cols-3">
        <select class="input" name="examination_id" onchange="this.form.submit()">
            <option value="">All examinations</option>
            @foreach($examinations as $exam)
                <option value="{{ $exam->id }}" @selected((string) $filters['examination_id'] === (string) $exam->id)>{{ $exam->name }} ({{ $exam->code }})</option>
            @endforeach
        </select>
        <select class="input" name="calculation_status">
            <option value="">All calculation statuses</option>
            @foreach($calculationStatuses as $status)
                <option value="{{ $status }}" @selected($filters['calculation_status'] === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <button class="button" type="submit">Filter</button>
    </form>

    @if($filters['examination_id'])
        <form method="POST" action="{{ route('result-publishing.examination', $filters['examination_id']) }}" class="mt-4">
            @csrf
            <button class="button" type="submit" onclick="return confirm('Publish every eligible calculated result for this examination? This action is transactional — either all eligible results are published or none is.')">
                Publish all eligible for this examination
            </button>
        </form>
    @endif

    {{-- Bulk publish: the selection is posted with the list form. --}}
    <form method="POST" action="{{ route('result-publishing.bulk') }}" class="mt-6">
        @csrf
        @if($filters['examination_id'])
            <input type="hidden" name="examination_id" value="{{ $filters['examination_id'] }}">
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-3 w-10"><span class="sr-only">Select</span></th>
                        <th>Enrollment No.</th>
                        <th>Student</th>
                        <th>Examination</th>
                        <th>Percentage</th>
                        <th>Grade</th>
                        <th>Result</th>
                        <th>Calculation</th>
                        <th>Publishing</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($results as $result)
                        @php
                            $publishable = ! $result->isPublished()
                                && $result->calculation_status === 'calculated'
                                && $result->isPublishable();
                        @endphp
                        <tr class="border-b">
                            <td class="py-3">
                                @if($publishable)
                                    <input type="checkbox" name="result_ids[]" value="{{ $result->id }}" class="rounded border-slate-300">
                                @endif
                            </td>
                            <td class="font-medium">{{ $result->studentEnrollment?->enrollment_number }}</td>
                            <td>{{ $result->studentEnrollment?->student?->full_name }}</td>
                            <td>{{ $result->examination?->name }}</td>
                            <td>{{ $result->percentage !== null ? $result->percentage.'%' : '—' }}</td>
                            <td>{{ $result->overall_grade ?? '—' }}</td>
                            <td>
                                <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $result->result_status === 'pass' ? 'bg-emerald-100 text-emerald-700' : ($result->result_status === 'fail' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">{{ ucfirst($result->result_status) }}</span>
                            </td>
                            <td>
                                <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $result->calculation_status === 'calculated' ? 'bg-emerald-100 text-emerald-700' : ($result->calculation_status === 'failed' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">{{ ucfirst($result->calculation_status) }}</span>
                            </td>
                            <td>
                                @if($result->isPublished())
                                    <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-700">Published</span>
                                    <p class="mt-1 text-xs text-slate-500">{{ $result->published_at?->format('M d, Y H:i') }} · {{ $result->publishedBy?->name }}</p>
                                @else
                                    <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">Unpublished</span>
                                    @unless($publishable)
                                        <p class="mt-1 text-xs text-rose-600">Not ready for publishing</p>
                                    @endunless
                                @endif
                            </td>
                            <td class="text-right">
                                @if($result->isPublished())
                                    @can('unpublish', $result)
                                        <form method="POST" action="{{ route('result-publishing.unpublish', $result) }}" class="inline">
                                            @csrf
                                            <button class="button !bg-rose-100 !text-rose-700" type="submit" onclick="return confirm('Unpublish this result? It will no longer be visible as published.')">Unpublish</button>
                                        </form>
                                    @endcan
                                @elseif($publishable)
                                    @can('publish', $result)
                                        <form method="POST" action="{{ route('result-publishing.publish', $result) }}" class="inline">
                                            @csrf
                                            <button class="button" type="submit" onclick="return confirm('Publish this result?')">Publish</button>
                                        </form>
                                    @endcan
                                @else
                                    <span class="text-xs text-slate-400">Blocked</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-6 text-slate-500" colspan="10">No results found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @can('publish', App\Models\ExamResult::class)
            <div class="mt-4">
                <button class="button" type="submit" onclick="return confirm('Publish the selected results? Publication is transactional — either every eligible selection is published or none is.')">
                    Publish selected
                </button>
            </div>
        @endcan
    </form>

    <div class="mt-4">{{ $results->links() }}</div>
</div>
@endsection
