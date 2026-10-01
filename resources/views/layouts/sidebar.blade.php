@php
    /**
     * College ERP — the application sidebar, as a single component.
     *
     * Structure, top to bottom (the first three blocks stay put, only the module
     * list scrolls): brand header · menu search · Dashboard · module navigation.
     *
     * The signed-in user is NOT part of this component any more: the account area
     * lives in the header (<x-user.menu />), so the same name, e-mail and avatar
     * are no longer rendered twice.
     *
     * Everything here is presentation. The module tree, its permission gates and
     * its URLs are exactly the ones the previous flat layout carried: they were
     * lifted verbatim into <x-nav.group> / <x-nav.link> so row anatomy, active
     * state and collapsibility are defined once instead of ~130 times.
     *
     * Styling lives in public/css/erp-sidebar.css and behaviour in
     * public/js/erp-sidebar.js, both linked directly by
     * resources/views/layouts/app.blade.php — not through @vite — so the sidebar
     * keeps its look and its interactions even when no frontend build exists.
     */
    $navBrand = $institutionBrand ?? ['short_name' => null, 'has_logo' => false];
    $navBrandName = trim((string) ($navBrand['short_name'] ?? '')) ?: 'College ERP';
    $navBrandProduct = $navBrandName === 'College ERP' ? 'Student Management' : 'College ERP';
    $navBrandMark = $navBrandName === 'College ERP'
        ? 'ERP'
        : mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $navBrandName) ?: 'ERP', 0, 3));

    /*
     * Whether this account wants the rail. The server renders the choice as
     * `data-rail-default` so the first paint is already right instead of expanding to
     * 280px and snapping shut a frame later; the behaviour script only treats it as the
     * starting point, because a click on the rail toggle in this browser is the stronger
     * signal and wins for as long as it is remembered (preferredRail() in
     * public/js/erp-sidebar.js). Nothing else about the sidebar is user-configurable.
     */
    $navRailAccount = auth()->user();
    $navRailByDefault = $navRailAccount === null
        ? false
        : (bool) app(\App\Services\Settings\UserPreferenceService::class)->resolved($navRailAccount)['sidebar.rail_by_default'];
@endphp

<aside class="erp-sidebar no-print" id="erp-sidebar" data-rail="false" data-rail-default="{{ $navRailByDefault ? 'true' : 'false' }}" aria-label="College ERP navigation">
    {{-- 1 · Brand header (64px): the product identity, never the framework name. --}}
    <div class="erp-nav-brand">
        <div class="erp-nav-brand__mark" aria-hidden="true">
            @if ($navBrand['has_logo'] ?? false)
                <img src="{{ route('admin.institution-settings.logo') }}" alt="">
            @else
                {{ $navBrandMark }}
            @endif
        </div>
        <div class="erp-nav-brand__text">
            <p class="erp-nav-brand__name">{{ $navBrandName }}</p>
            <p class="erp-nav-brand__product">{{ $navBrandProduct }}</p>
        </div>
        <button type="button" class="erp-nav-button erp-nav-button--rail" data-nav-rail-toggle aria-controls="erp-nav" aria-expanded="true" aria-label="Collapse sidebar">
            <x-nav.icon name="menu" :size="18" />
        </button>
        <button type="button" class="erp-nav-button erp-nav-button--close" data-nav-drawer-close aria-label="Close menu">
            <x-nav.icon name="close" :size="18" />
        </button>
    </div>

    {{-- 2 · Menu search (40px field): filters the module list, never the routes. --}}
    <div class="erp-nav-search">
        <div class="erp-nav-search__field">
            <span class="erp-nav-search__icon"><x-nav.icon name="search" :size="16" /></span>
            <input type="search" class="erp-nav-search__input" data-nav-search placeholder="Search menu…" aria-label="Search menu items" aria-controls="erp-nav-list" autocomplete="off" spellcheck="false">
            <button type="button" class="erp-nav-search__clear" data-nav-search-clear aria-label="Clear menu search" hidden>
                <x-nav.icon name="close" :size="12" />
            </button>
        </div>
    </div>

    {{-- 3 · Dashboard: a navigation row in its own right, not a section heading. --}}
    <div class="erp-nav-primary">
        <a class="nav-dashboard" href="{{ route('dashboard') }}"@if (request()->routeIs('dashboard')) aria-current="page"@endif>
            <span class="nav-dashboard__icon"><x-nav.icon name="home" :size="18" /></span>
            <span>Dashboard</span>
        </a>
    </div>

    {{-- 4 · Module navigation: the only scrollable region of the sidebar. --}}
    <nav class="erp-nav" id="erp-nav" aria-label="College ERP modules">
        <p class="erp-nav__section-label" id="erp-nav-section-label">Navigation</p>
        <div class="erp-nav__scroll">
            <p class="erp-nav__empty" data-nav-empty hidden>No menu item matches that search.</p>
            <ul class="erp-nav__list" id="erp-nav-list" aria-labelledby="erp-nav-section-label">
                    <x-nav.group id="platform" label="Platform" icon="grid">
                        <x-nav.link label="Campuses" route="campuses.index" />
                        <x-nav.link label="Programs" route="programs.index" />
                        <x-nav.link label="Academic years" route="academic-years.index" />
                        <x-nav.link label="Academic Terms" route="academic-terms.index" />
                        <x-nav.link label="Sections / Batches" route="sections.index" />
                        <x-nav.link label="Subjects" route="subjects.index" />
                        <x-nav.link label="Faculty–Subject Assignments" route="faculty-subject-assignments.index" />
                    </x-nav.group>
                    <x-nav.group id="hr" label="Human Resource (HR)" icon="users" perm="faculties.view|departments.view|designations.view|employee_documents.view|staff_attendance.view|leave_types.view|leave_requests.view|salary_structures.view|salary_components.view|payrolls.view">
                        <x-nav.link label="Staff / Employee" route="employees.index" perm="faculties.view" />
                        <x-nav.link label="Staff Departments" route="staff-departments.index" perm="departments.view" />
                        <x-nav.link label="Designations" route="designations.index" perm="designations.view" />
                        <x-nav.link label="Employee Documents" route="employee-documents.index" perm="employee_documents.view" />
                        <x-nav.link label="Staff Attendance" route="staff-attendance.index" perm="staff_attendance.view" />
                        <x-nav.link label="Leave Management" :href="auth()->user()?->hasPermission('leave_requests.view') ? route('leave-requests.index') : route('leave-types.index')" pattern="leave-requests.*|leave-types.*" perm="leave_requests.view|leave_types.view" />
                        <x-nav.link label="Staff Salary / Payroll" :href="auth()->user()?->hasPermission('payrolls.view') ? route('payrolls.index') : (auth()->user()?->hasPermission('salary_structures.view') ? route('salary-structures.index') : route('salary-components.index'))" pattern="payrolls.*|salary-structures.*|salary-components.*" perm="salary_structures.view|salary_components.view|payrolls.view" />
                    </x-nav.group>
                    <x-nav.group id="admissions" label="Admissions" icon="user-plus">
                        <x-nav.link label="Dashboard" route="admission.dashboard" />
                        <x-nav.link label="Applicants" route="admission-applicants.index" />
                        <x-nav.link label="Enquiries" route="admission-enquiries.index" />
                        <x-nav.link label="Applications" route="admission-applications.index" />
                        <x-nav.link label="Documents" route="admission-documents.index" />
                        <x-nav.link label="Document Types" route="admission-document-types.index" />
                        <x-nav.link label="Merit / Selection" route="admission-merit-lists.index" />
                        <x-nav.link label="Admissions" route="admissions.index" />
                        <x-nav.link label="Reports" route="admission-reports.index" />
                    </x-nav.group>
                    <x-nav.group id="students" label="Students" icon="graduation-cap">
                        <x-nav.link label="Students" route="students.index" />
                        <x-nav.link label="Enrollments" route="student-enrollments.index" />
                        <x-nav.link label="Academic Records" route="student-academic-records.index" />
                        <x-nav.link label="Documents" route="student-documents.index" />
                        <x-nav.link label="ID Cards" route="student-id-cards.index" />
                        <x-nav.link label="Promotion" route="student-promotions.index" />
                        <x-nav.link label="Student Transfers" route="student-transfers.index" />
                        <x-nav.link label="Student History" route="student-history.index" />
                    </x-nav.group>
                    <x-nav.group id="certificates" label="Certificate" icon="award" perm="certificates.view|certificate_types.manage|certificate_templates.manage|certificate_reports.view">
                        <x-nav.link label="Transfer Certificate (TC)" route="certificates.requests.index" :params="['type' => 'TC']" pattern="certificates.*" :query="['type' => 'TC']" perm="certificates.view" />
                        <x-nav.link label="Bonafide Certificate" route="certificates.requests.index" :params="['type' => 'BON']" pattern="certificates.*" :query="['type' => 'BON']" perm="certificates.view" />
                        <x-nav.link label="Character Certificate" route="certificates.requests.index" :params="['type' => 'CHAR']" pattern="certificates.*" :query="['type' => 'CHAR']" perm="certificates.view" />
                        <x-nav.link label="Course Completion Certificate" route="certificates.requests.index" :params="['type' => 'CC']" pattern="certificates.*" :query="['type' => 'CC']" perm="certificates.view" />
                        <x-nav.link label="Migration Certificate" route="certificates.requests.index" :params="['type' => 'MIG']" pattern="certificates.*" :query="['type' => 'MIG']" perm="certificates.view" />
                        <x-nav.link label="Provisional Certificate" route="certificates.requests.index" :params="['type' => 'PROV']" pattern="certificates.*" :query="['type' => 'PROV']" perm="certificates.view" />
                        <x-nav.link label="Custom Certificate" :href="auth()->user()->hasPermission('certificates.view') ? route('certificates.requests.index', ['type' => 'CUSTOM']) : route('certificates.types')" perm="certificates.view|certificate_types.manage" pattern="certificates.*" :query="['type' => 'CUSTOM']" />
                        <x-nav.link label="Certificate Templates" route="certificates.templates.index" perm="certificate_templates.manage" />
                        <x-nav.link label="Certificate Reports" route="certificates.reports.index" perm="certificate_reports.view" />
                    </x-nav.group>
                    <x-nav.group id="academics" label="Academics" icon="book-open">
                        <x-nav.link label="Student Subject Enrollment" route="academic-subject-enrollments.index" />
                        <x-nav.link label="Class / Section Management" route="academic-sections.index" />
                        <x-nav.link label="Timetable" route="academic-timetables.index" />
                        <x-nav.link label="Attendance" route="academic-attendance.index" />
                        <x-nav.link label="Academic Calendar" route="academic-calendar.index" />
                        <x-nav.link label="Faculty Workload" route="academic-workload.index" />
                    </x-nav.group>
                    <x-nav.group id="examinations" label="Examinations" icon="clipboard-check" perm="examinations.view|exam_schedules.view|exam_attendance.view|exam_marks.view|results.view|result_calculation.view|grade_scales.view|result_publishing.view|marksheets.view|grade_cards.view|exam_reports.view|student_result_history.view">
                        <x-nav.link label="Examinations" route="examinations.index" perm="examinations.view" />
                        <x-nav.link label="Exam Schedule" route="exam-schedules.index" perm="exam_schedules.view" />
                        <x-nav.link label="Exam Attendance" route="exam-attendance.index" perm="exam_attendance.view" />
                        <x-nav.link label="Marks Entry" route="exam-marks.index" perm="exam_marks.view" />
                        <x-nav.link label="Results" route="results.index" perm="results.view" />
                        <x-nav.link label="Result Calculation" route="result-calculation.index" perm="result_calculation.view" />
                        <x-nav.link label="Grade / Pass-Fail" route="grade-scales.index" perm="grade_scales.view" />
                        <x-nav.link label="Result Publishing" route="result-publishing.index" perm="result_publishing.view" />
                        <x-nav.link label="Marksheets" route="marksheets.index" perm="marksheets.view" />
                        <x-nav.link label="Grade Cards" route="grade-cards.index" perm="grade_cards.view" />
                        <x-nav.link label="Exam Reports" route="exam-reports.index" perm="exam_reports.view" />
                        <x-nav.link label="Student Result History" route="student-result-history.index" perm="student_result_history.view" />
                    </x-nav.group>
                    <x-nav.group id="finance" label="Fees" icon="wallet" perm="fee_structures.view|fee_categories.view|student_fee_assignments.view|fee_collections.view|receipts.view|fee_dues.view|fee_concessions.view|refunds.view|fee_reports.view">
                        <x-nav.link label="Fee Structures" route="fee-structures.index" perm="fee_structures.view" />
                        <x-nav.link label="Fee Categories" route="fee-categories.index" perm="fee_categories.view" />
                        <x-nav.link label="Student Fee Assignment" route="student-fee-assignments.index" perm="student_fee_assignments.view" />
                        <x-nav.link label="Fee Collection" route="fee-collections.index" perm="fee_collections.view" />
                        <x-nav.link label="Receipts" route="receipts.index" perm="receipts.view" />
                        <x-nav.link label="Due / Outstanding Fees" route="fee-dues.index" perm="fee_dues.view" />
                        <x-nav.link label="Fee Discounts / Concessions" route="fee-concessions.index" perm="fee_concessions.view" />
                        <x-nav.link label="Refunds" route="refunds.index" perm="refunds.view" />
                        <x-nav.link label="Fee Reports" route="fee-reports.index" perm="fee_reports.view" />
                    </x-nav.group>
                    <x-nav.group id="transport" label="Transport" icon="bus" perm="transport_dashboard.view|vehicles.view|vehicle_documents.view|transport_drivers.view|transport_routes.view|student_transport_assignments.view|transport_fees.view">
                        <x-nav.link label="Transport Dashboard" route="transport.dashboard" perm="transport_dashboard.view" />
                        <x-nav.link label="Vehicles" route="vehicles.index" perm="vehicles.view" />
                        <x-nav.link label="Vehicle Documents" route="vehicle-documents.index" perm="vehicle_documents.view" />
                        <x-nav.link label="Drivers" route="transport-drivers.index" perm="transport_drivers.view" />
                        <x-nav.link label="Routes" route="transport-routes.index" perm="transport_routes.view" />
                        <x-nav.link label="Stops" route="transport-stops.list" perm="transport_routes.view" />
                        <x-nav.link label="Student Transport Assignment" route="transport-assignments.index" perm="student_transport_assignments.view" />
                        <x-nav.link label="Transport Fees" route="transport-fees.index" perm="transport_fees.view" />
                    </x-nav.group>
                    <x-nav.group id="library" label="Library" icon="books" perm="library_dashboard.view|books.view|book_categories.view|authors.view|publishers.view|book_copies.view|library_members.view|library_transactions.view|library_renewals.view|library_fines.view">
                        <x-nav.link label="Library Dashboard" route="library.dashboard" perm="library_dashboard.view" />
                        <x-nav.link label="Books" route="books.index" perm="books.view" />
                        <x-nav.link label="Book Categories" route="book-categories.index" perm="book_categories.view" />
                        <x-nav.link label="Authors / Publishers" perm="authors.view|publishers.view" :href="auth()->user()?->hasPermission('authors.view') ? route('authors.index') : route('publishers.index')" pattern="authors.*|publishers.*" />
                        <x-nav.link label="Book Copies" route="book-copies.index" perm="book_copies.view" />
                        <x-nav.link label="Library Members" route="library-members.index" perm="library_members.view" />
                        <x-nav.link label="Issue / Return" route="library-transactions.index" perm="library_transactions.view" />
                        <x-nav.link label="Renewals" route="library-renewals.index" perm="library_renewals.view" />
                        <x-nav.link label="Fines / Penalties" route="library-fines.index" perm="library_fines.view" />
                    </x-nav.group>
                    <x-nav.group id="hostel" label="Hostel" icon="building" perm="hostel_dashboard.view|hostels.view|hostel_buildings.view|hostel_rooms.view|hostel_beds.view|hostel_allocations.view|hostel_fees.view|hostel_attendance.view">
                        <x-nav.link label="Hostel Dashboard" route="hostels.dashboard" perm="hostel_dashboard.view" />
                        <x-nav.link label="Hostels" route="hostels.index" perm="hostels.view" pattern="hostels.index|hostels.create|hostels.edit|hostels.store|hostels.update|hostels.destroy" />
                        <x-nav.link label="Buildings / Blocks" route="hostel-buildings.index" perm="hostel_buildings.view" />
                        <x-nav.link label="Rooms" route="hostel-rooms.index" perm="hostel_rooms.view" />
                        <x-nav.link label="Beds" route="hostel-beds.index" perm="hostel_beds.view" />
                        <x-nav.link label="Hostel Allocation" route="hostel-allocations.index" perm="hostel_allocations.view" />
                        <x-nav.link label="Hostel Fees" route="hostel-fees.index" perm="hostel_fees.view" />
                        <x-nav.link label="Hostel Attendance" route="hostel-attendance.index" perm="hostel_attendance.view" />
                    </x-nav.group>
                    <x-nav.group id="communication" label="Communication" icon="message" perm="communication_dashboard.view|notices.view|circulars.view|notifications.view|communication_templates.view|communication_logs.view|communication_tracking.view">
                        <x-nav.link label="Communication Dashboard" route="communication.dashboard" perm="communication_dashboard.view" />
                        <x-nav.link label="Notices / Announcements" route="notices.index" perm="notices.view" />
                        <x-nav.link label="Circulars" route="circulars.index" perm="circulars.view" />
                        <x-nav.link label="Notifications" route="notifications.index" perm="notifications.view" />
                        <x-nav.link label="SMS / Email Templates" route="communication-templates.index" perm="communication_templates.view" />
                        <x-nav.link label="SMS / Email Logs" route="communication-logs.index" perm="communication_logs.view" />
                        <x-nav.link label="Delivery / Read Tracking" route="communication-tracking.index" perm="communication_tracking.view" />
                    </x-nav.group>
                    <x-nav.group id="inventory" label="Inventory" icon="box" perm="inventory_dashboard.view|inventory_categories.view|inventory_items.view|inventory_vendors.view|inventory_purchase_orders.view|inventory_goods_receipts.view|inventory_stock_adjustments.view|inventory_transactions.view|inventory_stock.view|inventory_issues.view|inventory_assignments.view|inventory_asset_returns.view|inventory_maintenance.view|inventory_current_stock.view|inventory_low_stock.view|inventory_asset_register.view|inventory_stock_reports.view">
                        <x-nav.link label="Inventory Dashboard" route="inventory.dashboard" perm="inventory_dashboard.view" />
                        <x-nav.link label="Item Categories" route="inventory-categories.index" perm="inventory_categories.view" />
                        <x-nav.link label="Items / Assets" route="inventory-items.index" perm="inventory_items.view" />
                        <x-nav.link label="Vendors" route="inventory-vendors.index" perm="inventory_vendors.view" />
                        <x-nav.link label="Purchase Orders" route="inventory-purchase-orders.index" perm="inventory_purchase_orders.view" />
                        <x-nav.link label="Goods Receipt / Stock In" route="inventory-goods-receipts.index" perm="inventory_goods_receipts.view" />
                        <x-nav.link label="Stock Adjustment" route="inventory-stock-adjustments.index" perm="inventory_stock_adjustments.view" />
                        <x-nav.link label="Inventory Transactions" route="inventory-transactions.index" perm="inventory_transactions.view" />
                        <x-nav.link label="Item Issue / Allocation" route="inventory-issues.index" perm="inventory_issues.view" />
                        <x-nav.link label="Asset Assignment" route="inventory-assignments.index" perm="inventory_assignments.view" />
                        <x-nav.link label="Asset Return" route="inventory-asset-returns.index" perm="inventory_asset_returns.view" />
                        <x-nav.link label="Asset Maintenance" route="inventory-maintenances.index" perm="inventory_maintenance.view" />
                        <x-nav.link label="Current Stock" route="inventory-current-stock.index" perm="inventory_current_stock.view" />
                        <x-nav.link label="Low Stock" route="inventory-low-stock.index" perm="inventory_low_stock.view" />
                        <x-nav.link label="Asset Register" route="inventory-asset-register.index" perm="inventory_asset_register.view" />
                        <x-nav.link label="Stock / Transaction Reports" route="inventory-stock-reports.index" perm="inventory_stock_reports.view" />
                    </x-nav.group>
                    <x-nav.group id="reports" label="Reports" icon="chart-bar" perm="student_reports.view|academic_reports.view|examination_reports.view|finance_reports.view|inventory_reports.view|hr_reports.view|library_reports.view|transport_reports.view|hostel_reports.view|communication_reports.view|certificate_reports.view|consolidated_reports.view">
                        <x-nav.link label="Student Reports" route="student-reports.index" perm="student_reports.view" />
                        <x-nav.link label="Academic Reports" route="academic-reports.index" perm="academic_reports.view" />
                        <x-nav.link label="Examination Reports" route="examination-reports.index" perm="examination_reports.view" />
                        <x-nav.link label="Finance Reports" route="finance-reports.index" perm="finance_reports.view" />
                        <x-nav.link label="Inventory / Asset Reports" route="inventory-reports.index" perm="inventory_reports.view" />
                        <x-nav.link label="HR Reports" route="hr-reports.index" perm="hr_reports.view" />
                        <x-nav.link label="Library Reports" route="library-reports.index" perm="library_reports.view" />
                        <x-nav.link label="Transport Reports" route="transport-reports.index" perm="transport_reports.view" />
                        <x-nav.link label="Hostel Reports" route="hostel-reports.index" perm="hostel_reports.view" />
                        <x-nav.link label="Communication Reports" route="communication-reports.index" perm="communication_reports.view" />
                        <x-nav.link label="Certificate Reports" route="certificate-reports.index" perm="certificate_reports.view" />
                        <x-nav.link label="Consolidated Reports" route="consolidated-reports.index" perm="consolidated_reports.view" />
                    </x-nav.group>
                @include('administration.partials.navigation')
            </ul>
        </div>
    </nav>
</aside>

{{-- Drawer scrim: outside <aside> so it can cover the page underneath it. --}}
<div class="erp-nav-overlay" data-nav-overlay tabindex="-1" hidden></div>
