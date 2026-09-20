<?php

namespace Tests\Feature\Examinations;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Examination;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class ExaminationAuthorizationTest extends TestCase
{
    use DepartmentTestHelpers;

    /**
     * Requirement 2: unauthorized user cannot view
     */
    public function test_unauthorized_user_cannot_view_examinations(): void
    {
        $college = $this->makeCollege('EXAU');
        $userWithoutPerm = $this->makeUserWithPermissions($college, []);

        $this->asCollege($college, $userWithoutPerm)
            ->get(route('examinations.index'))
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_create_examination(): void
    {
        $college = $this->makeCollege('EXAC');
        $viewerOnly = $this->makeUserWithPermissions($college, ['examinations.view']);

        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $term = AcademicTerm::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => 'Sem 1',
            'code' => 'SEM1',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $this->asCollege($college, $viewerOnly)
            ->get(route('examinations.create'))
            ->assertForbidden();

        $this->asCollege($college, $viewerOnly)
            ->post(route('examinations.store'), [
                'name' => 'Unauthorized Exam',
                'code' => 'UNAUTH-01',
                'academic_year_id' => $year->id,
                'academic_term_id' => $term->id,
                'exam_type' => 'Internal',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-10',
                'status' => 'draft',
            ])
            ->assertForbidden();

        $this->assertSame(0, Examination::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_completed_examination_cannot_be_deleted_by_regular_admin(): void
    {
        $college = $this->makeCollege('EXCD');
        $admin = $this->makeUserWithPermissions($college, ['examinations.view', 'examinations.update', 'examinations.delete']);

        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $term = AcademicTerm::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => 'Sem 1',
            'code' => 'SEM1',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $exam = Examination::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'name' => 'Completed Exam',
            'code' => 'COMP-EXAM',
            'exam_type' => 'Internal',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-10',
            'status' => Examination::STATUS_COMPLETED,
        ]);

        // Regular admin delete blocked
        $this->asCollege($college, $admin)
            ->delete(route('examinations.destroy', $exam))
            ->assertForbidden();

        $this->assertNull($exam->fresh()->deleted_at);

        // Super Admin can delete completed examination
        $superAdmin = $this->makeUserWithPermissions($college, ['examinations.view', 'examinations.delete'], true);
        $this->asCollege($college, $superAdmin)
            ->delete(route('examinations.destroy', $exam))
            ->assertRedirect(route('examinations.index'));

        $this->assertSoftDeleted('examinations', ['id' => $exam->id]);
    }
}
