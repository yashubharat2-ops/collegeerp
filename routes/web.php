<?php

use App\Http\Controllers\AcademicReportController;
use App\Http\Controllers\AcademicsController;
use App\Http\Controllers\AcademicsExportController;
use App\Http\Controllers\AcademicTermController;
use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\Account\PasswordController as AccountPasswordController;
use App\Http\Controllers\Account\PreferenceController as AccountPreferenceController;
use App\Http\Controllers\Account\ProfileController as AccountProfileController;
use App\Http\Controllers\AdmissionApplicantController;
use App\Http\Controllers\AdmissionApplicationController;
use App\Http\Controllers\AdmissionController;
use App\Http\Controllers\AdmissionDashboardController;
use App\Http\Controllers\AdmissionDocumentController;
use App\Http\Controllers\AdmissionDocumentTypeController;
use App\Http\Controllers\AdmissionEnquiryController;
use App\Http\Controllers\AdmissionMeritEntryController;
use App\Http\Controllers\AdmissionMeritListController;
use App\Http\Controllers\AdmissionReportController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\AuthorController;
use App\Http\Controllers\BookCategoryController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BookCopyController;
use App\Http\Controllers\CampusController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\CertificateReportController;
use App\Http\Controllers\CollegeSwitchController;
use App\Http\Controllers\Communication\CircularController;
use App\Http\Controllers\Communication\CommunicationDashboardController;
use App\Http\Controllers\Communication\CommunicationLogController;
use App\Http\Controllers\Communication\CommunicationNotificationController;
use App\Http\Controllers\Communication\CommunicationReportController;
use App\Http\Controllers\Communication\CommunicationTemplateController;
use App\Http\Controllers\Communication\CommunicationTrackingController;
use App\Http\Controllers\Communication\NoticeController;
use App\Http\Controllers\ConsolidatedReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeDocumentController;
use App\Http\Controllers\ExamAttendanceController;
use App\Http\Controllers\ExaminationController;
use App\Http\Controllers\ExaminationReportController;
use App\Http\Controllers\ExamMarkController;
use App\Http\Controllers\ExamReportController;
use App\Http\Controllers\ExamScheduleController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\FacultySubjectAssignmentController;
use App\Http\Controllers\FeeCategoryController;
use App\Http\Controllers\FeeConcessionController;
use App\Http\Controllers\FeeDueController;
use App\Http\Controllers\FeePaymentController;
use App\Http\Controllers\FeeReceiptController;
use App\Http\Controllers\FeeRefundController;
use App\Http\Controllers\FeeReportController;
use App\Http\Controllers\FeeStructureController;
use App\Http\Controllers\FinanceReportController;
use App\Http\Controllers\GradeCardController;
use App\Http\Controllers\GradeScaleController;
use App\Http\Controllers\Hostel\HostelAllocationController;
use App\Http\Controllers\Hostel\HostelAttendanceController;
use App\Http\Controllers\Hostel\HostelBedController;
use App\Http\Controllers\Hostel\HostelBuildingController;
use App\Http\Controllers\Hostel\HostelController;
use App\Http\Controllers\Hostel\HostelDashboardController;
use App\Http\Controllers\Hostel\HostelFeeController;
use App\Http\Controllers\Hostel\HostelFeeStructureController;
use App\Http\Controllers\Hostel\HostelReportController;
use App\Http\Controllers\Hostel\HostelRoomController;
use App\Http\Controllers\HrReportController;
use App\Http\Controllers\InstitutionalSettingController;
use App\Http\Controllers\Inventory\InventoryAssetRegisterController;
use App\Http\Controllers\Inventory\InventoryAssetReturnController;
use App\Http\Controllers\Inventory\InventoryAssignmentController;
use App\Http\Controllers\Inventory\InventoryCategoryController;
use App\Http\Controllers\Inventory\InventoryCurrentStockController;
use App\Http\Controllers\Inventory\InventoryDashboardController;
use App\Http\Controllers\Inventory\InventoryGoodsReceiptController;
use App\Http\Controllers\Inventory\InventoryIssueController;
use App\Http\Controllers\Inventory\InventoryItemController;
use App\Http\Controllers\Inventory\InventoryLowStockController;
use App\Http\Controllers\Inventory\InventoryMaintenanceController;
use App\Http\Controllers\Inventory\InventoryPurchaseOrderController;
use App\Http\Controllers\Inventory\InventoryReportController;
use App\Http\Controllers\Inventory\InventoryStockAdjustmentController;
use App\Http\Controllers\Inventory\InventoryStockController;
use App\Http\Controllers\Inventory\InventoryStockReportController;
use App\Http\Controllers\Inventory\InventoryTransactionController;
use App\Http\Controllers\Inventory\InventoryVendorController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\LibraryDashboardController;
use App\Http\Controllers\LibraryFineController;
use App\Http\Controllers\LibraryMemberController;
use App\Http\Controllers\LibraryRenewalController;
use App\Http\Controllers\LibraryReportController;
use App\Http\Controllers\LibraryTransactionController;
use App\Http\Controllers\MarksheetController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProgramController;
use App\Http\Controllers\PublisherController;
use App\Http\Controllers\ResultCalculationController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\ResultPublishingController;
use App\Http\Controllers\SalaryComponentController;
use App\Http\Controllers\SalaryStructureController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\StaffAttendanceController;
use App\Http\Controllers\StudentAcademicRecordController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentImportController;
use App\Http\Controllers\StudentDocumentController;
use App\Http\Controllers\StudentEnrollmentController;
use App\Http\Controllers\StudentFeeAssignmentController;
use App\Http\Controllers\StudentHistoryController;
use App\Http\Controllers\StudentIdCardController;
use App\Http\Controllers\StudentPromotionController;
use App\Http\Controllers\StudentReportController;
use App\Http\Controllers\StudentResultHistoryController;
use App\Http\Controllers\StudentTransferController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\Transport\StudentTransportAssignmentController;
use App\Http\Controllers\Transport\TransportDashboardController;
use App\Http\Controllers\Transport\TransportDriverController;
use App\Http\Controllers\Transport\TransportFeeController;
use App\Http\Controllers\Transport\TransportFeeStructureController;
use App\Http\Controllers\Transport\TransportReportController;
use App\Http\Controllers\Transport\TransportRouteController;
use App\Http\Controllers\Transport\TransportStopController;
use App\Http\Controllers\Transport\VehicleController;
use App\Http\Controllers\Transport\VehicleDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'))->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', LogoutController::class)->name('logout');
    Route::get('/dashboard', DashboardController::class)->middleware('tenant')->name('dashboard');
    Route::post('/college-context', CollegeSwitchController::class)->name('college-context.switch');

    // The account panel's own screens. `auth` plus the same `tenant` middleware /dashboard
    // uses — no permission gate and no `tenant.access`: each screen acts on the record of
    // the person holding the session and takes no identifier in its URL, so there is no
    // other user to reach, and what a user may not change about themselves is refused by
    // the FormRequest (`prohibited` on status, roles, permissions, college membership and
    // password) rather than by a policy — those policies stay in the Administration module.
    // `tenant` is here because it is what populates TenantContext: the audit rows these
    // writes produce carry the college the person was actually working in, exactly like
    // every other audited change in the ERP. Nothing tenant-owned is stored by them:
    // preferences are keyed by user alone.
    Route::middleware('tenant')->group(function (): void {
        Route::get('/profile', [AccountProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profile', [AccountProfileController::class, 'update'])->name('profile.update');
        Route::get('/password/change', [AccountPasswordController::class, 'edit'])->name('password.change.edit');
        Route::put('/password/change', [AccountPasswordController::class, 'store'])->name('password.change.store');
        Route::get('/preferences', [AccountPreferenceController::class, 'edit'])->name('preferences.edit');
        Route::put('/preferences', [AccountPreferenceController::class, 'update'])->name('preferences.update');
    });
    Route::middleware(['tenant', 'tenant.access'])->group(function () {
        Route::prefix('certificates')->name('certificates.')->controller(CertificateController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            // Shared, type-filtered workflow entry points; legacy URLs remain available.
            foreach (['requests', 'generation', 'issuance', 'verification'] as $stage) {
                Route::get('/'.$stage, 'index')->defaults('certificate_stage', $stage)->name($stage.'.index');
            }
            // Bulk CSV exports: the register (all types / stages), the types
            // screen and the templates screen. They re-resolve the ticked ids
            // inside the active college.
            Route::get('/export', 'export')->name('export');
            Route::get('/types/export', 'exportTypes')->name('types.export');
            Route::get('/templates/export', 'exportTemplates')->name('templates.export');
            Route::get('/templates/index', 'templates')->name('templates.index');
            Route::get('/reports/index', 'reports')->name('reports.index');
            Route::post('/', 'store')->name('store');
            Route::get('/types', 'types')->name('types');
            Route::post('/types', 'storeType')->name('types.store');
            Route::get('/templates', 'templates')->name('templates');
            Route::post('/templates', 'storeTemplate')->name('templates.store');
            Route::get('/reports', 'reports')->name('reports');
            Route::post('/verify', 'verify')->middleware('throttle:30,1')->name('verify');
            Route::get('/{certificate}', 'show')->whereNumber('certificate')->name('show');
            Route::post('/{certificate}/generate', 'generate')->whereNumber('certificate')->name('generate');
            Route::post('/{certificate}/issue', 'issue')->whereNumber('certificate')->name('issue');
        });

        Route::get('/campuses', [CampusController::class, 'index'])->name('campuses.index');
        Route::post('/campuses', [CampusController::class, 'store'])->name('campuses.store');
        Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::get('/departments/create', [DepartmentController::class, 'create'])->name('departments.create');
        Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::get('/departments/{department}/edit', [DepartmentController::class, 'edit'])->name('departments.edit');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::patch('/departments/{department}/status', [DepartmentController::class, 'updateStatus'])->name('departments.status');
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');
        Route::get('/academic-years', [AcademicYearController::class, 'index'])->name('academic-years.index');
        Route::post('/academic-years', [AcademicYearController::class, 'store'])->name('academic-years.store');
        Route::resource('academic-terms', AcademicTermController::class)->except('show');
        Route::resource('programs', ProgramController::class)->except('show');
        Route::resource('sections', SectionController::class)->except('show');
        Route::resource('subjects', SubjectController::class)->except('show');
        // HR / Staff Management reuses the existing Platform Faculty/Staff and
        // Department records. These aliases do not create duplicate masters.
        Route::get('employees/export', [EmployeeController::class, 'export'])->name('employees.export');
        Route::resource('employees', EmployeeController::class);
        Route::resource('staff', EmployeeController::class);
        Route::resource('faculties', FacultyController::class)->except('show');
        Route::get('faculties/{faculty}', [FacultyController::class, 'show'])->name('faculties.show');
        Route::get('staff-departments/export', [DepartmentController::class, 'export'])->name('staff-departments.export');
        Route::resource('staff-departments', DepartmentController::class)->except('show');
        Route::get('designations/export', [DesignationController::class, 'export'])->name('designations.export');
        Route::resource('designations', DesignationController::class);
        Route::get('employee-documents/{employee_document}/download', [EmployeeDocumentController::class, 'download'])->name('employee-documents.download');
        Route::get('employee-documents/export', [EmployeeDocumentController::class, 'export'])->name('employee-documents.export');
        Route::resource('employee-documents', EmployeeDocumentController::class);

        // HR Phase 1 additions: tenant-scoped operational records and live reports.
        Route::get('staff-attendance/export', [StaffAttendanceController::class, 'export'])->name('staff-attendance.export');
        Route::resource('staff-attendance', StaffAttendanceController::class)->except('show');
        Route::get('leave-types/export', [LeaveTypeController::class, 'export'])->name('leave-types.export');
        Route::resource('leave-types', LeaveTypeController::class)->except('show');
        Route::post('leave-requests/{leave_request}/approve', [LeaveRequestController::class, 'approve'])->name('leave-requests.approve');
        Route::post('leave-requests/{leave_request}/reject', [LeaveRequestController::class, 'reject'])->name('leave-requests.reject');
        Route::post('leave-requests/{leave_request}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave-requests.cancel');
        Route::get('leave-requests/export', [LeaveRequestController::class, 'export'])->name('leave-requests.export');
        Route::resource('leave-requests', LeaveRequestController::class)->except('show');
        Route::get('salary-structures/export', [SalaryStructureController::class, 'export'])->name('salary-structures.export');
        Route::resource('salary-structures', SalaryStructureController::class);
        Route::get('salary-components/export', [SalaryComponentController::class, 'export'])->name('salary-components.export');
        Route::resource('salary-components', SalaryComponentController::class)->except('show');
        Route::post('payrolls/{payroll}/cancel', [PayrollController::class, 'cancel'])->name('payrolls.cancel');
        Route::get('payrolls/export', [PayrollController::class, 'export'])->name('payrolls.export');
        Route::resource('payrolls', PayrollController::class)->only(['index', 'create', 'store', 'show']);

        Route::resource('faculty-subject-assignments', FacultySubjectAssignmentController::class)->except('show');
        Route::get('admission/dashboard', AdmissionDashboardController::class)->name('admission.dashboard');
        Route::resource('admission-applicants', AdmissionApplicantController::class)->except('show');
        Route::get('admission-enquiries/duplicate-check', [AdmissionEnquiryController::class, 'duplicateCheck'])->name('admission-enquiries.duplicate-check');
        Route::resource('admission-enquiries', AdmissionEnquiryController::class)->except('show');
        Route::resource('admission-applications', AdmissionApplicationController::class)->except('show');
        Route::resource('admission-document-types', AdmissionDocumentTypeController::class)->except('show');
        Route::get('admission-documents/{admission_document}/download', [AdmissionDocumentController::class, 'download'])->name('admission-documents.download');
        Route::post('admission-documents/{admission_document}/verify', [AdmissionDocumentController::class, 'verify'])->name('admission-documents.verify');
        Route::resource('admission-documents', AdmissionDocumentController::class)->except('show');
        Route::post('admission-merit-lists/{admission_merit_list}/publish', [AdmissionMeritListController::class, 'publish'])->name('admission-merit-lists.publish');
        Route::post('admission-merit-lists/{admission_merit_list}/unpublish', [AdmissionMeritListController::class, 'unpublish'])->name('admission-merit-lists.unpublish');
        Route::resource('admission-merit-lists', AdmissionMeritListController::class);
        Route::resource('admission-merit-entries', AdmissionMeritEntryController::class)->except('show');
        Route::get('admissions/export', [AdmissionController::class, 'export'])->name('admissions.export');
        Route::get('admissions/{admission}/convert', [StudentController::class, 'createFromAdmission'])->name('admissions.convert.create');
        Route::post('admissions/{admission}/convert', [StudentController::class, 'storeFromAdmission'])->name('admissions.convert.store');
        Route::post('admissions/{admission}/cancel', [AdmissionController::class, 'cancel'])->name('admissions.cancel');
        Route::resource('admissions', AdmissionController::class)->except('show');
        Route::post('students/convert/{admission_application}', [StudentController::class, 'convert'])->name('students.convert');
        // CSV export of the filtered list (or of an authorized selection of it).
        // Declared BEFORE the resource route: `students/{student}` would otherwise
        // capture "students/export" and 404 on a student named "export".
        Route::get('students/import', [StudentImportController::class, 'index'])->name('students.import.index');
        Route::get('students/import/template', [StudentImportController::class, 'template'])->name('students.import.template');
        Route::post('students/import/validate', [StudentImportController::class, 'validateUpload'])->name('students.import.validate');
        Route::post('students/import', [StudentImportController::class, 'store'])->name('students.import.store');
        Route::get('students/export', [StudentController::class, 'export'])->name('students.export');
        // The same filtered/selected list as a printable A4 report: "PDF" is the
        // review-then-save-as-PDF page, "print" opens it with the native print
        // dialog. Same permission, same tenant scope, same filter pipeline.
        Route::get('students/export/pdf', [StudentController::class, 'exportPdf'])->name('students.export.pdf');
        Route::get('students/export/print', [StudentController::class, 'exportPrint'])->name('students.export.print');
        Route::get('students/{student}/photo', [StudentController::class, 'photo'])->name('students.photo');
        Route::resource('students', StudentController::class);
        Route::get('student-enrollments/export', [StudentEnrollmentController::class, 'export'])->name('student-enrollments.export');
        Route::resource('student-enrollments', StudentEnrollmentController::class)->except('show');

        // Students — Academic Records (progression ledger; references Platform
        // academic master data, never duplicates it).
        Route::resource('student-academic-records', StudentAcademicRecordController::class)->except('show');

        // Students — Documents (private disk, tenant-safe, authorized streaming).
        Route::get('student-documents/{student_document}/download', [StudentDocumentController::class, 'download'])->name('student-documents.download');
        Route::post('student-documents/{student_document}/verify', [StudentDocumentController::class, 'verify'])->name('student-documents.verify');
        // Consolidated printable document pack for a selection of students (the
        // listing's bulk "Bulk documents" action). Static segment first, so
        // "batch" is never read as a {student_document} id.
        Route::get('student-documents/batch', [StudentDocumentController::class, 'batch'])->name('student-documents.batch');
        Route::resource('student-documents', StudentDocumentController::class)->except('show');

        // Students — ID Cards (generated from Student + Enrollment; no records).
        Route::get('student-id-cards', [StudentIdCardController::class, 'index'])->name('student-id-cards.index');
        // Printable batch of cards for a selection of students (the listing's
        // bulk "Generate ID cards" action). Declared before the {student} route.
        Route::get('student-id-cards/batch', [StudentIdCardController::class, 'batch'])->name('student-id-cards.batch');
        Route::get('student-id-cards/{student}', [StudentIdCardController::class, 'show'])->name('student-id-cards.show');

        // Students — Promotion (request → approve; additive, never destructive).
        Route::post('student-promotions/{student_promotion}/approve', [StudentPromotionController::class, 'approve'])->name('student-promotions.approve');
        Route::post('student-promotions/{student_promotion}/cancel', [StudentPromotionController::class, 'cancel'])->name('student-promotions.cancel');
        Route::resource('student-promotions', StudentPromotionController::class)->only(['index', 'create', 'store']);

        // Students — Transfer / TC (statuses only; history is never deleted).
        Route::post('student-transfers/{student_transfer}/approve', [StudentTransferController::class, 'approve'])->name('student-transfers.approve');
        Route::post('student-transfers/{student_transfer}/reject', [StudentTransferController::class, 'reject'])->name('student-transfers.reject');
        Route::post('student-transfers/{student_transfer}/issue', [StudentTransferController::class, 'issue'])->name('student-transfers.issue');
        Route::post('student-transfers/{student_transfer}/cancel', [StudentTransferController::class, 'cancel'])->name('student-transfers.cancel');
        Route::get('student-transfers/{student_transfer}/download', [StudentTransferController::class, 'download'])->name('student-transfers.download');
        Route::resource('student-transfers', StudentTransferController::class)->except('show');

        // Students — History (derived timeline; read-only).
        Route::get('student-history', [StudentHistoryController::class, 'index'])->name('student-history.index');
        Route::get('student-history/{student}', [StudentHistoryController::class, 'show'])->name('student-history.show');

        // REPORTS — Student Reports only. No write, file-download or export endpoints.
        Route::prefix('student-reports')->name('student-reports.')->controller(StudentReportController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/profile/{student}', 'profile')->whereNumber('student')->name('profile');
            Route::get('/history/{student}', 'history')->whereNumber('student')->name('history');
        });

        // REPORTS — Academic Reports: read-only views over existing Academic data. GET only.
        Route::get('academic-reports', [AcademicReportController::class, 'index'])->name('academic-reports.index');

        // REPORTS — Examination Reports: read-only views over existing Examination/Result data. GET only.
        Route::get('examination-reports', [ExaminationReportController::class, 'index'])->name('examination-reports.index');

        // REPORTS — Finance Reports: read-only views over the existing Finance / Fees
        // records (and the Transport / Hostel fee assignments that share the same
        // payment rows). Aggregated live — no report tables, no write routes.
        Route::get('finance-reports', [FinanceReportController::class, 'index'])->name('finance-reports.index');

        // REPORTS — HR Reports: read-only views over the existing staff / employee,
        // department, designation, employee document, staff attendance, leave and
        // payroll records. Aggregated live — no report tables, no write routes.
        Route::get('hr-reports', [HrReportController::class, 'index'])->name('hr-reports.index');

        // REPORTS — Library Reports: read-only views over the existing Library
        // records (catalogue, copies, members, circulation, renewals, fines).
        // Aggregated live — no report tables, no write routes.
        Route::get('library-reports', [LibraryReportController::class, 'index'])->name('library-reports.index');

        Route::get('admission-reports', [AdmissionReportController::class, 'index'])->name('admission-reports.index');
        // ACADEMIC BULK ACTIONS — the CSV destination of the six Academic listings'
        // bulk "Export selected" actions. Each one re-resolves the authorized ids
        // inside the active college (never trusting the client list), re-checks the
        // listing's own permission and streams through the shared CsvStreamExport.
        // Registered together, before the academic list/detail routes, so a static
        // "…/export" segment is never captured as a record id.
        Route::get('academics/subject-enrollments/export', [AcademicsExportController::class, 'subjectEnrollments'])->name('academic-subject-enrollments.export');
        Route::get('academics/sections/export', [AcademicsExportController::class, 'sections'])->name('academic-sections.export');
        Route::get('academics/timetables/export', [AcademicsExportController::class, 'timetables'])->name('academic-timetables.export');
        Route::get('academics/attendance/export', [AcademicsExportController::class, 'attendance'])->name('academic-attendance.export');
        Route::get('academics/calendar/export', [AcademicsExportController::class, 'calendar'])->name('academic-calendar.export');
        Route::get('academics/workload/export', [AcademicsExportController::class, 'workload'])->name('academic-workload.export');

        // Academics is operational only: all master data remains in Platform/Students.
        Route::get('academics/subject-enrollments', [AcademicsController::class, 'subjectEnrollments'])->name('academic-subject-enrollments.index');
        Route::get('academics/subject-enrollments/create', [AcademicsController::class, 'createSubjectEnrollment'])->name('academic-subject-enrollments.create');
        Route::post('academics/subject-enrollments', [AcademicsController::class, 'storeSubjectEnrollment'])->name('academic-subject-enrollments.store');
        Route::put('academics/subject-enrollments/{item}', [AcademicsController::class, 'updateSubjectEnrollment'])->name('academic-subject-enrollments.update');
        Route::delete('academics/subject-enrollments/{item}', [AcademicsController::class, 'destroySubjectEnrollment'])->name('academic-subject-enrollments.destroy');
        Route::get('academics/sections', [AcademicsController::class, 'sections'])->name('academic-sections.index');
        Route::get('academics/sections/{section}', [AcademicsController::class, 'section'])->name('academic-sections.show');
        Route::get('academics/timetables', [AcademicsController::class, 'timetables'])->name('academic-timetables.index');
        Route::get('academics/timetables/create', [AcademicsController::class, 'createTimetable'])->name('academic-timetables.create');
        Route::post('academics/timetables', [AcademicsController::class, 'storeTimetable'])->name('academic-timetables.store');
        Route::put('academics/timetables/{item}', [AcademicsController::class, 'updateTimetable'])->name('academic-timetables.update');
        Route::delete('academics/timetables/{item}', [AcademicsController::class, 'destroyTimetable'])->name('academic-timetables.destroy');
        Route::get('academics/attendance', [AcademicsController::class, 'attendance'])->name('academic-attendance.index');
        Route::post('academics/attendance', [AcademicsController::class, 'storeAttendance'])->name('academic-attendance.store');
        Route::post('academics/attendance/bulk', [AcademicsController::class, 'bulkAttendance'])->name('academic-attendance.bulk');
        Route::get('academics/calendar', [AcademicsController::class, 'calendar'])->name('academic-calendar.index');
        Route::get('academics/calendar/create', [AcademicsController::class, 'createCalendar'])->name('academic-calendar.create');
        Route::post('academics/calendar', [AcademicsController::class, 'storeCalendar'])->name('academic-calendar.store');
        Route::put('academics/calendar/{item}', [AcademicsController::class, 'updateCalendar'])->name('academic-calendar.update');
        Route::delete('academics/calendar/{item}', [AcademicsController::class, 'destroyCalendar'])->name('academic-calendar.destroy');
        Route::get('academics/workload', [AcademicsController::class, 'workload'])->name('academic-workload.index');

        // Examinations (Phase 1)
        // EXAMINATION BULK ACTIONS — the CSV destination of each Examination
        // listing's bulk "Export selected" action. Declared before the resource
        // routes so a static "…/export" segment is never read as a record id, and
        // every endpoint re-checks its listing's permission and re-resolves the
        // authorized ids inside the active college before streaming.
        Route::get('examinations/export', [ExaminationController::class, 'export'])->name('examinations.export');
        Route::resource('examinations', ExaminationController::class)->except('show');
        Route::get('exam-schedules/export', [ExamScheduleController::class, 'export'])->name('exam-schedules.export');
        Route::resource('exam-schedules', ExamScheduleController::class)->except('show');

        // Examinations (Phase 2) — Exam Attendance and Marks Entry.
        // Both are export-only in the bulk bar: marking and marks entry keep their
        // existing per-record / board paths, which resolve eligibility server-side.
        Route::get('exam-attendance/export', [ExamAttendanceController::class, 'export'])->name('exam-attendance.export');
        Route::post('exam-attendance/bulk', [ExamAttendanceController::class, 'bulk'])->name('exam-attendance.bulk');
        Route::resource('exam-attendance', ExamAttendanceController::class)->except('show');
        Route::get('exam-marks/export', [ExamMarkController::class, 'export'])->name('exam-marks.export');
        Route::post('exam-marks/bulk', [ExamMarkController::class, 'bulk'])->name('exam-marks.bulk');
        Route::resource('exam-marks', ExamMarkController::class)->except('show');

        // Examinations (Phase 3) — Results, Result Calculation, Grade / Pass-Fail, Result Publishing.
        // Results and Result Calculation are read-only screens; their bulk bar
        // exports the authorized selection. The CSV routes are declared before the
        // "{result}" detail route so "results/export" is never read as an id.
        Route::get('results/export', [ResultController::class, 'export'])->name('results.export');
        Route::get('results', [ResultController::class, 'index'])->name('results.index');
        Route::get('results/{result}', [ResultController::class, 'show'])->name('results.show');

        Route::get('result-calculation/export', [ResultCalculationController::class, 'export'])->name('result-calculation.export');
        Route::get('result-calculation', [ResultCalculationController::class, 'index'])->name('result-calculation.index');
        Route::post('result-calculation/calculate', [ResultCalculationController::class, 'calculate'])->name('result-calculation.calculate');
        Route::post('result-calculation/recalculate', [ResultCalculationController::class, 'recalculate'])->name('result-calculation.recalculate');

        Route::get('grade-scales/export', [GradeScaleController::class, 'export'])->name('grade-scales.export');
        Route::resource('grade-scales', GradeScaleController::class)->except('show');

        // The publishing worklist exports its selection; publishing itself stays
        // with ResultPublishingService (the page's own bulk form below).
        Route::get('result-publishing/export', [ResultPublishingController::class, 'export'])->name('result-publishing.export');
        Route::get('result-publishing', [ResultPublishingController::class, 'index'])->name('result-publishing.index');
        Route::post('result-publishing/bulk', [ResultPublishingController::class, 'publishBulk'])->name('result-publishing.bulk');
        Route::post('result-publishing/examination/{examination}', [ResultPublishingController::class, 'publishExamination'])->name('result-publishing.examination');
        Route::post('result-publishing/{result}/publish', [ResultPublishingController::class, 'publish'])->name('result-publishing.publish');
        Route::post('result-publishing/{result}/unpublish', [ResultPublishingController::class, 'unpublish'])->name('result-publishing.unpublish');

        // Examinations (Phase 4A) — Marksheets. Derived printable documents,
        // read-only: no create/update/delete routes.
        Route::get('marksheets/export', [MarksheetController::class, 'export'])->name('marksheets.export');
        Route::get('marksheets', [MarksheetController::class, 'index'])->name('marksheets.index');
        Route::get('marksheets/{result}', [MarksheetController::class, 'show'])->name('marksheets.show');

        // Examinations (Phase 4) — Grade Cards. Derived printable documents,
        // read-only: no create/update/delete routes.
        Route::get('grade-cards/export', [GradeCardController::class, 'export'])->name('grade-cards.export');
        Route::get('grade-cards', [GradeCardController::class, 'index'])->name('grade-cards.index');
        Route::get('grade-cards/{result}', [GradeCardController::class, 'show'])->name('grade-cards.show');

        // Examinations (Phase 4) — Exam Reports. Aggregated published-result
        // summaries, read-only.
        Route::get('exam-reports/export', [ExamReportController::class, 'export'])->name('exam-reports.export');
        Route::get('exam-reports', [ExamReportController::class, 'index'])->name('exam-reports.index');

        // Examinations (Phase 4) — Student Result History. A student's
        // published examination timeline, read-only.
        Route::get('student-result-history', [StudentResultHistoryController::class, 'index'])->name('student-result-history.index');
        Route::get('student-result-history/{student}', [StudentResultHistoryController::class, 'show'])->name('student-result-history.show');

        // Finance / Fees — Fee Structure foundation (structure definitions only;
        // no money moves here).
        Route::get('fee-structures/export', [FeeStructureController::class, 'export'])->name('fee-structures.export');
        Route::resource('fee-structures', FeeStructureController::class)->except('show');

        // Finance / Fees — Fee Categories: the classification of fee heads.
        Route::get('fee-categories/export', [FeeCategoryController::class, 'export'])->name('fee-categories.export');
        Route::resource('fee-categories', FeeCategoryController::class)->except('show');

        // Finance / Fees — Student Fee Assignment: an existing fee structure
        // assigned to an existing student enrollment.
        Route::get('student-fee-assignments/export', [StudentFeeAssignmentController::class, 'export'])->name('student-fee-assignments.export');
        Route::resource('student-fee-assignments', StudentFeeAssignmentController::class)->except('show');

        // Finance / Fees — Fee Collection: the only screen that moves money in.
        Route::post('fee-collections/{fee_collection}/cancel', [FeePaymentController::class, 'cancel'])->name('fee-collections.cancel');
        Route::get('fee-collections/export', [FeePaymentController::class, 'export'])->name('fee-collections.export');
        Route::resource('fee-collections', FeePaymentController::class)->except('show');

        // Finance / Fees — Receipts: derived from successful collections, so
        // read-only (no create/update/delete routes exist).
        Route::get('receipts', [FeeReceiptController::class, 'index'])->name('receipts.index');
        Route::get('receipts/export', [FeeReceiptController::class, 'export'])->name('receipts.export');
        Route::get('receipts/{fee_payment}/print', [FeeReceiptController::class, 'print'])->name('receipts.print');
        Route::get('receipts/{fee_payment}', [FeeReceiptController::class, 'show'])->name('receipts.show');

        // Finance / Fees — Due / Outstanding Fees: derived ledger, read-only.
        Route::get('fee-dues/export', [FeeDueController::class, 'export'])->name('fee-dues.export');
        Route::get('fee-dues', [FeeDueController::class, 'index'])->name('fee-dues.index');

        // Finance / Fees — Fee Discounts / Concessions.
        Route::post('fee-concessions/{fee_concession}/approve', [FeeConcessionController::class, 'approve'])->name('fee-concessions.approve');
        Route::get('fee-concessions/export', [FeeConcessionController::class, 'export'])->name('fee-concessions.export');
        Route::resource('fee-concessions', FeeConcessionController::class)->except('show');

        // Finance / Fees — Refunds against actual collections. No delete route:
        // refund records are never removed.
        Route::post('refunds/{refund}/approve', [FeeRefundController::class, 'approve'])->name('refunds.approve');
        Route::post('refunds/{refund}/process', [FeeRefundController::class, 'process'])->name('refunds.process');
        Route::get('refunds/export', [FeeRefundController::class, 'export'])->name('refunds.export');
        Route::resource('refunds', FeeRefundController::class)->except(['show', 'destroy']);

        // Finance / Fees — Fee Reports: read-only aggregation of the records above.
        Route::get('fee-reports', [FeeReportController::class, 'index'])->name('fee-reports.index');

        // Library Management — Library Dashboard: read-only overview of the
        // Phase 1 masters, aggregated live (no dashboard tables).
        Route::get('library/dashboard', LibraryDashboardController::class)->name('library.dashboard');

        // Library Management — Books: the book master (a title, not a copy).
        Route::get('books/export', [BookController::class, 'export'])->name('books.export');
        Route::resource('books', BookController::class);

        // Library Management — Book Categories.
        Route::resource('book-categories', BookCategoryController::class)->except('show');

        // Library Management — Authors / Publishers: reusable references for books.
        Route::resource('authors', AuthorController::class)->except('show');
        Route::resource('publishers', PublisherController::class)->except('show');

        // Library Management — Phase 2. Copies, members, issue/return and
        // renewals. Circulation history has no delete route.
        Route::get('book-copies/export', [BookCopyController::class, 'export'])->name('book-copies.export');
        Route::resource('book-copies', BookCopyController::class);
        Route::get('library-members/export', [LibraryMemberController::class, 'export'])->name('library-members.export');
        Route::resource('library-members', LibraryMemberController::class);
        Route::post('library-transactions/{library_transaction}/return', [LibraryTransactionController::class, 'returnCopy'])->name('library-transactions.return');
        Route::post('library-transactions/{library_transaction}/lost', [LibraryTransactionController::class, 'markLost'])->name('library-transactions.lost');
        Route::get('library-transactions/export', [LibraryTransactionController::class, 'export'])->name('library-transactions.export');
        Route::resource('library-transactions', LibraryTransactionController::class)->except('destroy');
        Route::get('library-renewals/export', [LibraryRenewalController::class, 'export'])->name('library-renewals.export');
        Route::resource('library-renewals', LibraryRenewalController::class)->only(['index', 'create', 'store', 'show']);
        Route::get('library-fines/export', [LibraryFineController::class, 'export'])->name('library-fines.export');
        Route::get('library-fines', [LibraryFineController::class, 'index'])->name('library-fines.index');
        Route::post('library-fines', [LibraryFineController::class, 'store'])->name('library-fines.store');
        Route::post('library-fines/{library_fine}/pay', [LibraryFineController::class, 'pay'])->name('library-fines.pay');

        // Transport Phase 1 — tenant-scoped masters only. Each master listing's
        // bulk "Export selected" action streams its CSV from these endpoints;
        // they re-resolve the ticked ids inside the active college.
        Route::get('transport/dashboard', TransportDashboardController::class)->name('transport.dashboard');
        Route::get('vehicles/export', [VehicleController::class, 'export'])->name('vehicles.export');
        Route::resource('vehicles', VehicleController::class)->except('show')->parameters(['vehicles' => 'record']);
        Route::get('transport-drivers/export', [TransportDriverController::class, 'export'])->name('transport-drivers.export');
        Route::resource('transport-drivers', TransportDriverController::class)->except('show')->parameters(['transport-drivers' => 'record']);
        Route::get('transport-routes/export', [TransportRouteController::class, 'export'])->name('transport-routes.export');
        Route::resource('transport-routes', TransportRouteController::class)->except('show')->parameters(['transport-routes' => 'record']);
        Route::resource('transport-routes.transport-stops', TransportStopController::class)->except('show')->parameters(['transport-stops' => 'record'])->names([
            'index' => 'transport-stops.index',
            'create' => 'transport-stops.create',
            'store' => 'transport-stops.store',
            'edit' => 'transport-stops.edit',
            'update' => 'transport-stops.update',
            'destroy' => 'transport-stops.destroy',
        ]);

        // Transport Phase 2 — flat cross-route stop listing for the "Stops"
        // navigation entry (per-route stop management stays nested above).
        // The stop CSV export is shared by both stop listings.
        Route::get('transport-stops/export', [TransportStopController::class, 'export'])->name('transport-stops.export');
        Route::get('transport-stops', [TransportStopController::class, 'indexAll'])->name('transport-stops.list');

        // Transport Phase 2 — Vehicle Documents (secure private files).
        Route::get('vehicle-documents/{vehicle_document}/download', [VehicleDocumentController::class, 'download'])->name('vehicle-documents.download');
        Route::get('vehicle-documents/export', [VehicleDocumentController::class, 'export'])->name('vehicle-documents.export');
        Route::resource('vehicle-documents', VehicleDocumentController::class)->except('show')->parameters(['vehicle-documents' => 'vehicle_document']);

        // Transport Phase 2 — Student Transport Assignment (existing enrollments
        // onto existing routes/stops; no duplicate masters).
        Route::get('transport-assignments/export', [StudentTransportAssignmentController::class, 'export'])->name('transport-assignments.export');
        Route::resource('transport-assignments', StudentTransportAssignmentController::class)->except('show')->parameters(['transport-assignments' => 'transport_assignment']);

        // Transport Phase 2 — Transport Fees. Collections reuse the existing
        // Finance fee_payments rows (see FeeCollectionService::collectTransportFee).
        Route::post('transport-fees/{transport_fee}/collect', [TransportFeeController::class, 'collect'])->name('transport-fees.collect');
        Route::get('transport-fees/export', [TransportFeeController::class, 'export'])->name('transport-fees.export');
        Route::resource('transport-fees', TransportFeeController::class)->except('show')->parameters(['transport-fees' => 'transport_fee']);
        Route::resource('transport-fee-structures', TransportFeeStructureController::class)->except('show')->parameters(['transport-fee-structures' => 'transport_fee_structure']);

        // Transport Phase 2 — Transport Reports (read-only).
        Route::get('transport-reports', [TransportReportController::class, 'index'])->name('transport-reports.index');

        // Hostel Management — Phase 1 masters + Phase 2 allocations and fees.
        // The dashboard is read-only and aggregated live (no dashboard tables).
        // Each listing's bulk "Export selected" action streams its CSV from
        // these endpoints; they re-resolve the ticked ids inside the active
        // college.
        Route::get('hostels/dashboard', HostelDashboardController::class)->name('hostels.dashboard');
        Route::get('hostels/export', [HostelController::class, 'export'])->name('hostels.export');
        Route::resource('hostels', HostelController::class)->except('show')->parameters(['hostels' => 'hostel']);
        Route::get('hostel-buildings/export', [HostelBuildingController::class, 'export'])->name('hostel-buildings.export');
        Route::resource('hostel-buildings', HostelBuildingController::class)->except('show')->parameters(['hostel-buildings' => 'building']);
        Route::get('hostel-rooms/export', [HostelRoomController::class, 'export'])->name('hostel-rooms.export');
        Route::resource('hostel-rooms', HostelRoomController::class)->except('show')->parameters(['hostel-rooms' => 'room']);
        Route::get('hostel-beds/export', [HostelBedController::class, 'export'])->name('hostel-beds.export');
        Route::resource('hostel-beds', HostelBedController::class)->except('show')->parameters(['hostel-beds' => 'bed']);

        // Hostel Management Phase 2 — Hostel Allocation (existing enrollments to existing beds).
        Route::post('hostel-allocations/{allocation}/vacate', [HostelAllocationController::class, 'vacate'])->name('hostel-allocations.vacate');
        Route::post('hostel-allocations/{allocation}/cancel', [HostelAllocationController::class, 'cancel'])->name('hostel-allocations.cancel');
        Route::get('hostel-allocations/export', [HostelAllocationController::class, 'export'])->name('hostel-allocations.export');
        Route::resource('hostel-allocations', HostelAllocationController::class)->parameters(['hostel-allocations' => 'allocation']);

        // Hostel Management Phase 2 — Hostel Fees. Collections reuse existing Finance fee_payments rows.
        Route::post('hostel-fees/{fee}/collect', [HostelFeeController::class, 'collect'])->name('hostel-fees.collect');
        Route::get('hostel-fees/export', [HostelFeeController::class, 'export'])->name('hostel-fees.export');
        Route::resource('hostel-fees', HostelFeeController::class)->except('show')->parameters(['hostel-fees' => 'fee']);
        Route::resource('hostel-fee-structures', HostelFeeStructureController::class)->except('show')->parameters(['hostel-fee-structures' => 'fee_structure']);

        // Hostel Management Phase 3 — Hostel Attendance. Bulk routes are
        // registered before the resource so "bulk" is not captured as an id.
        Route::get('hostel-attendance/bulk', [HostelAttendanceController::class, 'bulk'])->name('hostel-attendance.bulk');
        Route::post('hostel-attendance/bulk', [HostelAttendanceController::class, 'storeBulk'])->name('hostel-attendance.bulk.store');
        Route::get('hostel-attendance/export', [HostelAttendanceController::class, 'export'])->name('hostel-attendance.export');
        Route::resource('hostel-attendance', HostelAttendanceController::class)->except('show')->parameters(['hostel-attendance' => 'hostel_attendance']);

        // Hostel Management Phase 3 — Hostel Reports (read-only; GET only).
        Route::get('hostel-reports', [HostelReportController::class, 'index'])->name('hostel-reports.index');

        // Communication Management — Phase 1 (internal only: no SMS / e-mail /
        // WhatsApp gateways, templates, delivery logs or reports). The
        // dashboard is read-only and aggregated live (no dashboard tables).
        Route::get('communication', CommunicationDashboardController::class)->name('communication.dashboard');

        // Notices / Announcements — status only changes through the workflow
        // actions (notices.publish); attachments stream from the private disk.
        Route::post('notices/{notice}/publish', [NoticeController::class, 'publish'])->name('notices.publish');
        Route::post('notices/{notice}/unpublish', [NoticeController::class, 'unpublish'])->name('notices.unpublish');
        Route::post('notices/{notice}/archive', [NoticeController::class, 'archive'])->name('notices.archive');
        Route::get('notices/{notice}/attachment', [NoticeController::class, 'attachment'])->name('notices.attachment');
        Route::resource('notices', NoticeController::class);

        // Circulars — a separate module with its own numbering (circulars.publish).
        Route::post('circulars/{circular}/publish', [CircularController::class, 'publish'])->name('circulars.publish');
        Route::post('circulars/{circular}/unpublish', [CircularController::class, 'unpublish'])->name('circulars.unpublish');
        Route::post('circulars/{circular}/archive', [CircularController::class, 'archive'])->name('circulars.archive');
        Route::get('circulars/{circular}/attachment', [CircularController::class, 'attachment'])->name('circulars.attachment');
        Route::resource('circulars', CircularController::class);

        // Internal (in-app) notifications. "read-all" is registered before the
        // resource so it is never captured as a notification id.
        Route::post('notifications/read-all', [CommunicationNotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [CommunicationNotificationController::class, 'markRead'])->name('notifications.read');
        Route::post('notifications/{notification}/unread', [CommunicationNotificationController::class, 'markUnread'])->name('notifications.unread');
        Route::resource('notifications', CommunicationNotificationController::class);

        // Communication Management — Phase 2 (templates, logs, delivery /
        // read tracking, reports). Still no external SMS / e-mail gateway:
        // templates are reusable definitions and logs only record what
        // happened to a message.
        Route::resource('communication-templates', CommunicationTemplateController::class);

        // SMS / Email logs are immutable from the UI: read-only routes only.
        Route::get('communication-logs', [CommunicationLogController::class, 'index'])->name('communication-logs.index');
        Route::get('communication-logs/{communicationLog}', [CommunicationLogController::class, 'show'])->name('communication-logs.show');

        // Delivery / read tracking of the existing notification flow.
        Route::get('communication-tracking', [CommunicationTrackingController::class, 'index'])->name('communication-tracking.index');
        Route::post('communication-tracking/{notification}/delivered', [CommunicationTrackingController::class, 'markDelivered'])->name('communication-tracking.delivered');

        // Read-only Communication Reports (live aggregates, no report tables).
        Route::get('communication-reports', [CommunicationReportController::class, 'index'])->name('communication-reports.index');

        // Read-only Certificate Reports (live aggregates over existing certificates, no report tables).
        Route::get('certificate-reports', [CertificateReportController::class, 'index'])->name('certificate-reports.index');

        // REPORTS — Consolidated Reports: one tenant-scoped, read-only screen that
        // presents the college-wide summaries of the existing modules (Student,
        // Academic, Examination, Finance, HR, Library, Transport, Hostel,
        // Inventory, Communication, Certificate). Every figure is delegated to the
        // report service that already owns it, so there are no report tables, no
        // snapshots and no write routes here — GET only.
        Route::get('consolidated-reports', [ConsolidatedReportController::class, 'index'])->name('consolidated-reports.index');

        // Inventory / Asset Management — Phase 1. The dashboard is read-only
        // and aggregated live (no dashboard tables). Items and assets share
        // one master.
        Route::get('inventory/dashboard', InventoryDashboardController::class)->name('inventory.dashboard');
        Route::get('inventory-categories/export', [InventoryCategoryController::class, 'export'])->name('inventory-categories.export');
        Route::resource('inventory-categories', InventoryCategoryController::class)->except('show')->parameters(['inventory-categories' => 'inventory_category']);
        Route::get('inventory-items/export', [InventoryItemController::class, 'export'])->name('inventory-items.export');
        Route::resource('inventory-items', InventoryItemController::class)->except('show')->parameters(['inventory-items' => 'inventory_item']);
        Route::get('inventory-vendors/export', [InventoryVendorController::class, 'export'])->name('inventory-vendors.export');
        Route::resource('inventory-vendors', InventoryVendorController::class)->except('show')->parameters(['inventory-vendors' => 'inventory_vendor']);

        // Inventory / Asset Management — Phase 2 final structure.
        // Final sidebar: Purchase & Stock → Purchase Orders, Goods Receipt / Stock In,
        // Stock Adjustment, Inventory Transactions.
        // Architecture: PO → Goods Receipt / Stock In → Inventory Transactions → Current Stock
        // Stock Adjustment also generates inventory transactions.
        // Lifecycle actions are their own routes so submitting, receiving and
        // cancelling can be granted separately. Issue / allocation, asset
        // assignment, asset return and maintenance are Phase 3 (below);
        // the read-only reporting screens are registered below.
        Route::post('inventory-purchase-orders/{purchase_order}/submit', [InventoryPurchaseOrderController::class, 'submit'])->name('inventory-purchase-orders.submit');
        Route::post('inventory-purchase-orders/{purchase_order}/cancel', [InventoryPurchaseOrderController::class, 'cancel'])->name('inventory-purchase-orders.cancel');
        Route::get('inventory-purchase-orders/{purchase_order}/receive', [InventoryPurchaseOrderController::class, 'receiveForm'])->name('inventory-purchase-orders.receive.create');
        Route::post('inventory-purchase-orders/{purchase_order}/receive', [InventoryPurchaseOrderController::class, 'receive'])->name('inventory-purchase-orders.receive.store');
        Route::get('inventory-purchase-orders/export', [InventoryPurchaseOrderController::class, 'export'])->name('inventory-purchase-orders.export');
        Route::resource('inventory-purchase-orders', InventoryPurchaseOrderController::class)->parameters(['inventory-purchase-orders' => 'purchase_order']);

        // Goods Receipt / Stock In — incoming stock (purchase_receipt + stock_in).
        // Manual stock_in is recorded here; PO receipts are booked via PO receive
        // but listed here as well.
        Route::get('inventory-goods-receipts/export', [InventoryGoodsReceiptController::class, 'export'])->name('inventory-goods-receipts.export');
        Route::get('inventory-goods-receipts', [InventoryGoodsReceiptController::class, 'index'])->name('inventory-goods-receipts.index');
        Route::get('inventory-goods-receipts/create', [InventoryGoodsReceiptController::class, 'create'])->name('inventory-goods-receipts.create');
        Route::post('inventory-goods-receipts', [InventoryGoodsReceiptController::class, 'store'])->name('inventory-goods-receipts.store');

        // Stock Adjustment — corrections and stock_out, each generating a transaction.
        Route::get('inventory-stock-adjustments/export', [InventoryStockAdjustmentController::class, 'export'])->name('inventory-stock-adjustments.export');
        Route::get('inventory-stock-adjustments', [InventoryStockAdjustmentController::class, 'index'])->name('inventory-stock-adjustments.index');
        Route::get('inventory-stock-adjustments/create', [InventoryStockAdjustmentController::class, 'create'])->name('inventory-stock-adjustments.create');
        Route::post('inventory-stock-adjustments', [InventoryStockAdjustmentController::class, 'store'])->name('inventory-stock-adjustments.store');

        // Inventory Transactions — immutable ledger (refactored Stock Movements).
        Route::get('inventory-transactions/export', [InventoryTransactionController::class, 'export'])->name('inventory-transactions.export');
        Route::get('inventory-transactions', [InventoryTransactionController::class, 'index'])->name('inventory-transactions.index');

        // Backward compatibility: old Stock Movements routes still work via the
        // original controller, but are removed from the sidebar (see layout).
        // New modules use the refactored controllers above.
        Route::get('inventory-stock', [InventoryStockController::class, 'index'])->name('inventory-stock.index');
        Route::get('inventory-stock/create', [InventoryStockController::class, 'create'])->name('inventory-stock.create');
        Route::post('inventory-stock', [InventoryStockController::class, 'store'])->name('inventory-stock.store');

        // Inventory / Asset Management — Phase 3. Four modules, each gated by
        // its OWN permission family, all inside the single existing
        // "Inventory / Asset Management" sidebar section (see layout):
        //   - Item Issue / Allocation: consumables only; stock is reduced
        //     through the existing Phase 2 ledger (stock_out movement whose
        //     reference is the issue number). Append-only.
        //   - Asset Assignment: individual assets only; custody, not
        //     consumption — no stock movement. One active assignment per
        //     asset; the rows are the asset's custody history.
        //   - Asset Return: flips the active assignment row to `returned`
        //     (history preserved); a re-assignment is a new row.
        //   - Asset Maintenance: work orders always linked to an existing
        //     asset; optional vendor reuses the Phase 1 vendor master.
        Route::get('inventory-issues/export', [InventoryIssueController::class, 'export'])->name('inventory-issues.export');
        Route::get('inventory-issues', [InventoryIssueController::class, 'index'])->name('inventory-issues.index');
        Route::get('inventory-issues/create', [InventoryIssueController::class, 'create'])->name('inventory-issues.create');
        Route::post('inventory-issues', [InventoryIssueController::class, 'store'])->name('inventory-issues.store');

        Route::get('inventory-assignments/export', [InventoryAssignmentController::class, 'export'])->name('inventory-assignments.export');
        Route::get('inventory-assignments', [InventoryAssignmentController::class, 'index'])->name('inventory-assignments.index');
        Route::get('inventory-assignments/create', [InventoryAssignmentController::class, 'create'])->name('inventory-assignments.create');
        Route::post('inventory-assignments', [InventoryAssignmentController::class, 'store'])->name('inventory-assignments.store');

        Route::get('inventory-asset-returns/export', [InventoryAssetReturnController::class, 'export'])->name('inventory-asset-returns.export');
        Route::get('inventory-asset-returns', [InventoryAssetReturnController::class, 'index'])->name('inventory-asset-returns.index');
        Route::post('inventory-asset-returns', [InventoryAssetReturnController::class, 'store'])->name('inventory-asset-returns.store');

        Route::get('inventory-maintenances/export', [InventoryMaintenanceController::class, 'export'])->name('inventory-maintenances.export');
        Route::get('inventory-maintenances', [InventoryMaintenanceController::class, 'index'])->name('inventory-maintenances.index');
        Route::get('inventory-maintenances/create', [InventoryMaintenanceController::class, 'create'])->name('inventory-maintenances.create');
        Route::post('inventory-maintenances', [InventoryMaintenanceController::class, 'store'])->name('inventory-maintenances.store');
        Route::get('inventory-maintenances/{maintenance}/edit', [InventoryMaintenanceController::class, 'edit'])->name('inventory-maintenances.edit');
        Route::put('inventory-maintenances/{maintenance}', [InventoryMaintenanceController::class, 'update'])->name('inventory-maintenances.update');

        // Phase 4: five independent, read-only views on the existing ledger,
        // item/asset master, assignment/return history and maintenance rows.
        Route::get('inventory-current-stock', [InventoryCurrentStockController::class, 'index'])->name('inventory-current-stock.index');
        Route::get('inventory-low-stock', [InventoryLowStockController::class, 'index'])->name('inventory-low-stock.index');
        Route::get('inventory-asset-register', [InventoryAssetRegisterController::class, 'index'])->name('inventory-asset-register.index');
        Route::get('inventory-stock-reports', [InventoryStockReportController::class, 'index'])->name('inventory-stock-reports.index');
        Route::get('inventory-reports', [InventoryReportController::class, 'index'])->name('inventory-reports.index');

        Route::get('/settings', [InstitutionalSettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [InstitutionalSettingController::class, 'update'])->name('settings.update');

        // Central bulk action execution endpoint
        Route::post('bulk-actions', \App\Http\Controllers\BulkActionController::class)->name('bulk-actions.execute');

    });
});

// Reuse the same auth / tenant middleware and resource policies.
require __DIR__.'/administration.php';
