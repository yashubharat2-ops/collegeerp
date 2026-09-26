<?php

use App\Http\Controllers\AcademicsController;
use App\Http\Controllers\AcademicTermController;
use App\Http\Controllers\AcademicYearController;
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
use App\Http\Controllers\CampusController;
use App\Http\Controllers\CollegeSwitchController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeDocumentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExamAttendanceController;
use App\Http\Controllers\ExaminationController;
use App\Http\Controllers\ExamMarkController;
use App\Http\Controllers\ExamScheduleController;
use App\Http\Controllers\ExamReportController;
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
use App\Http\Controllers\StudentFeeAssignmentController;
use App\Http\Controllers\GradeCardController;
use App\Http\Controllers\GradeScaleController;
use App\Http\Controllers\InstitutionalSettingController;
use App\Http\Controllers\StaffAttendanceController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\SalaryStructureController;
use App\Http\Controllers\SalaryComponentController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\HrReportController;
use App\Http\Controllers\AuthorController;
use App\Http\Controllers\BookCategoryController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BookCopyController;
use App\Http\Controllers\LibraryDashboardController;
use App\Http\Controllers\LibraryMemberController;
use App\Http\Controllers\LibraryFineController;
use App\Http\Controllers\LibraryReportController;
use App\Http\Controllers\LibraryRenewalController;
use App\Http\Controllers\LibraryTransactionController;
use App\Http\Controllers\PublisherController;
use App\Http\Controllers\MarksheetController;
use App\Http\Controllers\ProgramController;
use App\Http\Controllers\ResultCalculationController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\ResultPublishingController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentAcademicRecordController;
use App\Http\Controllers\StudentDocumentController;
use App\Http\Controllers\StudentEnrollmentController;
use App\Http\Controllers\StudentHistoryController;
use App\Http\Controllers\StudentIdCardController;
use App\Http\Controllers\StudentPromotionController;
use App\Http\Controllers\StudentResultHistoryController;
use App\Http\Controllers\StudentTransferController;
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
    Route::middleware(['tenant', 'tenant.access'])->group(function () {
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
        Route::resource('employees', EmployeeController::class);
        Route::resource('staff', EmployeeController::class);
        Route::resource('faculties', FacultyController::class)->except('show');
        Route::get('faculties/{faculty}', [FacultyController::class, 'show'])->name('faculties.show');
        Route::resource('staff-departments', DepartmentController::class)->except('show');
        Route::resource('designations', DesignationController::class);
        Route::get('employee-documents/{employee_document}/download', [EmployeeDocumentController::class, 'download'])->name('employee-documents.download');
        Route::resource('employee-documents', EmployeeDocumentController::class);

        // HR Phase 1 additions: tenant-scoped operational records and live reports.
        Route::resource('staff-attendance', StaffAttendanceController::class)->except('show');
        Route::resource('leave-types', LeaveTypeController::class)->except('show');
        Route::post('leave-requests/{leave_request}/approve', [LeaveRequestController::class, 'approve'])->name('leave-requests.approve');
        Route::post('leave-requests/{leave_request}/reject', [LeaveRequestController::class, 'reject'])->name('leave-requests.reject');
        Route::post('leave-requests/{leave_request}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave-requests.cancel');
        Route::resource('leave-requests', LeaveRequestController::class)->except('show');
        Route::resource('salary-structures', SalaryStructureController::class);
        Route::resource('salary-components', SalaryComponentController::class)->except('show');
        Route::post('payrolls/{payroll}/cancel', [PayrollController::class, 'cancel'])->name('payrolls.cancel');
        Route::resource('payrolls', PayrollController::class)->only(['index', 'create', 'store', 'show']);
        Route::get('hr-reports', [HrReportController::class, 'index'])->name('hr-reports.index');

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
        Route::post('admissions/{admission}/cancel', [AdmissionController::class, 'cancel'])->name('admissions.cancel');
        Route::resource('admissions', AdmissionController::class)->except('show');
        Route::post('students/convert/{admission_application}', [StudentController::class, 'convert'])->name('students.convert');
        Route::get('students/{student}/photo', [StudentController::class, 'photo'])->name('students.photo');
        Route::resource('students', StudentController::class);
        Route::resource('student-enrollments', StudentEnrollmentController::class)->except('show');

        // Students — Academic Records (progression ledger; references Platform
        // academic master data, never duplicates it).
        Route::resource('student-academic-records', StudentAcademicRecordController::class)->except('show');

        // Students — Documents (private disk, tenant-safe, authorized streaming).
        Route::get('student-documents/{student_document}/download', [StudentDocumentController::class, 'download'])->name('student-documents.download');
        Route::post('student-documents/{student_document}/verify', [StudentDocumentController::class, 'verify'])->name('student-documents.verify');
        Route::resource('student-documents', StudentDocumentController::class)->except('show');

        // Students — ID Cards (generated from Student + Enrollment; no records).
        Route::get('student-id-cards', [StudentIdCardController::class, 'index'])->name('student-id-cards.index');
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
        Route::get('admission-reports', [AdmissionReportController::class, 'index'])->name('admission-reports.index');
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
        Route::resource('examinations', ExaminationController::class)->except('show');
        Route::resource('exam-schedules', ExamScheduleController::class)->except('show');

        // Examinations (Phase 2) — Exam Attendance and Marks Entry.
        Route::post('exam-attendance/bulk', [ExamAttendanceController::class, 'bulk'])->name('exam-attendance.bulk');
        Route::resource('exam-attendance', ExamAttendanceController::class)->except('show');
        Route::post('exam-marks/bulk', [ExamMarkController::class, 'bulk'])->name('exam-marks.bulk');
        Route::resource('exam-marks', ExamMarkController::class)->except('show');

        // Examinations (Phase 3) — Results, Result Calculation, Grade / Pass-Fail, Result Publishing.
        Route::get('results', [ResultController::class, 'index'])->name('results.index');
        Route::get('results/{result}', [ResultController::class, 'show'])->name('results.show');

        Route::get('result-calculation', [ResultCalculationController::class, 'index'])->name('result-calculation.index');
        Route::post('result-calculation/calculate', [ResultCalculationController::class, 'calculate'])->name('result-calculation.calculate');
        Route::post('result-calculation/recalculate', [ResultCalculationController::class, 'recalculate'])->name('result-calculation.recalculate');

        Route::resource('grade-scales', GradeScaleController::class)->except('show');

        Route::get('result-publishing', [ResultPublishingController::class, 'index'])->name('result-publishing.index');
        Route::post('result-publishing/bulk', [ResultPublishingController::class, 'publishBulk'])->name('result-publishing.bulk');
        Route::post('result-publishing/examination/{examination}', [ResultPublishingController::class, 'publishExamination'])->name('result-publishing.examination');
        Route::post('result-publishing/{result}/publish', [ResultPublishingController::class, 'publish'])->name('result-publishing.publish');
        Route::post('result-publishing/{result}/unpublish', [ResultPublishingController::class, 'unpublish'])->name('result-publishing.unpublish');

        // Examinations (Phase 4A) — Marksheets. Derived printable documents,
        // read-only: no create/update/delete routes.
        Route::get('marksheets', [MarksheetController::class, 'index'])->name('marksheets.index');
        Route::get('marksheets/{result}', [MarksheetController::class, 'show'])->name('marksheets.show');

        // Examinations (Phase 4) — Grade Cards. Derived printable documents,
        // read-only: no create/update/delete routes.
        Route::get('grade-cards', [GradeCardController::class, 'index'])->name('grade-cards.index');
        Route::get('grade-cards/{result}', [GradeCardController::class, 'show'])->name('grade-cards.show');

        // Examinations (Phase 4) — Exam Reports. Aggregated published-result
        // summaries, read-only.
        Route::get('exam-reports', [ExamReportController::class, 'index'])->name('exam-reports.index');

        // Examinations (Phase 4) — Student Result History. A student's
        // published examination timeline, read-only.
        Route::get('student-result-history', [StudentResultHistoryController::class, 'index'])->name('student-result-history.index');
        Route::get('student-result-history/{student}', [StudentResultHistoryController::class, 'show'])->name('student-result-history.show');

        // Finance / Fees — Fee Structure foundation (structure definitions only;
        // no money moves here).
        Route::resource('fee-structures', FeeStructureController::class)->except('show');

        // Finance / Fees — Fee Categories: the classification of fee heads.
        Route::resource('fee-categories', FeeCategoryController::class)->except('show');

        // Finance / Fees — Student Fee Assignment: an existing fee structure
        // assigned to an existing student enrollment.
        Route::resource('student-fee-assignments', StudentFeeAssignmentController::class)->except('show');

        // Finance / Fees — Fee Collection: the only screen that moves money in.
        Route::post('fee-collections/{fee_collection}/cancel', [FeePaymentController::class, 'cancel'])->name('fee-collections.cancel');
        Route::resource('fee-collections', FeePaymentController::class)->except('show');

        // Finance / Fees — Receipts: derived from successful collections, so
        // read-only (no create/update/delete routes exist).
        Route::get('receipts', [FeeReceiptController::class, 'index'])->name('receipts.index');
        Route::get('receipts/{fee_payment}/print', [FeeReceiptController::class, 'print'])->name('receipts.print');
        Route::get('receipts/{fee_payment}', [FeeReceiptController::class, 'show'])->name('receipts.show');

        // Finance / Fees — Due / Outstanding Fees: derived ledger, read-only.
        Route::get('fee-dues', [FeeDueController::class, 'index'])->name('fee-dues.index');

        // Finance / Fees — Fee Discounts / Concessions.
        Route::post('fee-concessions/{fee_concession}/approve', [FeeConcessionController::class, 'approve'])->name('fee-concessions.approve');
        Route::resource('fee-concessions', FeeConcessionController::class)->except('show');

        // Finance / Fees — Refunds against actual collections. No delete route:
        // refund records are never removed.
        Route::post('refunds/{refund}/approve', [FeeRefundController::class, 'approve'])->name('refunds.approve');
        Route::post('refunds/{refund}/process', [FeeRefundController::class, 'process'])->name('refunds.process');
        Route::resource('refunds', FeeRefundController::class)->except(['show', 'destroy']);

        // Finance / Fees — Fee Reports: read-only aggregation of the records above.
        Route::get('fee-reports', [FeeReportController::class, 'index'])->name('fee-reports.index');

        // Library Management — Library Dashboard: read-only overview of the
        // Phase 1 masters, aggregated live (no dashboard tables).
        Route::get('library/dashboard', LibraryDashboardController::class)->name('library.dashboard');

        // Library Management — Books: the book master (a title, not a copy).
        Route::resource('books', BookController::class);

        // Library Management — Book Categories.
        Route::resource('book-categories', BookCategoryController::class)->except('show');

        // Library Management — Authors / Publishers: reusable references for books.
        Route::resource('authors', AuthorController::class)->except('show');
        Route::resource('publishers', PublisherController::class)->except('show');

        // Library Management — Phase 2. Copies, members, issue/return and
        // renewals. Circulation history has no delete route.
        Route::resource('book-copies', BookCopyController::class);
        Route::resource('library-members', LibraryMemberController::class);
        Route::post('library-transactions/{library_transaction}/return', [LibraryTransactionController::class, 'returnCopy'])->name('library-transactions.return');
        Route::post('library-transactions/{library_transaction}/lost', [LibraryTransactionController::class, 'markLost'])->name('library-transactions.lost');
        Route::resource('library-transactions', LibraryTransactionController::class)->except('destroy');
        Route::resource('library-renewals', LibraryRenewalController::class)->only(['index', 'create', 'store', 'show']);
        Route::get('library-fines', [LibraryFineController::class, 'index'])->name('library-fines.index');
        Route::post('library-fines', [LibraryFineController::class, 'store'])->name('library-fines.store');
        Route::post('library-fines/{library_fine}/pay', [LibraryFineController::class, 'pay'])->name('library-fines.pay');
        Route::get('library-reports', [LibraryReportController::class, 'index'])->name('library-reports.index');

        // Transport Phase 1 — tenant-scoped masters only.
        Route::get('transport/dashboard', \App\Http\Controllers\Transport\TransportDashboardController::class)->name('transport.dashboard');
        Route::resource('vehicles', \App\Http\Controllers\Transport\VehicleController::class)->except('show')->parameters(['vehicles' => 'record']);
        Route::resource('transport-drivers', \App\Http\Controllers\Transport\TransportDriverController::class)->except('show')->parameters(['transport-drivers' => 'record']);
        Route::resource('transport-routes', \App\Http\Controllers\Transport\TransportRouteController::class)->except('show')->parameters(['transport-routes' => 'record']);
        Route::resource('transport-routes.transport-stops', \App\Http\Controllers\Transport\TransportStopController::class)->except('show')->parameters(['transport-stops' => 'record'])->names([
            'index' => 'transport-stops.index',
            'create' => 'transport-stops.create',
            'store' => 'transport-stops.store',
            'edit' => 'transport-stops.edit',
            'update' => 'transport-stops.update',
            'destroy' => 'transport-stops.destroy',
        ]);

        // Transport Phase 2 — flat cross-route stop listing for the "Stops"
        // navigation entry (per-route stop management stays nested above).
        Route::get('transport-stops', [\App\Http\Controllers\Transport\TransportStopController::class, 'indexAll'])->name('transport-stops.list');

        // Transport Phase 2 — Vehicle Documents (secure private files).
        Route::get('vehicle-documents/{vehicle_document}/download', [\App\Http\Controllers\Transport\VehicleDocumentController::class, 'download'])->name('vehicle-documents.download');
        Route::resource('vehicle-documents', \App\Http\Controllers\Transport\VehicleDocumentController::class)->except('show')->parameters(['vehicle-documents' => 'vehicle_document']);

        // Transport Phase 2 — Student Transport Assignment (existing enrollments
        // onto existing routes/stops; no duplicate masters).
        Route::resource('transport-assignments', \App\Http\Controllers\Transport\StudentTransportAssignmentController::class)->except('show')->parameters(['transport-assignments' => 'transport_assignment']);

        // Transport Phase 2 — Transport Fees. Collections reuse the existing
        // Finance fee_payments rows (see FeeCollectionService::collectTransportFee).
        Route::post('transport-fees/{transport_fee}/collect', [\App\Http\Controllers\Transport\TransportFeeController::class, 'collect'])->name('transport-fees.collect');
        Route::resource('transport-fees', \App\Http\Controllers\Transport\TransportFeeController::class)->except('show')->parameters(['transport-fees' => 'transport_fee']);
        Route::resource('transport-fee-structures', \App\Http\Controllers\Transport\TransportFeeStructureController::class)->except('show')->parameters(['transport-fee-structures' => 'transport_fee_structure']);

        // Transport Phase 2 — Transport Reports (read-only).
        Route::get('transport-reports', [\App\Http\Controllers\Transport\TransportReportController::class, 'index'])->name('transport-reports.index');

        // Hostel Management — Phase 1 masters + Phase 2 allocations and fees.
        // The dashboard is read-only and aggregated live (no dashboard tables).
        Route::get('hostels/dashboard', \App\Http\Controllers\Hostel\HostelDashboardController::class)->name('hostels.dashboard');
        Route::resource('hostels', \App\Http\Controllers\Hostel\HostelController::class)->except('show')->parameters(['hostels' => 'hostel']);
        Route::resource('hostel-buildings', \App\Http\Controllers\Hostel\HostelBuildingController::class)->except('show')->parameters(['hostel-buildings' => 'building']);
        Route::resource('hostel-rooms', \App\Http\Controllers\Hostel\HostelRoomController::class)->except('show')->parameters(['hostel-rooms' => 'room']);
        Route::resource('hostel-beds', \App\Http\Controllers\Hostel\HostelBedController::class)->except('show')->parameters(['hostel-beds' => 'bed']);

        // Hostel Management Phase 2 — Hostel Allocation (existing enrollments to existing beds).
        Route::post('hostel-allocations/{allocation}/vacate', [\App\Http\Controllers\Hostel\HostelAllocationController::class, 'vacate'])->name('hostel-allocations.vacate');
        Route::post('hostel-allocations/{allocation}/cancel', [\App\Http\Controllers\Hostel\HostelAllocationController::class, 'cancel'])->name('hostel-allocations.cancel');
        Route::resource('hostel-allocations', \App\Http\Controllers\Hostel\HostelAllocationController::class)->parameters(['hostel-allocations' => 'allocation']);

        // Hostel Management Phase 2 — Hostel Fees. Collections reuse existing Finance fee_payments rows.
        Route::post('hostel-fees/{fee}/collect', [\App\Http\Controllers\Hostel\HostelFeeController::class, 'collect'])->name('hostel-fees.collect');
        Route::resource('hostel-fees', \App\Http\Controllers\Hostel\HostelFeeController::class)->except('show')->parameters(['hostel-fees' => 'fee']);
        Route::resource('hostel-fee-structures', \App\Http\Controllers\Hostel\HostelFeeStructureController::class)->except('show')->parameters(['hostel-fee-structures' => 'fee_structure']);

        // Hostel Management Phase 3 — Hostel Attendance. Bulk routes are
        // registered before the resource so "bulk" is not captured as an id.
        Route::get('hostel-attendance/bulk', [\App\Http\Controllers\Hostel\HostelAttendanceController::class, 'bulk'])->name('hostel-attendance.bulk');
        Route::post('hostel-attendance/bulk', [\App\Http\Controllers\Hostel\HostelAttendanceController::class, 'storeBulk'])->name('hostel-attendance.bulk.store');
        Route::resource('hostel-attendance', \App\Http\Controllers\Hostel\HostelAttendanceController::class)->except('show')->parameters(['hostel-attendance' => 'hostel_attendance']);

        // Hostel Management Phase 3 — Hostel Reports (read-only; GET only).
        Route::get('hostel-reports', [\App\Http\Controllers\Hostel\HostelReportController::class, 'index'])->name('hostel-reports.index');

        // Communication Management — Phase 1 (internal only: no SMS / e-mail /
        // WhatsApp gateways, templates, delivery logs or reports). The
        // dashboard is read-only and aggregated live (no dashboard tables).
        Route::get('communication', \App\Http\Controllers\Communication\CommunicationDashboardController::class)->name('communication.dashboard');

        // Notices / Announcements — status only changes through the workflow
        // actions (notices.publish); attachments stream from the private disk.
        Route::post('notices/{notice}/publish', [\App\Http\Controllers\Communication\NoticeController::class, 'publish'])->name('notices.publish');
        Route::post('notices/{notice}/unpublish', [\App\Http\Controllers\Communication\NoticeController::class, 'unpublish'])->name('notices.unpublish');
        Route::post('notices/{notice}/archive', [\App\Http\Controllers\Communication\NoticeController::class, 'archive'])->name('notices.archive');
        Route::get('notices/{notice}/attachment', [\App\Http\Controllers\Communication\NoticeController::class, 'attachment'])->name('notices.attachment');
        Route::resource('notices', \App\Http\Controllers\Communication\NoticeController::class);

        // Circulars — a separate module with its own numbering (circulars.publish).
        Route::post('circulars/{circular}/publish', [\App\Http\Controllers\Communication\CircularController::class, 'publish'])->name('circulars.publish');
        Route::post('circulars/{circular}/unpublish', [\App\Http\Controllers\Communication\CircularController::class, 'unpublish'])->name('circulars.unpublish');
        Route::post('circulars/{circular}/archive', [\App\Http\Controllers\Communication\CircularController::class, 'archive'])->name('circulars.archive');
        Route::get('circulars/{circular}/attachment', [\App\Http\Controllers\Communication\CircularController::class, 'attachment'])->name('circulars.attachment');
        Route::resource('circulars', \App\Http\Controllers\Communication\CircularController::class);

        // Internal (in-app) notifications. "read-all" is registered before the
        // resource so it is never captured as a notification id.
        Route::post('notifications/read-all', [\App\Http\Controllers\Communication\CommunicationNotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [\App\Http\Controllers\Communication\CommunicationNotificationController::class, 'markRead'])->name('notifications.read');
        Route::post('notifications/{notification}/unread', [\App\Http\Controllers\Communication\CommunicationNotificationController::class, 'markUnread'])->name('notifications.unread');
        Route::resource('notifications', \App\Http\Controllers\Communication\CommunicationNotificationController::class);

        // Communication Management — Phase 2 (templates, logs, delivery /
        // read tracking, reports). Still no external SMS / e-mail gateway:
        // templates are reusable definitions and logs only record what
        // happened to a message.
        Route::resource('communication-templates', \App\Http\Controllers\Communication\CommunicationTemplateController::class);

        // SMS / Email logs are immutable from the UI: read-only routes only.
        Route::get('communication-logs', [\App\Http\Controllers\Communication\CommunicationLogController::class, 'index'])->name('communication-logs.index');
        Route::get('communication-logs/{communicationLog}', [\App\Http\Controllers\Communication\CommunicationLogController::class, 'show'])->name('communication-logs.show');

        // Delivery / read tracking of the existing notification flow.
        Route::get('communication-tracking', [\App\Http\Controllers\Communication\CommunicationTrackingController::class, 'index'])->name('communication-tracking.index');
        Route::post('communication-tracking/{notification}/delivered', [\App\Http\Controllers\Communication\CommunicationTrackingController::class, 'markDelivered'])->name('communication-tracking.delivered');

        // Read-only Communication Reports (live aggregates, no report tables).
        Route::get('communication-reports', [\App\Http\Controllers\Communication\CommunicationReportController::class, 'index'])->name('communication-reports.index');

        // Inventory / Asset Management — Phase 1. The dashboard is read-only
        // and aggregated live (no dashboard tables). Items and assets share
        // one master.
        Route::get('inventory/dashboard', \App\Http\Controllers\Inventory\InventoryDashboardController::class)->name('inventory.dashboard');
        Route::resource('inventory-categories', \App\Http\Controllers\Inventory\InventoryCategoryController::class)->except('show')->parameters(['inventory-categories' => 'inventory_category']);
        Route::resource('inventory-items', \App\Http\Controllers\Inventory\InventoryItemController::class)->except('show')->parameters(['inventory-items' => 'inventory_item']);
        Route::resource('inventory-vendors', \App\Http\Controllers\Inventory\InventoryVendorController::class)->except('show')->parameters(['inventory-vendors' => 'inventory_vendor']);

        // Inventory / Asset Management — Phase 2 final structure.
        // Final sidebar: Purchase & Stock → Purchase Orders, Goods Receipt / Stock In,
        // Stock Adjustment, Inventory Transactions.
        // Architecture: PO → Goods Receipt / Stock In → Inventory Transactions → Current Stock
        // Stock Adjustment also generates inventory transactions.
        // Lifecycle actions are their own routes so submitting, receiving and
        // cancelling can be granted separately. Issue/return to staff, asset
        // assignment, maintenance and reports are later phases.
        Route::post('inventory-purchase-orders/{purchase_order}/submit', [\App\Http\Controllers\Inventory\InventoryPurchaseOrderController::class, 'submit'])->name('inventory-purchase-orders.submit');
        Route::post('inventory-purchase-orders/{purchase_order}/cancel', [\App\Http\Controllers\Inventory\InventoryPurchaseOrderController::class, 'cancel'])->name('inventory-purchase-orders.cancel');
        Route::get('inventory-purchase-orders/{purchase_order}/receive', [\App\Http\Controllers\Inventory\InventoryPurchaseOrderController::class, 'receiveForm'])->name('inventory-purchase-orders.receive.create');
        Route::post('inventory-purchase-orders/{purchase_order}/receive', [\App\Http\Controllers\Inventory\InventoryPurchaseOrderController::class, 'receive'])->name('inventory-purchase-orders.receive.store');
        Route::resource('inventory-purchase-orders', \App\Http\Controllers\Inventory\InventoryPurchaseOrderController::class)->parameters(['inventory-purchase-orders' => 'purchase_order']);

        // Goods Receipt / Stock In — incoming stock (purchase_receipt + stock_in).
        // Manual stock_in is recorded here; PO receipts are booked via PO receive
        // but listed here as well.
        Route::get('inventory-goods-receipts', [\App\Http\Controllers\Inventory\InventoryGoodsReceiptController::class, 'index'])->name('inventory-goods-receipts.index');
        Route::get('inventory-goods-receipts/create', [\App\Http\Controllers\Inventory\InventoryGoodsReceiptController::class, 'create'])->name('inventory-goods-receipts.create');
        Route::post('inventory-goods-receipts', [\App\Http\Controllers\Inventory\InventoryGoodsReceiptController::class, 'store'])->name('inventory-goods-receipts.store');

        // Stock Adjustment — corrections and stock_out, each generating a transaction.
        Route::get('inventory-stock-adjustments', [\App\Http\Controllers\Inventory\InventoryStockAdjustmentController::class, 'index'])->name('inventory-stock-adjustments.index');
        Route::get('inventory-stock-adjustments/create', [\App\Http\Controllers\Inventory\InventoryStockAdjustmentController::class, 'create'])->name('inventory-stock-adjustments.create');
        Route::post('inventory-stock-adjustments', [\App\Http\Controllers\Inventory\InventoryStockAdjustmentController::class, 'store'])->name('inventory-stock-adjustments.store');

        // Inventory Transactions — immutable ledger (refactored Stock Movements).
        Route::get('inventory-transactions', [\App\Http\Controllers\Inventory\InventoryTransactionController::class, 'index'])->name('inventory-transactions.index');

        // Backward compatibility: old Stock Movements routes still work via the
        // original controller, but are removed from the sidebar (see layout).
        // New modules use the refactored controllers above.
        Route::get('inventory-stock', [\App\Http\Controllers\Inventory\InventoryStockController::class, 'index'])->name('inventory-stock.index');
        Route::get('inventory-stock/create', [\App\Http\Controllers\Inventory\InventoryStockController::class, 'create'])->name('inventory-stock.create');
        Route::post('inventory-stock', [\App\Http\Controllers\Inventory\InventoryStockController::class, 'store'])->name('inventory-stock.store');

        Route::get('/settings', [InstitutionalSettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [InstitutionalSettingController::class, 'update'])->name('settings.update');
    });
});
