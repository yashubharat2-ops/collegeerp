@extends('layouts.app')

@section('title', 'Bulk Registration / Import Students')

@section('content')
@php
    $preview = $preview ?? null;
    $token = $token ?? null;
@endphp

<div class="erp-student-page">
    <header class="erp-page-header">
        <div>
            <h2 class="erp-page-title">Bulk Registration / Import Students</h2>
            <p class="erp-page-sub">Create many students from a CSV using the same fields and rules as New Student. Nothing is written until the file is valid and you confirm.</p>
        </div>
        <a class="button !bg-slate-200 !text-slate-700" href="{{ route('students.index') }}">Back to students</a>
    </header>

    <div class="panel space-y-6">
        <section>
            <h3 class="panel-title">1. Download the template</h3>
            <p class="panel-subtitle mt-1">Column names must match the template. Academic year, program and section values are the existing master IDs for this college. Dates use DD/MM/YYYY.</p>
            <p class="mt-3">
                <a class="button" href="{{ route('students.import.template') }}">Download CSV template</a>
            </p>
        </section>

        <section>
            <h3 class="panel-title">2. Upload CSV</h3>
            <form method="POST" action="{{ route('students.import.validate') }}" enctype="multipart/form-data" class="mt-3 space-y-3">
                @csrf
                <div>
                    <label class="erp-label" for="student-import-file">CSV file</label>
                    <input class="erp-input" id="student-import-file" type="file" name="file" accept=".csv,text/csv" required>
                    <p class="erp-error">@error('file'){{ $message }}@enderror</p>
                </div>
                <button type="submit" class="button">Validate file</button>
            </form>
        </section>

        @if(is_array($preview))
            <section>
                <h3 class="panel-title">3. Validation result</h3>
                <p class="panel-subtitle mt-1">{{ (int) $preview['rows'] }} data row(s) read. The file is {{ !empty($preview['ok']) ? 'valid' : 'not valid' }}.</p>

                @if(!empty($preview['errors']))
                    <p class="mt-3 text-sm font-semibold text-rose-700">No students were created. Fix every row below and upload again.</p>
                    <div class="mt-3 overflow-x-auto">
                        <table class="erp-table">
                            <thead>
                                <tr>
                                    <th>Row</th>
                                    <th>Field</th>
                                    <th>Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($preview['errors'] as $error)
                                    <tr>
                                        <td>{{ $error['row'] ?: '—' }}</td>
                                        <td>{{ $error['field'] }}</td>
                                        <td>{{ $error['message'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if(!empty($preview['ok']) && $token)
                    <form method="POST" action="{{ route('students.import.store') }}" class="mt-4">
                        @csrf
                        <input type="hidden" name="token" value="{{ $token }}">
                        <p class="text-sm text-slate-600">{{ (int) $preview['rows'] }} valid student(s) will be created with server-generated student numbers. First enrollments use the existing enrollment workflow.</p>
                        <button type="submit" class="button mt-3">Confirm import</button>
                    </form>
                @endif
            </section>
        @endif
    </div>
</div>
@endsection
