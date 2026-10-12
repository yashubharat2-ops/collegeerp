<?php

namespace Tests\Feature\Students;

use App\Models\StudentEnrollment;
use App\Models\StudentPromotion;
use Tests\TestCase;

/**
 * The option lists behind the promotion create form (audit finding C-5).
 *
 * The rule under test is deliberately narrow: the form must not offer a record
 * that `PromoteStudent::assertPromotable()` would refuse, and must keep offering
 * every record that guard accepts. That guard rejects exactly a `withdrawn`
 * student, a `withdrawn` source enrollment and a student with an issued transfer
 * certificate — nothing else — so these tests assert that ONLY `withdrawn` is
 * filtered. Filtering anything more would encode an eligibility policy that has
 * not been decided.
 *
 * Filtering the dropdown is a convenience, never the enforcement. The last test
 * pins that: posting a withdrawn student directly is still refused server-side.
 */
class StudentPromotionOptionsTest extends TestCase
{
    use StudentTestHelpers;

    public function test_the_create_form_does_not_offer_a_withdrawn_student(): void
    {
        $college = $this->makeCollege('PRMOPTW');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $active = $this->makeStudent($college, ['student_number' => 'STU-OPT-ACT']);
        $withdrawn = $this->makeStudent($college, ['student_number' => 'STU-OPT-WDR', 'status' => 'withdrawn']);

        $this->asCollege($college, $admin)
            ->get(route('student-promotions.create'))
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->pluck('id')->all() === [$active->id]);

        $this->assertNotContains($withdrawn->id, $this->renderedStudentIds($college, $admin));
    }

    public function test_the_create_form_still_offers_every_student_status_the_action_accepts(): void
    {
        $college = $this->makeCollege('PRMOPTST');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);

        // `graduated`, `inactive` and `suspended` are NOT rejected by
        // assertPromotable(). Whether they *should* be is an open institutional
        // decision, so the form must keep offering them — filtering them here
        // would silently decide it.
        $ids = [];
        foreach (['active', 'graduated', 'inactive', 'suspended'] as $status) {
            $ids[] = $this->makeStudent($college, [
                'student_number' => 'STU-OPT-'.strtoupper(substr($status, 0, 3)),
                'status' => $status,
            ])->id;
        }

        $offered = $this->renderedStudentIds($college, $admin);
        sort($ids);
        sort($offered);

        $this->assertSame($ids, $offered);
    }

    public function test_the_create_form_does_not_offer_a_withdrawn_source_enrollment(): void
    {
        $college = $this->makeCollege('PRMOPTEW');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);

        $usable = $this->makeEnrollment($college, $student, $year, $program);
        $withdrawn = $this->makeEnrollment($college, $student, $year, $program, ['status' => 'withdrawn']);

        $this->asCollege($college, $admin)
            ->get(route('student-promotions.create'))
            ->assertOk()
            ->assertViewHas('enrollments', fn ($enrollments) => $enrollments->pluck('id')->all() === [$usable->id]);

        $this->assertNotContains($withdrawn->id, $this->renderedEnrollmentIds($college, $admin));
    }

    public function test_the_create_form_still_offers_completed_and_cancelled_sources(): void
    {
        $college = $this->makeCollege('PRMOPTES');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);

        // A `completed` or `cancelled` source is accepted by the action
        // (re-admission). Only `withdrawn` is refused.
        $ids = [];
        foreach (['active', 'completed', 'cancelled'] as $status) {
            $ids[] = $this->makeEnrollment($college, $student, $year, $program, ['status' => $status])->id;
        }

        $offered = $this->renderedEnrollmentIds($college, $admin);
        sort($ids);
        sort($offered);

        $this->assertSame($ids, $offered);
    }

    public function test_the_index_filter_still_offers_a_withdrawn_student_so_their_promotions_stay_searchable(): void
    {
        $college = $this->makeCollege('PRMOPTIX');
        $viewer = $this->makeUserWithPermissions($college, ['student_promotions.view']);
        $withdrawn = $this->makeStudent($college, ['student_number' => 'STU-OPT-IX', 'status' => 'withdrawn']);

        // The index searches promotions that ALREADY exist. A promotion may
        // belong to a student who withdrew afterwards, so the filter must keep
        // offering them or those promotions become unsearchable.
        $this->asCollege($college, $viewer)
            ->get(route('student-promotions.index'))
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->pluck('id')->all() === [$withdrawn->id]);
    }

    public function test_the_option_lists_are_scoped_to_the_active_college(): void
    {
        $college = $this->makeCollege('PRMOPTTA');
        $other = $this->makeCollege('PRMOPTTB');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college);

        $mine = $this->makeStudent($college, ['student_number' => 'STU-OPT-MINE']);
        $myEnrollment = $this->makeEnrollment($college, $mine, $year, $program);

        // A withdrawn student in ANOTHER college must not leak into the list
        // either by being filtered out silently or by being shown.
        $foreignStudent = $this->makeStudent($other, ['student_number' => 'STU-OPT-FOREIGN']);
        $foreignYear = $this->makeYear($other, '2026', '2026-27 (Other)');
        $foreignEnrollment = $this->makeEnrollment($other, $foreignStudent, $foreignYear);

        $this->asCollege($college, $admin)
            ->get(route('student-promotions.create'))
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->pluck('id')->all() === [$mine->id])
            ->assertViewHas('enrollments', fn ($enrollments) => $enrollments->pluck('id')->all() === [$myEnrollment->id]);

        $this->assertNotContains($foreignStudent->id, $this->renderedStudentIds($college, $admin));
        $this->assertNotContains($foreignEnrollment->id, $this->renderedEnrollmentIds($college, $admin));
    }

    public function test_hiding_an_option_is_not_the_enforcement_and_a_withdrawn_student_is_still_refused(): void
    {
        $college = $this->makeCollege('PRMOPTSV');
        $admin = $this->makeUserWithPermissions($college, ['student_promotions.create']);
        $year = $this->makeYear($college, '2026');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);

        // Not offered by the form, but still postable by a crafted request.
        $withdrawn = $this->makeStudent($college, ['student_number' => 'STU-OPT-SRV', 'status' => 'withdrawn']);
        $source = $this->makeEnrollment($college, $withdrawn, $year, $program);

        $this->asCollege($college, $admin)
            ->post(route('student-promotions.store'), [
                'student_id' => $withdrawn->id,
                'source_enrollment_id' => $source->id,
                'target_academic_year_id' => $nextYear->id,
                'target_program_id' => $program->id,
            ])
            ->assertSessionHasErrors([
                'student_id' => 'A withdrawn student cannot receive a new active enrollment through promotion.',
            ]);

        $this->assertSame(0, StudentPromotion::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame('active', $source->fresh()->status);
    }

    private function renderedStudentIds($college, $admin): array
    {
        $students = $this->asCollege($college, $admin)
            ->get(route('student-promotions.create'))
            ->viewData('students');

        return $students->pluck('id')->all();
    }

    private function renderedEnrollmentIds($college, $admin): array
    {
        $enrollments = $this->asCollege($college, $admin)
            ->get(route('student-promotions.create'))
            ->viewData('enrollments');

        $this->assertInstanceOf(StudentEnrollment::class, $enrollments->first());

        return $enrollments->pluck('id')->all();
    }
}
