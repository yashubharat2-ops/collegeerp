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
        $this->assertSame(2, substr_count($html, 'data-date-picker-toggle'));
        $this->assertSame(2, substr_count($html, 'data-date-picker-prev'));
        $this->assertSame(2, substr_count($html, 'data-date-picker-next'));
        $this->assertStringContainsString('data-date-picker-calendar', $html);
        $this->assertStringContainsString('data-date-picker-days', $html);
        $this->assertStringContainsString('data-date-picker-clear', $html);
        $this->assertStringContainsString('data-date-picker-close', $html);
        $this->assertStringContainsString('erp-list-date-controls', $html);
        $this->assertStringContainsString('erp-list-date-separator', $html);
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

        $this->assertSame(2, substr_count($html, 'data-date-picker-popover'));
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertSame(2, substr_count($html, 'data-list-date-display'));
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
            'academic_year_id', 'department_id', 'program_id', 'academic_term_id', 'section_id',
            'gender', 'category', 'status', 'enrollment_status', 'admission_date_from', 'admission_date_to',
        ] as $filter) {
            $this->assertStringContainsString('name="'.$filter.'"', $html, "The {$filter} filter must be rendered.");
        }
        foreach ([
            'Academic year', 'Department', 'Program / Course', 'Academic term / semester',
            'Section / Batch', 'Gender', 'Category', 'Student status', 'Enrollment status',
            'Admission date', 'From', 'To',
        ] as $label) {
            $this->assertStringContainsString($label, $html, "The {$label} filter label must be visible.");
        }

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
}
