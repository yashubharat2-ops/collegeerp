<?php

namespace Tests\Feature\Students;

use App\Models\College;
use App\Models\Department;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Step 2 of the Students module: the listing (search, filters, sorting,
 * pagination) built on the shared ListQueryBuilder / ListContext / list-*
 * components.
 *
 * These tests pin the contract:
 *  - every documented search field is searched server-side,
 *  - every documented filter narrows the list and nothing else,
 *  - all enrollment dimensions must match the SAME enrollment,
 *  - sorting is an allow-list, direction is validated,
 *  - pagination and sort links preserve search/filters/sort,
 *  - tenant isolation is absolute (a foreign college's student or master id
 *    changes nothing),
 *  - the page renders the SHARED listing components rather than a private
 *    re-implementation, and no master table was duplicated for the new filter.
 */
class StudentListFiltersTest extends TestCase
{
    use StudentTestHelpers;

    /**
     * A viewer of the given college (students.view only — the listing does not
     * need any other permission).
     *
     * @return array{0: College, 1: User}
     */
    private function collegeWithViewer(string $code, array $permissions = ['students.view']): array
    {
        $college = $this->makeCollege($code);

        return [$college, $this->makeUserWithPermissions($college, $permissions)];
    }

    private function list(College $college, User $user, array $query = []): TestResponse
    {
        return $this->asCollege($college, $user)->get(route('students.index', $query));
    }

    /**
     * One date-range control, sliced out of the rendered page by its own
     * accessible name.
     *
     * The list carries TWO independent ranges now — Admission date and Date of
     * birth — and each renders a From/To pair, so a page-wide count of the
     * picker's inner attributes can no longer say which control they belong to.
     * Slicing keeps every assertion about one range; the companion assertion
     * that the page carries exactly two `data-list-date-range` controls is what
     * proves both are still there.
     *
     * The slice runs from the control's `aria-label="<label>"` to the next
     * `data-list-date-range` (or the end of the filter form), so it can only
     * contain that one control's markup.
     */
    private function dateRangeControl(string $html, string $label): string
    {
        $marker = 'aria-label="'.$label.'"';
        $start = strpos($html, $marker);
        $this->assertNotFalse($start, "The {$label} date range must be rendered.");

        $end = strlen($html);
        foreach (['data-list-date-range', '</form>'] as $boundary) {
            $at = strpos($html, $boundary, $start + strlen($marker));
            if ($at !== false && $at < $end) {
                $end = $at;
            }
        }

        return substr($html, $start, $end - $start);
    }

    public function test_search_covers_name_number_enrollment_number_email_and_mobile(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF1');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $this->makeStudent($college, ['first_name' => 'Meera', 'last_name' => 'Iyer', 'student_number' => 'STU-S1']);
        $this->makeStudent($college, ['first_name' => 'Arun', 'last_name' => 'Rao', 'student_number' => 'STU-FIND-NUMBER']);
        $this->makeStudent($college, ['first_name' => 'Divya', 'last_name' => 'Nair', 'student_number' => 'STU-S3', 'email' => 'divya.unique@example.test']);
        $this->makeStudent($college, ['first_name' => 'Kabir', 'last_name' => 'Shah', 'student_number' => 'STU-S4', 'phone' => '9812345678']);
        $byEnrollment = $this->makeStudent($college, ['first_name' => 'Sana', 'last_name' => 'Khan', 'student_number' => 'STU-S5']);
        $this->makeEnrollment($college, $byEnrollment, $year, $program, ['enrollment_number' => 'ENR-UNIQUE-777']);

        // Student name — any part of it.
        $this->list($college, $viewer, ['search' => 'Meera'])->assertOk()->assertSee('STU-S1')->assertDontSee('STU-S3');
        $this->list($college, $viewer, ['search' => 'Iyer'])->assertOk()->assertSee('STU-S1')->assertDontSee('STU-S4');

        // Student number.
        $this->list($college, $viewer, ['search' => 'FIND-NUMBER'])->assertOk()->assertSee('STU-FIND-NUMBER')->assertDontSee('STU-S1');

        // E-mail.
        $this->list($college, $viewer, ['search' => 'divya.unique@example.test'])->assertOk()->assertSee('STU-S3')->assertDontSee('STU-S1');

        // Mobile.
        $this->list($college, $viewer, ['search' => '9812345678'])->assertOk()->assertSee('STU-S4')->assertDontSee('STU-S1');

        // Enrollment number — on the related enrollment, in the same search term.
        $this->list($college, $viewer, ['search' => 'ENR-UNIQUE-777'])->assertOk()->assertSee('STU-S5')->assertDontSee('STU-S1');
    }

    public function test_academic_year_department_program_section_and_enrollment_status_filters(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF2');

        $year = $this->makeYear($college, '2026', '2026-27');
        $otherYear = $this->makeYear($college, '2027', '2027-28', '2027-06-01', '2028-05-31');
        $department = Department::withoutGlobalScopes()->create([
            'college_id' => $college->id, 'name' => 'Science', 'code' => 'SCIDEPT', 'status' => 'active',
        ]);
        $program = $this->makeProgram($college, 'BSCX');
        $program->update(['department_id' => $department->id]);
        $otherProgram = $this->makeProgram($college, 'BCOMX');
        $section = $this->makeSection($college, $year, $program, 'A');

        $inSection = $this->makeStudent($college, ['first_name' => 'InSection', 'student_number' => 'STU-SEC']);
        $this->makeEnrollment($college, $inSection, $year, $program, ['section_id' => $section->id, 'status' => 'active']);

        $noSection = $this->makeStudent($college, ['first_name' => 'NoSection', 'student_number' => 'STU-NOSEC']);
        $this->makeEnrollment($college, $noSection, $year, $program, ['status' => 'active']);

        $elsewhere = $this->makeStudent($college, ['first_name' => 'Elsewhere', 'student_number' => 'STU-ELSE']);
        $this->makeEnrollment($college, $elsewhere, $otherYear, $otherProgram, ['status' => 'completed']);

        $this->list($college, $viewer, ['academic_year_id' => $year->id])
            ->assertOk()->assertSee('STU-SEC')->assertSee('STU-NOSEC')->assertDontSee('STU-ELSE');

        $this->list($college, $viewer, ['program_id' => $program->id])
            ->assertOk()->assertSee('STU-SEC')->assertSee('STU-NOSEC')->assertDontSee('STU-ELSE');

        $this->list($college, $viewer, ['department_id' => $department->id])
            ->assertOk()->assertSee('STU-SEC')->assertSee('STU-NOSEC')->assertDontSee('STU-ELSE');

        $this->list($college, $viewer, ['section_id' => $section->id])
            ->assertOk()->assertSee('STU-SEC')->assertDontSee('STU-NOSEC')->assertDontSee('STU-ELSE');

        $this->list($college, $viewer, ['enrollment_status' => 'completed'])
            ->assertOk()->assertSee('STU-ELSE')->assertDontSee('STU-SEC')->assertDontSee('STU-NOSEC');

        // A comma-separated list is honoured, still on the same enrollment.
        $this->list($college, $viewer, ['enrollment_status' => 'active,completed'])
            ->assertOk()->assertSee('STU-SEC')->assertSee('STU-ELSE');
    }

    public function test_academic_term_filter_matches_the_academic_record(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF2T');

        $year = $this->makeYear($college, '2026', '2026-27');
        $program = $this->makeProgram($college, 'BSC');
        $term = $this->makeAcademicTerm($college, $year, 'SEM1', 'Semester 1');
        $otherTerm = $this->makeAcademicTerm($college, $year, 'SEM2', 'Semester 2', 2);

        $inTerm = $this->makeStudent($college, ['first_name' => 'InTerm', 'student_number' => 'STU-TERM']);
        $enrollment = $this->makeEnrollment($college, $inTerm, $year, $program);
        $this->makeAcademicRecord($college, $inTerm, $year, [
            'academic_term_id' => $term->id,
            'enrollment_id' => $enrollment->id,
            'program_id' => $program->id,
        ]);

        $noRecord = $this->makeStudent($college, ['first_name' => 'NoRecord', 'student_number' => 'STU-NOREC']);
        $this->makeEnrollment($college, $noRecord, $year, $program);

        $otherTermRecord = $this->makeStudent($college, ['first_name' => 'OtherTerm', 'student_number' => 'STU-OTERM']);
        $this->makeAcademicRecord($college, $otherTermRecord, $year, ['academic_term_id' => $otherTerm->id]);

        $this->list($college, $viewer, ['academic_term_id' => $term->id])
            ->assertOk()->assertSee('STU-TERM')->assertDontSee('STU-NOREC')->assertDontSee('STU-OTERM');

        // The term and the year together constrain the same academic record.
        $this->list($college, $viewer, ['academic_term_id' => $term->id, 'academic_year_id' => $year->id])
            ->assertOk()->assertSee('STU-TERM');

        $otherYear = $this->makeYear($college, '2027', '2027-28', '2027-06-01', '2028-05-31');
        $this->list($college, $viewer, ['academic_term_id' => $term->id, 'academic_year_id' => $otherYear->id])
            ->assertOk()->assertDontSee('STU-TERM');
    }

    public function test_all_enrollment_dimensions_must_match_the_same_enrollment(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF3');

        $yearA = $this->makeYear($college, '2026', '2026-27');
        $yearB = $this->makeYear($college, '2027', '2027-28', '2027-06-01', '2028-05-31');
        $programA = $this->makeProgram($college, 'BSC');
        $programB = $this->makeProgram($college, 'BCOM');

        $student = $this->makeStudent($college, ['first_name' => 'Crossed', 'student_number' => 'STU-CROSS']);
        $this->makeEnrollment($college, $student, $yearA, $programA);
        $this->makeEnrollment($college, $student, $yearB, $programB);

        // A pair that exists on one enrollment matches...
        $this->list($college, $viewer, ['academic_year_id' => $yearA->id, 'program_id' => $programA->id])
            ->assertOk()->assertSee('STU-CROSS');

        // ...but the cross pair (2026 + BCom) must not: no single enrollment is both.
        $this->list($college, $viewer, ['academic_year_id' => $yearA->id, 'program_id' => $programB->id])
            ->assertOk()->assertDontSee('STU-CROSS');
    }

    public function test_gender_category_student_status_and_admission_date_filters(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF4');
        $year = $this->makeYear($college);

        $female = $this->makeStudent($college, [
            'first_name' => 'FilterFemale', 'student_number' => 'STU-F1', 'gender' => 'female', 'category' => 'obc',
            'status' => 'active', 'admission_date' => '2026-07-01',
        ]);
        $male = $this->makeStudent($college, [
            'first_name' => 'FilterMale', 'student_number' => 'STU-F2', 'gender' => 'male', 'category' => 'general',
            'status' => 'graduated', 'admission_date' => '2025-07-01',
        ]);
        $other = $this->makeStudent($college, [
            'first_name' => 'FilterOther', 'student_number' => 'STU-F3', 'gender' => 'other', 'category' => 'sc',
            'status' => 'suspended', 'admission_date' => '2026-07-02',
        ]);

        $this->makeEnrollment($college, $female, $year);
        $this->makeEnrollment($college, $male, $year);
        $this->makeEnrollment($college, $other, $year);

        $this->list($college, $viewer, ['gender' => 'female'])
            ->assertOk()->assertSee('STU-F1')->assertDontSee('STU-F2')->assertDontSee('STU-F3');

        $this->list($college, $viewer, ['category' => 'obc'])
            ->assertOk()->assertSee('STU-F1')->assertDontSee('STU-F2')->assertDontSee('STU-F3');

        $this->list($college, $viewer, ['category' => 'general,sc'])
            ->assertOk()->assertSee('STU-F2')->assertSee('STU-F3')->assertDontSee('STU-F1');

        $this->list($college, $viewer, ['status' => 'graduated'])
            ->assertOk()->assertSee('STU-F2')->assertDontSee('STU-F1');

        // Admission date: one exact day, and a from/to range.
        $this->list($college, $viewer, ['admission_date' => '2025-07-01'])
            ->assertOk()->assertSee('STU-F2')->assertDontSee('STU-F1');

        $this->list($college, $viewer, ['admission_date_from' => '2026-01-01', 'admission_date_to' => '2026-12-31'])
            ->assertOk()->assertSee('STU-F1')->assertSee('STU-F3')->assertDontSee('STU-F2');
    }

    public function test_admission_date_range_displays_dd_mm_yyyy_and_keeps_iso_query_values(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF4D');
        $this->makeStudent($college, [
            'first_name' => 'DateFiltered',
            'student_number' => 'STU-DATE',
            'admission_date' => '2026-07-05',
        ]);

        $html = $this->list($college, $viewer, [
            'admission_date_from' => '2026-07-05',
            'admission_date_to' => '2026-07-31',
        ])->assertOk()->assertSee('STU-DATE')->getContent();

        // The human-facing controls are DD/MM/YYYY, while the named values sent
        // to Laravel remain the ISO dates consumed by StudentListService.
        $this->assertStringContainsString('id="filter-display-admission_date_from"', $html);
        $this->assertStringContainsString('id="filter-display-admission_date_to"', $html);
        $this->assertStringContainsString('placeholder="DD/MM/YYYY"', $html);
        $this->assertStringContainsString('value="05/07/2026"', $html);
        $this->assertStringContainsString('value="31/07/2026"', $html);
        $this->assertSame(1, substr_count($html, 'name="admission_date_from"'));
        $this->assertSame(1, substr_count($html, 'name="admission_date_to"'));
        $this->assertStringContainsString('value="2026-07-05"', $html);
        $this->assertStringContainsString('value="2026-07-31"', $html);
        $this->assertStringContainsString('data-list-date-display', $html);
        $this->assertStringContainsString('data-list-date-value', $html);

        // The picker's internals are asserted PER CONTROL. Counting them across
        // the whole page would be asserting 4 and calling it "the admission date
        // range" — which is exactly what broke when the Date of birth range was
        // added beside it.
        $admission = $this->dateRangeControl($html, 'REG. DATE');

        $this->assertSame(2, substr_count($admission, 'data-date-picker-toggle'), 'The Admission date range has a From and a To picker.');
        $this->assertSame(2, substr_count($admission, 'data-date-picker-prev'));
        $this->assertSame(2, substr_count($admission, 'data-date-picker-next'));
        $this->assertSame(2, substr_count($admission, 'class="erp-list-date-prefix"'));

        // …and the page carries exactly two ranges, so a third one appearing by
        // accident still fails this test.
        $this->assertSame(2, substr_count($html, 'data-list-date-range'), 'The list carries exactly two date ranges: admission and date of birth.');
        $this->assertSame(4, substr_count($html, 'data-date-picker-toggle'), 'Two ranges x a From/To pair each.');
        $this->assertSame(4, substr_count($html, 'data-date-picker-popover'));

        $this->assertStringContainsString('data-date-picker-calendar', $html);
        $this->assertStringContainsString('data-date-picker-days', $html);
        $this->assertStringContainsString('data-date-picker-clear', $html);
        $this->assertStringContainsString('data-date-picker-close', $html);
        $this->assertStringContainsString('erp-list-date-controls', $html);
        $this->assertStringContainsString('erp-list-date-separator', $html);
        $this->assertStringContainsString('data-list-date-edge="from"', $html);
        $this->assertStringContainsString('data-list-date-edge="to"', $html);
        $this->assertStringContainsString('data-date-picker-label="From"', $html);
        $this->assertStringContainsString('data-date-picker-label="To"', $html);
        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
        $this->assertStringContainsString('aria-modal="false"', $html);
        $this->assertStringNotContainsString('type="date"', $html);
        $this->assertStringContainsString('src="'.asset('js/erp-list.js').'"', $html);
    }

    public function test_admission_date_fields_start_blank_and_calendar_is_closed(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF4E');
        $html = $this->list($college, $viewer)->assertOk()->getContent();

        foreach (['admission_date_from', 'admission_date_to'] as $filter) {
            $this->assertMatchesRegularExpression(
                '/<input(?=[^>]*id="filter-display-'.$filter.'")(?=[^>]*value="")[^>]*>/',
                $html,
                "The visible {$filter} field must be empty on first load."
            );
            $this->assertMatchesRegularExpression(
                '/<input(?=[^>]*type="hidden")(?=[^>]*name="'.$filter.'")(?=[^>]*value="")[^>]*>/',
                $html,
                "The {$filter} query value must start empty."
            );
        }

        // Per control again: "the admission range's two popovers are both closed"
        // is a statement about the admission control, not about the page.
        $admission = $this->dateRangeControl($html, 'REG. DATE');
        $this->assertSame(2, substr_count($admission, 'data-date-picker-popover'), 'The Admission date range has a From and a To popover.');
        $this->assertSame(2, substr_count($admission, 'data-list-date-display'));
        $this->assertStringContainsString('aria-expanded="false"', $admission);
        $this->assertSame(0, substr_count($admission, 'aria-expanded="true"'), 'Both Admission date pickers start closed.');
    }

    public function test_both_date_ranges_own_their_pickers(): void
    {
        // Two ranges now share one page. Each must own its whole picker — display
        // field, query field, calendar, title — because erp-list.js addresses the
        // parts through `id` and through the ids it derives from the field names.
        // A shared id would wire one calendar twice and leave the other dead, and
        // neither range may pick up the other's query parameter.
        [$college, $viewer] = $this->collegeWithViewer('SLF4F');

        $html = $this->list($college, $viewer, [
            'admission_date_from' => '2026-07-05',
            'admission_date_to' => '2026-07-31',
            'date_of_birth_from' => '2005-06-15',
            'date_of_birth_to' => '2006-01-20',
        ])->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-list-date-range'), 'Exactly two date ranges: admission and date of birth.');

        $admission = $this->dateRangeControl($html, 'REG. DATE');
        $birth = $this->dateRangeControl($html, 'Date of birth');

        $ranges = [
            'REG. DATE' => [$admission, 'admission_date_from', 'admission_date_to', '05/07/2026', '2026-07-05'],
            'Date of birth' => [$birth, 'date_of_birth_from', 'date_of_birth_to', '15/06/2005', '2005-06-15'],
        ];

        foreach ($ranges as $label => [$range, $from, $to, $display, $iso]) {
            // Each control owns its own From/To pair.
            $this->assertSame(1, substr_count($range, 'name="'.$from.'"'), "{$label} owns its {$from} field.");
            $this->assertSame(1, substr_count($range, 'name="'.$to.'"'), "{$label} owns its {$to} field.");
            $this->assertSame(2, substr_count($range, 'data-date-picker-toggle'), "{$label} renders two picker triggers.");
            $this->assertSame(2, substr_count($range, 'data-date-picker-popover'), "{$label} renders two popovers.");

            // The visible value is localised, the submitted value stays ISO.
            $this->assertStringContainsString('value="'.$display.'"', $range, "{$label} shows DD/MM/YYYY.");
            $this->assertStringContainsString('value="'.$iso.'"', $range, "{$label} keeps an ISO query value.");

            // Every id the picker wires by appears exactly once on the whole page.
            foreach ([$from, $to] as $name) {
                foreach (['filter-display-', 'filter-', 'filter-calendar-', 'filter-calendar-title-'] as $prefix) {
                    $this->assertSame(
                        1,
                        substr_count($html, 'id="'.$prefix.$name.'"'),
                        "The id {$prefix}{$name} must be rendered exactly once."
                    );
                }
            }
        }

        // Neither range leaks the other's parameter, so one broken control can
        // never silently filter on the other's column.
        $this->assertStringNotContainsString('date_of_birth', $admission, 'The Admission date range must not carry the date-of-birth parameters.');
        $this->assertStringNotContainsString('admission_date', $birth, 'The Date of birth range must not carry the admission-date parameters.');

        // The script that wires them iterates EVERY range and then every picker
        // inside its own root. A single-element lookup here would make the second
        // calendar silently stop opening, which no markup assertion would catch.
        $js = (string) file_get_contents(public_path('js/erp-list.js'));
        $this->assertStringContainsString(
            "querySelectorAll('[data-list-date-range]')",
            $js,
            'The date-range initializer must handle every range on the page, not the first.'
        );
        $this->assertStringContainsString(
            "range.querySelectorAll('[data-erp-date-picker]')",
            $js,
            'Each range must initialise each of its own From/To pickers.'
        );
    }

    public function test_unknown_or_malformed_filter_values_are_ignored(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF5');
        $this->makeStudent($college, ['first_name' => 'StillHere', 'student_number' => 'STU-IGN']);

        // None of these is a usable value: the list must not error, and an
        // unusable value must not silently filter everything out either.
        $this->list($college, $viewer, [
            'gender' => 'unicorn',
            'category' => 'not-a-category',
            'status' => 'deleted',
            'enrollment_status' => 'unknown',
            'academic_year_id' => 'abc',
            'section_id' => '1 OR 1=1',
            'department_id' => ['nested'],
            'academic_term_id' => 'oops',
            'admission_date' => '2026-13-45',
            // Sort state is query input too: an array must be ignored like any
            // other malformed value, never cast.
            'sort' => ['student_number'],
            'direction' => ['desc'],
            // …and so is the search term: an array is not a term, so it filters
            // nothing and must not be echoed into the search box.
            'search' => ['nested'],
        ])->assertOk()->assertSee('STU-IGN');

        // A numeric value that matches nothing is still just a filter.
        $this->list($college, $viewer, ['academic_year_id' => '999999'])->assertOk()->assertDontSee('STU-IGN');
    }

    public function test_sorting_is_an_allow_list_and_direction_is_validated(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF6');

        $this->makeStudent($college, ['first_name' => 'Zara', 'student_number' => 'STU-Z']);
        $this->makeStudent($college, ['first_name' => 'Aaron', 'student_number' => 'STU-A']);

        $ascending = $this->list($college, $viewer, ['sort' => 'name', 'direction' => 'asc'])->assertOk()->getContent();
        $this->assertLessThan(strpos($ascending, 'STU-Z'), strpos($ascending, 'STU-A'), 'Ascending name sort must put Aaron first.');

        $descending = $this->list($college, $viewer, ['sort' => 'name', 'direction' => 'desc'])->assertOk()->getContent();
        $this->assertLessThan(strpos($descending, 'STU-A'), strpos($descending, 'STU-Z'), 'Descending name sort must put Zara first.');

        // An unknown sort key is ignored (the default creation order applies),
        // and a junk direction falls back to ascending.
        $this->list($college, $viewer, ['sort' => 'students.password', 'direction' => 'desc'])
            ->assertOk()->assertSee('STU-A')->assertSee('STU-Z');
        $this->list($college, $viewer, ['sort' => 'name', 'direction' => 'sideways'])
            ->assertOk()->assertSee('STU-A');
    }

    public function test_pagination_preserves_search_filters_and_sort(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF7');

        foreach (range(1, 32) as $i) {
            $this->makeStudent($college, [
                'first_name' => 'Page'.sprintf('%02d', $i),
                'student_number' => 'STU-P'.sprintf('%02d', $i),
                'status' => 'active',
            ]);
        }

        $html = $this->list($college, $viewer, [
            'search' => 'Page',
            'status' => 'active',
            'sort' => 'student_number',
            'direction' => 'asc',
            'page' => 2,
        ])->assertOk()->getContent();

        // 32 rows at the module's page size of 15: page 2 holds STU-P16…STU-P30.
        $this->assertStringContainsString('STU-P16', $html);
        $this->assertStringContainsString('STU-P30', $html);
        $this->assertStringNotContainsString('STU-P01', $html);
        $this->assertStringNotContainsString('STU-P31', $html);

        // Every page link keeps the search, the filters and the sort.
        $this->assertGreaterThan(0, preg_match('/href="([^"]*page=3[^"]*)"/', $html, $matches), 'Expected a page 3 link.');
        $pageLink = html_entity_decode($matches[1]);
        $this->assertStringContainsString('search=Page', $pageLink);
        $this->assertStringContainsString('status=active', $pageLink);
        $this->assertStringContainsString('sort=student_number', $pageLink);
        $this->assertStringContainsString('direction=asc', $pageLink);

        // A sort header link keeps the filters too, and resets paging.
        $this->assertGreaterThan(0, preg_match('/href="([^"]*sort=name[^"]*)"/', $html, $sortMatches), 'Expected a sort header link.');
        $sortLink = html_entity_decode($sortMatches[1]);
        $this->assertStringContainsString('search=Page', $sortLink);
        $this->assertStringContainsString('sort=name', $sortLink);
        $this->assertStringNotContainsString('page=', $sortLink);

        // The filter form itself carries the current order as hidden inputs, so
        // re-applying a filter never silently drops the sort the user chose.
        $this->assertStringContainsString('<input type="hidden" name="sort" value="student_number">', $html);
        $this->assertStringContainsString('<input type="hidden" name="direction" value="asc">', $html);
    }

    public function test_the_empty_state_offers_to_clear_the_filters(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF13');
        $this->makeStudent($college, ['first_name' => 'Present', 'student_number' => 'STU-PRESENT']);

        $html = $this->list($college, $viewer, ['search' => 'no-such-student-anywhere'])
            ->assertOk()
            ->assertSee('No records found matching your active filters.')
            ->getContent();

        $this->assertStringContainsString('Clear Filters', $html);
        // Clearing returns to the unfiltered list URL.
        $this->assertStringContainsString('href="'.route('students.index').'"', $html);
    }

    public function test_the_list_renders_the_shared_listing_components(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF8', ['students.view', 'students.export', 'student_id_cards.generate', 'student_documents.view']);
        $student = $this->makeStudent($college, ['first_name' => 'Component', 'student_number' => 'STU-CMP']);

        $html = $this->list($college, $viewer)->assertOk()->getContent();

        // Bulk selection hooks used by public/js/erp-list.js.
        $this->assertStringContainsString('data-bulk-selection', $html);
        $this->assertStringContainsString('data-module="students"', $html);
        $this->assertStringContainsString('data-selected-count', $html);
        $this->assertStringContainsString('data-select-all', $html);
        $this->assertStringContainsString('data-select-row', $html);
        $this->assertStringContainsString('value="'.$student->id.'"', $html);

        // The three bulk actions, addressed by the action names their handlers
        // are registered under.
        $this->assertStringContainsString('data-bulk-action="export"', $html);
        $this->assertStringContainsString('data-bulk-action="id_cards"', $html);
        $this->assertStringContainsString('data-bulk-action="documents"', $html);

        // The bar, the select-all and the row checkboxes must live in the SAME
        // .panel: public/js/erp-list.js scopes the selection to
        // `bar.closest('.panel')`, so moving the bar out of the table's panel
        // would silently break select-all and the counter.
        $panelAt = strpos($html, 'class="panel"');
        $barAt = strpos($html, 'data-bulk-selection');
        $selectAllAt = strpos($html, 'data-select-all');
        $this->assertNotFalse($panelAt);
        $this->assertNotFalse($barAt);
        $this->assertNotFalse($selectAllAt);
        $this->assertLessThan($barAt, $panelAt, 'The bulk bar must be inside the table panel.');
        $this->assertLessThan($selectAllAt, $barAt, 'The bulk bar must be rendered before the table it drives.');

        // And the shared selection script is actually loaded on this page (as a
        // module script — the only form the layout allows).
        $this->assertStringContainsString('src="'.asset('js/erp-list.js').'"', $html);

        // Search + every documented filter is rendered in the form.
        $this->assertStringContainsString('name="search"', $html);
        $this->assertStringContainsString('Search name, student no., enrollment no., email or mobile', $html);
        $this->assertStringContainsString('class="erp-list-grid"', $html);
        $this->assertStringContainsString('erp-list-filter-span-2', $html);
        foreach ([
            'academic_year_id', 'department_id', 'program_id', 'academic_context',
            'gender', 'category', 'academic_status', 'student_status', 'admission_date_from', 'admission_date_to',
            'student_number', 'student_name', 'enrollment_number',
            'mobile_number', 'father_name', 'mother_name', 'mother_tongue',
        ] as $filter) {
            $this->assertStringContainsString('name="'.$filter.'"', $html, "The {$filter} filter must be rendered.");
        }
        foreach ([
            'Academic year', 'Department', 'PROGRAM / CLASS', 'SEMESTER / SECTION',
            'Gender', 'Category', 'STUDENT STATUS', 'ACADEMIC STATUS',
            'REG. DATE', 'REG.NO', 'NAME OF STUDENT',
            'ENROLLMENT NO.', 'MOBILE NUMBER', "FATHER'S NAME", "MOTHER'S NAME",
            'Mother tongue', 'From', 'To',
        ] as $label) {
            $this->assertStringContainsString(e($label), $html, "The {$label} filter label must be visible.");
        }

        // Fields the filtering interface deliberately does not offer. The profile
        // columns still exist and the legacy query parameters still work (the
        // blood-group, date-of-birth and nationality tests below prove it); they
        // are simply not part of the filter panel.
        foreach ([
            'name="unique_student_id"', 'name="blood_group"', 'name="nationality"',
            'name="date_of_birth_from"', 'name="date_of_birth_to"',
            'UNIQUE STUDENT ID', 'Blood group', 'Date of birth',
        ] as $removed) {
            $this->assertStringNotContainsString($removed, $html, "{$removed} must not be part of the filter panel.");
        }

        // Section is a Section. The old "Section / Batch" wording represented the
        // column as something the schema has no concept of — there is no batch.
        $this->assertStringNotContainsString(
            'Section / Batch',
            $html,
            'The Section filter must not be labelled as a Batch.'
        );

        // Sorting and pagination controls are the shared components' output.
        $this->assertStringContainsString('sort=student_number', $html);
        $this->assertStringContainsString('sort=name', $html);
        $this->assertStringContainsString('Showing 1', $html);
    }

    public function test_the_category_filter_is_a_column_not_a_duplicated_master_table(): void
    {
        // The Category filter must not have introduced a second student,
        // enrollment or category master: the value is one nullable attribute of
        // the existing students table.
        $this->assertTrue(Schema::hasColumn('students', 'category'));
        $this->assertFalse(Schema::hasTable('student_categories'));
        $this->assertFalse(Schema::hasTable('students_master'));
        $this->assertFalse(Schema::hasTable('enrollments_master'));
        $this->assertSame(1, DB::table('migrations')->where('migration', 'like', '%create_students_table')->count());
        $this->assertSame(1, DB::table('migrations')->where('migration', 'like', '%create_student_enrollments_table')->count());
    }

    public function test_the_list_is_strictly_tenant_scoped(): void
    {
        [$collegeA, $viewer] = $this->collegeWithViewer('SLF9A');

        $collegeB = $this->makeCollege('SLF9B');
        $yearB = $this->makeYear($collegeB, '2026', '2026-27');
        $programB = $this->makeProgram($collegeB, 'BXPROG');
        $foreign = $this->makeStudent($collegeB, ['first_name' => 'Foreigner', 'student_number' => 'STU-FOREIGN', 'email' => 'foreign@example.test']);
        $this->makeEnrollment($collegeB, $foreign, $yearB, $programB, ['enrollment_number' => 'ENR-FOREIGN']);

        $own = $this->makeStudent($collegeA, ['first_name' => 'Local', 'student_number' => 'STU-LOCAL']);
        $this->makeEnrollment($collegeA, $own, $this->makeYear($collegeA), $this->makeProgram($collegeA));

        $this->list($collegeA, $viewer)->assertOk()->assertSee('STU-LOCAL')->assertDontSee('STU-FOREIGN');

        // Searching the foreign student's data reaches nothing.
        $this->list($collegeA, $viewer, ['search' => 'Foreigner'])->assertOk()->assertDontSee('STU-FOREIGN');
        $this->list($collegeA, $viewer, ['search' => 'ENR-FOREIGN'])->assertOk()->assertDontSee('STU-FOREIGN');

        // This college's foreign master ids match nothing and never widen the query.
        $this->list($collegeA, $viewer, ['academic_year_id' => $yearB->id])->assertOk()->assertDontSee('STU-FOREIGN')->assertDontSee('STU-LOCAL');
        $this->list($collegeA, $viewer, ['program_id' => $programB->id])->assertOk()->assertDontSee('STU-FOREIGN');

        // And the filter dropdowns never offer another college's masters.
        $this->list($collegeA, $viewer)->assertOk()->assertDontSee('BXPROG');
    }

    public function test_the_export_link_is_only_offered_with_the_export_permission(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF10', ['students.view']);
        $exporter = $this->makeUserWithPermissions($college, ['students.view', 'students.export']);

        $exportUrl = route('students.export');

        $this->asCollege($college, $viewer)->get(route('students.index'))->assertOk()
            ->assertDontSee('href="'.$exportUrl.'"', false);

        $this->asCollege($college, $exporter)->get(route('students.index'))->assertOk()
            ->assertSee('href="'.$exportUrl.'"', false);
    }

    public function test_the_export_link_carries_the_current_filters(): void
    {
        [$college, $exporter] = $this->collegeWithViewer('SLF14', ['students.view', 'students.export']);
        $this->makeStudent($college, ['first_name' => 'Exportable', 'student_number' => 'STU-EXP-LINK', 'status' => 'active', 'category' => 'obc']);

        $html = $this->list($college, $exporter, [
            'search' => 'Exportable',
            'status' => 'active',
            'category' => 'obc',
        ])->assertOk()->getContent();

        $this->assertGreaterThan(0, preg_match('/href="([^"]*students\/export[^"]*)"/', $html, $matches), 'Expected an export link.');
        $exportLink = html_entity_decode($matches[1]);

        // The link hands the active query string to the export endpoint, so the
        // CSV the user downloads matches the list the user is looking at.
        $this->assertStringContainsString('search=Exportable', $exportLink);
        $this->assertStringContainsString('status=active', $exportLink);
        $this->assertStringContainsString('category=obc', $exportLink);
    }

    public function test_a_user_without_students_view_still_cannot_open_the_list(): void
    {
        [$college, $nobody] = $this->collegeWithViewer('SLF11', ['dashboard.view']);

        $this->asCollege($college, $nobody)->get(route('students.index'))->assertForbidden();
        $this->asCollege($college, $nobody)->get(route('students.export'))->assertForbidden();
    }

    public function test_searching_a_student_number_never_returns_a_soft_deleted_student(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF12', ['students.view', 'students.export']);

        $student = $this->makeStudent($college, ['first_name' => 'Trashed', 'student_number' => 'STU-GONE']);
        $student->delete();

        $response = $this->list($college, $viewer, ['search' => 'STU-GONE'])->assertOk();

        // The list is asserted on its DATA and on the table body — not on the
        // whole document: the search term is deliberately echoed back into the
        // search box, so a page-wide assertDontSee() would be asserting the wrong
        // thing.
        $response->assertViewHas('students', fn ($students) => $students->total() === 0);

        $html = $response->getContent();
        $bodyStart = strpos($html, '<tbody>');
        $bodyEnd = strpos($html, '</tbody>');
        $this->assertNotFalse($bodyStart);
        $this->assertNotFalse($bodyEnd);
        $this->assertStringNotContainsString('STU-GONE', substr($html, $bodyStart, $bodyEnd - $bodyStart),
            'A soft-deleted student must not be rendered as a row.');
        // The table shows its empty state instead: no student matched.
        $this->assertStringContainsString('No records found matching your active filters.', $html);

        // The export shares the query, so it cannot leak the record either.
        $csv = $this->asCollege($college, $viewer)
            ->get(route('students.export', ['search' => 'STU-GONE']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringNotContainsString('STU-GONE', $csv);
        $this->assertStringNotContainsString('Trashed', $csv);

        // Soft delete preserved — the row is still in the table, only hidden.
        // With the college context set explicitly, the model's normal query
        // (SoftDeletingScope + CollegeScope) finds nothing, while the fully
        // unscoped count proves the delete was never a hard delete. Asserting
        // both keeps this about SoftDeletes rather than about tenant scoping.
        $this->assertSoftDeleted('students', ['id' => $student->id]);
        app(TenantContext::class)->set($college);
        $this->assertSame(0, Student::query()->where('student_number', 'STU-GONE')->count(),
            'A normal student query must not see the trashed row.');
        $this->assertSame(1, Student::withoutGlobalScopes()->where('student_number', 'STU-GONE')->count(),
            'The row must still exist: soft delete, never a hard delete.');
    }

    /* ------------------------------------------------------------------ *\
     * Filters added on top of the original set: Registration Number,
     * Blood group, Nationality, Mother tongue and Date of birth.
     * \* ------------------------------------------------------------------ */

    public function test_registration_number_filter_matches_a_partial_number(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF13');

        $this->makeStudent($college, ['first_name' => 'Match', 'student_number' => 'STU-2026-0042']);
        $this->makeStudent($college, ['first_name' => 'Other', 'student_number' => 'STU-2025-0007']);

        // A partial number is enough: this is a contains-match on one column, not
        // a second full-text search.
        $this->list($college, $viewer, ['student_number' => '2026-00'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 1
                && $students->first()->student_number === 'STU-2026-0042');

        // And the whole number still matches the field it was typed into.
        $this->list($college, $viewer, ['student_number' => 'STU-2025-0007'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 1);
    }

    public function test_the_registration_number_filter_is_not_the_search_box(): void
    {
        // The two are separate controls on purpose: typing in one must not run the
        // multi-column group search, or a name would match "Registration Number".
        [$college, $viewer] = $this->collegeWithViewer('SLF14');

        $this->makeStudent($college, ['first_name' => 'Riya', 'student_number' => 'STU-3001-0001']);

        $this->list($college, $viewer, ['student_number' => 'Riya'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 0);

        // The search box still does cross-column matching, unchanged.
        $this->list($college, $viewer, ['search' => 'Riya'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 1);
    }

    public function test_blood_group_filter_uses_the_model_allow_list(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF15');

        $this->makeStudent($college, ['first_name' => 'O Positive', 'blood_group' => 'O+']);
        $this->makeStudent($college, ['first_name' => 'A Positive', 'blood_group' => 'A+']);

        $this->list($college, $viewer, ['blood_group' => 'O+'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 1
                && $students->first()->blood_group === 'O+');

        // A value outside Student::BLOOD_GROUPS is ignored, not rejected and not
        // interpolated — the list simply is not narrowed. Same rule as the other
        // whitelisted filters.
        $this->list($college, $viewer, ['blood_group' => 'Z+'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 2);
    }

    public function test_date_of_birth_range_filters_between_the_bounds(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF16');

        $this->makeStudent($college, ['first_name' => 'Early', 'date_of_birth' => '2004-12-31']);
        $this->makeStudent($college, ['first_name' => 'Inside', 'date_of_birth' => '2005-06-15']);
        $this->makeStudent($college, ['first_name' => 'Late', 'date_of_birth' => '2006-01-01']);

        // Both bounds inclusive, one student outside the window.
        $this->list($college, $viewer, ['date_of_birth_from' => '2005-01-01', 'date_of_birth_to' => '2005-12-31'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 1
                && $students->first()->first_name === 'Inside');

        // An open-ended range: "born on or after this date" keeps two.
        $this->list($college, $viewer, ['date_of_birth_from' => '2005-01-01'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 2);

        // A range that matches nobody renders the empty state, not an error.
        $this->list($college, $viewer, ['date_of_birth_from' => '1990-01-01', 'date_of_birth_to' => '1990-12-31'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 0);
    }

    public function test_nationality_and_mother_tongue_are_contains_matches(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF17');

        $this->makeStudent($college, ['first_name' => 'Delhi', 'nationality' => 'Indian', 'mother_tongue' => 'Hindi']);
        $this->makeStudent($college, ['first_name' => 'Kathmandu', 'nationality' => 'Nepali', 'mother_tongue' => 'Nepali']);
        $this->makeStudent($college, ['first_name' => 'Blank', 'nationality' => null, 'mother_tongue' => null]);

        // Part of the value is enough for both fields.
        $this->list($college, $viewer, ['nationality' => 'Ind'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 1
                && $students->first()->first_name === 'Delhi');

        $this->list($college, $viewer, ['mother_tongue' => 'Nep'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 1
                && $students->first()->first_name === 'Kathmandu');
    }

    public function test_a_text_filter_narrows_only_its_own_column(): void
    {
        // Mother tongue "Nepali" and nationality "Nepali" share a word. Filtering
        // by nationality must not return the student who merely speaks it, which
        // is what would happen if these were aliased onto the group search.
        [$college, $viewer] = $this->collegeWithViewer('SLF18');

        $this->makeStudent($college, ['first_name' => 'ByNationality', 'nationality' => 'Nepali', 'mother_tongue' => 'Hindi']);
        $this->makeStudent($college, ['first_name' => 'ByTongue', 'nationality' => 'Indian', 'mother_tongue' => 'Nepali']);

        $this->list($college, $viewer, ['nationality' => 'Nepali'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 1
                && $students->first()->first_name === 'ByNationality');

        $this->list($college, $viewer, ['mother_tongue' => 'Nepali'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 1
                && $students->first()->first_name === 'ByTongue');
    }

    public function test_the_new_filters_combine(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF19');

        $this->makeStudent($college, ['first_name' => 'Target', 'nationality' => 'Indian', 'blood_group' => 'O+', 'date_of_birth' => '2005-06-15']);
        $this->makeStudent($college, ['first_name' => 'WrongBlood', 'nationality' => 'Indian', 'blood_group' => 'A+', 'date_of_birth' => '2005-06-15']);
        $this->makeStudent($college, ['first_name' => 'WrongNationality', 'nationality' => 'Nepali', 'blood_group' => 'O+', 'date_of_birth' => '2005-06-15']);
        $this->makeStudent($college, ['first_name' => 'WrongDob', 'nationality' => 'Indian', 'blood_group' => 'O+', 'date_of_birth' => '2007-06-15']);

        // All four conditions must hold on the SAME student.
        $this->list($college, $viewer, [
            'nationality' => 'Indian',
            'blood_group' => 'O+',
            'date_of_birth_from' => '2005-01-01',
            'date_of_birth_to' => '2005-12-31',
        ])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 1
                && $students->first()->first_name === 'Target');
    }

    public function test_identity_filters_expose_only_safe_supported_controls(): void
    {
        // Full encrypted identity values and unrelated government IDs remain
        // unavailable. APAAR is a separate canonical identifier; Aadhaar uses
        // only its existing last-four column and is permission-gated.
        [$college, $viewer] = $this->collegeWithViewer('SLF20');

        $html = $this->list($college, $viewer)->assertOk()->getContent();

        foreach (['aadhaar_number', 'aadhaar_hash', 'govt_id_number', 'govt_id_type', 'aadhaar_last4'] as $field) {
            $this->assertStringNotContainsString(
                'name="'.$field.'"',
                $html,
                "The {$field} column must not be exposed to an ordinary viewer."
            );
        }

        $this->assertStringContainsString('name="student_number"', $html);
        $this->assertStringContainsString('name="apaar_id"', $html);
    }

    public function test_malformed_values_for_the_new_filters_are_ignored(): void
    {
        // An array where a text input expects one value is a fatal "Array to
        // string conversion" if it is ever interpolated into a LIKE pattern. The
        // filter must read it as "no filter" and render the page.
        [$college, $viewer] = $this->collegeWithViewer('SLF21');

        $this->makeStudent($college, ['first_name' => 'Safe']);

        $queries = [
            ['nationality' => ['nested']],
            ['mother_tongue' => ['nested']],
            ['student_number' => ['nested']],
            ['blood_group' => ['nested']],
            ['date_of_birth_from' => ['nested']],
            ['date_of_birth_to' => ['nested']],
        ];

        foreach ($queries as $query) {
            $this->list($college, $viewer, $query)
                ->assertOk()
                ->assertViewHas('students', fn ($students) => $students->total() === 1);
        }

        // A date-of-birth value that is not a plain Y-m-d date is ignored too:
        // the range filter is regex-checked and never casts junk into a date.
        foreach (['not-a-date', '15/06/2005', '2005-6-1'] as $bad) {
            $this->list($college, $viewer, ['date_of_birth_from' => $bad, 'date_of_birth_to' => $bad])
                ->assertOk()
                ->assertViewHas('students', fn ($students) => $students->total() === 1);
        }
    }

    public function test_the_new_filters_are_kept_in_the_pagination_links(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('SLF22');

        // Enough rows to paginate (the module pages at 15). No enrollment is
        // needed: both filters live on the students table itself.
        foreach (range(1, 18) as $i) {
            $this->makeStudent($college, [
                'first_name' => 'Paged'.sprintf('%02d', $i),
                'nationality' => 'Indian',
                'blood_group' => 'O+',
            ]);
        }

        $response = $this->list($college, $viewer, ['blood_group' => 'O+', 'nationality' => 'Indian'])->assertOk();

        $response->assertViewHas('students', fn ($students) => $students->total() === 18);

        // Every page link keeps the new filters (asserted on the decoded href, the
        // same way the original pagination test does).
        $html = $response->getContent();
        $this->assertGreaterThan(0, preg_match('/href="([^"]*page=2[^"]*)"/', $html, $matches), 'Expected a page 2 link.');
        $pageLink = html_entity_decode($matches[1]);
        $this->assertStringContainsString('blood_group=O%2B', $pageLink, 'The blood group filter must survive paging.');
        $this->assertStringContainsString('nationality=Indian', $pageLink, 'The nationality filter must survive paging.');

        // Following the pager keeps the new filters: 18 rows at 15 per page means
        // page 2 holds the remaining 3, and nothing else filters them away.
        $this->list($college, $viewer, ['blood_group' => 'O+', 'nationality' => 'Indian', 'page' => 2])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 18 && $students->count() === 3);
    }

    public function test_the_export_shares_the_new_filters(): void
    {
        // The export is the SAME StudentListService pipeline as the list, so a
        // filter cannot apply on screen and disappear from the download.
        [$college, $viewer] = $this->collegeWithViewer('SLF23', ['students.view', 'students.export']);

        $this->makeStudent($college, ['first_name' => 'Exported', 'nationality' => 'Indian']);
        $this->makeStudent($college, ['first_name' => 'Excluded', 'nationality' => 'Nepali']);

        $this->list($college, $viewer, ['nationality' => 'Indian'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 1);

        $csv = $this->asCollege($college, $viewer)
            ->get(route('students.export', ['nationality' => 'Indian']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Exported', $csv, 'The filtered row must be exported.');
        $this->assertStringNotContainsString('Excluded', $csv, 'A row the filter excludes must not appear in the export.');
    }
}
