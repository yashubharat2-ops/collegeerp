<?php

namespace Tests\Feature\Students;

use App\Models\College;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * CSV export of the Student list.
 *
 * The export is the file half of the listing, so it must obey the same rules:
 * the same filter pipeline (StudentListService), the server-side tenant scope,
 * and an explicit permission of its own. A selected ids list may only ever
 * NARROW the filtered set — it is re-queried, never trusted.
 */
class StudentExportTest extends TestCase
{
    use StudentTestHelpers;

    /**
     * @return array{0: College, 1: User}
     */
    private function collegeWithExporter(string $code, array $permissions = ['students.view', 'students.export']): array
    {
        $college = $this->makeCollege($code);

        return [$college, $this->makeUserWithPermissions($college, $permissions)];
    }

    private function exportBody(TestResponse $response): string
    {
        return $response->streamedContent();
    }

    public function test_export_requires_the_export_permission(): void
    {
        [$college] = $this->collegeWithExporter('SEXP1');
        $this->makeStudent($college, ['first_name' => 'Locked', 'student_number' => 'STU-EXP-LOCK']);

        // Checked FIRST: filling the sheet is behind `auth`, so the guest request
        // must be made before any actingAs() call authenticates the test.
        $this->get(route('students.export'))->assertRedirect(route('login'));

        $nobody = $this->makeUserWithPermissions($college, ['dashboard.view']);
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);
        $exporter = $this->makeUserWithPermissions($college, ['students.view', 'students.export']);

        $this->asCollege($college, $nobody)->get(route('students.export'))->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('students.export'))->assertForbidden();

        $this->asCollege($college, $exporter)->get(route('students.export'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_export_streams_the_expected_columns_and_values(): void
    {
        [$college, $exporter] = $this->collegeWithExporter('SEXP2');
        $year = $this->makeYear($college, '2026', '2026-27');
        $program = $this->makeProgram($college, 'BSC');

        $student = $this->makeStudent($college, [
            'student_number' => 'STU-EXP-1',
            'first_name' => 'Export',
            'last_name' => 'Candidate',
            'email' => 'export.candidate@example.test',
            'phone' => '9000000001',
            'gender' => 'female',
            'category' => 'obc',
            'status' => 'active',
            'admission_date' => '2026-07-05',
        ]);
        $this->makeEnrollment($college, $student, $year, $program, ['enrollment_number' => 'ENR-EXP-1']);

        $response = $this->asCollege($college, $exporter)->get(route('students.export'))->assertOk();

        $this->assertStringContainsString('students-export-', (string) $response->headers->get('Content-Disposition'));

        $csv = $this->exportBody($response);

        // UTF-8 BOM for Excel, then the header row.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $body = substr($csv, 3);

        // fputcsv quotes every field that contains a space — that quoting is the
        // Excel-safe behaviour, not a defect — so the header is validated as
        // PARSED fields, never as raw text.
        $headerLine = rtrim(explode("\n", $body, 2)[0], "\r");
        $this->assertSame([
            'Student number', 'First name', 'Middle name', 'Last name', 'Email', 'Mobile', 'Alternate mobile',
            'Gender', 'Category', 'Student status', 'Admission date', 'Date of birth',
            'Enrollment number', 'Academic year', 'Program', 'Department', 'Section', 'Enrollment status',
        ], str_getcsv($headerLine, ',', '"', '\\'));

        // The quoting itself is part of the contract: it is what keeps Excel from
        // splitting a header such as "Student number" into two columns.
        $this->assertStringContainsString('"Student number","First name"', $body);

        // The row carries the student, the category column and current-enrollment data.
        $this->assertStringContainsString('STU-EXP-1', $body);
        $this->assertStringContainsString('Export', $body);
        $this->assertStringContainsString('Candidate', $body);
        $this->assertStringContainsString('export.candidate@example.test', $body);
        $this->assertStringContainsString('9000000001', $body);
        $this->assertStringContainsString('obc', $body);
        $this->assertStringContainsString('2026-07-05', $body);
        $this->assertStringContainsString('ENR-EXP-1', $body);
        $this->assertStringContainsString('2026-27', $body);
        $this->assertStringContainsString('BSc', $body);
    }

    public function test_export_respects_the_currently_applied_filters(): void
    {
        [$college, $exporter] = $this->collegeWithExporter('SEXP3');
        $year = $this->makeYear($college, '2026', '2026-27');
        $otherYear = $this->makeYear($college, '2027', '2027-28', '2027-06-01', '2028-05-31');
        $program = $this->makeProgram($college, 'BSC');

        $match = $this->makeStudent($college, ['first_name' => 'Matching', 'student_number' => 'STU-MATCH', 'status' => 'active', 'category' => 'sc']);
        $wrongStatus = $this->makeStudent($college, ['first_name' => 'Graduated', 'student_number' => 'STU-WRONGSTATUS', 'status' => 'graduated', 'category' => 'sc']);
        $wrongCategory = $this->makeStudent($college, ['first_name' => 'OtherCategory', 'student_number' => 'STU-WRONGCAT', 'status' => 'active', 'category' => 'general']);
        $wrongYear = $this->makeStudent($college, ['first_name' => 'OtherYearStudent', 'student_number' => 'STU-WRONGYEAR', 'status' => 'active', 'category' => 'sc']);

        $this->makeEnrollment($college, $match, $year, $program);
        $this->makeEnrollment($college, $wrongStatus, $year, $program);
        $this->makeEnrollment($college, $wrongCategory, $year, $program);
        $this->makeEnrollment($college, $wrongYear, $otherYear, $program);

        // status + category + academic year, exactly as the list would apply them.
        $csv = $this->exportBody($this->asCollege($college, $exporter)->get(route('students.export', [
            'status' => 'active',
            'category' => 'sc',
            'academic_year_id' => $year->id,
        ]))->assertOk());

        $this->assertStringContainsString('STU-MATCH', $csv);
        $this->assertStringNotContainsString('STU-WRONGSTATUS', $csv);
        $this->assertStringNotContainsString('STU-WRONGCAT', $csv);
        $this->assertStringNotContainsString('STU-WRONGYEAR', $csv);

        // A search term narrows it the same way on screen and in the file.
        $csv = $this->exportBody($this->asCollege($college, $exporter)->get(route('students.export', [
            'search' => 'Matching',
        ]))->assertOk());

        $this->assertStringContainsString('STU-MATCH', $csv);
        $this->assertStringNotContainsString('STU-WRONGSTATUS', $csv);
    }

    public function test_export_can_be_narrowed_to_selected_ids_and_ignores_foreign_ids(): void
    {
        [$collegeA, $exporter] = $this->collegeWithExporter('SEXP4A');
        $collegeB = $this->makeCollege('SEXP4B');

        $selected = $this->makeStudent($collegeA, ['first_name' => 'Selected', 'student_number' => 'STU-SELECTED']);
        $notSelected = $this->makeStudent($collegeA, ['first_name' => 'NotSelected', 'student_number' => 'STU-NOTSEL']);
        $foreign = $this->makeStudent($collegeB, ['first_name' => 'Foreign', 'student_number' => 'STU-FOREIGNEXP']);

        // The client asks for its own student AND a foreign college's student.
        $csv = $this->exportBody($this->asCollege($collegeA, $exporter)->get(route('students.export', [
            'ids' => [$selected->id, $foreign->id],
        ]))->assertOk());

        $this->assertStringContainsString('STU-SELECTED', $csv);
        $this->assertStringNotContainsString('STU-NOTSEL', $csv);
        $this->assertStringNotContainsString('STU-FOREIGNEXP', $csv);

        // Ids are re-queried server-side: junk and duplicates change nothing.
        $csv = $this->exportBody($this->asCollege($collegeA, $exporter)->get(route('students.export', [
            'ids' => [$selected->id, $selected->id, 'DROP TABLE students', '', '999999'],
        ]))->assertOk());

        $this->assertStringContainsString('STU-SELECTED', $csv);
        $this->assertStringNotContainsString('STU-NOTSEL', $csv);
    }

    public function test_export_is_tenant_scoped_and_only_covers_the_active_college(): void
    {
        [$collegeA, $exporter] = $this->collegeWithExporter('SEXP5A');
        $collegeB = $this->makeCollege('SEXP5B');

        $this->makeStudent($collegeA, ['first_name' => 'Local', 'student_number' => 'STU-LOCALEXP']);
        $this->makeStudent($collegeB, ['first_name' => 'Foreign', 'student_number' => 'STU-FOREIGNONLY']);

        $csv = $this->exportBody($this->asCollege($collegeA, $exporter)->get(route('students.export'))->assertOk());

        $this->assertStringContainsString('STU-LOCALEXP', $csv);
        $this->assertStringNotContainsString('STU-FOREIGNONLY', $csv);
    }

    public function test_export_is_audited_with_the_filter_context(): void
    {
        [$college, $exporter] = $this->collegeWithExporter('SEXP6');
        $this->makeStudent($college, ['first_name' => 'Audited', 'student_number' => 'STU-EXPAUDIT']);

        $this->asCollege($college, $exporter)->get(route('students.export', ['status' => 'active']))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'students.exported',
            'college_id' => $college->id,
        ]);
    }
}
