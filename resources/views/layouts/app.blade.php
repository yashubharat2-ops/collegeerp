<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title', 'Dashboard') · {{ config('app.name', 'College ERP') }}</title>@vite(['resources/css/app.css','resources/js/app.js'])</head><body class="bg-slate-100 text-slate-900"><div class="min-h-screen lg:flex"><aside class="no-print w-full bg-slate-950 text-white lg:min-h-screen lg:w-72"><div class="flex items-center justify-between px-6 py-6"><div><p class="text-xs font-semibold uppercase tracking-widest text-indigo-300">College ERP</p><p class="mt-1 text-lg font-bold">Administration</p></div><button class="lg:hidden" aria-label="Open menu">☰</button></div><nav class="space-y-1 px-4 pb-6"><a class="nav-link" href="{{ route('dashboard') }}">▦ <span>Dashboard</span></a><div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">Platform</div>
<a class="nav-link" href="{{ route('campuses.index') }}">▣ <span>Campuses</span></a>
<a class="nav-link" href="{{ route('departments.index') }}">▤ <span>Departments</span></a>
<a class="nav-link" href="{{ route('programs.index') }}">▥ <span>Programs</span></a>
<a class="nav-link" href="{{ route('academic-years.index') }}">◫ <span>Academic years</span></a>
<a class="nav-link" href="{{ route('academic-terms.index') }}">🗓 <span>Academic Terms</span></a>
<a class="nav-link" href="{{ route('sections.index') }}">👥 <span>Sections / Batches</span></a>
<a class="nav-link" href="{{ route('subjects.index') }}">📚 <span>Subjects</span></a>
<a class="nav-link" href="{{ route('faculties.index') }}">👨‍🏫 <span>Faculty / Staff</span></a>
<a class="nav-link" href="{{ route('faculty-subject-assignments.index') }}">🔗 <span>Faculty–Subject Assignments</span></a><div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">Admissions</div>
<a class="nav-link" href="{{ route('admission.dashboard') }}">📊 <span>Dashboard</span></a>
<a class="nav-link" href="{{ route('admission-applicants.index') }}">👤 <span>Applicants</span></a>
<a class="nav-link" href="{{ route('admission-enquiries.index') }}">❓ <span>Enquiries</span></a>
<a class="nav-link" href="{{ route('admission-applications.index') }}">📝 <span>Applications</span></a>
<a class="nav-link" href="{{ route('admission-documents.index') }}">📄 <span>Documents</span></a>
<a class="nav-link" href="{{ route('admission-document-types.index') }}">📑 <span>Document Types</span></a>
<a class="nav-link" href="{{ route('admission-merit-lists.index') }}">🏅 <span>Merit / Selection</span></a>
 <a class="nav-link" href="{{ route('admissions.index') }}">🎓 <span>Admissions</span></a>
<a class="nav-link" href="{{ route('admission-reports.index') }}">📈 <span>Reports</span></a>
<div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">Students</div>
<a class="nav-link" href="{{ route('students.index') }}">🧑 <span>Students</span></a>
<a class="nav-link" href="{{ route('student-enrollments.index') }}">🗂 <span>Enrollments</span></a>
<a class="nav-link" href="{{ route('student-academic-records.index') }}">📚 <span>Academic Records</span></a>
<a class="nav-link" href="{{ route('student-documents.index') }}">📄 <span>Documents</span></a>
<a class="nav-link" href="{{ route('student-id-cards.index') }}">🪪 <span>ID Cards</span></a>
<a class="nav-link" href="{{ route('student-promotions.index') }}">🔄 <span>Promotion</span></a>
<a class="nav-link" href="{{ route('student-transfers.index') }}">🚚 <span>Transfer / TC</span></a>
<a class="nav-link" href="{{ route('student-history.index') }}">🕘 <span>Student History</span></a>
<div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">Academics</div>
<a class="nav-link" href="{{ route('academic-subject-enrollments.index') }}">📚 <span>Student Subject Enrollment</span></a>
<a class="nav-link" href="{{ route('academic-sections.index') }}">🏫 <span>Class / Section Management</span></a>
<a class="nav-link" href="{{ route('academic-timetables.index') }}">🗓 <span>Timetable</span></a>
<a class="nav-link" href="{{ route('academic-attendance.index') }}">✓ <span>Attendance</span></a>
<a class="nav-link" href="{{ route('academic-calendar.index') }}">📅 <span>Academic Calendar</span></a>
<a class="nav-link" href="{{ route('academic-workload.index') }}">👨‍🏫 <span>Faculty Workload</span></a>
@if(auth()->user()?->hasPermission('examinations.view') || auth()->user()?->hasPermission('exam_schedules.view') || auth()->user()?->hasPermission('exam_attendance.view') || auth()->user()?->hasPermission('exam_marks.view') || auth()->user()?->hasPermission('results.view') || auth()->user()?->hasPermission('result_calculation.view') || auth()->user()?->hasPermission('grade_scales.view') || auth()->user()?->hasPermission('result_publishing.view') || auth()->user()?->hasPermission('marksheets.view'))
<div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">Examinations</div>
@if(auth()->user()?->hasPermission('examinations.view'))
<a class="nav-link" href="{{ route('examinations.index') }}">📝 <span>Examinations</span></a>
@endif
@if(auth()->user()?->hasPermission('exam_schedules.view'))
<a class="nav-link" href="{{ route('exam-schedules.index') }}">🗓 <span>Exam Schedule</span></a>
@endif
@if(auth()->user()?->hasPermission('exam_attendance.view'))
<a class="nav-link" href="{{ route('exam-attendance.index') }}">✅ <span>Exam Attendance</span></a>
@endif
@if(auth()->user()?->hasPermission('exam_marks.view'))
<a class="nav-link" href="{{ route('exam-marks.index') }}">🔢 <span>Marks Entry</span></a>
@endif
@if(auth()->user()?->hasPermission('results.view'))
<a class="nav-link" href="{{ route('results.index') }}">📈 <span>Results</span></a>
@endif
@if(auth()->user()?->hasPermission('result_calculation.view'))
<a class="nav-link" href="{{ route('result-calculation.index') }}">🧮 <span>Result Calculation</span></a>
@endif
@if(auth()->user()?->hasPermission('grade_scales.view'))
<a class="nav-link" href="{{ route('grade-scales.index') }}">📏 <span>Grade / Pass-Fail</span></a>
@endif
@if(auth()->user()?->hasPermission('result_publishing.view'))
<a class="nav-link" href="{{ route('result-publishing.index') }}">📢 <span>Result Publishing</span></a>
@endif
@if(auth()->user()?->hasPermission('marksheets.view'))
<a class="nav-link" href="{{ route('marksheets.index') }}">🧾 <span>Marksheets</span></a>
@endif
@endif
<div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">Platform</div>
<a class="nav-link" href="{{ route('settings.index') }}">⚙ <span>Settings</span></a></nav></aside><section class="min-w-0 flex-1"><header class="no-print flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4"><div><div class="flex items-center gap-3"><p class="text-sm text-slate-500">{{ app(\App\Support\Tenancy\TenantContext::class)->college()?->name ?? 'Platform' }}</p>@isset($colleges) @if($colleges->count() > 1)<form method="POST" action="{{ route('college-context.switch') }}">@csrf<select class="rounded-lg border-slate-300 text-xs" name="college_id" onchange="this.form.submit()">@foreach($colleges as $college)<option value="{{ $college->id }}" @selected(app(\App\Support\Tenancy\TenantContext::class)->id() === $college->id)>{{ $college->name }}</option>@endforeach</select></form>@endif @endisset</div><h1 class="text-xl font-semibold">@yield('title', 'Dashboard')</h1></div><div class="flex items-center gap-4"><button class="relative text-slate-500" aria-label="Notifications">♢<span class="absolute -right-1 -top-1 h-2 w-2 rounded-full bg-indigo-500"></span></button><div class="flex items-center gap-3"><div class="grid h-9 w-9 place-items-center rounded-full bg-indigo-100 font-bold text-indigo-700">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div><div class="hidden text-sm sm:block"><p class="font-semibold">{{ auth()->user()->name }}</p><p class="text-slate-500">{{ auth()->user()->email }}</p></div><form method="POST" action="{{ route('logout') }}">@csrf<button class="text-sm text-slate-500 hover:text-rose-600" type="submit">Logout</button></form></div></div></header><main class="p-6">@if(session('success'))<div class="alert-success">{{ session('success') }}</div>@endif @if($errors->any())<div class="alert-error">{{ $errors->first() }}</div>@endif @yield('content')</main></section></div>@stack('scripts')</body></html>
