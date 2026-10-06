<?php

namespace Tests\Feature\Students;

use App\Domain\Student\Actions\GenerateStudentNumber;
use App\Domain\Student\Support\Aadhaar;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use StudentTestHelpers;

    private const AADHAAR = '999941057058';

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function csv(array $rows): UploadedFile
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, \App\Domain\Student\Services\StudentImportService::COLUMNS);

        foreach ($rows as $row) {
            $line = [];
            foreach (\App\Domain\Student\Services\StudentImportService::COLUMNS as $column) {
                $line[] = $row[$column] ?? '';
            }
            fputcsv($handle, $line);
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return UploadedFile::fake()->createWithContent('students.csv', $contents);
    }

    public function test_guest_is_redirected_from_import(): void
    {
        $this->get(route('students.import.index'))->assertRedirect(route('login'));
        $this->get(route('students.import.template'))->assertRedirect(route('login'));
    }

    public function test_user_without_create_permission_is_forbidden(): void
    {
        $college = $this->makeCollege('IMPFB');
        $user = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $user)->get(route('students.import.index'))->assertForbidden();
        $this->asCollege($college, $user)->get(route('students.import.template'))->assertForbidden();
        $this->asCollege($college, $user)
            ->post(route('students.import.validate'), ['file' => $this->csv([['first_name' => 'A', 'status' => 'active']])])
            ->assertForbidden();
    }

    public function test_index_is_linked_from_the_student_list(): void
    {
        $college = $this->makeCollege('IMPLK');
        $admin = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);

        $this->asCollege($college, $admin)
            ->get(route('students.index'))
            ->assertOk()
            ->assertSee('Bulk Registration / Import Students')
            ->assertSee(route('students.import.index'), false);
    }

    public function test_template_downloads_official_headers(): void
    {
        $college = $this->makeCollege('IMPTL');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);

        $response = $this->asCollege($college, $admin)->get(route('students.import.template'));

        $response->assertOk();
        $this->assertStringContainsString('first_name', $response->streamedContent());
        $this->assertStringContainsString('academic_year_id', $response->streamedContent());
        $this->assertStringNotContainsString('aadhaar_hash', $response->streamedContent());
    }

    public function test_validation_reports_row_and_field_and_creates_nothing(): void
    {
        $college = $this->makeCollege('IMPVL');
        $admin = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);

        $this->asCollege($college, $admin)
            ->post(route('students.import.validate'), [
                'file' => $this->csv([
                    ['first_name' => '', 'status' => 'nope'],
                ]),
            ])
            ->assertRedirect(route('students.import.index'))
            ->assertSessionHas('student_import_preview');

        $preview = session('student_import_preview');
        $this->assertFalse($preview['ok']);
        $fields = array_column($preview['errors'], 'field');
        $this->assertContains('first_name', $fields);
        $this->assertContains('status', $fields);
        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_foreign_college_master_ids_are_rejected(): void
    {
        $collegeA = $this->makeCollege('IMPA');
        $collegeB = $this->makeCollege('IMPB');
        $yearB = $this->makeYear($collegeB);
        $programB = $this->makeProgram($collegeB);
        $adminA = $this->makeUserWithPermissions($collegeA, [
            'students.view', 'students.create', 'student_enrollments.create',
        ]);

        $this->asCollege($collegeA, $adminA)
            ->post(route('students.import.validate'), [
                'file' => $this->csv([[
                    'first_name' => 'Riya',
                    'status' => 'active',
                    'academic_year_id' => $yearB->id,
                    'program_id' => $programB->id,
                    'enrollment_date' => '01/06/2026',
                ]]),
            ]);

        $preview = session('student_import_preview');
        $this->assertFalse($preview['ok']);
        $fields = array_column($preview['errors'], 'field');
        $this->assertContains('academic_year_id', $fields);
        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_mismatched_section_is_rejected_before_write(): void
    {
        $college = $this->makeCollege('IMPSEC');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $otherProgram = $this->makeProgram($college, 'BA');
        $section = $this->makeSection($college, $year, $otherProgram);
        $admin = $this->makeUserWithPermissions($college, [
            'students.view', 'students.create', 'student_enrollments.create',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('students.import.validate'), [
                'file' => $this->csv([[
                    'first_name' => 'Riya',
                    'status' => 'active',
                    'academic_year_id' => $year->id,
                    'program_id' => $program->id,
                    'section_id' => $section->id,
                    'enrollment_date' => '01/06/2026',
                ]]),
            ]);

        $preview = session('student_import_preview');
        $this->assertFalse($preview['ok']);
        $this->assertTrue(collect($preview['errors'])->contains(fn ($e) => $e['field'] === 'section_id'));
        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_duplicate_phone_and_aadhaar_in_file_are_rejected(): void
    {
        $college = $this->makeCollege('IMPDUP');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);

        $this->asCollege($college, $admin)
            ->post(route('students.import.validate'), [
                'file' => $this->csv([
                    ['first_name' => 'One', 'status' => 'active', 'phone' => '9000000001', 'aadhaar_number' => self::AADHAAR],
                    ['first_name' => 'Two', 'status' => 'active', 'phone' => '9000000001', 'aadhaar_number' => self::AADHAAR],
                ]),
            ]);

        $preview = session('student_import_preview');
        $this->assertFalse($preview['ok']);
        $fields = array_column($preview['errors'], 'field');
        $this->assertContains('phone', $fields);
        $this->assertContains('aadhaar_number', $fields);

        $messages = implode(' ', array_column($preview['errors'], 'message'));
        $this->assertStringNotContainsString(self::AADHAAR, $messages);
        $this->assertStringNotContainsString('999941057058', $messages);
    }

    public function test_existing_college_phone_and_aadhaar_block_import(): void
    {
        $college = $this->makeCollege('IMPEX');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);
        $this->makeStudent($college, [
            'phone' => '9111111111',
            'aadhaar_number' => self::AADHAAR,
            'aadhaar_last4' => '7058',
            'aadhaar_hash' => Aadhaar::hash(self::AADHAAR),
        ]);

        $this->asCollege($college, $admin)
            ->post(route('students.import.validate'), [
                'file' => $this->csv([[
                    'first_name' => 'New',
                    'status' => 'active',
                    'phone' => '9111111111',
                    'aadhaar_number' => self::AADHAAR,
                ]]),
            ]);

        $preview = session('student_import_preview');
        $this->assertFalse($preview['ok']);
        $fields = array_column($preview['errors'], 'field');
        $this->assertContains('phone', $fields);
        $this->assertContains('aadhaar_number', $fields);
    }

    public function test_confirm_creates_students_and_enrollments_with_generated_numbers(): void
    {
        Storage::fake('local');

        $college = $this->makeCollege('IMPOK');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $section = $this->makeSection($college, $year, $program);
        $admin = $this->makeUserWithPermissions($college, [
            'students.view', 'students.create', 'student_enrollments.create',
        ]);

        $file = $this->csv([[
            'first_name' => 'Imported',
            'last_name' => 'Student',
            'status' => 'active',
            'date_of_birth' => '15/06/2008',
            'admission_date' => '01/06/2026',
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'section_id' => $section->id,
            'enrollment_date' => '02/06/2026',
            'gender' => 'female',
            'phone' => '9222222222',
        ]]);

        $this->asCollege($college, $admin)
            ->post(route('students.import.validate'), ['file' => $file])
            ->assertSessionHas('student_import_token');

        $token = session('student_import_token');
        $preview = session('student_import_preview');
        $this->assertTrue($preview['ok']);
        $this->assertSame(1, $preview['rows']);

        $this->asCollege($college, $admin)
            ->post(route('students.import.store'), ['token' => $token])
            ->assertRedirect(route('students.index'))
            ->assertSessionHas('success');

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($student);
        $this->assertSame('Imported', $student->first_name);
        $this->assertStringStartsWith('STU-', $student->student_number);
        $this->assertSame('2008-06-15', $student->date_of_birth?->format('Y-m-d'));
        $this->assertSame('2026-06-01', $student->admission_date?->format('Y-m-d'));

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->first();
        $this->assertNotNull($enrollment);
        $this->assertStringStartsWith('ENR-', $enrollment->enrollment_number);
        $this->assertSame($year->id, $enrollment->academic_year_id);
        $this->assertSame($program->id, $enrollment->program_id);
        $this->assertSame($section->id, $enrollment->section_id);
        $this->assertSame('2026-06-02', $enrollment->enrollment_date?->format('Y-m-d'));
    }

    public function test_failed_import_does_not_leave_partial_students(): void
    {
        Storage::fake('local');

        $college = $this->makeCollege('IMPTX');
        $admin = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);

        $this->app->bind(GenerateStudentNumber::class, function () {
            return new class extends GenerateStudentNumber {
                private int $calls = 0;

                public function execute(int $collegeId, ?string $academicYearCode = null): string
                {
                    $this->calls++;
                    if ($this->calls > 1) {
                        throw new \RuntimeException('generator failed');
                    }

                    return parent::execute($collegeId, $academicYearCode);
                }
            };
        });

        $file = $this->csv([
            ['first_name' => 'First', 'status' => 'active', 'phone' => '9333333331'],
            ['first_name' => 'Second', 'status' => 'active', 'phone' => '9333333332'],
        ]);

        $this->asCollege($college, $admin)
            ->post(route('students.import.validate'), ['file' => $file]);

        $token = session('student_import_token');

        $this->withoutExceptionHandling();

        try {
            $this->asCollege($college, $admin)
                ->post(route('students.import.store'), ['token' => $token]);
            $this->fail('The import should abort when a later row cannot be created.');
        } catch (\RuntimeException $e) {
            $this->assertSame('generator failed', $e->getMessage());
        }

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_import_token_is_college_and_user_scoped(): void
    {
        Storage::fake('local');

        $collegeA = $this->makeCollege('IMTA');
        $collegeB = $this->makeCollege('IMTB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['students.create']);
        $adminB = $this->makeUserWithPermissions($collegeB, ['students.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('students.import.validate'), [
                'file' => $this->csv([['first_name' => 'A', 'status' => 'active']]),
            ]);

        $token = session('student_import_token');

        $this->asCollege($collegeB, $adminB)
            ->post(route('students.import.store'), ['token' => $token])
            ->assertNotFound();

        $this->assertSame(0, Student::withoutGlobalScopes()->count());
    }

    public function test_enrollment_columns_require_enrollment_permission(): void
    {
        $college = $this->makeCollege('IMPEN');
        $year = $this->makeYear($college);
        $admin = $this->makeUserWithPermissions($college, ['students.create']);

        $this->asCollege($college, $admin)
            ->post(route('students.import.validate'), [
                'file' => $this->csv([[
                    'first_name' => 'Riya',
                    'status' => 'active',
                    'academic_year_id' => $year->id,
                    'enrollment_date' => '01/06/2026',
                ]]),
            ]);

        $preview = session('student_import_preview');
        $this->assertFalse($preview['ok']);
        $this->assertTrue(collect($preview['errors'])->contains(fn ($e) => $e['field'] === 'academic_year_id'));
    }
}
