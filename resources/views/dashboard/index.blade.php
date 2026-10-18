@extends('layouts.app')

@section('title', 'Dashboard')

@push('styles')
    @php($erpDashboardCssVersion = is_file($p = public_path('css/erp-dashboard.css')) ? (@hash_file('crc32b', $p) ?: @filemtime($p) ?: null) : null)
    <link rel="stylesheet" href="{{ asset('css/erp-dashboard.css') }}@if ($erpDashboardCssVersion)?v={{ $erpDashboardCssVersion }}@endif">
@endpush

@section('content')
    <div class="erp-dashboard" id="college-dashboard">
        <div class="erp-dashboard__inner">
            <header class="erp-dashboard__intro">
                <div class="erp-dashboard__welcome">
                    <p class="erp-dashboard__eyebrow">College overview</p>
                    <h2 class="erp-dashboard__title">Good day, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}.</h2>
                    <p class="erp-dashboard__description">A live, college-scoped view of your institution.</p>
                </div>
                <div class="erp-dashboard__context-group">
                    @if ($college)
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
                    @if ($canViewAcademicYear)
                        <div class="erp-dashboard__year-chip">
                            <span class="erp-dashboard__year-chip-label">Academic year</span>
                            <span>{{ $academicYear?->name ?? 'No active year set' }}</span>
                        </div>
                    @endif
                </div>
            </header>

            <section class="erp-dashboard__metrics" aria-label="College key performance indicators">
                @forelse ($metrics as $metric)
                    <article class="erp-dashboard__metric-card" aria-labelledby="metric-{{ $metric['key'] }}">
                        <div class="erp-dashboard__metric-topline">
                            <div class="erp-dashboard__metric-mark" aria-hidden="true">{{ $metric['format'] === 'money' ? 'F' : '＋' }}</div>
                            <span class="erp-dashboard__metric-kicker">{{ $academicYear?->name ?? 'College summary' }}</span>
                        </div>
                        <h3 class="erp-dashboard__metric-label" id="metric-{{ $metric['key'] }}">{{ $metric['label'] }}</h3>
                        <p class="erp-dashboard__metric-value {{ $metric['format'] === 'money' ? 'erp-dashboard__metric-value--money' : '' }}" data-dashboard-metric="{{ $metric['key'] }}">
                            @if ($metric['value'] === null)
                                <span class="erp-dashboard__unavailable">Unavailable</span>
                            @elseif ($metric['format'] === 'money')
                                {{ number_format((float) $metric['value'], 2) }}
                            @else
                                {{ number_format((int) $metric['value']) }}
                            @endif
                        </p>
                        <p class="erp-dashboard__metric-note">{{ $metric['note'] }}</p>
                        <a class="erp-dashboard__action" href="{{ $metric['url'] }}">
                            {{ $metric['action'] }} <span aria-hidden="true">&rarr;</span>
                        </a>
                    </article>
                @empty
                    <div class="erp-dashboard__no-metrics">
                        <strong>No dashboard metrics are available for this role.</strong>
                        <span>Metric cards appear only when your permissions allow access to their source data.</span>
                    </div>
                @endforelse
            </section>

            @if ($actionCenterAvailable || $canViewUpcomingExams)
                <section class="erp-dashboard__section" aria-labelledby="action-center-title">
                    <div class="erp-dashboard__section-heading">
                        <div>
                            <p class="erp-dashboard__eyebrow">Work queue</p>
                            <h2 class="erp-dashboard__section-title" id="action-center-title">Action center</h2>
                        </div>
                        @if ($hasActiveYear && $canViewAcademicYear)
                            <span class="erp-dashboard__section-context">{{ $academicYear->name }}</span>
                        @endif
                    </div>
                    <div class="erp-dashboard__action-grid">
                        <div class="erp-dashboard__panel erp-dashboard__action-panel">
                            <h3 class="erp-dashboard__panel-title">Items needing attention</h3>
                            @if (count($actions))
                                <ul class="erp-dashboard__action-list">
                                    @foreach ($actions as $action)
                                        <li>
                                            <a href="{{ $action['url'] }}">
                                                <span class="erp-dashboard__task-copy">{{ $action['label'] }}@if (!empty($action['note']))<small>{{ $action['note'] }}</small>@endif</span>
                                                <span class="erp-dashboard__task-count">{{ number_format($action['count']) }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="erp-dashboard__empty-copy">
                                    @if (! $hasActiveYear && $canViewApplications)
                                        Application work queues are unavailable until this college has an active academic year.
                                    @else
                                        No pending applications or documents were found in the available queues.
                                    @endif
                                </p>
                            @endif
                        </div>

                        @if ($canViewUpcomingExams)
                            <div class="erp-dashboard__panel erp-dashboard__action-panel">
                                <div class="erp-dashboard__panel-heading">
                                    <h3 class="erp-dashboard__panel-title">Upcoming examinations</h3>
                                    <a class="erp-dashboard__text-link" href="{{ route('exam-schedules.index', ['academic_year_id' => $currentAcademicYearId, 'status' => 'scheduled']) }}">View schedule</a>
                                </div>
                                @if ($upcomingExams->isNotEmpty())
                                    <ul class="erp-dashboard__exam-list">
                                        @foreach ($upcomingExams as $exam)
                                            <li>
                                                <span class="erp-dashboard__exam-date">{{ $exam->exam_date->format('M j') }}</span>
                                                <span class="erp-dashboard__exam-details">
                                                    <strong>{{ $exam->examination?->name ?? $exam->subject?->name ?? 'Scheduled examination' }}</strong>
                                                    <small>{{ $exam->start_time ? \Illuminate\Support\Carbon::parse($exam->start_time)->format('g:i A') : 'Time not set' }}</small>
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="erp-dashboard__empty-copy">No scheduled examinations are coming up for this academic year.</p>
                                @endif
                            </div>
                        @endif
                    </div>
                </section>
            @endif

            @if ($canViewProgramAnalytics || $canViewAttendance || $canViewAdmissionTrend)
                <section class="erp-dashboard__section" aria-labelledby="academic-analytics-title">
                    <div class="erp-dashboard__section-heading">
                        <div>
                            <p class="erp-dashboard__eyebrow">Academic analytics</p>
                            <h2 class="erp-dashboard__section-title" id="academic-analytics-title">Learning & admissions</h2>
                        </div>
                        @if ($hasActiveYear && $canViewAcademicYear)
                            <span class="erp-dashboard__section-context">{{ $academicYear->name }}</span>
                        @endif
                    </div>
                    <div class="erp-dashboard__analytics-grid">
                        @if ($canViewProgramAnalytics)
                            <article class="erp-dashboard__panel erp-dashboard__chart-panel" aria-labelledby="program-chart-title">
                                <div class="erp-dashboard__panel-heading">
                                    <div>
                                        <h3 class="erp-dashboard__panel-title" id="program-chart-title">Students by program</h3>
                                        <p class="erp-dashboard__panel-caption">Distinct students with an active current-year enrollment.</p>
                                    </div>
                                </div>
                                @if ($programCounts->isNotEmpty())
                                    @php($programMax = max(1, max(array_column($programCounts->all(), 'students'))))
                                    <ul class="erp-dashboard__horizontal-bars" aria-label="Active students grouped by program">
                                        @foreach ($programCounts as $row)
                                            <li>
                                                <span class="erp-dashboard__bar-label" title="{{ $row['program'] }}">{{ $row['program'] }}</span>
                                                <span class="erp-dashboard__bar-track"><span class="erp-dashboard__bar-fill" style="width: {{ round($row['students'] / $programMax * 100, 1) }}%"></span></span>
                                                <strong>{{ number_format($row['students']) }}</strong>
                                            </li>
                                        @endforeach
                                    </ul>
                                    <p class="erp-dashboard__chart-footnote">A student with active enrollments in multiple programs appears in each relevant program.</p>
                                @else
                                    <p class="erp-dashboard__empty-copy">No active student enrollments are recorded for this academic year.</p>
                                @endif
                            </article>
                        @endif

                        @if ($canViewAttendance)
                            <article class="erp-dashboard__panel erp-dashboard__chart-panel" aria-labelledby="attendance-summary-title">
                                <div class="erp-dashboard__panel-heading">
                                    <div>
                                        <h3 class="erp-dashboard__panel-title" id="attendance-summary-title">Student attendance</h3>
                                        <p class="erp-dashboard__panel-caption">Current academic year · present and late count as attended.</p>
                                    </div>
                                    <a class="erp-dashboard__text-link" href="{{ $attendanceReportUrl }}">Details</a>
                                </div>
                                @if ($attendance['percentage'] !== null)
                                    <div class="erp-dashboard__attendance-result">
                                        <strong>{{ number_format($attendance['percentage'], 1) }}<span>%</span></strong>
                                        <div>
                                            <span>{{ number_format($attendance['attended']) }} of {{ number_format($attendance['recorded']) }} recorded sessions</span>
                                            <div class="erp-dashboard__attendance-track"><span style="width: {{ $attendance['percentage'] }}%"></span></div>
                                        </div>
                                    </div>
                                    <p class="erp-dashboard__chart-footnote">Formula matches Academic Reports: (present + late) ÷ all recorded attendance rows.</p>
                                @else
                                    <p class="erp-dashboard__empty-copy">No attendance records are available for this academic year.</p>
                                @endif
                            </article>
                        @endif

                        @if ($canViewAdmissionTrend)
                            <article class="erp-dashboard__panel erp-dashboard__chart-panel erp-dashboard__chart-panel--wide" aria-labelledby="admission-trend-title">
                                <div class="erp-dashboard__panel-heading">
                                    <div>
                                        <h3 class="erp-dashboard__panel-title" id="admission-trend-title">Admission application trend</h3>
                                        <p class="erp-dashboard__panel-caption">Recorded application submissions by month · last six months · current academic year.</p>
                                    </div>
                                    <a class="erp-dashboard__text-link" href="{{ route('admission-applications.index', ['academic_year_id' => $currentAcademicYearId]) }}">Applications</a>
                                </div>
                                @php($admissionMax = max(1, max(array_column($admissionTrend, 'value'))))
                                <div class="erp-dashboard__month-chart" role="list" aria-label="First application submissions by month">
                                    @foreach ($admissionTrend as $month)
                                        <div class="erp-dashboard__month-column" role="listitem" aria-label="{{ $month['label'] }}: {{ $month['value'] }} submissions">
                                            <span class="erp-dashboard__month-value">{{ $month['value'] }}</span>
                                            <span class="erp-dashboard__month-track"><span class="erp-dashboard__month-bar" style="height: {{ $month['value'] > 0 ? max(7, round($month['value'] / $admissionMax * 100, 1)) : 0 }}%"></span></span>
                                            <span class="erp-dashboard__month-label">{{ $month['label'] }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </article>
                        @endif
                    </div>
                </section>
            @endif

            @if ($canViewFinancialAnalytics)
                <section class="erp-dashboard__section" aria-labelledby="financial-analytics-title">
                    <div class="erp-dashboard__section-heading">
                        <div>
                            <p class="erp-dashboard__eyebrow">Financial analytics</p>
                            <h2 class="erp-dashboard__section-title" id="financial-analytics-title">Collection activity</h2>
                        </div>
                        @if ($hasActiveYear && $canViewAcademicYear)
                            <span class="erp-dashboard__section-context">{{ $academicYear->name }}</span>
                        @endif
                    </div>
                    <article class="erp-dashboard__panel erp-dashboard__chart-panel">
                        <div class="erp-dashboard__panel-heading">
                            <div>
                                <h3 class="erp-dashboard__panel-title">Monthly fee collection</h3>
                                <p class="erp-dashboard__panel-caption">Completed payment rows by payment date · current academic year · refunds reported separately.</p>
                            </div>
                            <a class="erp-dashboard__text-link" href="{{ route('finance-reports.index', ['report' => 'summary', 'academic_year_id' => $currentAcademicYearId]) }}">Financial summary</a>
                        </div>
                        @php($collectionMax = max(1, max(array_column($collectionTrend, 'value'))))
                        <div class="erp-dashboard__month-chart" role="list" aria-label="Gross completed fee collections by month">
                            @foreach ($collectionTrend as $month)
                                <div class="erp-dashboard__month-column" role="listitem" aria-label="{{ $month['label'] }}: {{ number_format($month['value'], 2) }} collected">
                                    <span class="erp-dashboard__month-value">{{ number_format($month['value'], 0) }}</span>
                                    <span class="erp-dashboard__month-track"><span class="erp-dashboard__month-bar erp-dashboard__month-bar--teal" style="height: {{ $month['value'] > 0 ? max(7, round($month['value'] / $collectionMax * 100, 1)) : 0 }}%"></span></span>
                                    <span class="erp-dashboard__month-label">{{ $month['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </article>
                </section>
            @endif

            @if ($hasActivityPermission)
                <section class="erp-dashboard__section" aria-labelledby="recent-activity-title">
                    <div class="erp-dashboard__section-heading">
                        <div>
                            <p class="erp-dashboard__eyebrow">Audit trail</p>
                            <h2 class="erp-dashboard__section-title" id="recent-activity-title">Recent activity</h2>
                        </div>
                        <a class="erp-dashboard__text-link" href="{{ route('admin.audit-logs.index') }}">View audit log</a>
                    </div>
                    <div class="erp-dashboard__panel">
                        @if ($recentActivity->isNotEmpty())
                            <ul class="erp-dashboard__activity-list">
                                @foreach ($recentActivity as $activity)
                                    <li>
                                        <span class="erp-dashboard__activity-dot" aria-hidden="true"></span>
                                        <span class="erp-dashboard__activity-action">{{ $activity['action'] }}</span>
                                        <span class="erp-dashboard__activity-user">{{ $activity['user'] }}</span>
                                        <time class="erp-dashboard__activity-time" datetime="{{ $activity['created_at']->toIso8601String() }}">{{ $activity['created_at']->diffForHumans() }}</time>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="erp-dashboard__empty-copy">No audit activity has been recorded for this college yet.</p>
                        @endif
                    </div>
                </section>
            @endif
        </div>
    </div>
@endsection
