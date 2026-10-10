<?php

namespace App\Providers;

use App\Domain\Academic\BulkActions\AcademicAttendanceBulkExportHandler;
use App\Domain\Academic\BulkActions\AcademicCalendarBulkExportHandler;
use App\Domain\Academic\BulkActions\AcademicSectionBulkExportHandler;
use App\Domain\Academic\BulkActions\AcademicSubjectEnrollmentBulkExportHandler;
use App\Domain\Academic\BulkActions\AcademicTimetableBulkExportHandler;
use App\Domain\Academic\BulkActions\AcademicWorkloadBulkExportHandler;
use App\Domain\Admission\BulkActions\AdmissionApplicantBulkExportHandler;
use App\Domain\Admission\BulkActions\AdmissionApplicationBulkExportHandler;
use App\Domain\Admission\BulkActions\AdmissionApplicationBulkReviewHandler;
use App\Domain\Admission\BulkActions\AdmissionBulkCancelHandler;
use App\Domain\Admission\BulkActions\AdmissionBulkCompleteHandler;
use App\Domain\Admission\BulkActions\AdmissionBulkExportHandler;
use App\Domain\Admission\BulkActions\AdmissionEnquiryBulkExportHandler;
use App\Domain\Admission\BulkActions\AdmissionEnquiryBulkStatusHandler;
use App\Domain\Certificates\BulkActions\CertificateBulkExportHandler;
use App\Domain\Certificates\BulkActions\CertificateTemplateBulkExportHandler;
use App\Domain\Certificates\BulkActions\CertificateTypeBulkExportHandler;
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
use App\Domain\Hostel\BulkActions\HostelAllocationBulkExportHandler;
use App\Domain\Hostel\BulkActions\HostelAttendanceBulkExportHandler;
use App\Domain\Hostel\BulkActions\HostelBedBulkExportHandler;
use App\Domain\Hostel\BulkActions\HostelBuildingBulkExportHandler;
use App\Domain\Hostel\BulkActions\HostelBulkExportHandler;
use App\Domain\Hostel\BulkActions\HostelFeeAssignmentBulkExportHandler;
use App\Domain\Hostel\BulkActions\HostelRoomBulkExportHandler;
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
use App\Domain\Inventory\BulkActions\InventoryAssetReturnBulkExportHandler;
use App\Domain\Inventory\BulkActions\InventoryAssignmentBulkExportHandler;
use App\Domain\Inventory\BulkActions\InventoryCategoryBulkExportHandler;
use App\Domain\Inventory\BulkActions\InventoryGoodsReceiptBulkExportHandler;
use App\Domain\Inventory\BulkActions\InventoryIssueBulkExportHandler;
use App\Domain\Inventory\BulkActions\InventoryItemBulkExportHandler;
use App\Domain\Inventory\BulkActions\InventoryMaintenanceBulkExportHandler;
use App\Domain\Inventory\BulkActions\InventoryPurchaseOrderBulkExportHandler;
use App\Domain\Inventory\BulkActions\InventoryStockAdjustmentBulkExportHandler;
use App\Domain\Inventory\BulkActions\InventoryTransactionBulkExportHandler;
use App\Domain\Inventory\BulkActions\InventoryVendorBulkExportHandler;
use App\Domain\Library\BulkActions\BookBulkExportHandler;
use App\Domain\Library\BulkActions\BookCopyBulkExportHandler;
use App\Domain\Library\BulkActions\LibraryFineBulkExportHandler;
use App\Domain\Library\BulkActions\LibraryMemberBulkExportHandler;
use App\Domain\Library\BulkActions\LibraryRenewalBulkExportHandler;
use App\Domain\Library\BulkActions\LibraryTransactionBulkExportHandler;
use App\Domain\Student\BulkActions\EnrollmentBulkExportHandler;
use App\Domain\Student\BulkActions\EnrollmentBulkSectionHandler;
use App\Domain\Student\BulkActions\EnrollmentBulkStatusHandler;
use App\Domain\Student\BulkActions\StudentBulkDocumentHandler;
use App\Domain\Student\BulkActions\StudentBulkExportHandler;
use App\Domain\Student\BulkActions\StudentBulkIdCardHandler;
use App\Domain\Student\BulkActions\StudentBulkPdfHandler;
use App\Domain\Student\BulkActions\StudentBulkPrintHandler;
use App\Domain\Transport\BulkActions\StudentTransportAssignmentBulkExportHandler;
use App\Domain\Transport\BulkActions\StudentTransportFeeAssignmentBulkExportHandler;
use App\Domain\Transport\BulkActions\TransportDriverBulkExportHandler;
use App\Domain\Transport\BulkActions\TransportRouteBulkExportHandler;
use App\Domain\Transport\BulkActions\TransportStopBulkExportHandler;
use App\Domain\Transport\BulkActions\VehicleBulkExportHandler;
use App\Domain\Transport\BulkActions\VehicleDocumentBulkExportHandler;
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

        // ADMISSION ENQUIRIES / APPLICANTS / APPLICATIONS — Phase D. Enquiries get a
        // validated bulk status change (never `converted`); all three get a CSV
        // export. Applications also get a bulk review limited to workflow-approved
        // outcomes (no bulk admit). Admission conversion stays single-record.
        $registry
            ->register('admission_enquiries', 'export', AdmissionEnquiryBulkExportHandler::class)
            ->register('admission_enquiries', 'change_status', AdmissionEnquiryBulkStatusHandler::class)
            ->register('admission_applicants', 'export', AdmissionApplicantBulkExportHandler::class)
            ->register('admission_applications', 'export', AdmissionApplicationBulkExportHandler::class)
            ->register('admission_applications', 'review', AdmissionApplicationBulkReviewHandler::class);

        $registry
            ->register('enrollments', 'export', EnrollmentBulkExportHandler::class)
            ->register('enrollments', 'change_status', EnrollmentBulkStatusHandler::class)
            ->register('enrollments', 'assign_section', EnrollmentBulkSectionHandler::class);

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

        // Library — the whole circulation family is export-only. Books, copies,
        // members, issues, renewals and fines are read out exactly as stored;
        // no bulk issue, return, renewal, fine assessment / waiver / payment or
        // deletion exists — circulation stays a single-record workflow with its
        // own services, policies and audit trail.
        $registry
            ->register('books', 'export', BookBulkExportHandler::class)
            ->register('book_copies', 'export', BookCopyBulkExportHandler::class)
            ->register('library_members', 'export', LibraryMemberBulkExportHandler::class)
            ->register('library_transactions', 'export', LibraryTransactionBulkExportHandler::class)
            ->register('library_renewals', 'export', LibraryRenewalBulkExportHandler::class)
            ->register('library_fines', 'export', LibraryFineBulkExportHandler::class);

        // Transport — masters, secure document metadata, assignments and fees.
        // Stops share one action across the flat Stops page and the per-route
        // stops listing (both show TransportStop records). The vehicle-document
        // CSV is metadata only — the private file path never leaves the server,
        // the file itself streams through the authorized download route. The
        // driver CSV never carries the license number (a government identity
        // document number). No bulk assignment, fee collection or document
        // mutation exists.
        $registry
            ->register('vehicles', 'export', VehicleBulkExportHandler::class)
            ->register('transport_drivers', 'export', TransportDriverBulkExportHandler::class)
            ->register('transport_routes', 'export', TransportRouteBulkExportHandler::class)
            ->register('transport_stops', 'export', TransportStopBulkExportHandler::class)
            ->register('vehicle_documents', 'export', VehicleDocumentBulkExportHandler::class)
            ->register('student_transport_assignments', 'export', StudentTransportAssignmentBulkExportHandler::class)
            ->register('transport_fees', 'export', StudentTransportFeeAssignmentBulkExportHandler::class);

        // Hostel — masters (hostel → building → room → bed), allocations,
        // fees and attendance. The fee CSV reuses the same live ledger the
        // listing derives from Finance fee_payments. The existing bulk
        // attendance MARKING screen is a mutation workflow and is untouched;
        // the attendance export only reads the marked records. No bulk
        // allocation, vacating, fee collection or attendance mutation exists.
        $registry
            ->register('hostels', 'export', HostelBulkExportHandler::class)
            ->register('hostel_buildings', 'export', HostelBuildingBulkExportHandler::class)
            ->register('hostel_rooms', 'export', HostelRoomBulkExportHandler::class)
            ->register('hostel_beds', 'export', HostelBedBulkExportHandler::class)
            ->register('hostel_allocations', 'export', HostelAllocationBulkExportHandler::class)
            ->register('hostel_fees', 'export', HostelFeeAssignmentBulkExportHandler::class)
            ->register('hostel_attendance', 'export', HostelAttendanceBulkExportHandler::class);

        // Inventory / Asset Management — masters, purchase orders and the
        // immutable stock ledger, plus the Phase 3 custody and maintenance
        // rows. The three stock-movement modules share the ledger model but
        // are separate module keys with their own permission, and each
        // handler accepts only the movement types its listing shows. The
        // Asset Return export accepts only ACTIVE assignments, exactly like
        // its listing. No bulk stock, issue / return, assignment / return,
        // purchase or maintenance mutation exists.
        $registry
            ->register('inventory_categories', 'export', InventoryCategoryBulkExportHandler::class)
            ->register('inventory_items', 'export', InventoryItemBulkExportHandler::class)
            ->register('inventory_vendors', 'export', InventoryVendorBulkExportHandler::class)
            ->register('inventory_purchase_orders', 'export', InventoryPurchaseOrderBulkExportHandler::class)
            ->register('inventory_goods_receipts', 'export', InventoryGoodsReceiptBulkExportHandler::class)
            ->register('inventory_stock_adjustments', 'export', InventoryStockAdjustmentBulkExportHandler::class)
            ->register('inventory_transactions', 'export', InventoryTransactionBulkExportHandler::class)
            ->register('inventory_issues', 'export', InventoryIssueBulkExportHandler::class)
            ->register('inventory_assignments', 'export', InventoryAssignmentBulkExportHandler::class)
            ->register('inventory_asset_returns', 'export', InventoryAssetReturnBulkExportHandler::class)
            ->register('inventory_maintenance', 'export', InventoryMaintenanceBulkExportHandler::class);

        // Certificates — the register screen covers every certificate type
        // (TC, Bonafide, Character, Course Completion, Migration, Provisional,
        // Custom) at every stage, so one action serves them all. Types and
        // templates are manage screens with their own permission families.
        // No bulk request, generation, issuance, verification or deletion
        // exists — the workflow stays single-record.
        $registry
            ->register('certificates', 'export', CertificateBulkExportHandler::class)
            ->register('certificate_types', 'export', CertificateTypeBulkExportHandler::class)
            ->register('certificate_templates', 'export', CertificateTemplateBulkExportHandler::class);
    }
}
