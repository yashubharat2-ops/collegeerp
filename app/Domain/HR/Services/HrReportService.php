<?php

namespace App\Domain\HR\Services;

use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\StaffAttendance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only live aggregations; HR reports do not create report tables. */
class HrReportService
{
    public function attendanceSummary(array $filters): array
    {
        $query = StaffAttendance::query()
            ->select('faculty_id', 'status', DB::raw('COUNT(*) as total'))
            ->with('employee')
            ->groupBy('faculty_id', 'status')
            ->orderBy('faculty_id');
        $this->attendanceFilters($query, $filters);

        $rows = [];
        foreach ($query->get() as $row) {
            $rows[] = [
                'employee' => $row->employee,
                'status' => $row->status,
                'total' => (int) $row->total,
            ];
        }

        return $rows;
    }

    public function leaveSummary(array $filters): array
    {
        $query = LeaveRequest::query()
            ->select('faculty_id', 'status', DB::raw('SUM(days) as total_days'), DB::raw('COUNT(*) as requests'))
            ->with('employee')
            ->groupBy('faculty_id', 'status')
            ->orderBy('faculty_id');
        $this->leaveFilters($query, $filters);

        return $query->get()->map(fn ($row) => [
            'employee' => $row->employee,
            'status' => $row->status,
            'total_days' => (int) $row->total_days,
            'requests' => (int) $row->requests,
        ])->all();
    }

    public function payrollSummary(array $filters): array
    {
        $query = Payroll::query()
            ->where('status', 'processed')
            ->select('faculty_id', DB::raw('COUNT(*) as payrolls'), DB::raw('COALESCE(SUM(gross_amount), 0) as gross_amount'), DB::raw('COALESCE(SUM(total_deductions), 0) as total_deductions'), DB::raw('COALESCE(SUM(net_amount), 0) as net_amount'))
            ->with('employee')
            ->groupBy('faculty_id')
            ->orderBy('faculty_id');
        $this->payrollFilters($query, $filters);

        return $query->get()->map(fn ($row) => [
            'employee' => $row->employee,
            'payrolls' => (int) $row->payrolls,
            'gross_amount' => round((float) $row->gross_amount, 2),
            'total_deductions' => round((float) $row->total_deductions, 2),
            'net_amount' => round((float) $row->net_amount, 2),
        ])->all();
    }

    private function attendanceFilters(Builder $query, array $filters): void
    {
        $query->when($filters['faculty_id'] ?? null, fn ($q, $value) => $q->where('faculty_id', $value))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->whereDate('attendance_date', '>=', $value))
            ->when($filters['to'] ?? null, fn ($q, $value) => $q->whereDate('attendance_date', '<=', $value));
    }

    private function leaveFilters(Builder $query, array $filters): void
    {
        $query->when($filters['faculty_id'] ?? null, fn ($q, $value) => $q->where('faculty_id', $value))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->whereDate('from_date', '<=', $filters['to'] ?? $value)->whereDate('to_date', '>=', $value))
            ->when(! ($filters['from'] ?? null) && ($filters['to'] ?? null), fn ($q) => $q->whereDate('from_date', '<=', $filters['to']));
    }

    private function payrollFilters(Builder $query, array $filters): void
    {
        $query->when($filters['faculty_id'] ?? null, fn ($q, $value) => $q->where('faculty_id', $value))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->whereDate('pay_period', '>=', $value.'-01'))
            ->when($filters['to'] ?? null, fn ($q, $value) => $q->whereDate('pay_period', '<=', $value.'-01'));
    }
}
