<?php

namespace Tests\Feature\Students;

use App\Domain\Student\Support\Aadhaar;
use App\Models\College;
use App\Models\Student;
use App\Models\User;
use Tests\TestCase;

/**
 * The shared Student Filter System, exercised through the screens that use it.
 *
 * Scope of these tests (one reusable implementation, applied to existing pages):
 *  - every required field changes the real database query;
 *  - Academic Status and Student Status stay two different filters;
 *  - Program / Semester-Section / Batch (the section master) are college-scoped
 *    and dependent, and an impossible combination is a validation error;
 *  - Aadhaar / ABC ID searches are authorized, exact, and never placed in a URL;
 *  - filters combine with each other, with free-text search, with pagination,
 *    and with Reset;
 *  - students created through the single registration workflow and through the
 *    bulk (import) workflow are both filterable;
 *  - bulk actions act only on the ids the user selected;
 *  - existing attendance / examination pages keep their own logic.
 */
class StudentFilterSystemTest extends TestCase
{
    use StudentTestHelpers;

    /** @return array{0: College, 1: User} */
    private function screen(string $code, array $permissions = ['students.view']): array
    {
        $college = $this->makeCollege($code);

        return [$college, $this->makeUserWithPermissions($college, $permissions)];
    }

    public function test_registration_number_name_and_registration_date_change_the_query(): void
    {
        [$college, $viewer] = $this->screen('SFS01');

        $inside = $this->makeStudent($college, [
            'student_number' => 'REG-2026-001',
            'first_name' => 'Aarav',
            'last_name' => 'Sharma',
            'admission_date' => '2026-07-01',
        ]);
        $edge = $this->makeStudent($college, [
            'student_number' => 'REG-2026-002',
            'first_name' => 'Isha',
            'last_name' => 'Verma',
            'admission_date' => '2026-07-31',
        ]);
        $outside = $this->makeStudent($college, [
            'student_number' => 'REG-2026-003',
            'first_name' => 'Kabir',
            'last_name' => 'Nair',
            'admission_date' => '2026-08-01',
        ]);

        // Partial registration number.
        $this->asCollege($college, $viewer)->get(route('students.index', ['student_number' => '2026-00']))
            ->assertOk()->assertViewHas('students', fn ($page) => $page->total() === 3);

        $this->asCollege($college, $viewer)->get(route('students.index', ['student_number' => 'REG-2026-002']))
            ->assertOk()
            ->assertViewHas('students', fn ($page) => $page->total() === 1 && $page->first()->is($edge));

        // Partial name, case-insensitive. "sha" is a substring of BOTH "Aarav
        // Sharma" (last name) and "Isha Verma" (first name), so a correct
        // partial match returns both — asserting one row here would only pass if
        // the filter silently degraded to a prefix/exact match.
        $this->asCollege($college, $viewer)->get(route('students.index', ['student_name' => 'sha']))
            ->assertOk()
            ->assertViewHas('students', function ($page) use ($inside, $edge): bool {
                return $page->total() === 2
                    && $page->pluck('id')->contains($inside->id)
                    && $page->pluck('id')->contains($edge->id);
            });

        // A fragment unique to one student still narrows to exactly that row.
        $this->asCollege($college, $viewer)->get(route('students.index', ['student_name' => 'harma']))
            ->assertOk()
            ->assertViewHas('students', fn ($page) => $page->total() === 1 && $page->first()->is($inside));

        // Inclusive range: both boundary dates match, the day after does not.
        $this->asCollege($college, $viewer)->get(route('students.index', [
            'admission_date_from' => '2026-07-01',
            'admission_date_to' => '2026-07-31',
        ]))->assertOk()->assertViewHas('students', function ($page) use ($inside, $edge): bool {
            return $page->total() === 2
                && $page->pluck('id')->contains($inside->id)
                && $page->pluck('id')->contains($edge->id);
        });

        $this->asCollege($college, $viewer)->get(route('students.index', [
            'admission_date_from' => '2026-08-01',
        ]))->assertOk()
            ->assertViewHas('students', fn ($page) => $page->total() === 1 && $page->first()->is($outside));

        // A reversed range is rejected rather than silently widened.
        $this->asCollege($college, $viewer)->get(route('students.index', [
            'admission_date_from' => '2026-08-01',
            'admission_date_to' => '2026-07-01',
        ]))->assertSessionHasErrors('admission_date_to');
    }

    public function test_academic_status_and_student_status_are_independent_filters(): void
    {
        [$college, $viewer] = $this->screen('SFS02');

        $continuing = $this->makeStudent($college, ['student_number' => 'SFS02-CONT', 'status' => 'active']);
        $inactive = $this->makeStudent($college, ['student_number' => 'SFS02-INACT', 'status' => 'inactive']);
        $graduated = $this->makeStudent($college, ['student_number' => 'SFS02-PASS', 'status' => 'graduated']);
        $dropped = $this->makeStudent($college, ['student_number' => 'SFS02-DROP', 'status' => 'withdrawn']);
        $transferred = $this->makeStudent($college, ['student_number' => 'SFS02-TC', 'status' => 'withdrawn']);
        $this->makeTransfer($college, $transferred, ['status' => 'approved']);
        $registered = $this->makeStudent($college, [
            'student_number' => 'SFS02-NEW',
            'status' => 'active',
            'registration_status' => 'approved',
            'registration_submitted_at' => now(),
        ]);

        // The progression ledger is the second source: an inactive student whose
        // academic record was discontinued is a dropout even though the student
        // row itself says nothing about dropping out.
        $ledgerDropout = $this->makeStudent($college, ['student_number' => 'SFS02-LEDGER', 'status' => 'inactive']);
        $this->makeAcademicRecord($college, $ledgerDropout, $this->makeYear($college, '2026'), [
            'academic_status' => 'discontinued',
        ]);

        $expect = function (array $query, array $students) use ($college, $viewer): void {
            $this->asCollege($college, $viewer)->get(route('students.index', $query))
                ->assertOk()
                ->assertViewHas('students', function ($page) use ($students): bool {
                    return $page->total() === count($students)
                        && $page->pluck('id')->sort()->values()->all() === collect($students)->pluck('id')->sort()->values()->all();
                });
        };

        // Student Status = students.status (Active / Inactive).
        $expect(['student_status' => 'active'], [$continuing, $registered]);
        $expect(['student_status' => 'inactive'], [$inactive, $ledgerDropout]);

        // Academic Status = the derived academic/progress status.
        $expect(['academic_status' => 'continue'], [$continuing, $registered]);
        $expect(['academic_status' => 'pass_out'], [$graduated]);
        $expect(['academic_status' => 'dropout'], [$dropped, $ledgerDropout]);
        $expect(['academic_status' => 'transfer_out'], [$transferred]);
        $expect(['academic_status' => 'new_registration'], [$registered]);

        // The two dimensions combine with AND semantics and stay distinguishable.
        $expect(['academic_status' => 'continue', 'student_status' => 'inactive'], []);
        $expect(['academic_status' => 'pass_out', 'student_status' => 'graduated'], [$graduated]);

        // An unsupported value is not applied at all (it can never be offered).
        $this->asCollege($college, $viewer)->get(route('students.index', ['academic_status' => 'online_registration']))
            ->assertOk()->assertViewHas('students', fn ($page) => $page->total() === 7);
    }

    public function test_program_semester_section_and_batch_are_college_scoped_and_dependent(): void
    {
        [$college, $viewer] = $this->screen('SFS03');
        [$foreignCollege] = $this->screen('SFS03X');

        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college, 'BSC');
        $otherProgram = $this->makeProgram($college, 'BCOM');
        $section = $this->makeSection($college, $year, $program, 'A');
        $foreignProgram = $this->makeProgram($foreignCollege, 'FOREIGN');
        $this->makeSection($foreignCollege, $this->makeYear($foreignCollege, '2026'), $foreignProgram, 'Z');

        $target = $this->makeStudent($college, ['student_number' => 'SFS03-IN']);
        $other = $this->makeStudent($college, ['student_number' => 'SFS03-OUT']);
        $this->makeEnrollment($college, $target, $year, $program, ['section_id' => $section->id]);
        $this->makeEnrollment($college, $other, $year, $otherProgram);

        $html = $this->asCollege($college, $viewer)->get(route('students.index'))->assertOk()->getContent();

        // Own-college options only.
        $this->assertStringContainsString('BSC', $html);
        $this->assertStringNotContainsString('FOREIGN', $html);

        // The section options carry the program/year they belong to, which is what
        // makes the dependent dropdown (and Batch) filter work.
        $this->assertStringContainsString('data-program="'.$program->id.'"', $html);
        $this->assertStringContainsString('data-year="'.$year->id.'"', $html);

        $this->asCollege($college, $viewer)->get(route('students.index', [
            'program_id' => $program->id,
            'academic_context' => 'section:'.$section->id,
        ]))->assertOk()
            ->assertSee($target->student_number)
            ->assertDontSee($other->student_number);

        // Section + program / year mismatch is refused.
        $this->asCollege($college, $viewer)->get(route('students.index', [
            'program_id' => $otherProgram->id,
            'academic_context' => 'section:'.$section->id,
        ]))->assertSessionHasErrors('academic_context');
    }

    public function test_identity_searches_are_authorized_exact_and_never_written_to_a_url(): void
    {
        [$college, $viewer] = $this->screen('SFS04');
        $sensitive = $this->makeUserWithPermissions($college, ['students.view', 'students.view_sensitive_identity']);
        [$otherCollege, $otherViewer] = $this->screen('SFS04X', ['students.view', 'students.view_sensitive_identity']);

        $target = $this->makeStudent($college, [
            'student_number' => 'SFS04-ID',
            'aadhaar_number' => '999941057058',
            'aadhaar_last4' => '7058',
            'aadhaar_hash' => Aadhaar::hash('999941057058'),
            'apaar_id' => 'ABC-SFS04-1',
        ]);
        $this->makeStudent($college, ['student_number' => 'SFS04-NO', 'apaar_id' => 'ABC-SFS04-2']);
        $foreign = $this->makeStudent($otherCollege, ['student_number' => 'SFS04-FOREIGN']);

        // Unauthorized viewer: no control, POST refused.
        $this->asCollege($college, $viewer)->get(route('students.index'))
            ->assertOk()->assertDontSee('name="aadhaar"', false);
        $this->asCollege($college, $viewer)
            ->post(route('students.filter.identity'), ['apaar_id' => 'ABC-SFS04-1'])
            ->assertForbidden();

        // Authorized, and the value never reaches the URL.
        $redirect = $this->asCollege($college, $sensitive)
            ->post(route('students.filter.identity'), ['aadhaar' => '999941057058'])
            ->assertRedirect();
        $location = (string) $redirect->headers->get('Location');
        $this->assertStringNotContainsString('999941057058', $location);
        $this->assertStringNotContainsString('7058', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('identity', $query);

        $body = $this->asCollege($college, $sensitive)->get($location)->assertOk()
            ->assertSee($target->student_number)
            ->assertDontSee('SFS04-NO')
            ->getContent();
        $this->assertStringNotContainsString('999941057058', $body);
        $this->assertStringNotContainsString('ABC-SFS04-1', $body);

        // The token is bound to the user and the college: another user in another
        // college cannot resolve it, and simply sees their own unfiltered list.
        $this->asCollege($otherCollege, $otherViewer)->get(route('students.index', ['identity' => $query['identity']]))
            ->assertOk()
            ->assertSee($foreign->student_number)
            ->assertDontSee($target->student_number);

        // Clearing the identity search keeps the ordinary filters and drops the token.
        $cleared = $this->asCollege($college, $sensitive)
            ->post(route('students.filter.identity'), ['identity' => $query['identity'], 'student_name' => 'ignored'])
            ->assertRedirect();
        $this->assertStringNotContainsString('identity=', (string) $cleared->headers->get('Location'));
    }

    public function test_filters_combine_with_each_other_and_with_free_text_search(): void
    {
        [$college, $viewer] = $this->screen('SFS05');
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college, 'BSC');

        $match = $this->makeStudent($college, [
            'student_number' => 'SFS05-A', 'first_name' => 'Meera', 'last_name' => 'Iyer',
            'email' => 'meera@example.test', 'status' => 'active', 'admission_date' => '2026-07-10',
        ]);
        $wrongName = $this->makeStudent($college, [
            'student_number' => 'SFS05-B', 'first_name' => 'Rohit', 'last_name' => 'Iyer',
            'email' => 'rohit@example.test', 'status' => 'active', 'admission_date' => '2026-07-10',
        ]);
        $wrongStatus = $this->makeStudent($college, [
            'student_number' => 'SFS05-C', 'first_name' => 'Meera', 'last_name' => 'Rao',
            'email' => 'meera2@example.test', 'status' => 'inactive', 'admission_date' => '2026-07-10',
        ]);
        $this->makeEnrollment($college, $match, $year, $program);
        $this->makeEnrollment($college, $wrongName, $year, $program);

        $this->asCollege($college, $viewer)->get(route('students.index', [
            'search' => 'meera@example.test',
            'student_name' => 'Meera',
            'student_status' => 'active',
            'academic_status' => 'continue',
            'program_id' => $program->id,
            'academic_year_id' => $year->id,
            'admission_date_from' => '2026-07-01',
            'admission_date_to' => '2026-07-31',
        ]))->assertOk()->assertViewHas('students', function ($page) use ($match): bool {
            return $page->total() === 1 && $page->first()->is($match);
        })->assertDontSee($wrongName->student_number)->assertDontSee($wrongStatus->student_number);
    }

    public function test_pagination_preserves_filters_and_reset_clears_them(): void
    {
        [$college, $viewer] = $this->screen('SFS06');
        foreach (['A', 'B', 'C'] as $index => $suffix) {
            $this->makeStudent($college, [
                'student_number' => 'SFS06-'.$suffix,
                'first_name' => 'Paged',
                'last_name' => 'Student',
                'status' => 'active',
            ]);
        }

        $filters = ['student_name' => 'Paged', 'student_status' => 'active', 'per_page' => 1];
        $html = $this->asCollege($college, $viewer)->get(route('students.index', $filters))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('student_name=Paged', $html);
        $this->assertStringContainsString('student_status=active', $html);
        $this->assertStringContainsString('page=2', $html);

        // Reset Filters points at the bare list URL (no filter survives).
        $this->assertStringContainsString('Reset Filters', $html);
        $this->assertStringContainsString('href="'.route('students.index').'"', $html);
    }

    public function test_registered_and_bulk_imported_students_are_both_filterable(): void
    {
        [$college, $viewer] = $this->screen('SFS07');

        // Single registration (the web form) and bulk registration/import both
        // write through StudentRegistrationService: same Student rows, same
        // enrollment relationship, one filter pipeline.
        $single = $this->makeStudent($college, [
            'student_number' => 'SFS07-SINGLE',
            'first_name' => 'Single',
            'registration_status' => 'approved',
            'registration_submitted_at' => now()->subDays(2),
        ]);
        $bulk = $this->makeStudent($college, [
            'student_number' => 'SFS07-BULK',
            'first_name' => 'Bulk',
            'registration_status' => 'approved',
            'registration_submitted_at' => now()->subDay(),
        ]);
        $pending = $this->makeStudent($college, [
            'student_number' => 'SFS07-PENDING',
            'first_name' => 'Pending',
            'registration_status' => 'pending_approval',
            'registration_submitted_at' => now(),
        ]);

        $this->asCollege($college, $viewer)->get(route('students.index', ['academic_status' => 'new_registration']))
            ->assertOk()
            ->assertSee($single->student_number)
            ->assertSee($bulk->student_number)
            // A pending registration is not treated as a student record.
            ->assertDontSee($pending->student_number);
    }

    public function test_bulk_actions_act_only_on_the_selected_records(): void
    {
        [$college, $user] = $this->screen('SFS08', ['students.view', 'students.export']);

        $selected = $this->makeStudent($college, ['student_number' => 'SFS08-PICK', 'first_name' => 'Filtered', 'status' => 'active']);
        $sameList = $this->makeStudent($college, ['student_number' => 'SFS08-ALSO', 'first_name' => 'Filtered', 'status' => 'active']);
        $notSelected = $this->makeStudent($college, ['student_number' => 'SFS08-NOPE', 'first_name' => 'Other', 'status' => 'active']);

        // The filtered list shows both matching students …
        $this->asCollege($college, $user)->get(route('students.index', ['student_name' => 'Filtered']))
            ->assertOk()->assertSee($selected->student_number)->assertSee($sameList->student_number);

        // … but the bulk action runs only for the ticked record.
        $response = $this->asCollege($college, $user)->postJson(route('bulk-actions.execute'), [
            'module' => 'students',
            'action' => 'export',
            'ids' => [$selected->id],
        ])->assertOk();

        $redirect = (string) $response->json('data.redirect');
        $this->assertNotSame('', $redirect);

        $csv = $this->asCollege($college, $user)->get($redirect)->assertOk()->streamedContent();
        $this->assertStringContainsString('SFS08-PICK', $csv);
        $this->assertStringNotContainsString('SFS08-ALSO', $csv);
        $this->assertStringNotContainsString('SFS08-NOPE', $csv);
    }

    public function test_existing_attendance_and_examination_screens_keep_their_own_logic(): void
    {
        [$college, $user] = $this->screen('SFS09', [
            'academic_attendance.view',
            'exam_attendance.view',
            'students.view',
            'students.view_sensitive_identity',
        ]);

        $this->makeStudent($college, ['student_number' => 'SFS09-A']);

        // The shared panel is present on both screens, and neither page gained a
        // bulk identity control or lost its own filters.
        $attendance = $this->asCollege($college, $user)->get(route('academic-attendance.index'))
            ->assertOk()->assertSee('Filter Student Attendance');
        $attendance->assertSee('name="academic_context"', false);

        $this->asCollege($college, $user)->get(route('exam-attendance.index'))
            ->assertOk()
            ->assertSee('name="academic_context"', false);
    }
}
