@extends('layouts.app')

@section('title', 'Dashboard')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/erp-dashboard.css') }}@if (is_file(public_path('css/erp-dashboard.css')))?v={{ filemtime(public_path('css/erp-dashboard.css')) ?: null }}@endif">
@endpush

@section('content')
    <div class="erp-dashboard" id="college-dashboard">
        <div class="erp-dashboard__inner">
            <header class="erp-dashboard__intro">
                <div>
                    <p class="erp-dashboard__eyebrow">College overview</p>
                    <h2 class="erp-dashboard__title">Good day, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}.</h2>
                    <p class="erp-dashboard__description">A focused view of the information available in your current college.</p>
                </div>
                @if ($college = app(\App\Support\Tenancy\TenantContext::class)->college())
                    <div class="erp-dashboard__context" aria-label="Current college">
                        <span class="erp-dashboard__context-dot" aria-hidden="true"></span>
                        <span><span class="erp-dashboard__context-label">Current college</span>{{ $college->name }}</span>
                    </div>
                @else
                    <div class="erp-dashboard__context erp-dashboard__context--platform">
                        <span class="erp-dashboard__context-dot" aria-hidden="true"></span>
                        <span>Platform workspace</span>
                    </div>
                @endif
            </header>

            <section class="erp-dashboard__grid" aria-label="College dashboard">
                <article class="erp-dashboard__metric-card" aria-labelledby="student-records-title">
                    @if ($canViewStudentMetric)
                        <div class="erp-dashboard__metric-topline">
                            <div class="erp-dashboard__metric-mark" aria-hidden="true">SR</div>
                            <span class="erp-dashboard__metric-kicker">Current college</span>
                        </div>
                        <h3 class="erp-dashboard__metric-label" id="student-records-title">Student records</h3>
                        <p class="erp-dashboard__metric-value" data-dashboard-student-count>{{ number_format($studentRecordCount) }}</p>
                        <p class="erp-dashboard__metric-note">Non-archived student profiles, across all statuses.</p>
                        @if ($studentRecordCount === 0)
                            <p class="erp-dashboard__empty-note">No student records have been added to this college yet.</p>
                        @endif
                        <a class="erp-dashboard__action" href="{{ route('students.index') }}">
                            Browse student records <span aria-hidden="true">&rarr;</span>
                        </a>
                    @else
                        <div class="erp-dashboard__metric-topline">
                            <div class="erp-dashboard__metric-mark" aria-hidden="true">OV</div>
                            <span class="erp-dashboard__metric-kicker">College overview</span>
                        </div>
                        <h3 class="erp-dashboard__metric-label" id="student-records-title">Dashboard metrics</h3>
                        <p class="erp-dashboard__access-message">
                            Student totals are available to users with student-view access while a college is selected.
                        </p>
                    @endif
                </article>

                <aside class="erp-dashboard__info-card" aria-labelledby="workspace-title">
                    <div class="erp-dashboard__info-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 10.5 12 4l9 6.5"></path>
                            <path d="M5.5 9.5v10h13v-10M9 19.5v-6h6v6"></path>
                        </svg>
                    </div>
                    <p class="erp-dashboard__eyebrow">Your workspace</p>
                    <h3 class="erp-dashboard__info-title" id="workspace-title">Built around your college</h3>
                    <p class="erp-dashboard__info-copy">
                        Dashboard information is limited to your selected college and the permissions assigned to your role.
                    </p>
                    <div class="erp-dashboard__info-rule"></div>
                    <p class="erp-dashboard__info-footnote">Only verified, permission-appropriate data appears here. Additional charts are omitted until reliable sources are available.</p>
                </aside>
            </section>
        </div>
    </div>
@endsection
