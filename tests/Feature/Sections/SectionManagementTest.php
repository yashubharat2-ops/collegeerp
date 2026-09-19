<?php

namespace Tests\Feature\Sections;

use App\Models\{AcademicYear, AuditLog, Campus, Program, Section};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class SectionManagementTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_college_admin_can_create_section_scoped_to_active_college(): void
    {
        $college = $this->makeCollege('SECM');
        $admin = $this->makeUserWithPermissions($college, ['sections.view', 'sections.create']);

        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $program = Program::create([
            'college_id' => $college->id,
            'name' => 'Computer Science',
            'code' => 'CS',
            'status' => 'active',
        ]);
        $campus = Campus::create([
            'college_id' => $college->id,
            'name' => 'Main Campus',
            'code' => 'MAIN',
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('sections.store'), [
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'campus_id' => $campus->id,
                'name' => 'Section A',
                'code' => 'SEC-A',
                'capacity' => 60,
                'status' => 'active',
                'description' => 'First cohort',
            ], ['Referer' => route('sections.index')])
            ->assertRedirect(route('sections.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('sections', [
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'campus_id' => $campus->id,
            'name' => 'Section A',
            'code' => 'SEC-A',
            'capacity' => 60,
            'status' => 'active',
        ]);
    }

    public function test_validation_rejects_missing_fields_and_invalid_capacity(): void
    {
        $college = $this->makeCollege('SECV');
        $admin = $this->makeUserWithPermissions($college, ['sections.view', 'sections.create']);

        $this->asCollege($college, $admin)
            ->post(route('sections.store'), [
                'academic_year_id' => '',
                'program_id' => '',
                'name' => '',
                'code' => '',
                'capacity' => -5,
                'status' => 'archived',
            ], ['Referer' => route('sections.index')])
            ->assertSessionHasErrors(['academic_year_id', 'program_id', 'name', 'code', 'capacity', 'status']);

        $this->assertSame(0, Section::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_duplicate_code_in_same_program_and_year_is_blocked_but_allowed_in_different_program(): void
    {
        $college = $this->makeCollege('SECD');
        $admin = $this->makeUserWithPermissions($college, ['sections.view', 'sections.create', 'sections.update']);

        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $progCS = Program::create([
            'college_id' => $college->id,
            'name' => 'Computer Science',
            'code' => 'CS',
            'status' => 'active',
        ]);
        $progIT = Program::create([
            'college_id' => $college->id,
            'name' => 'Information Technology',
            'code' => 'IT',
            'status' => 'active',
        ]);

        Section::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'program_id' => $progCS->id,
            'name' => 'Section A',
            'code' => 'A',
            'status' => 'active',
        ]);

        // Same code in same program and year: rejected
        $this->asCollege($college, $admin)
            ->post(route('sections.store'), [
                'academic_year_id' => $year->id,
                'program_id' => $progCS->id,
                'name' => 'Another Section A',
                'code' => 'A',
                'status' => 'active',
            ], ['Referer' => route('sections.index')])
            ->assertSessionHasErrors('code');

        // Same code in different program: allowed
        $this->asCollege($college, $admin)
            ->post(route('sections.store'), [
                'academic_year_id' => $year->id,
                'program_id' => $progIT->id,
                'name' => 'IT Section A',
                'code' => 'A',
                'status' => 'active',
            ], ['Referer' => route('sections.index')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(2, Section::withoutGlobalScopes()->where('college_id', $college->id)->where('code', 'A')->count());
    }

    public function test_admin_can_update_and_soft_delete_section(): void
    {
        $college = $this->makeCollege('SECU');
        $admin = $this->makeUserWithPermissions($college, ['sections.view', 'sections.update', 'sections.delete']);

        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $program = Program::create([
            'college_id' => $college->id,
            'name' => 'Physics',
            'code' => 'PHY',
            'status' => 'active',
        ]);
        $section = Section::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'name' => 'Morning Batch',
            'code' => 'MRN',
            'capacity' => 40,
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)
            ->put(route('sections.update', $section), [
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'name' => 'Morning Alpha Batch',
                'code' => 'MRN',
                'capacity' => 45,
                'status' => 'inactive',
                'description' => 'Updated desc',
            ], ['Referer' => route('sections.edit', $section)])
            ->assertSessionHas('success');

        $section->refresh();
        $this->assertSame('Morning Alpha Batch', $section->name);
        $this->assertSame(45, $section->capacity);
        $this->assertSame('inactive', $section->status);

        $this->asCollege($college, $admin)
            ->delete(route('sections.destroy', $section), [], ['Referer' => route('sections.index')])
            ->assertSessionHas('success');

        $this->assertSoftDeleted('sections', ['id' => $section->id]);
        $this->asCollege($college, $admin)->get(route('sections.index'))->assertDontSee('Morning Alpha Batch');
    }

    public function test_audit_logs_record_section_actions(): void
    {
        $college = $this->makeCollege('SECA');
        $admin = $this->makeUserWithPermissions($college, ['sections.view', 'sections.create', 'sections.update', 'sections.delete']);

        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $program = Program::create([
            'college_id' => $college->id,
            'name' => 'Chemistry',
            'code' => 'CHEM',
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)->post(route('sections.store'), [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'name' => 'Audit Section',
            'code' => 'AUD-SEC',
            'capacity' => 30,
            'status' => 'active',
        ], ['Referer' => route('sections.index')])->assertSessionHasNoErrors();

        $sec = Section::withoutGlobalScopes()->where('code', 'AUD-SEC')->firstOrFail();

        $this->asCollege($college, $admin)->put(route('sections.update', $sec), [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'name' => 'Audit Section Renamed',
            'code' => 'AUD-SEC',
            'capacity' => 35,
            'status' => 'active',
        ], ['Referer' => route('sections.edit', $sec)])->assertSessionHasNoErrors();

        $this->asCollege($college, $admin)->delete(route('sections.destroy', $sec), [], ['Referer' => route('sections.index')]);

        $base = [
            'college_id' => $college->id,
            'user_id' => $admin->id,
            'subject_type' => Section::class,
            'subject_id' => $sec->id,
        ];

        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'section.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'section.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'section.deleted']);
    }
}
