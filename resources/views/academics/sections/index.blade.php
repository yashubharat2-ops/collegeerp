@extends('layouts.app') @section('title','Class / Section Academic Management') @section('content')
<p class="mb-5 text-slate-500">Operational view of Platform sections. Sections are not managed here.</p>

{{-- Bulk selection over the section cards: the checkbox value is the section id,
     and the shared bar exports exactly the authorized selection. Sections are
     Platform master data, so the export is read-only and gated on this screen's
     own permission (academic_sections.view) — nothing is written. --}}
<x-list.bulk-selection-bar module="academic_sections">
    @if(auth()->user()?->hasPermission('academic_sections.view'))
        <button type="button" data-bulk-action="export"
                class="button !py-2 !text-xs font-semibold">
            Export selected
        </button>
    @endif
</x-list.bulk-selection-bar>

<div class="mb-4 flex items-center gap-2 text-xs text-slate-500">
    <x-list.select-all id="academic-sections-select-all" />
    <label for="academic-sections-select-all" class="cursor-pointer">Select all sections on this page</label>
</div>

<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    @forelse($items as $s)
        <div class="card hover:border-indigo-400">
            <div class="mb-3"><x-list.row-checkbox :id="$s->id" /></div>
            <a class="block" href="{{route('academic-sections.show',$s)}}">
                <h2 class="font-semibold">{{$s->program?->name}} · {{$s->name}}</h2>
                <p class="text-sm text-slate-500">{{$s->academicYear?->name}} · {{$s->campus?->name}}</p>
                <p class="mt-3 text-sm">Subjects: {{$s->subject_count}} · Faculty assignments: {{$s->assignments_count}}</p>
            </a>
        </div>
    @empty
        <p>No sections available.</p>
    @endforelse
</div>
{{$items->links()}}
@endsection
