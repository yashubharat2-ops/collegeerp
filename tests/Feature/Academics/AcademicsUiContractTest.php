<?php

namespace Tests\Feature\Academics;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The Academic module's UI contract.
 *
 * The reported regression was not a markup problem: every Academic view has
 * always carried `class="card"`, `class="table"` and `class="btn-primary"`, but
 * no stylesheet in the project defined those three names. Tailwind's preflight
 * resets form controls (background, border and padding all become empty), so an
 * undefined class on a <button> renders as plain text — which is exactly how the
 * "Save enrollment" / "Save timetable" / "Save event" buttons were reported.
 *
 * These checks keep the markup and the theme in step, and keep every save
 * control a real, submittable button:
 *
 *  1. every component class the Academic views use is defined in the theme;
 *  2. every POST form under resources/views/academics is CSRF-protected and has
 *     a real <button> save control (a text node cannot submit a form).
 */
class AcademicsUiContractTest extends TestCase
{
    /**
     * Academic component class → the theme rule that has to define it.
     *
     * @var array<string, string>
     */
    private const COMPONENT_CLASSES = [
        'card' => '.card {',
        'table' => '.table {',
        'btn-primary' => '.btn-primary {',
    ];

    public function test_every_component_class_used_by_the_academic_views_is_defined_in_the_theme(): void
    {
        $views = File::allFiles(resource_path('views/academics'));
        $this->assertNotEmpty($views);

        $used = [];

        foreach ($views as $view) {
            foreach ($this->classTokens($view->getContents()) as $token) {
                if (array_key_exists($token, self::COMPONENT_CLASSES)) {
                    $used[$token] = true;
                }
            }
        }

        // The views really do use them, so this contract cannot go stale.
        foreach (array_keys(self::COMPONENT_CLASSES) as $token) {
            $this->assertArrayHasKey(
                $token,
                $used,
                "No Academic view uses `{$token}` any more — update this contract together with the views.",
            );
        }

        // ...and the theme defines every one of them.
        $theme = File::get(resource_path('css/app.css'));

        foreach (self::COMPONENT_CLASSES as $token => $rule) {
            $this->assertStringContainsString(
                $rule,
                $theme,
                "The theme must define `{$token}`: the Academic views carry the class, "
                .'so without a rule an unstyled element (a plain-text button) is rendered.',
            );
        }
    }

    public function test_every_academic_post_form_has_a_real_csrf_protected_save_button(): void
    {
        $checked = 0;

        foreach (File::allFiles(resource_path('views/academics')) as $view) {
            foreach ($this->assertMatchesPattern('/<form\b[^>]*>.*?<\/form>/s', $view->getContents()) as $form) {
                // Filter / search forms are GET: they hold no save control.
                if (! preg_match('/method="post"/i', $form)) {
                    continue;
                }

                $checked++;
                $name = $view->getRelativePathname();

                $this->assertStringContainsString('@csrf', $form, "{$name} must keep its CSRF token.");

                $buttons = $this->assertMatchesPattern('/<button\b[^>]*>\s*[^<]+\s*<\/button>/', $form);

                $this->assertNotEmpty(
                    $buttons,
                    "{$name} must keep a real <button> save control — plain text cannot submit the form.",
                );
                $this->assertStringContainsString(
                    'btn-primary',
                    $buttons[0],
                    "{$name}'s save button must keep the themed primary-button class.",
                );
            }
        }

        // Subject enrollments, timetables and calendar each own one create form.
        $this->assertGreaterThanOrEqual(3, $checked, 'The Academic module must still expose its create forms.');
    }

    /**
     * Every class token of every static class attribute in one Blade file.
     *
     * @return array<int, string>
     */
    private function classTokens(string $blade): array
    {
        $tokens = [];

        foreach ($this->assertMatchesPattern('/class="([^"]*)"/', $blade, 1) as $attribute) {
            foreach (preg_split('/\s+/', trim($attribute)) ?: [] as $token) {
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }

    /**
     * Every match of $pattern in $subject (the $group-th capture group).
     *
     * Deliberately NOT called `matches()`: PHPUnit's Assert::matches() is final,
     * so a method with that name in a TestCase subclass is a fatal error
     * ("Cannot override final method PHPUnit\Framework\Assert::matches()") and it
     * breaks the whole suite, not just this file. The name used here is absent
     * from the whole Assert + TestCase API.
     *
     * @return array<int, string>
     */
    private function assertMatchesPattern(string $pattern, string $subject, int $group = 0): array
    {
        preg_match_all($pattern, $subject, $found);

        return $found[$group] ?? [];
    }
}
