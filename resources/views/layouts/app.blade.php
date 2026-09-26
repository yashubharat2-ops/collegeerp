<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title', 'Dashboard') · {{ config('app.name', 'College ERP') }}</title>{{-- Link compiled assets only when a build (public/build/manifest.json) or a running dev server (public/hot) exists, so a page render never depends on running npm. --}}@if(is_file(public_path('build/manifest.json')) || is_file(public_path('hot')))@vite(['resources/css/app.css','resources/js/app.js'])@endif</head><body class="bg-slate-100 text-slate-900"><div class="min-h-screen lg:flex"><aside class="no-print w-full bg-slate-950 text-white lg:min-h-screen lg:w-72"><div class="flex items-center justify-between px-6 py-6"><div><p class="text-xs font-semibold uppercase tracking-widest text-indigo-300">College ERP</p><p class="mt-1 text-lg font-bold">Administration</p></div><button class="lg:hidden" aria-label="Open menu">☰</button></div><nav class="space-y-1 px-4 pb-6"><a class="nav-link" href="{{ route('dashboard') }}">▦ <span>Dashboard</span></a><div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">Platform</div>
<a class="nav-link" href="{{ route('campuses.index') }}">▣ <span>Campuses</span></a>
<a class="nav-link" href="{{ route('programs.index') }}">▥ <span>Programs</span></a>
<a class="nav-link" href="{{ route('academic-years.index') }}">◫ <span>Academic years</span></a>
<a class="nav-link" href="{{ route('academic-terms.index') }}">🗓 <span>Academic Terms</span></a>
<a class="nav-link" href="{{ route('sections.index') }}">👥 <span>Sections / Batches</span></a>
<a class="nav-link" href="{{ route('subjects.index') }}">📚 <span>Subjects</span></a>
<a class="nav-link" href="{{ route('faculty-subject-assignments.index') }}">🔗 <span>Faculty–Subject Assignments</span></a>
@if(auth()->user()?->hasPermission('faculties.view') || auth()->user()?->hasPermission('departments.view') || auth()->user()?->hasPermission('designations.view') || auth()->user()?->hasPermission('employee_documents.view') || auth()->user()?->hasPermission('staff_attendance.view') || auth()->user()?->hasPermission('leave_types.view') || auth()->user()?->hasPermission('leave_requests.view') || auth()->user()?->hasPermission('salary_structures.view') || auth()->user()?->hasPermission('salary_components.view') || auth()->user()?->hasPermission('payrolls.view') || auth()->user()?->hasPermission('hr_reports.view'))
<div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">HR / Staff Management</div>
@if(auth()->user()?->hasPermission('faculties.view'))
<a class="nav-link" href="{{ route('employees.index') }}">👥 <span>Staff / Employee</span></a>
@endif
@if(auth()->user()?->hasPermission('departments.view'))
<a class="nav-link" href="{{ route('staff-departments.index') }}">▤ <span>Staff Departments</span></a>
@endif
@if(auth()->user()?->hasPermission('designations.view'))
<a class="nav-link" href="{{ route('designations.index') }}">🏷 <span>Designations</span></a>
@endif
@if(auth()->user()?->hasPermission('employee_documents.view'))
<a class="nav-link" href="{{ route('employee-documents.index') }}">📄 <span>Employee Documents</span></a>
@endif
@if(auth()->user()?->hasPermission('staff_attendance.view'))
<a class="nav-link" href="{{ route('staff-attendance.index') }}">✓ <span>Staff Attendance</span></a>
@endif
@if(auth()->user()?->hasPermission('leave_requests.view') || auth()->user()?->hasPermission('leave_types.view'))
<a class="nav-link" href="{{ auth()->user()?->hasPermission('leave_requests.view') ? route('leave-requests.index') : route('leave-types.index') }}">🗓 <span>Leave Management</span></a>
@endif
@if(auth()->user()?->hasPermission('salary_structures.view') || auth()->user()?->hasPermission('salary_components.view') || auth()->user()?->hasPermission('payrolls.view'))
<a class="nav-link" href="{{ auth()->user()?->hasPermission('payrolls.view') ? route('payrolls.index') : (auth()->user()?->hasPermission('salary_structures.view') ? route('salary-structures.index') : route('salary-components.index')) }}">💰 <span>Staff Salary / Payroll</span></a>
@endif
@if(auth()->user()?->hasPermission('hr_reports.view'))
<a class="nav-link" href="{{ route('hr-reports.index') }}">📈 <span>HR Reports</span></a>
@endif
@endif
<div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">Admissions</div>
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
@if(auth()->user()?->hasPermission('examinations.view') || auth()->user()?->hasPermission('exam_schedules.view') || auth()->user()?->hasPermission('exam_attendance.view') || auth()->user()?->hasPermission('exam_marks.view') || auth()->user()?->hasPermission('results.view') || auth()->user()?->hasPermission('result_calculation.view') || auth()->user()?->hasPermission('grade_scales.view') || auth()->user()?->hasPermission('result_publishing.view') || auth()->user()?->hasPermission('marksheets.view') || auth()->user()?->hasPermission('grade_cards.view') || auth()->user()?->hasPermission('exam_reports.view') || auth()->user()?->hasPermission('student_result_history.view'))
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
@if(auth()->user()?->hasPermission('grade_cards.view'))
<a class="nav-link" href="{{ route('grade-cards.index') }}">🎓 <span>Grade Cards</span></a>
@endif
@if(auth()->user()?->hasPermission('exam_reports.view'))
<a class="nav-link" href="{{ route('exam-reports.index') }}">📊 <span>Exam Reports</span></a>
@endif
@if(auth()->user()?->hasPermission('student_result_history.view'))
<a class="nav-link" href="{{ route('student-result-history.index') }}">🕘 <span>Student Result History</span></a>
@endif
@endif
@if(auth()->user()?->hasPermission('fee_structures.view') || auth()->user()?->hasPermission('fee_categories.view') || auth()->user()?->hasPermission('student_fee_assignments.view') || auth()->user()?->hasPermission('fee_collections.view') || auth()->user()?->hasPermission('receipts.view') || auth()->user()?->hasPermission('fee_dues.view') || auth()->user()?->hasPermission('fee_concessions.view') || auth()->user()?->hasPermission('refunds.view') || auth()->user()?->hasPermission('fee_reports.view'))
<div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">Finance / Fees</div>
@if(auth()->user()?->hasPermission('fee_structures.view'))
<a class="nav-link" href="{{ route('fee-structures.index') }}">💰 <span>Fee Structures</span></a>
@endif
@if(auth()->user()?->hasPermission('fee_categories.view'))
<a class="nav-link" href="{{ route('fee-categories.index') }}">🏷 <span>Fee Categories</span></a>
@endif
@if(auth()->user()?->hasPermission('student_fee_assignments.view'))
<a class="nav-link" href="{{ route('student-fee-assignments.index') }}">🧾 <span>Student Fee Assignment</span></a>
@endif
@if(auth()->user()?->hasPermission('fee_collections.view'))
<a class="nav-link" href="{{ route('fee-collections.index') }}">💵 <span>Fee Collection</span></a>
@endif
@if(auth()->user()?->hasPermission('receipts.view'))
<a class="nav-link" href="{{ route('receipts.index') }}">🧻 <span>Receipts</span></a>
@endif
@if(auth()->user()?->hasPermission('fee_dues.view'))
<a class="nav-link" href="{{ route('fee-dues.index') }}">⏳ <span>Due / Outstanding Fees</span></a>
@endif
@if(auth()->user()?->hasPermission('fee_concessions.view'))
<a class="nav-link" href="{{ route('fee-concessions.index') }}">🎁 <span>Fee Discounts / Concessions</span></a>
@endif
@if(auth()->user()?->hasPermission('refunds.view'))
<a class="nav-link" href="{{ route('refunds.index') }}">↩ <span>Refunds</span></a>
@endif
@if(auth()->user()?->hasPermission('fee_reports.view'))
<a class="nav-link" href="{{ route('fee-reports.index') }}">📊 <span>Fee Reports</span></a>
@endif
@endif
@if(auth()->user()?->hasPermission('transport_dashboard.view') || auth()->user()?->hasPermission('vehicles.view') || auth()->user()?->hasPermission('vehicle_documents.view') || auth()->user()?->hasPermission('transport_drivers.view') || auth()->user()?->hasPermission('transport_routes.view') || auth()->user()?->hasPermission('student_transport_assignments.view') || auth()->user()?->hasPermission('transport_fees.view') || auth()->user()?->hasPermission('transport_reports.view'))
<div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">Transport Management</div>
@if(auth()->user()?->hasPermission('transport_dashboard.view'))
<a class="nav-link" href="{{ route('transport.dashboard') }}"><span>Transport Dashboard</span></a>
@endif
@if(auth()->user()?->hasPermission('vehicles.view'))
<a class="nav-link" href="{{ route('vehicles.index') }}"><span>Vehicles</span></a>
@endif
@if(auth()->user()?->hasPermission('vehicle_documents.view'))
<a class="nav-link" href="{{ route('vehicle-documents.index') }}"><span>Vehicle Documents</span></a>
@endif
@if(auth()->user()?->hasPermission('transport_drivers.view'))
<a class="nav-link" href="{{ route('transport-drivers.index') }}"><span>Drivers</span></a>
@endif
@if(auth()->user()?->hasPermission('transport_routes.view'))
<a class="nav-link" href="{{ route('transport-routes.index') }}"><span>Routes</span></a>
@endif
@if(auth()->user()?->hasPermission('transport_routes.view'))
<a class="nav-link" href="{{ route('transport-stops.list') }}"><span>Stops</span></a>
@endif
@if(auth()->user()?->hasPermission('student_transport_assignments.view'))
<a class="nav-link" href="{{ route('transport-assignments.index') }}"><span>Student Transport Assignment</span></a>
@endif
@if(auth()->user()?->hasPermission('transport_fees.view'))
<a class="nav-link" href="{{ route('transport-fees.index') }}"><span>Transport Fees</span></a>
@endif
@if(auth()->user()?->hasPermission('transport_reports.view'))
<a class="nav-link" href="{{ route('transport-reports.index') }}"><span>Transport Reports</span></a>
@endif
@endif
@if(auth()->user()?->hasPermission('library_dashboard.view') || auth()->user()?->hasPermission('books.view') || auth()->user()?->hasPermission('book_categories.view') || auth()->user()?->hasPermission('authors.view') || auth()->user()?->hasPermission('publishers.view') || auth()->user()?->hasPermission('book_copies.view') || auth()->user()?->hasPermission('library_members.view') || auth()->user()?->hasPermission('library_transactions.view') || auth()->user()?->hasPermission('library_renewals.view') || auth()->user()?->hasPermission('library_fines.view') || auth()->user()?->hasPermission('library_reports.view'))
<div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">Library Management</div>
@if(auth()->user()?->hasPermission('library_dashboard.view'))
<a class="nav-link" href="{{ route('library.dashboard') }}">📚 <span>Library Dashboard</span></a>
@endif
@if(auth()->user()?->hasPermission('books.view'))
<a class="nav-link" href="{{ route('books.index') }}">📖 <span>Books</span></a>
@endif
@if(auth()->user()?->hasPermission('book_categories.view'))
<a class="nav-link" href="{{ route('book-categories.index') }}">🗂 <span>Book Categories</span></a>
@endif
@if(auth()->user()?->hasPermission('authors.view'))
<a class="nav-link" href="{{ route('authors.index') }}">✍ <span>Authors / Publishers</span></a>
@elseif(auth()->user()?->hasPermission('publishers.view'))
<a class="nav-link" href="{{ route('publishers.index') }}">✍ <span>Authors / Publishers</span></a>
@endif
@if(auth()->user()?->hasPermission('book_copies.view'))
<a class="nav-link" href="{{ route('book-copies.index') }}">📦 <span>Book Copies</span></a>
@endif
@if(auth()->user()?->hasPermission('library_members.view'))
<a class="nav-link" href="{{ route('library-members.index') }}">🪪 <span>Library Members</span></a>
@endif
@if(auth()->user()?->hasPermission('library_transactions.view'))
<a class="nav-link" href="{{ route('library-transactions.index') }}">🔁 <span>Issue / Return</span></a>
@endif
@if(auth()->user()?->hasPermission('library_renewals.view'))
<a class="nav-link" href="{{ route('library-renewals.index') }}">↻ <span>Renewals</span></a>
@endif
@if(auth()->user()?->hasPermission('library_fines.view'))
<a class="nav-link" href="{{ route('library-fines.index') }}">💰 <span>Fines / Penalties</span></a>
@endif
@if(auth()->user()?->hasPermission('library_reports.view'))
<a class="nav-link" href="{{ route('library-reports.index') }}">📊 <span>Library Reports</span></a>
@endif
@endif
@if(auth()->user()?->hasPermission('hostel_dashboard.view') || auth()->user()?->hasPermission('hostels.view') || auth()->user()?->hasPermission('hostel_buildings.view') || auth()->user()?->hasPermission('hostel_rooms.view') || auth()->user()?->hasPermission('hostel_beds.view') || auth()->user()?->hasPermission('hostel_allocations.view') || auth()->user()?->hasPermission('hostel_fees.view') || auth()->user()?->hasPermission('hostel_attendance.view') || auth()->user()?->hasPermission('hostel_reports.view'))
<div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">Hostel Management</div>
@if(auth()->user()?->hasPermission('hostel_dashboard.view'))
<a class="nav-link" href="{{ route('hostels.dashboard') }}">🏨 <span>Hostel Dashboard</span></a>
@endif
@if(auth()->user()?->hasPermission('hostels.view'))
<a class="nav-link" href="{{ route('hostels.index') }}">🏢 <span>Hostels</span></a>
@endif
@if(auth()->user()?->hasPermission('hostel_buildings.view'))
<a class="nav-link" href="{{ route('hostel-buildings.index') }}">▦ <span>Buildings / Blocks</span></a>
@endif
@if(auth()->user()?->hasPermission('hostel_rooms.view'))
<a class="nav-link" href="{{ route('hostel-rooms.index') }}">🚪 <span>Rooms</span></a>
@endif
@if(auth()->user()?->hasPermission('hostel_beds.view'))
<a class="nav-link" href="{{ route('hostel-beds.index') }}">🛏 <span>Beds</span></a>
@endif
@if(auth()->user()?->hasPermission('hostel_allocations.view'))
<a class="nav-link" href="{{ route('hostel-allocations.index') }}">🛏 <span>Hostel Allocation</span></a>
@endif
@if(auth()->user()?->hasPermission('hostel_fees.view'))
<a class="nav-link" href="{{ route('hostel-fees.index') }}">💰 <span>Hostel Fees</span></a>
@endif
@if(auth()->user()?->hasPermission('hostel_attendance.view'))
<a class="nav-link" href="{{ route('hostel-attendance.index') }}">✅ <span>Hostel Attendance</span></a>
@endif
@if(auth()->user()?->hasPermission('hostel_reports.view'))
<a class="nav-link" href="{{ route('hostel-reports.index') }}">📊 <span>Hostel Reports</span></a>
@endif
@endif
@if(auth()->user()?->hasPermission('communication_dashboard.view') || auth()->user()?->hasPermission('notices.view') || auth()->user()?->hasPermission('circulars.view') || auth()->user()?->hasPermission('notifications.view') || auth()->user()?->hasPermission('communication_templates.view') || auth()->user()?->hasPermission('communication_logs.view') || auth()->user()?->hasPermission('communication_tracking.view') || auth()->user()?->hasPermission('communication_reports.view'))
<div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">Communication Management</div>
@if(auth()->user()?->hasPermission('communication_dashboard.view'))
<a class="nav-link" href="{{ route('communication.dashboard') }}">📡 <span>Communication Dashboard</span></a>
@endif
@if(auth()->user()?->hasPermission('notices.view'))
<a class="nav-link" href="{{ route('notices.index') }}">📣 <span>Notices / Announcements</span></a>
@endif
@if(auth()->user()?->hasPermission('circulars.view'))
<a class="nav-link" href="{{ route('circulars.index') }}">📜 <span>Circulars</span></a>
@endif
@if(auth()->user()?->hasPermission('notifications.view'))
<a class="nav-link" href="{{ route('notifications.index') }}">🔔 <span>Notifications</span></a>
@endif
@if(auth()->user()?->hasPermission('communication_templates.view'))
<a class="nav-link" href="{{ route('communication-templates.index') }}">🧩 <span>SMS / Email Templates</span></a>
@endif
@if(auth()->user()?->hasPermission('communication_logs.view'))
<a class="nav-link" href="{{ route('communication-logs.index') }}">🗒 <span>SMS / Email Logs</span></a>
@endif
@if(auth()->user()?->hasPermission('communication_tracking.view'))
<a class="nav-link" href="{{ route('communication-tracking.index') }}">📬 <span>Delivery / Read Tracking</span></a>
@endif
@if(auth()->user()?->hasPermission('communication_reports.view'))
<a class="nav-link" href="{{ route('communication-reports.index') }}">📊 <span>Communication Reports</span></a>
@endif
@endif
@if(auth()->user()?->hasPermission('inventory_dashboard.view') || auth()->user()?->hasPermission('inventory_categories.view') || auth()->user()?->hasPermission('inventory_items.view') || auth()->user()?->hasPermission('inventory_vendors.view') || auth()->user()?->hasPermission('inventory_purchase_orders.view') || auth()->user()?->hasPermission('inventory_goods_receipts.view') || auth()->user()?->hasPermission('inventory_stock_adjustments.view') || auth()->user()?->hasPermission('inventory_transactions.view') || auth()->user()?->hasPermission('inventory_stock.view') || auth()->user()?->hasPermission('inventory_issues.view') || auth()->user()?->hasPermission('inventory_assignments.view') || auth()->user()?->hasPermission('inventory_asset_returns.view') || auth()->user()?->hasPermission('inventory_maintenance.view'))
<div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">Inventory / Asset Management</div>
@if(auth()->user()?->hasPermission('inventory_dashboard.view'))
<a class="nav-link" href="{{ route('inventory.dashboard') }}">📦 <span>Inventory Dashboard</span></a>
@endif
@if(auth()->user()?->hasPermission('inventory_categories.view'))
<a class="nav-link" href="{{ route('inventory-categories.index') }}">🗂 <span>Item Categories</span></a>
@endif
@if(auth()->user()?->hasPermission('inventory_items.view'))
<a class="nav-link" href="{{ route('inventory-items.index') }}">🧰 <span>Items / Assets</span></a>
@endif
@if(auth()->user()?->hasPermission('inventory_vendors.view'))
<a class="nav-link" href="{{ route('inventory-vendors.index') }}">🏪 <span>Vendors</span></a>
@endif
@if(auth()->user()?->hasPermission('inventory_purchase_orders.view'))
<a class="nav-link" href="{{ route('inventory-purchase-orders.index') }}">📝 <span>Purchase Orders</span></a>
@endif
@if(auth()->user()?->hasPermission('inventory_goods_receipts.view'))
<a class="nav-link" href="{{ route('inventory-goods-receipts.index') }}">📥 <span>Goods Receipt / Stock In</span></a>
@endif
@if(auth()->user()?->hasPermission('inventory_stock_adjustments.view'))
<a class="nav-link" href="{{ route('inventory-stock-adjustments.index') }}">⚖️ <span>Stock Adjustment</span></a>
@endif
@if(auth()->user()?->hasPermission('inventory_transactions.view'))
<a class="nav-link" href="{{ route('inventory-transactions.index') }}">📜 <span>Inventory Transactions</span></a>
@endif
@if(auth()->user()?->hasPermission('inventory_issues.view'))
<a class="nav-link" href="{{ route('inventory-issues.index') }}">📤 <span>Item Issue / Allocation</span></a>
@endif
@if(auth()->user()?->hasPermission('inventory_assignments.view'))
<a class="nav-link" href="{{ route('inventory-assignments.index') }}">🧑‍🎓 <span>Asset Assignment</span></a>
@endif
@if(auth()->user()?->hasPermission('inventory_asset_returns.view'))
<a class="nav-link" href="{{ route('inventory-asset-returns.index') }}">🔄 <span>Asset Return</span></a>
@endif
@if(auth()->user()?->hasPermission('inventory_maintenance.view'))
<a class="nav-link" href="{{ route('inventory-maintenances.index') }}">🔧 <span>Asset Maintenance</span></a>
@endif
@endif
<div class="px-3 pb-2 pt-6 text-xs font-semibold uppercase tracking-widest text-slate-500">Platform</div>
<a class="nav-link" href="{{ route('settings.index') }}">⚙ <span>Settings</span></a></nav></aside><section class="min-w-0 flex-1"><header class="no-print flex items-center justify-between border-b border-slate-200 bg-white px-6 py-4"><div><div class="flex items-center gap-3"><p class="text-sm text-slate-500">{{ app(\App\Support\Tenancy\TenantContext::class)->college()?->name ?? 'Platform' }}</p>@isset($colleges) @if($colleges->count() > 1)<form method="POST" action="{{ route('college-context.switch') }}">@csrf<select class="rounded-lg border-slate-300 text-xs" name="college_id" onchange="this.form.submit()">@foreach($colleges as $college)<option value="{{ $college->id }}" @selected(app(\App\Support\Tenancy\TenantContext::class)->id() === $college->id)>{{ $college->name }}</option>@endforeach</select></form>@endif @endisset</div><h1 class="text-xl font-semibold">@yield('title', 'Dashboard')</h1></div><div class="flex items-center gap-4"><button class="relative text-slate-500" aria-label="Notifications">♢<span class="absolute -right-1 -top-1 h-2 w-2 rounded-full bg-indigo-500"></span></button><div class="flex items-center gap-3"><div class="grid h-9 w-9 place-items-center rounded-full bg-indigo-100 font-bold text-indigo-700">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div><div class="hidden text-sm sm:block"><p class="font-semibold">{{ auth()->user()->name }}</p><p class="text-slate-500">{{ auth()->user()->email }}</p></div><form method="POST" action="{{ route('logout') }}">@csrf<button class="text-sm text-slate-500 hover:text-rose-600" type="submit">Logout</button></form></div></div></header><main class="p-6">@if(session('success'))<div class="alert-success">{{ session('success') }}</div>@endif @if($errors->any())<div class="alert-error">{{ $errors->first() }}</div>@endif @yield('content')</main></section></div>@stack('scripts')</body></html>
