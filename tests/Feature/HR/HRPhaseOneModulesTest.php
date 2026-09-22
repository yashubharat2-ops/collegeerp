<?php

namespace Tests\Feature\HR;

use App\Models\{Faculty, LeaveRequest, LeaveType, Payroll, SalaryComponent, SalaryStructure, StaffAttendance};
use Tests\TestCase;

class HRPhaseOneModulesTest extends TestCase
{
    use HRTestHelpers;

    public function test_staff_attendance_is_tenant_scoped_correctable_and_duplicate_safe(): void
    {
        $college = $this->makeCollege('HRA');
        $otherCollege = $this->makeCollege('HRB');
        $admin = $this->makeUserWithPermissions($college, [
            'staff_attendance.view', 'staff_attendance.create', 'staff_attendance.update', 'staff_attendance.delete',
        ]);
        $otherAdmin = $this->makeUserWithPermissions($otherCollege, ['staff_attendance.view']);
        $employee = $this->employee($college, 'ATT-001');

        $this->asCollege($college, $admin)->post(route('staff-attendance.store'), [
            'faculty_id' => $employee->id,
            'attendance_date' => '2026-09-22',
            'status' => 'present',
            'remarks' => 'On time',
        ])->assertRedirect(route('staff-attendance.index'))->assertSessionHasNoErrors();

        $attendance = StaffAttendance::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $this->assertSame('present', $attendance->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff_attendance.created', 'subject_id' => $attendance->id]);

        $this->asCollege($college, $admin)->post(route('staff-attendance.store'), [
            'faculty_id' => $employee->id, 'attendance_date' => '2026-09-22', 'status' => 'late',
        ])->assertSessionHasErrors('attendance_date');

        $this->asCollege($college, $admin)->put(route('staff-attendance.update', $attendance), [
            'faculty_id' => $employee->id, 'attendance_date' => '2026-09-22', 'status' => 'late',
        ])->assertRedirect(route('staff-attendance.index'))->assertSessionHasNoErrors();
        $this->assertSame('late', $attendance->fresh()->status);

        $this->asCollege($otherCollege, $otherAdmin)->get(route('staff-attendance.index'))
            ->assertOk()->assertDontSee('ATT-001');
        $this->asCollege($college, $admin)->get(route('staff-attendance.edit', $attendance))->assertOk();
    }

    public function test_staff_attendance_rbac_blocks_mutation_without_create_permission(): void
    {
        $college = $this->makeCollege('HRATTACL');
        $viewer = $this->makeUserWithPermissions($college, ['staff_attendance.view']);
        $employee = $this->employee($college, 'ACL-001');

        $this->asCollege($college, $viewer)->post(route('staff-attendance.store'), [
            'faculty_id' => $employee->id, 'attendance_date' => '2026-09-22', 'status' => 'present',
        ])->assertForbidden();
    }

    public function test_leave_requests_validate_dates_and_prevent_approved_overlap(): void
    {
        $college = $this->makeCollege('HRLEAVE');
        $admin = $this->makeUserWithPermissions($college, [
            'leave_types.view', 'leave_types.create', 'leave_types.update', 'leave_types.delete',
            'leave_requests.view', 'leave_requests.create', 'leave_requests.update', 'leave_requests.delete', 'leave_requests.approve',
        ]);
        $employee = $this->employee($college, 'LV-001');
        $type = LeaveType::create(['college_id' => $college->id, 'name' => 'Casual Leave', 'code' => 'CL', 'status' => 'active']);

        $this->asCollege($college, $admin)->post(route('leave-requests.store'), [
            'faculty_id' => $employee->id, 'leave_type_id' => $type->id,
            'from_date' => '2026-09-10', 'to_date' => '2026-09-12', 'reason' => 'Personal work',
        ])->assertRedirect(route('leave-requests.index'))->assertSessionHasNoErrors();
        $request = LeaveRequest::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $this->assertSame(3, $request->days);

        $this->asCollege($college, $admin)->post(route('leave-requests.approve', $request), [])
            ->assertRedirect(route('leave-requests.index'))->assertSessionHasNoErrors();
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertNotNull($request->fresh()->approved_at);

        $this->asCollege($college, $admin)->post(route('leave-requests.store'), [
            'faculty_id' => $employee->id, 'leave_type_id' => $type->id,
            'from_date' => '2026-09-12', 'to_date' => '2026-09-14', 'reason' => 'Overlapping work',
        ])->assertSessionHasErrors('from_date');

        $this->asCollege($college, $admin)->post(route('leave-requests.store'), [
            'faculty_id' => $employee->id, 'leave_type_id' => $type->id,
            'from_date' => '2026-09-20', 'to_date' => '2026-09-19', 'reason' => 'Bad range',
        ])->assertSessionHasErrors('to_date');
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_request.approved', 'subject_id' => $request->id]);
    }

    public function test_payroll_calculates_monthly_money_and_rejects_duplicate_period(): void
    {
        $college = $this->makeCollege('HRPAY');
        $admin = $this->makeUserWithPermissions($college, ['payrolls.view', 'payrolls.process', 'payrolls.update']);
        $employee = $this->employee($college, 'PAY-001');
        $structure = SalaryStructure::create(['college_id' => $college->id, 'name' => 'Standard', 'code' => 'STD', 'status' => 'active']);
        SalaryComponent::create(['college_id' => $college->id, 'salary_structure_id' => $structure->id, 'name' => 'Basic', 'code' => 'BASIC', 'component_type' => 'earning', 'calculation_type' => 'fixed', 'value' => 1000, 'sort_order' => 1, 'status' => 'active']);
        SalaryComponent::create(['college_id' => $college->id, 'salary_structure_id' => $structure->id, 'name' => 'Housing', 'code' => 'HRA', 'component_type' => 'earning', 'calculation_type' => 'percentage', 'value' => 50, 'basis' => 'basic', 'sort_order' => 2, 'status' => 'active']);
        SalaryComponent::create(['college_id' => $college->id, 'salary_structure_id' => $structure->id, 'name' => 'Tax', 'code' => 'TAX', 'component_type' => 'deduction', 'calculation_type' => 'percentage', 'value' => 10, 'basis' => 'gross', 'sort_order' => 3, 'status' => 'active']);

        $payload = ['faculty_id' => $employee->id, 'salary_structure_id' => $structure->id, 'pay_period' => '2026-09'];
        $this->asCollege($college, $admin)->post(route('payrolls.store'), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $payroll = Payroll::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $this->assertSame('1000.00', (string) $payroll->basic_amount);
        $this->assertSame('1500.00', (string) $payroll->gross_amount);
        $this->assertSame('150.00', (string) $payroll->total_deductions);
        $this->assertSame('1350.00', (string) $payroll->net_amount);
        $this->assertCount(3, $payroll->items);

        $this->asCollege($college, $admin)->post(route('payrolls.store'), $payload)
            ->assertSessionHasErrors('pay_period');
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.processed', 'subject_id' => $payroll->id]);
    }

    public function test_hr_reports_are_live_filtered_read_only_summaries(): void
    {
        $college = $this->makeCollege('HRREPORT');
        $admin = $this->makeUserWithPermissions($college, ['hr_reports.view']);
        $employee = $this->employee($college, 'REP-001');
        StaffAttendance::create(['college_id' => $college->id, 'faculty_id' => $employee->id, 'attendance_date' => '2026-09-22', 'status' => 'present']);
        StaffAttendance::create(['college_id' => $college->id, 'faculty_id' => $employee->id, 'attendance_date' => '2026-09-23', 'status' => 'late']);

        $html = $this->asCollege($college, $admin)->get(route('hr-reports.index', [
            'report' => 'attendance', 'faculty_id' => $employee->id, 'from' => '2026-09-22', 'to' => '2026-09-22',
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('>Present</td>', $html);
        $this->assertStringNotContainsString('>Late</td>', $html);
        $this->assertStringNotContainsString('Create', $html);
    }

    private function employee($college, string $code): Faculty
    {
        return Faculty::create([
            'college_id' => $college->id,
            'employee_code' => $code,
            'first_name' => 'HR',
            'last_name' => 'Employee',
            'email' => strtolower($code).'@example.test',
            'status' => 'active',
        ]);
    }
}
