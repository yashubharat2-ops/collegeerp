<?php

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
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\FacultySubjectAssignmentController;
use App\Http\Controllers\InstitutionalSettingController;
use App\Http\Controllers\ProgramController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentEnrollmentController;
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
        Route::resource('faculties', FacultyController::class)->except('show');
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
        Route::resource('students', StudentController::class);
        Route::resource('student-enrollments', StudentEnrollmentController::class);
        Route::get('admission-reports', [AdmissionReportController::class, 'index'])->name('admission-reports.index');
        Route::get('/settings', [InstitutionalSettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [InstitutionalSettingController::class, 'update'])->name('settings.update');
    });
});
