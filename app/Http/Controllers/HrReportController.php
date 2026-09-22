<?php

namespace App\Http\Controllers;

use App\Domain\HR\Services\HrReportService;
use App\Models\Faculty;
use App\Models\HrReport;
use App\Models\LeaveRequest;
use App\Models\StaffAttendance;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HrReportController extends Controller
{
    public function index(Request $request, HrReportService $reports): View
    {
        $this->authorize('viewAny', HrReport::class);
        $report = in_array($request->input('report'), ['attendance', 'leave', 'payroll'], true) ? $request->input('report') : 'attendance';
        $filters = $request->validate([
            'faculty_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        if ($report === 'payroll') {
            $filters = $request->validate([
                'faculty_id' => ['nullable', 'integer'],
                'from' => ['nullable', 'date_format:Y-m'],
                'to' => ['nullable', 'date_format:Y-m', 'after_or_equal:from'],
            ]);
        }

        $rows = match ($report) {
            'leave' => $reports->leaveSummary($filters),
            'payroll' => $reports->payrollSummary($filters),
            default => $reports->attendanceSummary($filters),
        };

        return view('hr_reports.index', [
            'report' => $report,
            'rows' => $rows,
            'employees' => Faculty::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']),
            'attendanceStatuses' => StaffAttendance::STATUSES,
            'leaveStatuses' => LeaveRequest::STATUSES,
            'filters' => $filters,
        ]);
    }
}
