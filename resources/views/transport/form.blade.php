@extends('layouts.app')
@section('title', ($record ? 'Edit ' : 'Add ').$title)
@section('content')
@php($params = $parent ? ['transport_route' => $parent->id] : [])
<div class="panel">
    <h2 class="panel-title">{{ $record ? 'Edit' : 'Add' }} {{ $title }}</h2>
    <p class="panel-subtitle">{{ $parent ? 'Route: '.$parent->name.'. ' : '' }}Identifiers are normalized to uppercase and remain reserved after deletion.</p>
    @if($errors->any())<div class="alert-error mt-4"><ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route($routeName.($record ? '.update' : '.store'), $params + ($record ? ['record' => $record->id] : [])) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
        @csrf
        @if($record) @method('PUT') @endif
        @foreach($fields as $field => $type)
            @php($value = old($field, $record?->$field ?? ($field === 'status' ? 'active' : '')))
            <label class="block text-sm font-medium text-slate-700">
                {{ $field === 'faculty_id' ? 'Faculty / Staff' : \Illuminate\Support\Str::headline($field) }}
                @if($type === 'select')
                    <select class="input mt-1" name="{{ $field }}" required>@foreach($statuses as $status)<option value="{{ $status }}" @selected($value === $status)>{{ ucfirst($status) }}</option>@endforeach</select>
                @elseif($type === 'staff')
                    <select class="input mt-1" name="faculty_id" required><option value="">Select existing staff</option>@foreach($staff as $person)<option value="{{ $person->id }}" @selected((string)$value === (string)$person->id)>{{ $person->full_name }} ({{ $person->employee_code }})</option>@endforeach</select>
                @elseif($type === 'textarea')
                    <textarea class="input mt-1" name="{{ $field }}" maxlength="2000" rows="3">{{ $value }}</textarea>
                @else
                    <input class="input mt-1" type="{{ $type }}" name="{{ $field }}" value="{{ $type === 'time' ? substr($value, 0, 5) : $value }}" @if($type === 'number') min="1" step="1" @endif @required(in_array($field, ['name','code','registration_number','license_number','license_type','license_expiry','seating_capacity','sequence']))>
                @endif
                @error($field)<span class="text-red-600">{{ $message }}</span>@enderror
            </label>
        @endforeach
        <div class="flex gap-2 sm:col-span-2">
            <button class="button" type="submit">Save</button>
            <a class="button !bg-slate-200 !text-slate-700" href="{{ route($routeName.'.index', $params) }}">Cancel</a>
        </div>
    </form>
</div>
@endsection
