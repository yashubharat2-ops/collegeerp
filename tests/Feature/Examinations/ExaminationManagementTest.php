<?php

namespace Tests\Feature\Examinations;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Examination;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class ExaminationManagementTest extends TestCase
{
    use DepartmentTestHelpers;

    /**
     * Requirement 1: tenant can view examinations
     */
    public function test_tenant_can_view_examinations(): void
    {
        $college = $this->makeCollege('EXM1');
        $admin = $this->makeUserWithPermissions($college, ['examinations.view']);

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
            'name' => 'Semester 1',
            'code' => 'SEM1',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);

        Examination::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'name' => 'Mid Term 2026',
            'code' => 'EXAM-MID-2026',
            'exam_type' => 'Mid Term',
            'start_date' => '2026-10-15',
            'end_date' => '2026-10-25',
            'status' => 'draft',
        ]);

        $this->asCollege($college, $admin)
            ->get(route('examinations.index'))
            ->assertOk()
            ->assertSee('Mid Term 2026')
            ->assertSee('EXAM-MID-2026');
    }

    /**
     * Requirement 3: can create valid examination
     */
    public function test_can_create_valid_examination(): void
    {
        $college = $this->makeCollege('EXM3');
        $admin = $this->makeUserWithPermissions($college, ['examinations.view', 'examinations.create']);

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
            'name' => 'Semester 1',
            'code' => 'SEM1',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('examinations.store'), [
                'name' => 'Final Exam 2026',
                'code' => 'EXAM-FIN-2026',
                'academic_year_id' => $year->id,
                'academic_term_id' => $term->id,
                'exam_type' => 'Semester',
                'start_date' => '2026-12-01',
                'end_date' => '2026-12-15',
                'status' => 'draft',
                'description' => 'End of term exams',
            ], ['Referer' => route('examinations.index')])
            ->assertRedirect(route('examinations.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('examinations', [
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'name' => 'Final Exam 2026',
            'code' => 'EXAM-FIN-2026',
            'exam_type' => 'Semester',
            'status' => 'draft',
        ]);
    }

    /**
     * Requirement 6: academic term must belong to selected academic year
     */
    public function test_academic_term_must_belong_to_selected_academic_year(): void
    {
        $college = $this->makeCollege('EXM6');
        $admin = $this->makeUserWithPermissions($college, ['examinations.view', 'examinations.create']);

        $year1 = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2025-2026',
            'code' => 'AY-2025',
            'starts_on' => '2025-08-01',
            'ends_on' => '2026-05-31',
            'status' => 'active',
        ]);
        $year2 = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        // Term belongs to year 1
        $termOf1 = AcademicTerm::create([
            'college_id' => $college->id,
            'academic_year_id' => $year1->id,
            'name' => 'Semester 1 (2025)',
            'code' => 'SEM1-25',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);

        // Attempt to create exam with year 2 but term of year 1
        $this->asCollege($college, $admin)
            ->post(route('examinations.store'), [
                'name' => 'Invalid Context Exam',
                'code' => 'INV-EXAM',
                'academic_year_id' => $year2->id,
                'academic_term_id' => $termOf1->id,
                'exam_type' => 'Internal',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-10',
                'status' => 'draft',
            ], ['Referer' => route('examinations.index')])
            ->assertSessionHasErrors('academic_term_id');

        $this->assertSame(0, Examination::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    /**
     * Requirement 7: duplicate college+code is rejected
     */
    public function test_duplicate_college_and_code_is_rejected(): void
    {
        $college = $this->makeCollege('EXM7');
        $admin = $this->makeUserWithPermissions($college, ['examinations.view', 'examinations.create']);

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
            'name' => 'Semester 1',
            'code' => 'SEM1',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);

        Examination::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'name' => 'First Exam',
            'code' => 'DUP-CODE',
            'exam_type' => 'Internal',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-10',
            'status' => 'draft',
        ]);

        $this->asCollege($college, $admin)
            ->post(route('examinations.store'), [
                'name' => 'Second Exam Same Code',
                'code' => 'DUP-CODE',
                'academic_year_id' => $year->id,
                'academic_term_id' => $term->id,
                'exam_type' => 'Internal',
                'start_date' => '2026-09-15',
                'end_date' => '2026-09-20',
                'status' => 'draft',
            ], ['Referer' => route('examinations.index')])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Examination::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    /**
     * Requirement 8: update works
     */
    public function test_update_works(): void
    {
        $college = $this->makeCollege('EXM8');
        $admin = $this->makeUserWithPermissions($college, ['examinations.view', 'examinations.update']);

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
            'name' => 'Semester 1',
            'code' => 'SEM1',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $exam = Examination::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'name' => 'Original Name',
            'code' => 'EXAM-UPD',
            'exam_type' => 'Internal',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-10',
            'status' => 'draft',
        ]);

        $this->asCollege($college, $admin)
            ->put(route('examinations.update', $exam), [
                'name' => 'Updated Name',
                'code' => 'EXAM-UPD',
                'academic_year_id' => $year->id,
                'academic_term_id' => $term->id,
                'exam_type' => 'Mid Term',
                'start_date' => '2026-10-05',
                'end_date' => '2026-10-15',
                'status' => 'published',
                'description' => 'Updated description',
            ], ['Referer' => route('examinations.edit', $exam)])
            ->assertRedirect(route('examinations.index'))
            ->assertSessionHas('success');

        $exam->refresh();
        $this->assertSame('Updated Name', $exam->name);
        $this->assertSame('Mid Term', $exam->exam_type);
        $this->assertSame('published', $exam->status);
        $this->assertSame('Updated description', $exam->description);
    }

    /**
     * Requirement 9: delete works
     */
    public function test_delete_works(): void
    {
        $college = $this->makeCollege('EXM9');
        $admin = $this->makeUserWithPermissions($college, ['examinations.view', 'examinations.delete']);

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
            'name' => 'Semester 1',
            'code' => 'SEM1',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $exam = Examination::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'name' => 'To Delete',
            'code' => 'EXAM-DEL',
            'exam_type' => 'Internal',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-10',
            'status' => 'draft',
        ]);

        $this->asCollege($college, $admin)
            ->delete(route('examinations.destroy', $exam), [], ['Referer' => route('examinations.index')])
            ->assertRedirect(route('examinations.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('examinations', ['id' => $exam->id]);
    }

    /**
     * Requirement 11: search/filter works
     */
    public function test_search_and_filters_work(): void
    {
        $college = $this->makeCollege('EX11');
        $admin = $this->makeUserWithPermissions($college, ['examinations.view']);

        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => '2026-2027',
            'code' => 'AY-2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
        $term1 = AcademicTerm::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => 'Sem 1',
            'code' => 'SEM1',
            'type' => 'semester',
            'sequence' => 1,
            'status' => 'active',
        ]);
        $term2 = AcademicTerm::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => 'Sem 2',
            'code' => 'SEM2',
            'type' => 'semester',
            'sequence' => 2,
            'status' => 'active',
        ]);

        $exam1 = Examination::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term1->id,
            'name' => 'Biology Finals',
            'code' => 'BIO-FIN',
            'exam_type' => 'Semester',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-10',
            'status' => 'published',
        ]);

        $exam2 = Examination::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term2->id,
            'name' => 'Chemistry Practical',
            'code' => 'CHEM-PRAC',
            'exam_type' => 'Practical',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-10',
            'status' => 'draft',
        ]);

        // Search test
        $this->asCollege($college, $admin)
            ->get(route('examinations.index', ['search' => 'Biology']))
            ->assertSee('Biology Finals')
            ->assertDontSee('Chemistry Practical');

        // Status filter test
        $this->asCollege($college, $admin)
            ->get(route('examinations.index', ['status' => 'draft']))
            ->assertSee('Chemistry Practical')
            ->assertDontSee('Biology Finals');

        // Academic term filter test
        $this->asCollege($college, $admin)
            ->get(route('examinations.index', ['academic_term_id' => $term1->id]))
            ->assertSee('Biology Finals')
            ->assertDontSee('Chemistry Practical');
    }

    /**
     * Requirement 12: deterministic pagination
     */
    public function test_deterministic_pagination(): void
    {
        $college = $this->makeCollege('EX12');
        $admin = $this->makeUserWithPermissions($college, ['examinations.view']);

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

        for ($i = 1; $i <= 20; $i++) {
            Examination::create([
                'college_id' => $college->id,
                'academic_year_id' => $year->id,
                'academic_term_id' => $term->id,
                'name' => "Exam Entry {$i}",
                'code' => sprintf('EX-%03d', $i),
                'exam_type' => 'Internal',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-05',
                'status' => 'draft',
            ]);
        }

        $res1 = $this->asCollege($college, $admin)->get(route('examinations.index'));
        $res1->assertOk();
        $this->assertSame(20, $res1->viewData('examinations')->total());
        $this->assertSame(15, $res1->viewData('examinations')->count());

        $res2 = $this->asCollege($college, $admin)->get(route('examinations.index', ['page' => 2]));
        $res2->assertOk();
        $this->assertSame(5, $res2->viewData('examinations')->count());
    }

    public function test_audit_logs_record_examination_lifecycle(): void
    {
        $college = $this->makeCollege('EXMA');
        $admin = $this->makeUserWithPermissions($college, ['examinations.view', 'examinations.create', 'examinations.update', 'examinations.delete']);

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

        $this->asCollege($college, $admin)->post(route('examinations.store'), [
            'name' => 'Audited Exam',
            'code' => 'AUD-EXAM',
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'exam_type' => 'Internal',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-10',
            'status' => 'draft',
        ], ['Referer' => route('examinations.index')])->assertSessionHasNoErrors();

        $exam = Examination::withoutGlobalScopes()->where('code', 'AUD-EXAM')->firstOrFail();

        $this->asCollege($college, $admin)->put(route('examinations.update', $exam), [
            'name' => 'Audited Exam Renamed',
            'code' => 'AUD-EXAM',
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'exam_type' => 'Internal',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-10',
            'status' => 'published',
        ], ['Referer' => route('examinations.edit', $exam)])->assertSessionHasNoErrors();

        $this->asCollege($college, $admin)->delete(route('examinations.destroy', $exam), [], ['Referer' => route('examinations.index')]);

        $base = [
            'college_id' => $college->id,
            'user_id' => $admin->id,
            'subject_type' => Examination::class,
            'subject_id' => $exam->id,
        ];

        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'examination.created']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'examination.updated']);
        $this->assertDatabaseHas('audit_logs', $base + ['action' => 'examination.deleted']);
    }
}
