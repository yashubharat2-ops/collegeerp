<?php

namespace Tests\Feature\BulkAction;

use App\Models\College;
use App\Models\Designation;
use App\Models\Department;
use App\Models\EmployeeDocument;
use App\Models\Faculty;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\StaffAttendance;
use App\Support\BulkAction\BulkActionRegistry;
use App\Support\BulkAction\BulkExportHandler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\HR\HRTestHelpers;
use Tests\TestCase;

/**
 * HR bulk actions — selection, export authorization, policy enforcement, tenant
 * isolation and the export-only guarantee.
 *
 * The HR family is deliberately EXPORT-ONLY, and these tests pin it down: every
 * HR module registers exactly one action (`export`), the CSV endpoint re-queries
 * the ticked ids inside the active college and re-authorizes the module and the
 * record, a hand-edited URL cannot widen a download, and nothing bulk-mutates a
 * payroll, a salary figure, an attendance day, a leave decision or an employee's
 * status.
 *
 * The employee-document CSV is metadata only: the private server path
 * (`file_path`) must never appear in a download — the file itself is still
 * served exclusively by the authorized download route.
 */
class HrBulkActionTest extends TestCase
{
    use HRTestHelpers;

    /**
     * Every HR module, with the model its export handler operates on and the
     * permission that gates it.
     *
     * @return array<string, array{model: class-string, permission: string}>
     */
    private function hrModules(): array
    {
        return [
            'staff_employees' => ['model' => Faculty::class, 'permission' => 'faculties.view'],
            'staff_departments' => ['model' => Department::class, 'permission' => 'departments.view'],
            'designations' => ['model' => Designation::class, 'permission' => 'designations.view'],
            'employee_documents' => ['model' => EmployeeDocument::class, 'permission' => 'employee_documents.view'],
            'staff_attendance' => ['model' => StaffAttendance::class, 'permission' => 'staff_attendance.view'],
            'leave_requests' => ['model' => LeaveRequest::class, 'permission' => 'leave_requests.view'],
            'leave_types' => ['model' => LeaveType::class, 'permission' => 'leave_types.view'],
            'salary_structures' => ['model' => SalaryStructure::class, 'permission' => 'salary_structures.view'],
            'salary_components' => ['model' => SalaryComponent::class, 'permission' => 'salary_components.view'],
            'payrolls' => ['model' => Payroll::class, 'permission' => 'payrolls.view'],
        ];
    }

    private function makeEmployee(College $college, string $code): Faculty
    {
        return Faculty::create([
            'college_id' => $college->id,
            'employee_code' => $code,
            'first_name' => 'Bulk',
            'last_name' => $code,
            'status' => 'active',
        ]);
    }

    public function test_every_hr_bulk_module_registers_export_only(): void
    {
        $registry = app(BulkActionRegistry::class);

        foreach ($this->hrModules() as $module => $spec) {
            $this->assertSame(
                ['export'],
                array_keys($registry->getForModule($module)),
                "HR module [{$module}] must expose the export action and nothing else."
            );

            $handler = $registry->get($module, 'export');

            $this->assertInstanceOf(BulkExportHandler::class, $handler, $module);
            $this->assertSame($spec['model'], $handler->modelClass(), $module);
            $this->assertSame($spec['permission'], $handler->requiredPermission(), $module);
            $this->assertSame('view', $handler->policyAbility(), $module);
        }
    }

    public function test_staff_export_re_queries_ids_inside_the_active_college(): void
    {
        $collegeA = $this->makeCollege('HRBULKA');
        $collegeB = $this->makeCollege('HRBULKB');
        $admin = $this->makeUserWithPermissions($collegeA, ['faculties.view']);

        $mine = $this->makeEmployee($collegeA, 'EMP-A-100');
        $foreign = $this->makeEmployee($collegeB, 'EMP-B-200');

        $response = $this->asCollege($collegeA, $admin)->postJson(route('bulk-actions.execute'), [
            'module' => 'staff_employees',
            'action' => 'export',
            'ids' => [$mine->id, $foreign->id],
        ])->assertOk();

        $this->assertSame([$mine->id], $response->json('data.ids'));

        $redirect = $response->json('data.redirect');
        $this->assertIsString($redirect, 'The bulk endpoint must hand back a redirect to the CSV endpoint.');

        $csv = $this->asCollege($collegeA, $admin)->get($redirect)->assertOk();
        $this->assertStringContainsString('employees-export-', (string) $csv->headers->get('Content-Disposition'));

        $body = $csv->streamedContent();
        $this->assertStringContainsString('EMP-A-100', $body);
        $this->assertStringNotContainsString('EMP-B-200', $body);
    }

    public function test_hr_export_requires_the_module_permission(): void
    {
        $college = $this->makeCollege('HRPERM');
        $outsider = $this->makeUserWithPermissions($college, []);
        $designation = Designation::create([
            'college_id' => $college->id,
            'name' => 'Lecturer',
            'code' => 'DES-PERM',
            'status' => 'active',
        ]);

        $this->asCollege($college, $outsider)->postJson(route('bulk-actions.execute'), [
            'module' => 'designations',
            'action' => 'export',
            'ids' => [$designation->id],
        ])->assertForbidden();

        $this->asCollege($college, $outsider)
            ->get(route('designations.export', ['ids' => [$designation->id]]))
            ->assertForbidden();
    }

    public function test_hand_edited_export_url_cannot_widen_the_download(): void
    {
        $collegeA = $this->makeCollege('HRURLA');
        $collegeB = $this->makeCollege('HRURLB');
        $admin = $this->makeUserWithPermissions($collegeA, ['faculties.view']);

        $this->makeEmployee($collegeA, 'EMP-URL-A');
        $foreign = $this->makeEmployee($collegeB, 'EMP-URL-B');

        $csv = $this->asCollege($collegeA, $admin)
            ->get(route('employees.export', ['ids' => [$foreign->id, 999999]]));

        $csv->assertOk();

        $body = $csv->streamedContent();
        $this->assertStringNotContainsString('EMP-URL-B', $body);
        $this->assertSame(1, substr_count(trim($body), "\n") + 1, 'Only the header row may be exported.');
    }

    public function test_employee_document_export_carries_metadata_but_never_the_private_path(): void
    {
        Storage::fake('private');

        $college = $this->makeCollege('HRDOCBULK');
        $admin = $this->makeUserWithPermissions($college, [
            'faculties.view',
            'employee_documents.view',
            'employee_documents.create',
        ]);
        $employee = $this->makeEmployee($college, 'EMP-DOC-BULK');

        $this->asCollege($college, $admin)
            ->post(route('employee-documents.store'), [
                'employee_id' => $employee->id,
                'document_name' => 'Employment Contract',
                'document_type' => 'Contract',
                'issue_date' => '2026-01-01',
                'expiry_date' => '2027-01-01',
                'remarks' => 'Identity number 1234-5678 typed into a free-text field',
                'file' => UploadedFile::fake()->create('contract.pdf', 12, 'application/pdf'),
            ])
            ->assertRedirect(route('employee-documents.index'))
            ->assertSessionHasNoErrors();

        $document = EmployeeDocument::withoutGlobalScopes()->where('faculty_id', $employee->id)->firstOrFail();
        $this->assertStringStartsWith('employee-documents/'.$college->id.'/', $document->file_path);

        $response = $this->asCollege($college, $admin)->postJson(route('bulk-actions.execute'), [
            'module' => 'employee_documents',
            'action' => 'export',
            'ids' => [$document->id],
        ])->assertOk();

        $redirect = $response->json('data.redirect');
        $this->assertIsString($redirect, 'The bulk endpoint must hand back a redirect to the CSV endpoint.');

        $csv = $this->asCollege($college, $admin)->get($redirect)->assertOk();
        $this->assertStringContainsString('employee-documents-export-', (string) $csv->headers->get('Content-Disposition'));

        $body = $csv->streamedContent();

        // The shared CsvStreamExport leads every download with a UTF-8 BOM for
        // Excel — the project-wide convention StudentExportTest and
        // CsvStreamExportTest pin — and fputcsv quotes every field containing a
        // space (Excel-safe, not a defect). The header is therefore validated as
        // PARSED fields, with the BOM removed before str_getcsv() so it can never
        // end up inside the first field.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $lines = array_values(array_filter(explode("\n", trim(str_replace("\xEF\xBB\xBF", '', $body)))));

        // Metadata the listing shows: name, employee, type, dates, file name, size.
        $this->assertStringContainsString('Employment Contract', $body);
        $this->assertStringContainsString('contract.pdf', $body);
        $this->assertStringContainsString('EMP-DOC-BULK', $body);
        $this->assertSame(
            ['Document', 'Employee', 'Employee code', 'Type', 'Issue date', 'Expiry date', 'Validity', 'File', 'Size (KB)'],
            str_getcsv(rtrim($lines[0], "\r"), ',', '"', '\\')
        );

        // ...and never the private path, the stored file name or free-text remarks.
        $this->assertStringNotContainsString($document->file_path, $body);
        $this->assertStringNotContainsString('employee-documents/'.$college->id().'/', $body);
        $this->assertStringNotContainsString('Identity number', $body);
    }

    public function test_hr_exports_never_mutate_attendance_leave_or_payroll(): void
    {
        $college = $this->makeCollege('HRNOMUT');
        $admin = $this->makeUserWithPermissions($college, [
            'faculties.view',
            'staff_attendance.view',
            'leave_requests.view',
            'payrolls.view',
            'salary_structures.view',
        ]);
        $employee = $this->makeEmployee($college, 'EMP-NOMUT');

        $attendance = StaffAttendance::create([
            'college_id' => $college->id,
            'faculty_id' => $employee->id,
            'attendance_date' => '2026-08-10',
            'status' => 'present',
            'remarks' => 'On time',
        ]);
        $leaveType = LeaveType::create([
            'college_id' => $college->id,
            'name' => 'Casual Leave',
            'code' => 'CL-BULK',
            'max_days_per_year' => 12,
            'status' => 'active',
        ]);
        $leave = LeaveRequest::create([
            'college_id' => $college->id,
            'faculty_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'from_date' => '2026-08-11',
            'to_date' => '2026-08-12',
            'days' => 2,
            'reason' => 'Personal work',
            'status' => 'pending',
        ]);
        $structure = SalaryStructure::create([
            'college_id' => $college->id,
            'name' => 'Standard Scale',
            'code' => 'SS-BULK',
            'status' => 'active',
        ]);
        $payroll = Payroll::create([
            'college_id' => $college->id,
            'faculty_id' => $employee->id,
            'salary_structure_id' => $structure->id,
            'pay_period' => '2026-08-01',
            'basic_amount' => 30000,
            'gross_amount' => 32000,
            'total_deductions' => 2000,
            'net_amount' => 30000,
            'status' => 'processed',
        ]);

        $before = [
            'attendance' => $attendance->fresh()->getAttributes(),
            'leave' => $leave->fresh()->getAttributes(),
            'payroll' => $payroll->fresh()->getAttributes(),
            'employee' => $employee->fresh()->getAttributes(),
        ];

        foreach ([
            ['staff_attendance', $attendance->id],
            ['leave_requests', $leave->id],
            ['payrolls', $payroll->id],
        ] as [$module, $id]) {
            $response = $this->asCollege($college, $admin)->postJson(route('bulk-actions.execute'), [
                'module' => $module,
                'action' => 'export',
                'ids' => [$id],
            ])->assertOk();

            $this->asCollege($college, $admin)->get($response->json('data.redirect'))->assertOk();
        }

        // Read-only by construction: no attendance correction, no leave decision,
        // no payroll run and no employee-status change happened.
        $this->assertSame($before['attendance'], $attendance->fresh()->getAttributes());
        $this->assertSame($before['leave'], $leave->fresh()->getAttributes());
        $this->assertSame($before['payroll'], $payroll->fresh()->getAttributes());
        $this->assertSame($before['employee'], $employee->fresh()->getAttributes());
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_payroll_export_reports_the_stored_amounts_without_recalculating(): void
    {
        $college = $this->makeCollege('HRPAYBULK');
        $admin = $this->makeUserWithPermissions($college, ['payrolls.view', 'faculties.view']);
        $employee = $this->makeEmployee($college, 'EMP-PAY-BULK');
        $structure = SalaryStructure::create([
            'college_id' => $college->id,
            'name' => 'Scale A',
            'code' => 'SS-PAY',
            'status' => 'active',
        ]);
        $payroll = Payroll::create([
            'college_id' => $college->id,
            'faculty_id' => $employee->id,
            'salary_structure_id' => $structure->id,
            'pay_period' => '2026-07-01',
            'basic_amount' => 41000,
            'gross_amount' => 41000,
            'total_deductions' => 1000,
            'net_amount' => 40000,
            'status' => 'processed',
        ]);

        $response = $this->asCollege($college, $admin)->postJson(route('bulk-actions.execute'), [
            'module' => 'payrolls',
            'action' => 'export',
            'ids' => [$payroll->id],
        ])->assertOk();

        $redirect = $response->json('data.redirect');
        $this->assertIsString($redirect, 'The bulk endpoint must hand back a redirect to the CSV endpoint.');

        $csv = $this->asCollege($college, $admin)->get($redirect)->assertOk();
        $this->assertStringContainsString('payrolls-export-', (string) $csv->headers->get('Content-Disposition'));

        $body = str_replace("\xEF\xBB\xBF", '', $csv->streamedContent());

        $this->assertStringContainsString('EMP-PAY-BULK', $body);
        $this->assertStringContainsString('41000', $body);
        $this->assertStringContainsString('40000', $body);
        $this->assertEqualsWithDelta(40000.0, (float) $payroll->fresh()->net_amount, 0.001);

        // The stored snapshot is what was exported: nothing re-ran the payroll.
        $this->assertSame('processed', $payroll->fresh()->status);
    }
}
