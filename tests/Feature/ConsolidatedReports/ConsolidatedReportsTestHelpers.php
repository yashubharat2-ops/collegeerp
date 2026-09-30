<?php

namespace Tests\Feature\ConsolidatedReports;

use App\Models\AcademicAttendance;
use App\Models\AcademicSubjectEnrollment;
use App\Models\Book;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Models\Certificate;
use App\Models\CertificateType;
use App\Models\College;
use App\Models\CommunicationLog;
use App\Models\Department;
use App\Models\ExamAttendance;
use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\ExamSchedule;
use App\Models\Examination;
use App\Models\Faculty;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\GradeScale;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelBuilding;
use App\Models\HostelRoom;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\LibraryMember;
use App\Models\LibraryTransaction;
use App\Models\Notice;
use App\Models\StudentFeeAssignment;
use App\Models\StudentTransportAssignment;
use App\Models\Subject;
use App\Models\TransportDriver;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\Vehicle;
use App\Services\Certificates\CertificateCatalog;
use App\Services\Certificates\CertificateWorkflow;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Fixtures for the Consolidated Reports tests.
 *
 * The Consolidated Reports module owns no table of its own, so every fixture
 * here is a record of an EXISTING module (Student, Academic, Examination,
 * Finance, HR, Library, Transport, Hostel, Inventory, Communication,
 * Certificate). Only the one shared college world is built; each test then
 * asserts the summary that the owning module's report service produces.
 *
 * The project-wide fixtures (college, users, academic year / term / program /
 * section / student / enrollment) come from StudentTestHelpers.
 */
trait ConsolidatedReportsTestHelpers
{
    /**
     * One complete college world. Every module summarized by the consolidated
     * reports has at least one record, so a report that reads its module's data
     * shows a non-zero figure for this college and zero for any other.
     *
     * @return array<string, mixed>
     */
    private function makeConsolidatedWorld(College $college, string $prefix): array
    {
        /* ---------------------------------------------------------------- *
         * Academic scope (Platform masters) + a section with capacity
         * ---------------------------------------------------------------- */
        $year = $this->makeYear($college, 'AY-'.$prefix, '2026 '.$prefix, '2026-06-01', '2027-05-31');
        $term = $this->makeAcademicTerm($college, $year, 'T-'.$prefix, 'Term '.$prefix, 1);
        $program = $this->makeProgram($college, 'P-'.$prefix);

        $department = Department::create([
            'college_id' => $college->id,
            'name' => 'Department '.$prefix,
            'code' => 'DEPT-'.$prefix,
            'status' => 'active',
        ]);
        $program->forceFill(['department_id' => $department->id])->save();

        $section = $this->makeSection($college, $year, $program, 'SEC-'.$prefix);
        $section->forceFill(['capacity' => 60])->save();

        /* ---------------------------------------------------------------- *
         * Students, enrollments and the student strength figures
         * ---------------------------------------------------------------- */
        $studentA = $this->makeStudent($college, ['first_name' => 'Asha', 'last_name' => $prefix]);
        $studentB = $this->makeStudent($college, ['first_name' => 'Bhav', 'last_name' => $prefix]);

        $enrollmentA = $this->makeEnrollment($college, $studentA, $year, $program, ['section_id' => $section->id]);
        $enrollmentB = $this->makeEnrollment($college, $studentB, $year, $program, ['section_id' => $section->id]);

        /* ---------------------------------------------------------------- *
         * Examination with one present candidate and one published pass
         * ---------------------------------------------------------------- */
        $subject = Subject::create([
            'college_id' => $college->id,
            'name' => 'Subject '.$prefix,
            'code' => 'SUB-'.$prefix,
            'status' => 'active',
        ]);

        $examination = Examination::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'name' => 'Examination '.$prefix,
            'code' => 'EXAM-'.$prefix,
            'exam_type' => 'Internal',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-10',
            'status' => 'published',
        ]);

        $schedule = ExamSchedule::create([
            'college_id' => $college->id,
            'examination_id' => $examination->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'program_id' => $program->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'exam_date' => '2026-10-02',
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'max_marks' => 100,
            'passing_marks' => 40,
            'status' => ExamSchedule::STATUS_COMPLETED,
        ]);

        ExamAttendance::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $schedule->id,
            'student_enrollment_id' => $enrollmentA->id,
            'attendance_status' => ExamAttendance::STATUS_PRESENT,
            'marked_at' => now(),
        ]);

        ExamMark::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $schedule->id,
            'student_enrollment_id' => $enrollmentA->id,
            'max_marks' => 100,
            'passing_marks' => 40,
            'obtained_marks' => 75,
            'status' => ExamMark::STATUS_ENTERED,
            'entered_at' => now(),
        ]);

        $gradeScale = GradeScale::create([
            'college_id' => $college->id,
            'name' => 'Scale '.$prefix,
            'code' => 'GS-'.$prefix,
            'status' => GradeScale::STATUS_ACTIVE,
        ]);

        $result = ExamResult::create([
            'college_id' => $college->id,
            'examination_id' => $examination->id,
            'student_enrollment_id' => $enrollmentA->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'grade_scale_id' => $gradeScale->id,
            'total_max_marks' => 100,
            'total_obtained_marks' => 75,
            'percentage' => 75,
            'overall_grade' => 'B',
            'result_status' => ExamResult::RESULT_PASS,
            'calculation_status' => ExamResult::CALCULATION_CALCULATED,
            'calculated_at' => now(),
            'published_at' => now(),
        ]);

        /* ---------------------------------------------------------------- *
         * Finance: one 25,000 assignment with a 10,000 collection
         * ---------------------------------------------------------------- */
        $feeStructure = FeeStructure::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'program_id' => $program->id,
            'name' => 'Fee Plan '.$prefix,
            'code' => 'FEE-'.$prefix,
            'status' => 'active',
        ]);

        $feeAssignment = StudentFeeAssignment::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $enrollmentA->id,
            'fee_structure_id' => $feeStructure->id,
            'assigned_amount' => 25000,
            'assigned_at' => '2026-08-10',
            'status' => StudentFeeAssignment::STATUS_ACTIVE,
        ]);

        $payment = FeePayment::create([
            'college_id' => $college->id,
            'student_fee_assignment_id' => $feeAssignment->id,
            'student_enrollment_id' => $enrollmentA->id,
            'fee_structure_id' => $feeStructure->id,
            'payment_number' => 'PAY-'.$prefix,
            'payment_date' => '2026-08-15',
            'payment_mode' => FeePayment::MODE_CASH,
            'amount' => 10000,
            'status' => FeePayment::STATUS_COMPLETED,
        ]);

        /* ---------------------------------------------------------------- *
         * Library: one title, one copy on loan to one member
         * ---------------------------------------------------------------- */
        $bookCategory = BookCategory::create([
            'college_id' => $college->id,
            'name' => 'Category '.$prefix,
            'code' => 'BCAT-'.$prefix,
            'description' => 'Consolidated fixture category',
            'status' => BookCategory::STATUS_ACTIVE,
        ]);

        $book = Book::create([
            'college_id' => $college->id,
            'book_category_id' => $bookCategory->id,
            'title' => 'Book '.$prefix,
            'code' => 'BOOK-'.$prefix,
            'status' => Book::STATUS_ACTIVE,
        ]);

        $copy = BookCopy::create([
            'college_id' => $college->id,
            'book_id' => $book->id,
            'accession_number' => 'ACC-'.$prefix,
            'copy_number' => 1,
            'condition' => BookCopy::CONDITION_GOOD,
            'status' => BookCopy::STATUS_ISSUED,
        ]);

        $member = LibraryMember::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $enrollmentB->id,
            'member_code' => 'LM-'.$prefix,
            'membership_date' => '2026-08-01',
            'status' => LibraryMember::STATUS_ACTIVE,
        ]);

        $transaction = LibraryTransaction::create([
            'college_id' => $college->id,
            'book_copy_id' => $copy->id,
            'library_member_id' => $member->id,
            'issued_on' => now()->subDays(3)->toDateString(),
            'due_on' => now()->addDays(11)->toDateString(),
            'status' => LibraryTransaction::STATUS_ISSUED,
        ]);

        /* ---------------------------------------------------------------- *
         * HR staff member (also the transport driver)
         * ---------------------------------------------------------------- */
        $faculty = Faculty::create([
            'college_id' => $college->id,
            'employee_code' => 'EMP-'.$prefix,
            'first_name' => 'Staff',
            'last_name' => $prefix,
            'email' => strtolower($prefix).'-staff@example.test',
            'status' => 'active',
        ]);

        /* ---------------------------------------------------------------- *
         * Academics: one subject enrollment and one present attendance row
         * ---------------------------------------------------------------- */
        $subjectEnrollment = AcademicSubjectEnrollment::create([
            'college_id' => $college->id,
            'student_id' => $studentA->id,
            'student_enrollment_id' => $enrollmentA->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'program_id' => $program->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'status' => 'active',
            'enrollment_date' => '2026-08-01',
        ]);

        $academicAttendance = AcademicAttendance::create([
            'college_id' => $college->id,
            'student_id' => $studentA->id,
            'student_enrollment_id' => $enrollmentA->id,
            'student_subject_enrollment_id' => $subjectEnrollment->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'attendance_date' => '2026-09-10',
            'status' => 'present',
        ]);

        /* ---------------------------------------------------------------- *
         * Transport: a vehicle on an active route, a stop and an assignment
         * ---------------------------------------------------------------- */
        $vehicle = new Vehicle([
            'registration_number' => 'KA-01-'.$prefix,
            'seating_capacity' => 40,
            'status' => 'active',
        ]);
        $vehicle->college_id = $college->id;
        $vehicle->save();

        $route = new TransportRoute([
            'name' => 'Route '.$prefix,
            'code' => 'RT-'.$prefix,
            'status' => 'active',
        ]);
        $route->college_id = $college->id;
        $route->save();

        $stop = new TransportStop([
            'name' => 'Stop '.$prefix,
            'code' => 'ST-'.$prefix,
            'sequence' => 1,
            'pickup_time' => '08:15',
            'drop_time' => '17:30',
            'status' => 'active',
        ]);
        $stop->route_id = $route->id;
        $stop->college_id = $college->id;
        $stop->save();

        $driver = new TransportDriver([
            'faculty_id' => $faculty->id,
            'license_number' => 'DL-'.$prefix,
            'license_type' => 'Heavy passenger',
            'license_expiry' => '2028-12-31',
            'joining_date' => '2026-01-01',
            'status' => 'active',
        ]);
        $driver->college_id = $college->id;
        $driver->save();

        $transportAssignment = StudentTransportAssignment::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $enrollmentA->id,
            'academic_year_id' => $year->id,
            'transport_route_id' => $route->id,
            'transport_stop_id' => $stop->id,
            'start_date' => '2026-09-01',
            'status' => StudentTransportAssignment::STATUS_ACTIVE,
        ]);

        /* ---------------------------------------------------------------- *
         * Hostel: one occupied bed with an active allocation
         * ---------------------------------------------------------------- */
        $hostel = Hostel::create([
            'college_id' => $college->id,
            'name' => 'Hostel '.$prefix,
            'code' => 'H-'.$prefix,
            'hostel_type' => 'mixed',
            'gender' => 'any',
            'status' => Hostel::STATUS_ACTIVE,
        ]);

        $building = HostelBuilding::create([
            'college_id' => $college->id,
            'hostel_id' => $hostel->id,
            'name' => 'Block '.$prefix,
            'code' => 'B-'.$prefix,
            'floors' => 2,
            'status' => HostelBuilding::STATUS_ACTIVE,
        ]);

        $room = HostelRoom::create([
            'college_id' => $college->id,
            'hostel_id' => $hostel->id,
            'building_id' => $building->id,
            'room_number' => '101-'.$prefix,
            'floor' => 1,
            'room_type' => 'Double',
            'capacity' => 2,
            'status' => HostelRoom::STATUS_ACTIVE,
        ]);

        $bed = HostelBed::create([
            'college_id' => $college->id,
            'hostel_id' => $hostel->id,
            'building_id' => $building->id,
            'room_id' => $room->id,
            'bed_number' => 1,
            'status' => HostelBed::STATUS_OCCUPIED,
        ]);

        $allocation = HostelAllocation::create([
            'college_id' => $college->id,
            'student_enrollment_id' => $enrollmentA->id,
            'academic_year_id' => $year->id,
            'hostel_id' => $hostel->id,
            'hostel_building_id' => $building->id,
            'hostel_room_id' => $room->id,
            'hostel_bed_id' => $bed->id,
            'allocation_date' => '2026-08-06',
            'status' => HostelAllocation::STATUS_ACTIVE,
        ]);

        /* ---------------------------------------------------------------- *
         * Inventory: one consumable (below the low-stock threshold) + one asset
         * ---------------------------------------------------------------- */
        $inventoryCategory = InventoryCategory::create([
            'college_id' => $college->id,
            'name' => 'Inventory Category '.$prefix,
            'code' => 'ICAT-'.$prefix,
            'description' => 'Consolidated fixture category',
            'status' => InventoryCategory::STATUS_ACTIVE,
        ]);

        $consumable = InventoryItem::create([
            'college_id' => $college->id,
            'category_id' => $inventoryCategory->id,
            'name' => 'Consumable '.$prefix,
            'code' => 'ICON-'.$prefix,
            'item_type' => InventoryItem::TYPE_CONSUMABLE,
            'unit' => 'pcs',
            'quantity' => '1.00',
            'status' => InventoryItem::STATUS_ACTIVE,
        ]);

        $asset = InventoryItem::create([
            'college_id' => $college->id,
            'category_id' => $inventoryCategory->id,
            'name' => 'Asset '.$prefix,
            'code' => 'IASS-'.$prefix,
            'item_type' => InventoryItem::TYPE_ASSET,
            'unit' => 'pcs',
            'quantity' => '1.00',
            'status' => InventoryItem::STATUS_ACTIVE,
        ]);

        /* ---------------------------------------------------------------- *
         * Communication: one live notice and one delivered e-mail log
         * ---------------------------------------------------------------- */
        $notice = Notice::create([
            'college_id' => $college->id,
            'title' => 'Notice '.$prefix,
            'slug' => 'notice-'.Str::lower($prefix).'-'.Str::lower(Str::random(6)),
            'notice_type' => 'general',
            'content' => 'Consolidated fixture notice.',
            'publish_at' => now()->subDay(),
            'expires_at' => null,
            'status' => 'published',
            'priority' => 'normal',
            'target_type' => 'all',
            'target_id' => null,
        ]);

        $log = CommunicationLog::create([
            'college_id' => $college->id,
            'channel' => 'email',
            'recipient' => Str::lower($prefix).'@example.test',
            'subject' => 'Consolidated fixture',
            'content' => 'Consolidated fixture message.',
            'status' => 'delivered',
            'sent_at' => now(),
            'delivered_at' => now(),
        ]);

        /* ---------------------------------------------------------------- *
         * Certificate: one request for the first non-transfer type
         * ---------------------------------------------------------------- */
        [$certificateType, $certificate] = $this->withTenant($college, function () use ($college, $enrollmentA): array {
            app(CertificateCatalog::class)->provision($college->id);

            $type = CertificateType::query()
                ->where('builtin_key', '!=', 'transfer')
                ->orderBy('id')
                ->firstOrFail();

            $certificate = app(CertificateWorkflow::class)->request([
                'certificate_type_id' => $type->id,
                'student_enrollment_id' => $enrollmentA->id,
                'purpose' => 'Consolidated fixture',
            ]);

            return [$type, $certificate];
        });

        return [
            'year' => $year,
            'term' => $term,
            'program' => $program,
            'department' => $department,
            'section' => $section,
            'studentA' => $studentA,
            'studentB' => $studentB,
            'enrollmentA' => $enrollmentA->refresh(),
            'enrollmentB' => $enrollmentB->refresh(),
            'subject' => $subject,
            'subjectEnrollment' => $subjectEnrollment,
            'academicAttendance' => $academicAttendance,
            'examination' => $examination,
            'schedule' => $schedule,
            'result' => $result,
            'feeStructure' => $feeStructure,
            'feeAssignment' => $feeAssignment,
            'payment' => $payment,
            'bookCategory' => $bookCategory,
            'book' => $book,
            'copy' => $copy,
            'member' => $member,
            'transaction' => $transaction,
            'faculty' => $faculty,
            'vehicle' => $vehicle,
            'route' => $route,
            'stop' => $stop,
            'driver' => $driver,
            'transportAssignment' => $transportAssignment,
            'hostel' => $hostel,
            'building' => $building,
            'room' => $room,
            'bed' => $bed,
            'allocation' => $allocation,
            'inventoryCategory' => $inventoryCategory,
            'consumable' => $consumable,
            'asset' => $asset,
            'notice' => $notice,
            'log' => $log,
            'certificateType' => $certificateType,
            'certificate' => $certificate,
        ];
    }

    /**
     * Run a callback with the tenant context bound to $college (models carrying
     * CollegeScope resolve to `whereRaw('1 = 0')` without an active tenant).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(College $college, callable $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->set($college);

        try {
            return $callback();
        } finally {
            $context->clear();
        }
    }

    /**
     * The headline metric of one dashboard / management group.
     *
     * @param  \Illuminate\Testing\TestResponse  $page
     */
    private function headlineMetric($page, string $groupKey, string $label): mixed
    {
        foreach ($page->viewData('summary')['groups'] as $group) {
            if ($group['key'] !== $groupKey) {
                continue;
            }
            foreach ($group['metrics'] as $metric) {
                if ($metric['label'] === $label) {
                    return $metric['value'];
                }
            }
        }

        return null;
    }
}
