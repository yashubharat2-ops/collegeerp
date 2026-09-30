<?php

namespace App\Providers;

use App\Http\Controllers\LibraryReportController;
use App\Models\AcademicReport;
use App\Models\AcademicSubjectEnrollment;
use App\Models\AcademicTerm;
use App\Models\AcademicTimetable;
use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentType;
use App\Models\AdmissionEnquiry;
use App\Models\AdmissionMeritEntry;
use App\Models\AdmissionMeritList;
use App\Models\AuditLog;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Models\Campus;
use App\Models\CertificateReport;
use App\Models\Circular;
use App\Models\College;
use App\Models\CommunicationDashboard;
use App\Models\CommunicationLog;
use App\Models\CommunicationNotification;
use App\Models\CommunicationReport;
use App\Models\CommunicationTemplate;
use App\Models\CommunicationTracking;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\ExamAttendance;
use App\Models\Examination;
use App\Models\ExaminationReport;
use App\Models\ExamMark;
use App\Models\ExamReport;
use App\Models\ExamResult;
use App\Models\ExamSchedule;
use App\Models\Faculty;
use App\Models\FacultySubjectAssignment;
use App\Models\FeeCategory;
use App\Models\FeeConcession;
use App\Models\FeeDue;
use App\Models\FeePayment;
use App\Models\FeeReceipt;
use App\Models\FeeRefund;
use App\Models\FeeReport;
use App\Models\FeeStructure;
use App\Models\FinanceReport;
use App\Models\GradeCard;
use App\Models\GradeScale;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelAttendance;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelDashboard;
use App\Models\HostelFeeAssignment;
use App\Models\HostelFeeStructure;
use App\Models\HostelReport;
use App\Models\HostelRoom;
use App\Models\HrReport;
use App\Models\InstitutionalSetting;
use App\Models\InventoryAssignment;
use App\Models\InventoryCategory;
use App\Models\InventoryDashboard;
use App\Models\InventoryIssue;
use App\Models\InventoryItem;
use App\Models\InventoryMaintenance;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryStockMovement;
use App\Models\InventoryVendor;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LibraryDashboard;
use App\Models\LibraryFine;
use App\Models\LibraryMember;
use App\Models\LibraryRenewal;
use App\Models\LibraryTransaction;
use App\Models\Marksheet;
use App\Models\Notice;
use App\Models\Payroll;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Publisher;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\Section;
use App\Models\StaffAttendance;
use App\Models\Student;
use App\Models\StudentAcademicRecord;
use App\Models\StudentDocument;
use App\Models\StudentEnrollment;
use App\Models\StudentFeeAssignment;
use App\Models\StudentPromotion;
use App\Models\StudentReport;
use App\Models\StudentResultHistory;
use App\Models\StudentTransfer;
use App\Models\StudentTransportAssignment;
use App\Models\StudentTransportFeeAssignment;
use App\Models\Subject;
use App\Models\TransportDashboard;
use App\Models\TransportDriver;
use App\Models\TransportFeeStructure;
use App\Models\TransportReport;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Policies\AcademicReportPolicy;
use App\Policies\AcademicSubjectEnrollmentPolicy;
use App\Policies\AcademicTermPolicy;
use App\Policies\AcademicTimetablePolicy;
use App\Policies\AcademicYearPolicy;
use App\Policies\AdmissionApplicantPolicy;
use App\Policies\AdmissionApplicationPolicy;
use App\Policies\AdmissionDocumentPolicy;
use App\Policies\AdmissionDocumentTypePolicy;
use App\Policies\AdmissionEnquiryPolicy;
use App\Policies\AdmissionMeritEntryPolicy;
use App\Policies\AdmissionMeritListPolicy;
use App\Policies\AdmissionPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\AuthorPolicy;
use App\Policies\BookCategoryPolicy;
use App\Policies\BookCopyPolicy;
use App\Policies\BookPolicy;
use App\Policies\CampusPolicy;
use App\Policies\CertificateReportPolicy;
use App\Policies\CircularPolicy;
use App\Policies\CollegePolicy;
use App\Policies\CommunicationDashboardPolicy;
use App\Policies\CommunicationLogPolicy;
use App\Policies\CommunicationNotificationPolicy;
use App\Policies\CommunicationReportPolicy;
use App\Policies\CommunicationTemplatePolicy;
use App\Policies\CommunicationTrackingPolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\DesignationPolicy;
use App\Policies\EmployeeDocumentPolicy;
use App\Policies\ExamAttendancePolicy;
use App\Policies\ExaminationPolicy;
use App\Policies\ExaminationReportPolicy;
use App\Policies\ExamMarkPolicy;
use App\Policies\ExamReportPolicy;
use App\Policies\ExamResultPolicy;
use App\Policies\ExamSchedulePolicy;
use App\Policies\FacultyPolicy;
use App\Policies\FacultySubjectAssignmentPolicy;
use App\Policies\FeeCategoryPolicy;
use App\Policies\FeeConcessionPolicy;
use App\Policies\FeeDuePolicy;
use App\Policies\FeePaymentPolicy;
use App\Policies\FeeReceiptPolicy;
use App\Policies\FeeRefundPolicy;
use App\Policies\FeeReportPolicy;
use App\Policies\FeeStructurePolicy;
use App\Policies\FinanceReportPolicy;
use App\Policies\GradeCardPolicy;
use App\Policies\GradeScalePolicy;
use App\Policies\HostelAllocationPolicy;
use App\Policies\HostelAttendancePolicy;
use App\Policies\HostelBedPolicy;
use App\Policies\HostelBuildingPolicy;
use App\Policies\HostelDashboardPolicy;
use App\Policies\HostelFeeAssignmentPolicy;
use App\Policies\HostelFeeStructurePolicy;
use App\Policies\HostelPolicy;
use App\Policies\HostelReportPolicy;
use App\Policies\HostelRoomPolicy;
use App\Policies\HrReportPolicy;
use App\Policies\InstitutionalSettingPolicy;
use App\Policies\InventoryAssignmentPolicy;
use App\Policies\InventoryCategoryPolicy;
use App\Policies\InventoryDashboardPolicy;
use App\Policies\InventoryIssuePolicy;
use App\Policies\InventoryItemPolicy;
use App\Policies\InventoryMaintenancePolicy;
use App\Policies\InventoryPurchaseOrderPolicy;
use App\Policies\InventoryStockMovementPolicy;
use App\Policies\InventoryVendorPolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\LeaveTypePolicy;
use App\Policies\LibraryDashboardPolicy;
use App\Policies\LibraryFinePolicy;
use App\Policies\LibraryMemberPolicy;
use App\Policies\LibraryRenewalPolicy;
use App\Policies\LibraryReportPolicy;
use App\Policies\LibraryTransactionPolicy;
use App\Policies\MarksheetPolicy;
use App\Policies\NoticePolicy;
use App\Policies\PayrollPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\ProgramPolicy;
use App\Policies\PublisherPolicy;
use App\Policies\RolePolicy;
use App\Policies\SalaryComponentPolicy;
use App\Policies\SalaryStructurePolicy;
use App\Policies\SectionPolicy;
use App\Policies\StaffAttendancePolicy;
use App\Policies\StudentAcademicRecordPolicy;
use App\Policies\StudentDocumentPolicy;
use App\Policies\StudentEnrollmentPolicy;
use App\Policies\StudentFeeAssignmentPolicy;
use App\Policies\StudentPolicy;
use App\Policies\StudentPromotionPolicy;
use App\Policies\StudentReportPolicy;
use App\Policies\StudentResultHistoryPolicy;
use App\Policies\StudentTransferPolicy;
use App\Policies\StudentTransportAssignmentPolicy;
use App\Policies\StudentTransportFeeAssignmentPolicy;
use App\Policies\SubjectPolicy;
use App\Policies\TransportDashboardPolicy;
use App\Policies\TransportDriverPolicy;
use App\Policies\TransportFeeStructurePolicy;
use App\Policies\TransportReportPolicy;
use App\Policies\TransportRoutePolicy;
use App\Policies\TransportStopPolicy;
use App\Policies\UserPolicy;
use App\Policies\VehicleDocumentPolicy;
use App\Policies\VehiclePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        // Administration reuses the existing shared identities and immutable audit log.
        User::class => UserPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
        // Inventory / Asset Management — Phase 1 (dashboard, categories, items/assets, vendors).
        InventoryDashboard::class => InventoryDashboardPolicy::class,
        InventoryCategory::class => InventoryCategoryPolicy::class,
        InventoryItem::class => InventoryItemPolicy::class,
        InventoryVendor::class => InventoryVendorPolicy::class,
        // Inventory / Asset Management — Phase 2 (purchase orders, stock movements).
        InventoryPurchaseOrder::class => InventoryPurchaseOrderPolicy::class,
        InventoryStockMovement::class => InventoryStockMovementPolicy::class,
        // Inventory / Asset Management — Phase 3 (issue, assignment + return, maintenance).
        InventoryIssue::class => InventoryIssuePolicy::class,
        InventoryAssignment::class => InventoryAssignmentPolicy::class,
        InventoryMaintenance::class => InventoryMaintenancePolicy::class,
        // Communication Management — Phase 1 (dashboard, notices, circulars, internal notifications).
        CommunicationDashboard::class => CommunicationDashboardPolicy::class,
        Notice::class => NoticePolicy::class,
        Circular::class => CircularPolicy::class,
        CommunicationNotification::class => CommunicationNotificationPolicy::class,
        // Communication Management — Phase 2 (templates, logs, delivery / read tracking, reports).
        CommunicationTemplate::class => CommunicationTemplatePolicy::class,
        CommunicationLog::class => CommunicationLogPolicy::class,
        CommunicationTracking::class => CommunicationTrackingPolicy::class,
        CommunicationReport::class => CommunicationReportPolicy::class,
        // Certificate Management — read-only Certificate Reports.
        CertificateReport::class => CertificateReportPolicy::class,
        // Hostel Management — Phase 1 masters, Phase 2 allocations/fees, Phase 3 attendance/reports.
        HostelDashboard::class => HostelDashboardPolicy::class,
        Hostel::class => HostelPolicy::class,
        HostelBuilding::class => HostelBuildingPolicy::class,
        HostelRoom::class => HostelRoomPolicy::class,
        HostelBed::class => HostelBedPolicy::class,
        HostelAllocation::class => HostelAllocationPolicy::class,
        HostelFeeStructure::class => HostelFeeStructurePolicy::class,
        HostelFeeAssignment::class => HostelFeeAssignmentPolicy::class,
        HostelAttendance::class => HostelAttendancePolicy::class,
        HostelReport::class => HostelReportPolicy::class,
        // Transport Phase 1.
        TransportDashboard::class => TransportDashboardPolicy::class,
        Vehicle::class => VehiclePolicy::class,
        TransportDriver::class => TransportDriverPolicy::class,
        TransportRoute::class => TransportRoutePolicy::class,
        TransportStop::class => TransportStopPolicy::class,
        // Transport Phase 2 — Vehicle Documents, Student Transport Assignment,
        // Transport Fees (structures + assignments) and Transport Reports.
        VehicleDocument::class => VehicleDocumentPolicy::class,
        StudentTransportAssignment::class => StudentTransportAssignmentPolicy::class,
        TransportFeeStructure::class => TransportFeeStructurePolicy::class,
        StudentTransportFeeAssignment::class => StudentTransportFeeAssignmentPolicy::class,
        TransportReport::class => TransportReportPolicy::class,
        StudentReport::class => StudentReportPolicy::class,
        AcademicReport::class => AcademicReportPolicy::class,
        ExaminationReport::class => ExaminationReportPolicy::class,
        FinanceReport::class => FinanceReportPolicy::class,
        AcademicSubjectEnrollment::class => AcademicSubjectEnrollmentPolicy::class, AcademicTimetable::class => AcademicTimetablePolicy::class, College::class => CollegePolicy::class, Campus::class => CampusPolicy::class, Department::class => DepartmentPolicy::class, AcademicYear::class => AcademicYearPolicy::class, AcademicTerm::class => AcademicTermPolicy::class, Program::class => ProgramPolicy::class, Section::class => SectionPolicy::class, Subject::class => SubjectPolicy::class, Faculty::class => FacultyPolicy::class, Employee::class => FacultyPolicy::class, FacultySubjectAssignment::class => FacultySubjectAssignmentPolicy::class, FeeCategory::class => FeeCategoryPolicy::class, FeeConcession::class => FeeConcessionPolicy::class, FeeDue::class => FeeDuePolicy::class, FeePayment::class => FeePaymentPolicy::class, FeeReceipt::class => FeeReceiptPolicy::class, FeeRefund::class => FeeRefundPolicy::class, FeeReport::class => FeeReportPolicy::class, FeeStructure::class => FeeStructurePolicy::class, StudentFeeAssignment::class => StudentFeeAssignmentPolicy::class, InstitutionalSetting::class => InstitutionalSettingPolicy::class, Role::class => RolePolicy::class, Permission::class => PermissionPolicy::class, AdmissionApplicant::class => AdmissionApplicantPolicy::class, AdmissionEnquiry::class => AdmissionEnquiryPolicy::class, AdmissionApplication::class => AdmissionApplicationPolicy::class, AdmissionDocumentType::class => AdmissionDocumentTypePolicy::class, AdmissionDocument::class => AdmissionDocumentPolicy::class, AdmissionMeritList::class => AdmissionMeritListPolicy::class, AdmissionMeritEntry::class => AdmissionMeritEntryPolicy::class, Admission::class => AdmissionPolicy::class, Student::class => StudentPolicy::class, StudentEnrollment::class => StudentEnrollmentPolicy::class, StudentAcademicRecord::class => StudentAcademicRecordPolicy::class, StudentDocument::class => StudentDocumentPolicy::class, StudentPromotion::class => StudentPromotionPolicy::class, StudentTransfer::class => StudentTransferPolicy::class, Examination::class => ExaminationPolicy::class, ExamSchedule::class => ExamSchedulePolicy::class, ExamAttendance::class => ExamAttendancePolicy::class, ExamMark::class => ExamMarkPolicy::class, ExamResult::class => ExamResultPolicy::class, ExamReport::class => ExamReportPolicy::class, GradeScale::class => GradeScalePolicy::class, Marksheet::class => MarksheetPolicy::class, GradeCard::class => GradeCardPolicy::class, StudentResultHistory::class => StudentResultHistoryPolicy::class, Designation::class => DesignationPolicy::class, EmployeeDocument::class => EmployeeDocumentPolicy::class, StaffAttendance::class => StaffAttendancePolicy::class, LeaveType::class => LeaveTypePolicy::class, LeaveRequest::class => LeaveRequestPolicy::class, SalaryStructure::class => SalaryStructurePolicy::class, SalaryComponent::class => SalaryComponentPolicy::class, Payroll::class => PayrollPolicy::class, HrReport::class => HrReportPolicy::class, LibraryDashboard::class => LibraryDashboardPolicy::class, Book::class => BookPolicy::class, BookCategory::class => BookCategoryPolicy::class, Author::class => AuthorPolicy::class, Publisher::class => PublisherPolicy::class, BookCopy::class => BookCopyPolicy::class, LibraryMember::class => LibraryMemberPolicy::class, LibraryTransaction::class => LibraryTransactionPolicy::class, LibraryRenewal::class => LibraryRenewalPolicy::class, LibraryFine::class => LibraryFinePolicy::class, LibraryReportController::class => LibraryReportPolicy::class];

    public function boot(): void {}
}
