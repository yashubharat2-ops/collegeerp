<?php

namespace Tests\Feature\Platform;

use App\Models\AcademicYear;
use App\Models\College;
use App\Models\StudentPromotion;
use Tests\Feature\Students\StudentTestHelpers;
use Tests\TestCase;

/**
 * Academic Year update workflow (Phase E2.4, fixing audit finding C-2).
 *
 * Before this, `AcademicYearController` had only index() and store(), so a
 * year's status could be set exactly once — at creation. The policy already
 * declared update() and `academic-years.update` was already seeded, but no
 * route, request or view existed, so the capability was unreachable.
 *
 * The rules under test:
 * - both existing statuses (`active`, `inactive`) remain the only values;
 * - activating a year deactivates every other active year for that college,
 *   the invariant store() already maintains;
 * - deactivating the last active year is ALLOWED, because a college with zero
 *   active years is already legal (the column defaults to `inactive` and both
 *   readers of the active year render an explicit fallback);
 * - the creation invariants still hold on update: ends_on > starts_on, no date
 *   overlap, unique code per college — each excluding the year being edited;
 * - a status change touches nothing else.
 */
class AcademicYearUpdateTest extends TestCase
{
    use StudentTestHelpers;

    private function makeAcademicYear(College $college, string $code, string $startsOn, string $endsOn, string $status = 'inactive'): AcademicYear
    {
        return AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => $code,
            'code' => $code,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => $status,
        ]);
    }

    private function payload(AcademicYear $year, array $overrides = []): array
    {
        return array_merge([
            'name' => $year->name,
            'code' => $year->code,
            'starts_on' => $year->starts_on->toDateString(),
            'ends_on' => $year->ends_on->toDateString(),
            'status' => $year->status,
        ], $overrides);
    }

    // ---------------------------------------------------------------- happy path

    public function test_an_inactive_academic_year_can_be_activated_and_deactivates_the_previous_one(): void
    {
        $college = $this->makeCollege('AYUPD01');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);

        $current = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31', 'active');
        $next = $this->makeAcademicYear($college, '2027-28', '2027-06-01', '2028-05-31', 'inactive');

        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $next), $this->payload($next, ['status' => 'active']))
            ->assertRedirect(route('academic-years.index'))
            ->assertSessionHas('success');

        // The single-active-year invariant store() maintains must hold here too.
        $this->assertSame('active', $next->fresh()->status);
        $this->assertSame('inactive', $current->fresh()->status);
        $this->assertSame(1, AcademicYear::withoutGlobalScopes()
            ->where('college_id', $college->id)->where('status', 'active')->count());
    }

    public function test_a_year_created_with_the_inactive_default_can_later_be_activated(): void
    {
        $college = $this->makeCollege('AYUPD02');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);

        // The column default is `inactive`, so before this workflow such a year
        // could never become active at all.
        $year = $this->makeAcademicYear($college, '2028-29', '2028-06-01', '2029-05-31');
        $this->assertSame('inactive', $year->status);

        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $year), $this->payload($year, ['status' => 'active']))
            ->assertSessionHas('success');

        $this->assertSame('active', $year->fresh()->status);
    }

    public function test_a_superseded_year_can_be_reactivated(): void
    {
        $college = $this->makeCollege('AYUPD03');
        $admin = $this->makeUserWithPermissions($college, [
            'academic-years.view', 'academic-years.create', 'academic-years.update',
        ]);

        $old = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31', 'active');

        // Supersede the older year by creating the newer one active. This has to
        // go through the POST route: the single-active-year rule runs in
        // store() and in update(), never on the model, so creating the second
        // year directly with status 'active' would leave both years active and
        // the precondition below would be fiction.
        $this->asCollege($college, $admin)
            ->post(route('academic-years.store'), [
                'name' => '2027-28',
                'code' => '2027-28',
                'starts_on' => '2027-06-01',
                'ends_on' => '2028-05-31',
                'status' => 'active',
            ])
            ->assertSessionHas('success');

        $new = AcademicYear::withoutGlobalScopes()
            ->where('college_id', $college->id)->where('code', '2027-28')->firstOrFail();

        $this->assertSame('inactive', $old->fresh()->status);
        $this->assertSame('active', $new->status);

        // Reversing that decision must be possible, and must hand the active
        // slot back rather than leaving two years active.
        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $old), $this->payload($old, ['status' => 'active']))
            ->assertSessionHas('success');

        $this->assertSame('active', $old->fresh()->status);
        $this->assertSame('inactive', $new->fresh()->status);
        $this->assertSame(1, AcademicYear::withoutGlobalScopes()
            ->where('college_id', $college->id)->where('status', 'active')->count());
    }

    /**
     * Regression guard for the precondition the reactivation test above relies
     * on. The single-active-year rule on insert lives in store(), and nothing
     * previously asserted that it actually fires when a second active year is
     * created — AcademicYearConstraintTest only covers store()'s overlap
     * rejection.
     */
    public function test_creating_a_new_active_year_deactivates_the_previous_one(): void
    {
        $college = $this->makeCollege('AYUPD24');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.create']);

        $first = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31', 'active');

        $this->asCollege($college, $admin)
            ->post(route('academic-years.store'), [
                'name' => '2027-28',
                'code' => '2027-28',
                'starts_on' => '2027-06-01',
                'ends_on' => '2028-05-31',
                'status' => 'active',
            ])
            ->assertSessionHas('success');

        $this->assertSame('inactive', $first->fresh()->status);
        $this->assertSame(1, AcademicYear::withoutGlobalScopes()
            ->where('college_id', $college->id)->where('status', 'active')->count());
    }

    /**
     * Documents where the single-active-year rule is and is not enforced, so
     * the split is a recorded decision rather than an accident.
     *
     * It runs on the two HTTP write paths (store() and update()) and nowhere
     * else: there is no observer, no model hook and no unique index behind it.
     * That is load-bearing — StudentTestHelpers::makeYear() and makeNextYear()
     * both create active years straight through the model, and twelve test
     * files pair them for one college as fixtures. Pushing the rule down into
     * the model would silently rewrite those fixtures' data and is a new
     * business rule, not a bug fix.
     */
    public function test_the_single_active_year_rule_is_enforced_on_write_paths_not_on_the_model(): void
    {
        $college = $this->makeCollege('AYUPD25');

        $first = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31', 'active');
        $second = $this->makeAcademicYear($college, '2027-28', '2027-06-01', '2028-05-31', 'active');

        // A direct model write does not deactivate the other year...
        $this->assertSame('active', $first->fresh()->status);
        $this->assertSame('active', $second->fresh()->status);

        // ...but the next write through the controller brings it back to one.
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);

        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $first), $this->payload($first, ['status' => 'active']))
            ->assertSessionHas('success');

        $this->assertSame('active', $first->fresh()->status);
        $this->assertSame('inactive', $second->fresh()->status);
        $this->assertSame(1, AcademicYear::withoutGlobalScopes()
            ->where('college_id', $college->id)->where('status', 'active')->count());
    }

    public function test_the_last_active_year_can_be_deactivated(): void
    {
        $college = $this->makeCollege('AYUPD04');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);

        $only = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31', 'active');

        // Deliberately allowed: zero active years is already a legal state, so
        // refusing this would introduce an invariant the schema and the two
        // readers of the active year do not have.
        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $only), $this->payload($only, ['status' => 'inactive']))
            ->assertSessionHas('success');

        $this->assertSame('inactive', $only->fresh()->status);
        $this->assertSame(0, AcademicYear::withoutGlobalScopes()
            ->where('college_id', $college->id)->where('status', 'active')->count());
    }

    public function test_name_code_and_dates_can_be_updated_and_the_change_is_audited(): void
    {
        $college = $this->makeCollege('AYUPD05');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);

        $year = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31');

        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $year), $this->payload($year, [
                'name' => '2026–2027 (Revised)',
                'code' => 'AY26R',
                'starts_on' => '2026-07-01',
                'ends_on' => '2027-06-30',
            ]))
            ->assertSessionHas('success');

        $fresh = $year->fresh();
        $this->assertSame('2026–2027 (Revised)', $fresh->name);
        $this->assertSame('AY26R', $fresh->code);
        $this->assertSame('2026-07-01', $fresh->starts_on->toDateString());
        $this->assertSame('2027-06-30', $fresh->ends_on->toDateString());
        $this->assertSame($admin->id, $fresh->updated_by);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'academic_year.updated',
            'subject_id' => $year->id,
            'college_id' => $college->id,
        ]);
    }

    public function test_the_edit_form_is_reachable_and_shows_the_current_values(): void
    {
        $college = $this->makeCollege('AYUPD06');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);
        $year = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31', 'active');

        $this->asCollege($college, $admin)
            ->get(route('academic-years.edit', $year))
            ->assertOk()
            ->assertViewHas('academicYear', fn ($model) => $model->id === $year->id)
            ->assertSee('2026-27');
    }

    // ------------------------------------------------------------- invalid input

    public function test_an_unknown_status_value_is_rejected(): void
    {
        $college = $this->makeCollege('AYUPD07');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);
        $year = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31');

        // No new status is invented: only the two the column already uses.
        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $year), $this->payload($year, ['status' => 'archived']))
            ->assertSessionHasErrors('status');

        $this->assertSame('inactive', $year->fresh()->status);
    }

    public function test_a_missing_status_is_rejected(): void
    {
        $college = $this->makeCollege('AYUPD08');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);
        $year = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31');

        $payload = $this->payload($year);
        unset($payload['status']);

        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $year), $payload)
            ->assertSessionHasErrors('status');
    }

    public function test_ends_on_before_starts_on_is_rejected(): void
    {
        $college = $this->makeCollege('AYUPD09');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);
        $year = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31');

        // Creation's date-range rule must survive on update.
        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $year), $this->payload($year, [
                'starts_on' => '2027-05-31',
                'ends_on' => '2026-06-01',
            ]))
            ->assertSessionHasErrors('ends_on');

        $this->assertSame('2026-06-01', $year->fresh()->starts_on->toDateString());
    }

    public function test_a_code_used_by_another_year_is_rejected(): void
    {
        $college = $this->makeCollege('AYUPD10');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);

        $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31');
        $other = $this->makeAcademicYear($college, '2027-28', '2027-06-01', '2028-05-31');

        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $other), $this->payload($other, ['code' => '2026-27']))
            ->assertSessionHasErrors('code');

        $this->assertSame('2027-28', $other->fresh()->code);
    }

    public function test_a_year_may_keep_its_own_code_and_dates(): void
    {
        $college = $this->makeCollege('AYUPD11');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);
        $year = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31');

        // The uniqueness and overlap checks must exclude the year being edited,
        // or saving any year unchanged would always fail.
        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $year), $this->payload($year, ['name' => 'Same year, new name']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('Same year, new name', $year->fresh()->name);
        $this->assertSame('2026-27', $year->fresh()->code);
    }

    public function test_moving_a_year_onto_another_years_dates_is_rejected(): void
    {
        $college = $this->makeCollege('AYUPD12');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);

        $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31');
        $other = $this->makeAcademicYear($college, '2027-28', '2027-06-01', '2028-05-31');

        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $other), $this->payload($other, [
                'starts_on' => '2026-08-01',
                'ends_on' => '2027-04-30',
            ]))
            ->assertSessionHasErrors('starts_on');

        $this->assertSame('2027-06-01', $other->fresh()->starts_on->toDateString());
    }

    // ------------------------------------------------------------- authorization

    public function test_a_user_without_the_update_permission_cannot_edit_or_update(): void
    {
        $college = $this->makeCollege('AYUPD13');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);
        $viewer = $this->makeUserWithPermissions($college, ['academic-years.view']);
        $year = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31');

        $this->asCollege($college, $admin)
            ->get(route('academic-years.index'))
            ->assertOk()
            ->assertSee('Edit')
            ->assertSee(route('academic-years.edit', $year), false);

        // The affordance must be gated by the permission, not merely hidden.
        $this->asCollege($college, $viewer)
            ->get(route('academic-years.index'))
            ->assertOk()
            ->assertDontSee(route('academic-years.edit', $year), false);

        $this->asCollege($college, $viewer)->get(route('academic-years.edit', $year))->assertForbidden();
        $this->asCollege($college, $viewer)
            ->put(route('academic-years.update', $year), $this->payload($year, ['status' => 'active']))
            ->assertForbidden();

        $this->assertSame('inactive', $year->fresh()->status);
    }

    public function test_a_user_with_create_but_not_update_cannot_update(): void
    {
        $college = $this->makeCollege('AYUPD14');
        $creator = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.create']);
        $year = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31');

        $this->asCollege($college, $creator)
            ->put(route('academic-years.update', $year), $this->payload($year, ['status' => 'active']))
            ->assertForbidden();

        $this->assertSame('inactive', $year->fresh()->status);
    }

    public function test_an_unauthenticated_user_cannot_update(): void
    {
        $college = $this->makeCollege('AYUPD15');
        $year = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31');

        $this->put(route('academic-years.update', $year), $this->payload($year, ['status' => 'active']))
            ->assertRedirect(route('login'));

        $this->assertSame('inactive', $year->fresh()->status);
    }

    // ------------------------------------------------------------- tenant isolation

    public function test_a_foreign_colleges_academic_year_cannot_be_edited_or_updated(): void
    {
        $college = $this->makeCollege('AYUPD16');
        $other = $this->makeCollege('AYUPD17');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);

        $foreign = $this->makeAcademicYear($other, '2026-27', '2026-06-01', '2027-05-31', 'inactive');

        $this->asCollege($college, $admin)->get(route('academic-years.edit', $foreign))->assertNotFound();
        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $foreign), $this->payload($foreign, ['status' => 'active']))
            ->assertNotFound();

        $this->assertSame('inactive', $foreign->fresh()->status);
    }

    public function test_activating_a_year_does_not_deactivate_another_colleges_active_year(): void
    {
        $college = $this->makeCollege('AYUPD18');
        $other = $this->makeCollege('AYUPD19');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);

        $mine = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31');
        $theirs = $this->makeAcademicYear($other, '2026-27', '2026-06-01', '2027-05-31', 'active');

        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $mine), $this->payload($mine, ['status' => 'active']))
            ->assertSessionHas('success');

        $this->assertSame('active', $mine->fresh()->status);
        $this->assertSame('active', $theirs->fresh()->status, 'The deactivation must be scoped to one college.');
    }

    public function test_the_overlap_check_ignores_another_colleges_years(): void
    {
        $college = $this->makeCollege('AYUPD20');
        $other = $this->makeCollege('AYUPD21');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);

        // Same dates, different college: must not be treated as an overlap.
        $this->makeAcademicYear($other, '2026-27', '2026-06-01', '2027-05-31', 'active');
        $mine = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31');

        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $mine), $this->payload($mine, ['status' => 'active']))
            ->assertSessionHasNoErrors();
    }

    // ----------------------------------------------------------- no side effects

    public function test_changing_status_leaves_enrollments_and_promotions_untouched(): void
    {
        $college = $this->makeCollege('AYUPD22');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);

        $year = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31', 'active');
        $program = $this->makeProgram($college);
        $student = $this->makeStudent($college);
        $enrollment = $this->makeEnrollment($college, $student, $year, $program);

        $promotion = StudentPromotion::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'source_enrollment_id' => $enrollment->id,
            'source_academic_year_id' => $year->id,
            'target_academic_year_id' => $year->id,
            'status' => 'pending',
        ]);

        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $year), $this->payload($year, ['status' => 'inactive']))
            ->assertSessionHas('success');

        // Nothing outside the academic_years row and its audit entry may change:
        // enrollment, promotion and student all reference the year by id, and no
        // write path branches on its status.
        $this->assertSame('inactive', $year->fresh()->status);
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertSame('active', $student->fresh()->status);
        $this->assertSame('pending', $promotion->fresh()->status);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'student_enrollment.updated',
            'subject_id' => $enrollment->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'student_promotion.approved',
            'subject_id' => $promotion->id,
        ]);
    }

    public function test_a_rejected_update_changes_nothing(): void
    {
        $college = $this->makeCollege('AYUPD23');
        $admin = $this->makeUserWithPermissions($college, ['academic-years.view', 'academic-years.update']);

        $year = $this->makeAcademicYear($college, '2026-27', '2026-06-01', '2027-05-31', 'active');
        $other = $this->makeAcademicYear($college, '2027-28', '2027-06-01', '2028-05-31', 'inactive');

        // Overlapping dates AND a status flip: the whole request must be refused,
        // so the status must not have changed either.
        $this->asCollege($college, $admin)
            ->put(route('academic-years.update', $other), $this->payload($other, [
                'starts_on' => '2026-08-01',
                'ends_on' => '2027-04-30',
                'status' => 'active',
            ]))
            ->assertSessionHasErrors('starts_on');

        $this->assertSame('inactive', $other->fresh()->status);
        $this->assertSame('active', $year->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'academic_year.updated',
            'subject_id' => $other->id,
        ]);
    }
}
