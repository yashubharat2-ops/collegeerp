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
        Route::resource('student-enrollments', StudentEnrollmentController::class);

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

        Route::get('/settings', [InstitutionalSettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [InstitutionalSettingController::class, 'update'])->name('settings.update');
    });
});
es.pay');
        Route::get('library-reports', [LibraryReportController::class, 'index'])->name('library-reports.index');

        Route::get('/settings', [InstitutionalSettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [InstitutionalSettingController::class, 'update'])->name('settings.update');
    });
});
