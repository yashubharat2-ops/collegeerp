<?php

namespace Tests\Feature\Students;

use App\Models\Student;
use Tests\TestCase;

class StudentManagementTest extends TestCase
{
    use StudentTestHelpers;

    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Riya',
            'last_name' => 'Verma',
            'email' => 'riya@example.test',
            'phone' => '9999999999',
            'gender' => 'female',
            'date_of_birth' => '2008-06-15',
            'status' => 'active',
        ], $overrides);
    }

    public function test_authorized_user_can_view_student_index(): void
    {
        $college = $this->makeCollege('SMVA');
        $admin = $this->makeUserWithPermissions($college, ['students.view']);
        $student = $this->makeStudent($college, ['first_name' => 'Visible']);

        $this->asCollege($college, $admin)
            ->get(route('students.index'))
            ->assertOk()
            ->assertSee('Visible');
    }

    public function test_viewer_can_open_student_show_page(): void
    {
        $college = $this->makeCollege('SMSHOW');
        $admin = $this->makeUserWithPermissions($college, ['students.view']);
        $student = $this->makeStudent($college, ['student_number' => 'STU-SHOW']);

        $this->asCollege($college, $admin)
            ->get(route('students.show', $student))
            ->assertOk()
            ->assertSee('STU-SHOW');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('students.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $college = $this->makeCollege('SMFB');
        $user = $this->makeUserWithPermissions($college, []);

        $this->asCollege($college, $user)->get(route('students.index'))->assertForbidden();
        $this->asCollege($college, $user)->get(route('students.create'))->assertForbidden();
        $this->asCollege($college, $user)->post(route('students.store'), $this->storePayload())->assertForbidden();
    }

    public function test_college_admin_can_create_student_with_server_generated_number(): void
    {
        $college = $this->makeCollege('SMCR');
        $admin = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);

        $this->asCollege($college, $admin)
            ->post(route('students.store'), $this->storePayload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($student);
        $this->assertStringStartsWith('STU-', $student->student_number);
        $this->assertSame('Riya', $student->first_name);
        $this->assertSame('active', $student->status);
        $this->assertSame($college->id, $student->college_id);
    }

    public function test_student_number_is_sequential_per_college(): void
    {
        $college = $this->makeCollege('SMSEQ');
        $admin = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);

        foreach (['First', 'Second'] as $i => $name) {
            $this->asCollege($college, $admin)
                ->post(route('students.store'), $this->storePayload(['first_name' => $name, 'phone' => '900000000'.$i]))
                ->assertSessionHasNoErrors();
        }

        $numbers = Student::withoutGlobalScopes()->where('college_id', $college->id)->orderBy('id')->pluck('student_number')->all();
        $this->assertCount(2, $numbers);
        $this->assertNotSame($numbers[0], $numbers[1]);
        $this->assertStringStartsWith('STU-', $numbers[0]);
    }

    public function test_student_numbers_are_scoped_per_college(): void
    {
        $collegeA = $this->makeCollege('SMNA');
        $collegeB = $this->makeCollege('SMNB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['students.view', 'students.create']);
        $adminB = $this->makeUserWithPermissions($collegeB, ['students.view', 'students.create']);

        $this->asCollege($collegeA, $adminA)->post(route('students.store'), $this->storePayload(['first_name' => 'Aone']))->assertSessionHasNoErrors();
        $this->asCollege($collegeB, $adminB)->post(route('students.store'), $this->storePayload(['first_name' => 'Bone']))->assertSessionHasNoErrors();

        // Both colleges mint their own STU-…0001 sequence.
        $a = Student::withoutGlobalScopes()->where('college_id', $collegeA->id)->first();
        $b = Student::withoutGlobalScopes()->where('college_id', $collegeB->id)->first();
        $this->assertSame($a->student_number, $b->student_number);
        $this->assertNotSame($a->college_id, $b->college_id);
    }

    public function test_validation_rejects_missing_required_and_invalid_fields(): void
    {
        $college = $this->makeCollege('SMVL');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);

        $this->asCollege($college, $admin)
            ->post(route('students.store'), ['status' => 'active'])
            ->assertSessionHasErrors(['first_name']);

        $this->asCollege($college, $admin)
            ->post(route('students.store'), $this->storePayload(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');

        $this->asCollege($college, $admin)
            ->post(route('students.store'), $this->storePayload(['status' => 'invalid_status']))
            ->assertSessionHasErrors('status');

        $this->asCollege($college, $admin)
            ->post(route('students.store'), $this->storePayload(['date_of_birth' => '2035-01-01']))
            ->assertSessionHasErrors('date_of_birth');

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_admin_can_update_student_but_number_is_immutable(): void
    {
        $college = $this->makeCollege('SMUP');
        $admin = $this->makeUserWithPermissions($college, ['students.view', 'students.update']);
        $student = $this->makeStudent($college, ['student_number' => 'STU-ORIGINAL']);

        $this->asCollege($college, $admin)
            ->put(route('students.update', $student), $this->storePayload([
                'student_number' => 'HACKED-1',
                'college_id' => 999999,
                'admission_application_id' => 12345,
                'first_name' => 'Updated',
                'phone' => '8888888888',
            ]), ['Referer' => route('students.edit', $student)])
            ->assertSessionHas('success');

        $student->refresh();
        $this->assertSame('STU-ORIGINAL', $student->student_number);
        $this->assertSame($college->id, $student->college_id);
        $this->assertNull($student->admission_application_id);
        $this->assertSame('Updated', $student->first_name);
        $this->assertSame('8888888888', $student->phone);
    }

    public function test_college_id_from_browser_is_never_trusted(): void
    {
        $collegeA = $this->makeCollege('SMCSA');
        $collegeB = $this->makeCollege('SMCSB');
        $adminA = $this->makeUserWithPermissions($collegeA, ['students.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('students.store'), $this->storePayload(['college_id' => $collegeB->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());
        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_student_number_from_browser_is_never_trusted(): void
    {
        $college = $this->makeCollege('SMNS');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);

        $this->asCollege($college, $admin)
            ->post(route('students.store'), $this->storePayload(['student_number' => 'HACKED-123']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('students', ['student_number' => 'HACKED-123']);
        $this->assertDatabaseHas('students', ['college_id' => $college->id]);
    }

    public function test_soft_delete_hides_student_but_keeps_row(): void
    {
        $college = $this->makeCollege('SMDL');
        $admin = $this->makeUserWithPermissions($college, ['students.view', 'students.delete']);
        $student = $this->makeStudent($college, ['student_number' => 'STU-DEL']);

        $this->asCollege($college, $admin)
            ->delete(route('students.destroy', $student), [], ['Referer' => route('students.index')])
            ->assertSessionHas('success');

        $this->assertSoftDeleted('students', ['id' => $student->id]);
        $this->assertDatabaseHas('students', ['id' => $student->id, 'student_number' => 'STU-DEL']);
        $this->asCollege($college, $admin)->get(route('students.index'))->assertDontSee('STU-DEL');
    }

    public function test_index_supports_search_status_and_year_filter(): void
    {
        $college = $this->makeCollege('SMFL');
        $admin = $this->makeUserWithPermissions($college, ['students.view']);
        $year = $this->makeYear($college);

        $first = $this->makeStudent($college, ['first_name' => 'Searchable', 'status' => 'active']);
        $second = $this->makeStudent($college, ['first_name' => 'Hidden', 'status' => 'graduated']);
        $this->makeEnrollment($college, $first, $year);

        $this->asCollege($college, $admin)->get(route('students.index', ['search' => 'Searchable']))
            ->assertSee($first->student_number)->assertDontSee($second->student_number);

        $this->asCollege($college, $admin)->get(route('students.index', ['status' => 'graduated']))
            ->assertSee($second->student_number)->assertDontSee($first->student_number);

        $this->asCollege($college, $admin)->get(route('students.index', ['academic_year_id' => $year->id]))
            ->assertSee($first->student_number)->assertDontSee($second->student_number);
    }

    public function test_index_uses_deterministic_creation_order_pagination(): void
    {
        $college = $this->makeCollege('SMPG');
        $admin = $this->makeUserWithPermissions($college, ['students.view']);

        $numbers = [];
        foreach (range(1, 16) as $i) {
            $numbers[] = $this->makeStudent($college, ['student_number' => sprintf('STU-%03d', $i)])->student_number;
        }

        $this->asCollege($college, $admin)->get(route('students.index'))
            ->assertSee('STU-001')->assertSee('STU-015')->assertDontSee('STU-016');

        $this->asCollege($college, $admin)->get(route('students.index', ['page' => 2]))
            ->assertSee('STU-016')->assertDontSee('STU-001');
    }

    public function test_audit_logs_record_create_update_and_delete(): void
    {
        $college = $this->makeCollege('SMAU');
        $admin = $this->makeUserWithPermissions($college, ['students.view', 'students.create', 'students.update', 'students.delete']);

        $this->asCollege($college, $admin)->post(route('students.store'), $this->storePayload())->assertSessionHasNoErrors();
        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->first();

        $this->assertDatabaseHas('audit_logs', ['action' => 'student.created', 'subject_id' => $student->id]);

        $this->asCollege($college, $admin)
            ->put(route('students.update', $student), $this->storePayload(['first_name' => 'Audited']), ['Referer' => route('students.edit', $student)])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.updated', 'subject_id' => $student->id]);

        $this->asCollege($college, $admin)->delete(route('students.destroy', $student), [], ['Referer' => route('students.index')]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.deleted', 'subject_id' => $student->id]);
    }

    public function test_student_names_are_html_and_js_escaped(): void
    {
        $college = $this->makeCollege('SMXS');
        $admin = $this->makeUserWithPermissions($college, ['students.view', 'students.delete']);
        $malicious = "O'Brien');alert(1);('";
        $this->makeStudent($college, ['first_name' => $malicious, 'student_number' => 'STU-XSS']);

        $html = $this->asCollege($college, $admin)->get(route('students.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString("');alert(1);('", $html);
        $this->assertStringContainsString('O&#039;Brien', $html);
    }
}
