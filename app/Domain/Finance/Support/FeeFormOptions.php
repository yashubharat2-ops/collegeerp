<?php

namespace App\Domain\Finance\Support;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeConcession;
use App\Models\FeePayment;
use App\Models\FeeRefund;
use App\Models\FeeStructure;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentFeeAssignment;

/**
 * FeeFormOptions — the select lists the Finance / Fees screens share.
 *
 * Every list is read through the tenant-scoped models (CollegeScope), so a
 * controller can only ever offer the active college's masters. Keeping the
 * option lists in one place stops the eight screens from drifting apart, and
 * keeps the controllers thin.
 */
final class FeeFormOptions
{
    /** @return \Illuminate\Database\Eloquent\Collection<int, AcademicYear> */
    public static function academicYears(): mixed
    {
        return AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, AcademicTerm> */
    public static function academicTerms(): mixed
    {
        return AcademicTerm::query()->orderBy('sequence')->orderBy('name')->get(['id', 'name', 'code', 'academic_year_id']);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Program> */
    public static function programs(): mixed
    {
        return Program::query()->orderBy('name')->get(['id', 'name', 'code']);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Student> */
    public static function students(): mixed
    {
        return Student::query()->orderBy('first_name')->orderBy('last_name')->limit(500)->get(['id', 'first_name', 'middle_name', 'last_name', 'student_number']);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, StudentEnrollment> */
    public static function enrollments(): mixed
    {
        return StudentEnrollment::query()
            ->with('student:id,first_name,middle_name,last_name,student_number')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'student_id', 'academic_year_id', 'program_id', 'enrollment_number', 'status']);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, FeeStructure> */
    public static function feeStructures(): mixed
    {
        return FeeStructure::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'academic_year_id', 'program_id', 'academic_term_id', 'status']);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, FeeCategory> */
    public static function feeCategories(): mixed
    {
        return FeeCategory::query()->orderBy('name')->get(['id', 'name', 'code', 'status']);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, StudentFeeAssignment> */
    public static function assignments(): mixed
    {
        return StudentFeeAssignment::query()
            ->with('studentEnrollment.student:id,first_name,middle_name,last_name,student_number')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'student_enrollment_id', 'fee_structure_id', 'assigned_amount', 'assigned_at', 'status']);
    }

    /**
     * @return array<int, string>
     */
    public static function paymentModes(): array
    {
        return FeePayment::MODES;
    }

    /**
     * Every payment mode already in use for the active college, so historical
     * data created with a custom mode stays filterable.
     *
     * @return array<int, string>
     */
    public static function usedPaymentModes(): array
    {
        $modes = FeePayment::query()
            ->orderBy('payment_mode')
            ->distinct()
            ->pluck('payment_mode')
            ->all();

        return array_values(array_unique(array_merge($modes, FeePayment::MODES)));
    }

    /**
     * @return array<int, string>
     */
    public static function paymentStatuses(): array
    {
        return FeePayment::STATUSES;
    }

    /**
     * @return array<int, string>
     */
    public static function assignmentStatuses(): array
    {
        return StudentFeeAssignment::STATUSES;
    }

    /**
     * @return array<int, string>
     */
    public static function concessionTypes(): array
    {
        return FeeConcession::TYPES;
    }

    /**
     * @return array<int, string>
     */
    public static function concessionStatuses(): array
    {
        return FeeConcession::STATUSES;
    }

    /**
     * @return array<int, string>
     */
    public static function refundStatuses(): array
    {
        return FeeRefund::STATUSES;
    }

    /**
     * The full option set the fee structure, assignment, collection, receipt,
     * dues, concession, refund and report screens share.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return [
            'academicYears' => self::academicYears(),
            'academicTerms' => self::academicTerms(),
            'programs' => self::programs(),
            'students' => self::students(),
            'enrollments' => self::enrollments(),
            'feeStructures' => self::feeStructures(),
            'feeCategories' => self::feeCategories(),
            'assignments' => self::assignments(),
            'paymentModes' => self::paymentModes(),
            'usedPaymentModes' => self::usedPaymentModes(),
            'paymentStatuses' => self::paymentStatuses(),
            'assignmentStatuses' => self::assignmentStatuses(),
            'concessionTypes' => self::concessionTypes(),
            'concessionStatuses' => self::concessionStatuses(),
            'refundStatuses' => self::refundStatuses(),
        ];
    }
}
