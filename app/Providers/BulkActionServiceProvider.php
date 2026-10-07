<?php

namespace App\Providers;

use App\Domain\Academic\BulkActions\AcademicAttendanceBulkExportHandler;
use App\Domain\Academic\BulkActions\AcademicCalendarBulkExportHandler;
use App\Domain\Academic\BulkActions\AcademicSectionBulkExportHandler;
use App\Domain\Academic\BulkActions\AcademicSubjectEnrollmentBulkExportHandler;
use App\Domain\Academic\BulkActions\AcademicTimetableBulkExportHandler;
use App\Domain\Academic\BulkActions\AcademicWorkloadBulkExportHandler;
use App\Domain\Admission\BulkActions\AdmissionBulkCancelHandler;
use App\Domain\Admission\BulkActions\AdmissionBulkCompleteHandler;
use App\Domain\Admission\BulkActions\AdmissionBulkExportHandler;
use App\Domain\Examination\BulkActions\ExamAttendanceBulkExportHandler;
use App\Domain\Examination\BulkActions\ExamMarkBulkExportHandler;
use App\Domain\Examination\BulkActions\ExamReportProgramBulkExportHandler;
use App\Domain\Examination\BulkActions\ExamReportSubjectBulkExportHandler;
use App\Domain\Examination\BulkActions\ExamResultBulkExportHandler;
use App\Domain\Examination\BulkActions\ExamScheduleBulkExportHandler;
use App\Domain\Examination\BulkActions\ExaminationBulkExportHandler;
use App\Domain\Examination\BulkActions\GradeCardBulkExportHandler;
use App\Domain\Examination\BulkActions\GradeScaleBulkExportHandler;
use App\Domain\Examination\BulkActions\MarksheetBulkExportHandler;
use App\Domain\Examination\BulkActions\ResultCalculationBulkExportHandler;
use App\Domain\Examination\BulkActions\ResultPublishingBulkExportHandler;
use App\Domain\Finance\BulkActions\FeeCategoryBulkExportHandler;
use App\Domain\Finance\BulkActions\FeeCollectionBulkExportHandler;
use App\Domain\Finance\BulkActions\FeeConcessionBulkExportHandler;
use App\Domain\Finance\BulkActions\FeeDueBulkExportHandler;
use App\Domain\Finance\BulkActions\FeeStructureBulkExportHandler;
use App\Domain\Finance\BulkActions\ReceiptBulkExportHandler;
use App\Domain\Finance\BulkActions\RefundBulkExportHandler;
use App\Domain\Finance\BulkActions\StudentFeeAssignmentBulkExportHandler;
use App\Domain\HR\BulkActions\DesignationBulkExportHandler;
use App\Domain\HR\BulkActions\EmployeeDocumentBulkExportHandler;
use App\Domain\HR\BulkActions\LeaveRequestBulkExportHandler;
use App\Domain\HR\BulkActions\LeaveTypeBulkExportHandler;
use App\Domain\HR\BulkActions\PayrollBulkExportHandler;
use App\Domain\HR\BulkActions\SalaryComponentBulkExportHandler;
use App\Domain\HR\BulkActions\SalaryStructureBulkExportHandler;
use App\Domain\HR\BulkActions\StaffAttendanceBulkExportHandler;
use App\Domain\HR\BulkActions\StaffDepartmentBulkExportHandler;
use App\Domain\HR\BulkActions\StaffEmployeeBulkExportHandler;
use App\Domain\Student\BulkActions\EnrollmentBulkExportHandler;
use App\Domain\Student\BulkActions\EnrollmentBulkStatusHandler;
use App\Domain\Student\BulkActions\StudentBulkDocumentHandler;
use App\Domain\Student\BulkActions\StudentBulkExportHandler;
use App\Domain\Student\BulkActions\StudentBulkIdCardHandler;
use App\Domain\Student\BulkActions\StudentBulkPdfHandler;
use App\Domain\Student\BulkActions\StudentBulkPrintHandler;
use App\Support\BulkAction\BulkActionRegistry;
use Illuminate\Support\ServiceProvider;

class BulkActionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BulkActionRegistry::class, function () {
            return new BulkActionRegistry();
        });
    }

    public function boot(): void
    {
        $registry = $this->app->make(BulkActionRegistry::class);

        // Students — the first module to register handlers. `module` + `action`
        // are the two validated keys of the shared bulk action endpoint; the
        // action names are exactly what the listing's bulk bar posts.
        $registry
            ->register('students', 'export', StudentBulkExportHandler::class)
            ->register('students', 'id_cards', StudentBulkIdCardHandler::class)
            ->register('students', 'documents', StudentBulkDocumentHandler::class)
            // The Export menu's two report formats (the printable A4 student
            // list). Same permission, same server-side id re-query and same
            // per-record policy as `export` — only the destination differs, so
            // they are separate actions rather than a parameter the bulk bar
            // cannot send.
            ->register('students', 'export_pdf', StudentBulkPdfHandler::class)
            ->register('students', 'export_print', StudentBulkPrintHandler::class);

        $registry
            ->register('admissions', 'export', AdmissionBulkExportHandler::class)
            ->register('admissions', 'cancel', AdmissionBulkCancelHandler::class)
            ->register('admissions', 'complete', AdmissionBulkCompleteHandler::class);

        $registry
            ->register('enrollments', 'export', EnrollmentBulkExportHandler::class)
            ->register('enrollments', 'change_status', EnrollmentBulkStatusHandler::class);

        // ACADEMIC — one `export` action per Academic listing. The module keys
        // are exactly the `module` attribute the listing's bulk bar renders, and
        // every handler extends the same BulkExportHandler base: the ids are
        // re-queried inside the active college, each record is re-authorized
        // (policy where the model has one, module permission otherwise), and the
        // destination is a CSV endpoint that re-resolves those ids again. No
        // Academic bulk action mutates anything — timetable conflicts, attendance
        // marking and calendar edits keep their existing single-record paths.
        $registry
            ->register('academic_subject_enrollments', 'export', AcademicSubjectEnrollmentBulkExportHandler::class)
            ->register('academic_sections', 'export', AcademicSectionBulkExportHandler::class)
            ->register('academic_timetables', 'export', AcademicTimetableBulkExportHandler::class)
            ->register('academic_attendance', 'export', AcademicAttendanceBulkExportHandler::class)
            ->register('academic_calendar', 'export', AcademicCalendarBulkExportHandler::class)
            // Workload is derived; its "ids" are the representative timetable
            // entries of the selected groups (see FacultyWorkloadService).
            ->register('academic_workload', 'export', AcademicWorkloadBulkExportHandler::class);

        // EXAMINATIONS — the same contract for every Examination listing. Marks,
        // attendance, results, grades and exam schedules are export-only: none of
        // them has a safe bulk mutation, and result data is produced by the
        // calculation engine, never by hand.
        $registry
            ->register('examinations', 'export', ExaminationBulkExportHandler::class)
            ->register('exam_schedules', 'export', ExamScheduleBulkExportHandler::class)
            ->register('exam_attendance', 'export', ExamAttendanceBulkExportHandler::class)
            ->register('exam_marks', 'export', ExamMarkBulkExportHandler::class)
            ->register('results', 'export', ExamResultBulkExportHandler::class)
            // The calculation worklist exports the results in scope; the
            // calculate/recalculate endpoints remain the only way to run the
            // engine, and publishing stays with ResultPublishingService.
            ->register('result_calculation', 'export', ResultCalculationBulkExportHandler::class)
            ->register('grade_scales', 'export', GradeScaleBulkExportHandler::class)
            ->register('result_publishing', 'export', ResultPublishingBulkExportHandler::class)
            // Marksheets and grade cards are derived from PUBLISHED results, so
            // their "records" are those result ids and the published-only rule
            // lives in the handlers.
            ->register('marksheets', 'export', MarksheetBulkExportHandler::class)
            ->register('grade_cards', 'export', GradeCardBulkExportHandler::class)
            // Exam Reports has one selectable table per group: the program-wise
            // and the subject-wise summary. Each group is its own module key, so
            // program ids and subject ids can never be mixed in one selection.
            ->register('exam_reports', 'export', ExamReportProgramBulkExportHandler::class)
            ->register('exam_report_subjects', 'export', ExamReportSubjectBulkExportHandler::class);

        // Finance / Fees — every scrollable Finance listing registers exactly one
        // read-only handler. Money must never be moved from a checkbox: collecting
        // a fee, issuing/cancelling a receipt, approving a refund or a concession,
        // re-assigning a fee plan and modifying a ledger all stay single-record
        // workflows with their own controller actions, services, policies and audit
        // trails. Each handler re-queries the ticked ids inside the active college
        // and re-authorizes every record through its own policy before the CSV
        // endpoint streams; receipts and dues (derived from collections and
        // assignments) re-authorize the underlying record.
        $registry
            ->register('fee_structures', 'export', FeeStructureBulkExportHandler::class)
            ->register('fee_categories', 'export', FeeCategoryBulkExportHandler::class)
            ->register('student_fee_assignments', 'export', StudentFeeAssignmentBulkExportHandler::class)
            ->register('fee_collections', 'export', FeeCollectionBulkExportHandler::class)
            ->register('receipts', 'export', ReceiptBulkExportHandler::class)
            ->register('fee_dues', 'export', FeeDueBulkExportHandler::class)
            ->register('fee_concessions', 'export', FeeConcessionBulkExportHandler::class)
            ->register('refunds', 'export', RefundBulkExportHandler::class);

        // Human Resource (HR) — staff, masters, documents, attendance, leave and
        // salary / payroll. As in Finance, the whole family is export-only: pay
        // figures, payroll runs, attendance corrections, leave decisions and
        // employee status are produced by their existing services, never by a bulk
        // selection. The employee-document CSV is metadata only — the private file
        // path is never queried, so it cannot leak into a download.
        $registry
            ->register('staff_employees', 'export', StaffEmployeeBulkExportHandler::class)
            ->register('staff_departments', 'export', StaffDepartmentBulkExportHandler::class)
            ->register('designations', 'export', DesignationBulkExportHandler::class)
            ->register('employee_documents', 'export', EmployeeDocumentBulkExportHandler::class)
            ->register('staff_attendance', 'export', StaffAttendanceBulkExportHandler::class)
            ->register('leave_requests', 'export', LeaveRequestBulkExportHandler::class)
            ->register('leave_types', 'export', LeaveTypeBulkExportHandler::class)
            ->register('salary_structures', 'export', SalaryStructureBulkExportHandler::class)
            ->register('salary_components', 'export', SalaryComponentBulkExportHandler::class)
            ->register('payrolls', 'export', PayrollBulkExportHandler::class);
    }
}
