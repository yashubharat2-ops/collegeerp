<?php

namespace Tests\Feature\Layout;

use App\Models\{College, Permission};
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * Students → Registration vs Students → Student List in the sidebar.
 *
 * The reported defect: clicking "Registration" appeared to open the Student
 * List. The route was right — the wizard entry point redirected into
 * `/students/registration/step/1` — but the ACTIVE highlight was wrong, because
 * the generic resource widening of the Student List row (`students.index` →
 * `students.*`) also matched every `students.registration.*` route. The wizard
 * therefore looked like a page of the Student List, and the Registration row
 * never lit up.
 *
 * These tests pin the two rows independently: destinations, the highlight on the
 * wizard screens, the highlight on the list, and the absence of a second
 * Registration row.
 */
class RegistrationNavigationTest extends TestCase
{
    use DepartmentTestHelpers;

    private function fullAccessCollege(string $code = 'NAV1'): array
    {
        $college = $this->makeCollege($code);
        $user = $this->makeUserWithPermissions($college, Permission::query()->pluck('slug')->all());

        return [$college, $user];
    }

    /** Everything between the sidebar's opening tag and its close. */
    private function aside(string $html): string
    {
        $start = strpos($html, '<aside');
        $this->assertNotFalse($start, 'The layout must render the sidebar.');
        $end = strpos($html, '</aside>', $start);
        $this->assertNotFalse($end, 'The sidebar must close.');

        return substr($html, $start, $end - $start);
    }

    /** @return array<int, array{href: string, label: string}> the rows marked aria-current */
    private function currentRows(string $markup): array
    {
        preg_match_all('/<a\s[^>]*class="nav-link"[^>]*aria-current="page"[^>]*>(.*?)<\/a>/s', $markup, $matches);

        $rows = [];

        foreach ($matches[0] as $index => $anchor) {
            preg_match('/href="([^"]+)"/', $anchor, $href);

            $rows[] = [
                'href' => $href[1] ?? '',
                'label' => trim(strip_tags($matches[1][$index])),
            ];
        }

        return $rows;
    }

    /** The one row whose label is exactly $label. */
    private function rowFor(string $markup, string $label): string
    {
        preg_match_all('/<a\s[^>]*class="nav-link"[^>]*>.*?<\/a>/s', $markup, $rows);

        $found = array_values(array_filter(
            $rows[0],
            fn (string $row): bool => trim(strip_tags($row)) === $label,
        ));

        $this->assertCount(1, $found, "There must be exactly one \"{$label}\" row in the sidebar.");

        return $found[0];
    }

    private function hrefOf(string $row): string
    {
        preg_match('/href="([^"]+)"/', $row, $match);

        return $match[1] ?? '';
    }

    private function isCurrent(string $row): bool
    {
        return str_contains($row, 'aria-current="page"');
    }

    public function test_the_registration_row_opens_the_registration_wizard(): void
    {
        [$college, $user] = $this->fullAccessCollege('NAV2');

        $aside = $this->aside($this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent());
        $registration = $this->rowFor($aside, 'Registration');

        $this->assertSame(
            route('students.registration.create'),
            $this->hrefOf($registration),
            'Registration must point at the wizard entry route, not at the student list.',
        );

        // Following that link lands in the wizard: the entry point resumes (or
        // starts) a draft and redirects to the step the draft is on.
        $this->asCollege($college, $user)
            ->get(route('students.registration.create'))
            ->assertRedirect(route('students.registration.step', ['step' => 1]));

        $this->asCollege($college, $user)
            ->get(route('students.registration.step', ['step' => 1]))
            ->assertOk()
            ->assertSee('Student Registration')
            ->assertSee('Basic Information');
    }

    public function test_the_student_list_row_still_opens_the_student_list(): void
    {
        [$college, $user] = $this->fullAccessCollege('NAV3');

        $aside = $this->aside($this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent());
        $list = $this->rowFor($aside, 'Student List');

        $this->assertSame(route('students.index'), $this->hrefOf($list), 'Student List keeps its own destination.');

        $this->asCollege($college, $user)->get(route('students.index'))
            ->assertOk()
            ->assertSee('Students');
    }

    public function test_only_the_registration_row_is_current_while_the_wizard_is_open(): void
    {
        [$college, $user] = $this->fullAccessCollege('NAV4');

        // Create the draft through the entry point, then open a real wizard step.
        $this->asCollege($college, $user)->get(route('students.registration.create'))->assertRedirect();

        $html = $this->asCollege($college, $user)
            ->get(route('students.registration.step', ['step' => 1]))
            ->assertOk()
            ->getContent();

        $aside = $this->aside($html);
        $current = $this->currentRows($aside);

        $this->assertCount(1, $current, 'Exactly one sidebar row may be highlighted.');
        $this->assertSame('Registration', $current[0]['label'], 'The wizard belongs to the Registration row.');
        $this->assertSame(route('students.registration.create'), $current[0]['href']);
        $this->assertFalse(
            $this->isCurrent($this->rowFor($aside, 'Student List')),
            'Student List must not be highlighted on a wizard screen — that is the reported defect.',
        );
    }

    public function test_the_student_list_row_is_current_on_the_list_and_not_on_the_wizard(): void
    {
        [$college, $user] = $this->fullAccessCollege('NAV5');

        $listAside = $this->aside($this->asCollege($college, $user)->get(route('students.index'))->assertOk()->getContent());
        $listCurrent = $this->currentRows($listAside);

        $this->assertCount(1, $listCurrent, 'The list page highlights exactly one row.');
        $this->assertSame('Student List', $listCurrent[0]['label']);
        $this->assertFalse(
            $this->isCurrent($this->rowFor($listAside, 'Registration')),
            'Registration is not the current page on the student list.',
        );
    }

    public function test_the_wizard_steps_keep_the_registration_row_current(): void
    {
        [$college, $user] = $this->fullAccessCollege('NAV6');

        $this->asCollege($college, $user)->get(route('students.registration.create'))->assertRedirect();

        // Steps 1–5 are all wizard screens and all belong to Registration.
        foreach ([1, 2, 3, 4, 5] as $step) {
            $aside = $this->aside(
                $this->asCollege($college, $user)
                    ->get(route('students.registration.step', ['step' => $step]))
                    ->assertOk()
                    ->getContent()
            );

            $current = $this->currentRows($aside);

            $this->assertCount(1, $current, "Step {$step} highlights exactly one row.");
            $this->assertSame('Registration', $current[0]['label'], "Step {$step} belongs to Registration.");
        }
    }

    public function test_the_students_group_has_one_registration_row(): void
    {
        [$college, $user] = $this->fullAccessCollege('NAV7');

        $aside = $this->aside($this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent());

        $this->assertSame(
            1,
            substr_count($aside, '>Registration</span>'),
            'Registration must appear once — a duplicate menu item is not an acceptable fix.',
        );
        $this->assertSame(
            1,
            substr_count($aside, route('students.registration.create')),
            'One link may point at the wizard entry point.',
        );
    }
}
