<?php

namespace Tests\Feature\Sections;

use App\Models\{AcademicYear, Campus, College, Program, Section};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class SectionTenancyTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_list_only_shows_sections_of_the_active_college(): void
    {
        $collegeA = $this->makeCollege('SISA');
        $collegeB = $this->makeCollege('SISB');

        $yearA = AcademicYear::create(['college_id' => $collegeA->id, 'name' => '2026-2027 A', 'code' => 'AY-A', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $progA = Program::create(['college_id' => $collegeA->id, 'name' => 'Program A', 'code' => 'P-A', 'status' => 'active']);

        $yearB = AcademicYear::create(['college_id' => $collegeB->id, 'name' => '2026-2027 B', 'code' => 'AY-B', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $progB = Program::create(['college_id' => $collegeB->id, 'name' => 'Program B', 'code' => 'P-B', 'status' => 'active']);

        Section::create(['college_id' => $collegeA->id, 'academic_year_id' => $yearA->id, 'program_id' => $progA->id, 'name' => 'Alpha Section', 'code' => 'AS', 'status' => 'active']);
        Section::create(['college_id' => $collegeB->id, 'academic_year_id' => $yearB->id, 'program_id' => $progB->id, 'name' => 'Bravo Section', 'code' => 'BS', 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['sections.view']);

        $this->asCollege($collegeA, $adminA)->get(route('sections.index'))
            ->assertSee('Alpha Section')
            ->assertDontSee('Bravo Section');
    }

    public function test_cross_college_academic_year_rejected(): void
    {
        $collegeA = $this->makeCollege('SAYA');
        $collegeB = $this->makeCollege('SAYB');

        $yearB = AcademicYear::create(['college_id' => $collegeB->id, 'name' => '2026-2027 B', 'code' => 'AY-B', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $progA = Program::create(['college_id' => $collegeA->id, 'name' => 'Program A', 'code' => 'P-A', 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['sections.view', 'sections.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('sections.store'), [
                'academic_year_id' => $yearB->id,
                'program_id' => $progA->id,
                'name' => 'Illegal Year Section',
                'code' => 'IYS',
                'status' => 'active',
            ], ['Referer' => route('sections.index')])
            ->assertSessionHasErrors('academic_year_id');

        $this->assertDatabaseMissing('sections', ['code' => 'IYS']);
    }

    public function test_cross_college_program_rejected(): void
    {
        $collegeA = $this->makeCollege('SPGA');
        $collegeB = $this->makeCollege('SPGB');

        $yearA = AcademicYear::create(['college_id' => $collegeA->id, 'name' => '2026-2027 A', 'code' => 'AY-A', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $progB = Program::create(['college_id' => $collegeB->id, 'name' => 'Program B', 'code' => 'P-B', 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['sections.view', 'sections.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('sections.store'), [
                'academic_year_id' => $yearA->id,
                'program_id' => $progB->id,
                'name' => 'Illegal Program Section',
                'code' => 'IPS',
                'status' => 'active',
            ], ['Referer' => route('sections.index')])
            ->assertSessionHasErrors('program_id');

        $this->assertDatabaseMissing('sections', ['code' => 'IPS']);
    }

    public function test_cross_college_campus_rejected(): void
    {
        $collegeA = $this->makeCollege('SCPA');
        $collegeB = $this->makeCollege('SCPB');

        $yearA = AcademicYear::create(['college_id' => $collegeA->id, 'name' => '2026-2027 A', 'code' => 'AY-A', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $progA = Program::create(['college_id' => $collegeA->id, 'name' => 'Program A', 'code' => 'P-A', 'status' => 'active']);
        $campusB = Campus::create(['college_id' => $collegeB->id, 'name' => 'Campus B', 'code' => 'CB', 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['sections.view', 'sections.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('sections.store'), [
                'academic_year_id' => $yearA->id,
                'program_id' => $progA->id,
                'campus_id' => $campusB->id,
                'name' => 'Illegal Campus Section',
                'code' => 'ICS',
                'status' => 'active',
            ], ['Referer' => route('sections.index')])
            ->assertSessionHasErrors('campus_id');

        $this->assertDatabaseMissing('sections', ['code' => 'ICS']);
    }

    public function test_cross_college_section_cannot_be_read_edited_or_deleted(): void
    {
        $collegeA = $this->makeCollege('SOCA');
        $collegeB = $this->makeCollege('SOCB');

        $yearB = AcademicYear::create(['college_id' => $collegeB->id, 'name' => '2026-2027 B', 'code' => 'AY-B', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $progB = Program::create(['college_id' => $collegeB->id, 'name' => 'Program B', 'code' => 'P-B', 'status' => 'active']);
        $foreign = Section::create(['college_id' => $collegeB->id, 'academic_year_id' => $yearB->id, 'program_id' => $progB->id, 'name' => 'Foreign Section', 'code' => 'FS', 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['sections.view', 'sections.update', 'sections.delete']);

        $this->asCollege($collegeA, $adminA)->get(route('sections.edit', $foreign))->assertNotFound();
        $this->asCollege($collegeA, $adminA)->put(route('sections.update', $foreign), [
            'academic_year_id' => $yearB->id,
            'program_id' => $progB->id,
            'name' => 'Hijacked Section',
            'code' => 'FS',
            'status' => 'active',
        ], ['Referer' => route('sections.index')])->assertNotFound();
        $this->asCollege($collegeA, $adminA)->delete(route('sections.destroy', $foreign), [], ['Referer' => route('sections.index')])->assertNotFound();

        $this->assertSame('Foreign Section', $foreign->fresh()->name);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_college_id_from_client_input_is_never_trusted(): void
    {
        $collegeA = $this->makeCollege('SSPA');
        $collegeB = $this->makeCollege('SSPB');

        $yearA = AcademicYear::create(['college_id' => $collegeA->id, 'name' => '2026-2027 A', 'code' => 'AY-A', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $progA = Program::create(['college_id' => $collegeA->id, 'name' => 'Program A', 'code' => 'P-A', 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['sections.view', 'sections.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('sections.store'), [
                'college_id' => $collegeB->id,
                'academic_year_id' => $yearA->id,
                'program_id' => $progA->id,
                'name' => 'Sneaky Section',
                'code' => 'SNK-S',
                'status' => 'active',
            ], ['Referer' => route('sections.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sections', ['college_id' => $collegeA->id, 'code' => 'SNK-S']);
        $this->assertDatabaseMissing('sections', ['college_id' => $collegeB->id, 'code' => 'SNK-S']);
    }
}
