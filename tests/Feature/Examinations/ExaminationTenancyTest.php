<?php

namespace Tests\Feature\Examinations;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Examination;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class ExaminationTenancyTest extends TestCase
{
    use DepartmentTestHelpers;

    /**
     * Requirement 10: tenant isolation works
     */
    public function test_tenant_isolation_list_only_shows_active_college_examinations(): void
    {
        $collegeA = $this->makeCollege('EXTA');
        $collegeB = $this->makeCollege('EXTB');

        $yearA = AcademicYear::create(['college_id' => $collegeA->id, 'name' => '2026-2027 A', 'code' => 'AY-A', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $termA = AcademicTerm::create(['college_id' => $collegeA->id, 'academic_year_id' => $yearA->id, 'name' => 'Sem A', 'code' => 'SA', 'type' => 'semester', 'sequence' => 1, 'status' => 'active']);
        Examination::create(['college_id' => $collegeA->id, 'academic_year_id' => $yearA->id, 'academic_term_id' => $termA->id, 'name' => 'Alpha Exam', 'code' => 'ALPH-1', 'exam_type' => 'Mid Term', 'start_date' => '2026-10-01', 'end_date' => '2026-10-05', 'status' => 'draft']);

        $yearB = AcademicYear::create(['college_id' => $collegeB->id, 'name' => '2026-2027 B', 'code' => 'AY-B', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $termB = AcademicTerm::create(['college_id' => $collegeB->id, 'academic_year_id' => $yearB->id, 'name' => 'Sem B', 'code' => 'SB', 'type' => 'semester', 'sequence' => 1, 'status' => 'active']);
        Examination::create(['college_id' => $collegeB->id, 'academic_year_id' => $yearB->id, 'academic_term_id' => $termB->id, 'name' => 'Beta Exam', 'code' => 'BETA-1', 'exam_type' => 'Mid Term', 'start_date' => '2026-10-01', 'end_date' => '2026-10-05', 'status' => 'draft']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['examinations.view']);

        $this->asCollege($collegeA, $adminA)
            ->get(route('examinations.index'))
            ->assertSee('Alpha Exam')
            ->assertDontSee('Beta Exam');
    }

    /**
     * Requirement 4: cannot create examination using another college's academic year
     */
    public function test_cannot_create_examination_using_another_colleges_academic_year(): void
    {
        $collegeA = $this->makeCollege('EXT4A');
        $collegeB = $this->makeCollege('EXT4B');

        $yearA = AcademicYear::create(['college_id' => $collegeA->id, 'name' => '2026-2027 A', 'code' => 'AY-A', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $termA = AcademicTerm::create(['college_id' => $collegeA->id, 'academic_year_id' => $yearA->id, 'name' => 'Sem A', 'code' => 'SA', 'type' => 'semester', 'sequence' => 1, 'status' => 'active']);

        $foreignYearB = AcademicYear::create(['college_id' => $collegeB->id, 'name' => '2026-2027 B', 'code' => 'AY-B', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['examinations.view', 'examinations.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('examinations.store'), [
                'name' => 'Cross Year Exam',
                'code' => 'CROSS-01',
                'academic_year_id' => $foreignYearB->id,
                'academic_term_id' => $termA->id,
                'exam_type' => 'Internal',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-10',
                'status' => 'draft',
            ], ['Referer' => route('examinations.index')])
            ->assertSessionHasErrors('academic_year_id');

        $this->assertSame(0, Examination::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    /**
     * Requirement 5: cannot create examination using another college's academic term
     */
    public function test_cannot_create_examination_using_another_colleges_academic_term(): void
    {
        $collegeA = $this->makeCollege('EXT5A');
        $collegeB = $this->makeCollege('EXT5B');

        $yearA = AcademicYear::create(['college_id' => $collegeA->id, 'name' => '2026-2027 A', 'code' => 'AY-A', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $foreignYearB = AcademicYear::create(['college_id' => $collegeB->id, 'name' => '2026-2027 B', 'code' => 'AY-B', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $foreignTermB = AcademicTerm::create(['college_id' => $collegeB->id, 'academic_year_id' => $foreignYearB->id, 'name' => 'Sem B', 'code' => 'SB', 'type' => 'semester', 'sequence' => 1, 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['examinations.view', 'examinations.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('examinations.store'), [
                'name' => 'Cross Term Exam',
                'code' => 'CROSS-02',
                'academic_year_id' => $yearA->id,
                'academic_term_id' => $foreignTermB->id,
                'exam_type' => 'Internal',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-10',
                'status' => 'draft',
            ], ['Referer' => route('examinations.index')])
            ->assertSessionHasErrors('academic_term_id');

        $this->assertSame(0, Examination::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    public function test_cannot_edit_or_delete_another_colleges_examination(): void
    {
        $collegeA = $this->makeCollege('EXT6A');
        $collegeB = $this->makeCollege('EXT6B');

        $yearB = AcademicYear::create(['college_id' => $collegeB->id, 'name' => '2026-2027 B', 'code' => 'AY-B', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $termB = AcademicTerm::create(['college_id' => $collegeB->id, 'academic_year_id' => $yearB->id, 'name' => 'Sem B', 'code' => 'SB', 'type' => 'semester', 'sequence' => 1, 'status' => 'active']);
        $foreignExam = Examination::create(['college_id' => $collegeB->id, 'academic_year_id' => $yearB->id, 'academic_term_id' => $termB->id, 'name' => 'Foreign Exam', 'code' => 'FRG-01', 'exam_type' => 'Semester', 'start_date' => '2026-11-01', 'end_date' => '2026-11-10', 'status' => 'draft']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['examinations.view', 'examinations.update', 'examinations.delete']);

        $this->asCollege($collegeA, $adminA)
            ->get(route('examinations.edit', $foreignExam))
            ->assertNotFound();

        $this->asCollege($collegeA, $adminA)
            ->put(route('examinations.update', $foreignExam), [
                'name' => 'Hacked Name',
                'code' => 'FRG-01',
                'academic_year_id' => $yearB->id,
                'academic_term_id' => $termB->id,
                'exam_type' => 'Semester',
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-10',
                'status' => 'draft',
            ], ['Referer' => route('examinations.index')])
            ->assertNotFound();

        $this->asCollege($collegeA, $adminA)
            ->delete(route('examinations.destroy', $foreignExam), [], ['Referer' => route('examinations.index')])
            ->assertNotFound();

        $this->assertSame('Foreign Exam', $foreignExam->fresh()->name);
        $this->assertNull($foreignExam->fresh()->deleted_at);
    }

    public function test_college_id_from_client_is_never_trusted(): void
    {
        $collegeA = $this->makeCollege('EXT7A');
        $collegeB = $this->makeCollege('EXT7B');

        $yearA = AcademicYear::create(['college_id' => $collegeA->id, 'name' => '2026-2027 A', 'code' => 'AY-A', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $termA = AcademicTerm::create(['college_id' => $collegeA->id, 'academic_year_id' => $yearA->id, 'name' => 'Sem A', 'code' => 'SA', 'type' => 'semester', 'sequence' => 1, 'status' => 'active']);

        $adminA = $this->makeUserWithPermissions($collegeA, ['examinations.view', 'examinations.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('examinations.store'), [
                'college_id' => $collegeB->id, // Attempt to spoof college
                'name' => 'Spoof Attempt Exam',
                'code' => 'SPOOF-01',
                'academic_year_id' => $yearA->id,
                'academic_term_id' => $termA->id,
                'exam_type' => 'Internal',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-10',
                'status' => 'draft',
            ], ['Referer' => route('examinations.index')])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('examinations', [
            'college_id' => $collegeA->id,
            'code' => 'SPOOF-01',
        ]);
        $this->assertDatabaseMissing('examinations', [
            'college_id' => $collegeB->id,
            'code' => 'SPOOF-01',
        ]);
    }

    public function test_different_colleges_can_use_identical_examination_code(): void
    {
        $collegeA = $this->makeCollege('EXT8A');
        $collegeB = $this->makeCollege('EXT8B');

        $yearA = AcademicYear::create(['college_id' => $collegeA->id, 'name' => '2026-2027 A', 'code' => 'AY-A', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $termA = AcademicTerm::create(['college_id' => $collegeA->id, 'academic_year_id' => $yearA->id, 'name' => 'Sem A', 'code' => 'SA', 'type' => 'semester', 'sequence' => 1, 'status' => 'active']);

        $yearB = AcademicYear::create(['college_id' => $collegeB->id, 'name' => '2026-2027 B', 'code' => 'AY-B', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'status' => 'active']);
        $termB = AcademicTerm::create(['college_id' => $collegeB->id, 'academic_year_id' => $yearB->id, 'name' => 'Sem B', 'code' => 'SB', 'type' => 'semester', 'sequence' => 1, 'status' => 'active']);

        Examination::create([
            'college_id' => $collegeA->id,
            'academic_year_id' => $yearA->id,
            'academic_term_id' => $termA->id,
            'name' => 'Exam A',
            'code' => 'COMMON-CODE',
            'exam_type' => 'Internal',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-05',
            'status' => 'draft',
        ]);

        $adminB = $this->makeUserWithPermissions($collegeB, ['examinations.view', 'examinations.create']);

        $this->asCollege($collegeB, $adminB)
            ->post(route('examinations.store'), [
                'name' => 'Exam B with Same Code',
                'code' => 'COMMON-CODE',
                'academic_year_id' => $yearB->id,
                'academic_term_id' => $termB->id,
                'exam_type' => 'Internal',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-05',
                'status' => 'draft',
            ], ['Referer' => route('examinations.index')])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Examination::withoutGlobalScopes()->where('college_id', $collegeA->id)->where('code', 'COMMON-CODE')->count());
        $this->assertSame(1, Examination::withoutGlobalScopes()->where('college_id', $collegeB->id)->where('code', 'COMMON-CODE')->count());
    }
}
