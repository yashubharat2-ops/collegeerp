<?php

namespace Tests\Feature\Finance;

use Tests\TestCase;

/**
 * Finance / Fees — filter bar layout (UI regression).
 *
 * The filter bars of Receipts, Refunds, Fee Reports and Fee Collections all sat
 * their date range in a plain `flex gap-2` box inside one track of a six-column
 * grid. Both controls are `w-full` and a native date widget has an intrinsic
 * minimum width, so the pair could not shrink inside the track: it pushed past
 * the card and produced horizontal page overflow.
 *
 * The fix is structural, so the guarantees are asserted on the rendered HTML
 * rather than on a screenshot:
 *
 *   * the date pair lives in a two-column grid of `minmax(0, 1fr)` tracks
 *     (Tailwind `grid-cols-2`) and every date control carries `min-w-0`, so a
 *     control shrinks with its track instead of overflowing the card;
 *   * the date cell claims two tracks of the filter grid (`sm:col-span-2`), so
 *     both dates stay readable instead of being squeezed into one track;
 *   * the filter bar is a wrapping responsive grid — one `grid-cols-*` utility
 *     per breakpoint, never a fixed or arbitrary width — and the action buttons
 *     live in their own wrapping row, aligned with the inputs;
 *   * nothing in the filter bar uses an inline style or a pixel width.
 */
class FinanceFilterLayoutTest extends TestCase
{
    use FeeStructureTestHelpers;

    /**
     * Route → view permission, for every Finance / Fees screen with a filter bar.
     *
     * @var array<string, string>
     */
    private const FILTER_BARS = [
        'receipts.index' => 'receipts.view',
        'refunds.index' => 'refunds.view',
        'fee-reports.index' => 'fee_reports.view',
        'fee-collections.index' => 'fee_collections.view',
    ];

    /**
     * Render one screen and return its page and filter-form markup.
     *
     * @param  array<string, mixed>  $query
     * @return array{page: string, form: string}
     */
    private function renderFilterBar(string $route, string $permission, array $query = []): array
    {
        $college = $this->makeCollege('FLT'.strtoupper(substr(md5($route), 0, 5)));
        $user = $this->makeUserWithPermissions($college, [$permission]);

        $response = $this->asCollege($college, $user)->get(route($route, $query));

        $response->assertOk();

        $page = (string) $response->getContent();
        $start = strpos($page, '<form class="mt-6 grid');
        $panelStart = strpos($page, 'class="panel"');
        $tableStart = strpos($page, '<table');

        $this->assertNotFalse($panelStart, "The {$route} screen must render its card.");
        $this->assertNotFalse($start, "The {$route} filter bar must be a grid form.");
        $this->assertNotFalse($tableStart, "The {$route} screen must render its results table.");

        $end = strpos($page, '</form>', (int) $start);

        $this->assertNotFalse($end, "The {$route} filter bar must be a closed form.");

        // The filter bar sits between the card opening and the results table,
        // i.e. inside the card — never overflowing the panel.
        $this->assertGreaterThan((int) $panelStart, (int) $start, "The {$route} filter bar must sit inside the card.");
        $this->assertLessThan((int) $tableStart, (int) $end, "The {$route} filter bar must close before the results table.");

        return [
            'page' => $page,
            'form' => substr($page, (int) $start, (int) $end - (int) $start),
        ];
    }

    public function test_the_filter_bar_is_a_wrapping_grid_without_fixed_widths(): void
    {
        foreach (self::FILTER_BARS as $route => $permission) {
            ['form' => $form] = $this->renderFilterBar($route, $permission);

            $this->assertMatchesRegularExpression(
                '/^<form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-\d+ xl:grid-cols-\d+"/',
                $form,
                "The {$route} filter bar must wrap into fewer columns on smaller screens."
            );

            // No pixel widths and no inline styles anywhere in the filter bar.
            $this->assertStringNotContainsString('style=', $form, "The {$route} filter bar must not style inline.");
            $this->assertDoesNotMatchRegularExpression(
                '/\b(?:w|min-w|max-w|basis)-\[/',
                $form,
                "The {$route} filter bar must not use arbitrary pixel widths."
            );
            $this->assertStringNotContainsString('overflow-x', $form, "The {$route} filter bar must wrap, not scroll.");
        }
    }

    public function test_the_date_range_is_a_two_column_grid_of_shrinkable_controls(): void
    {
        foreach (self::FILTER_BARS as $route => $permission) {
            ['form' => $form] = $this->renderFilterBar($route, $permission);

            $this->assertStringContainsString(
                '<div class="grid grid-cols-2 gap-2">',
                $form,
                "The {$route} date range must be a minmax(0, 1fr) grid so both dates share one track."
            );

            $this->assertMatchesRegularExpression(
                '/<div class="sm:col-span-2">\s*<label class="label" for="from">Date from \/ to<\/label>/',
                $form,
                "The {$route} date range must claim two tracks of the filter grid."
            );

            // min-w-0 is the piece that removes the native date widget's intrinsic
            // minimum width — without it the pair overflows its track again.
            $this->assertMatchesRegularExpression(
                '/<input class="input min-w-0" id="from" name="from" type="date"[^>]*value="\{\{ \$selected\[\'from\'\] \}\}">/',
                $form,
                "The {$route} \"Date from\" control must be able to shrink."
            );
            $this->assertMatchesRegularExpression(
                '/<input class="input min-w-0" id="to" name="to" type="date"[^>]*value="\{\{ \$selected\[\'to\'\] \}\}">/',
                $form,
                "The {$route} \"Date to\" control must be able to shrink."
            );

            $this->assertSame(2, substr_count($form, 'type="date"'), "The {$route} filter bar has exactly two date controls.");
            $this->assertSame(2, substr_count($form, 'min-w-0'), "Both {$route} date controls carry min-w-0.");
        }
    }

    public function test_the_filter_actions_wrap_in_their_own_row_next_to_the_inputs(): void
    {
        foreach (self::FILTER_BARS as $route => $permission) {
            ['form' => $form] = $this->renderFilterBar($route, $permission);

            $this->assertMatchesRegularExpression(
                '/<div class="sm:col-span-2 lg:col-span-\d+ xl:col-span-\d+ flex flex-wrap items-end gap-2">/',
                $form,
                "The {$route} action row must wrap and stay aligned with the controls."
            );
        }
    }

    public function test_the_date_filters_still_render_their_values(): void
    {
        foreach (self::FILTER_BARS as $route => $permission) {
            ['form' => $form] = $this->renderFilterBar($route, $permission, [
                'from' => '2026-08-01',
                'to' => '2026-08-31',
            ]);

            $this->assertMatchesRegularExpression(
                '/id="from"[^>]*value="2026-08-01"/',
                $form,
                "The {$route} \"Date from\" value must survive the layout change."
            );
            $this->assertMatchesRegularExpression(
                '/id="to"[^>]*value="2026-08-31"/',
                $form,
                "The {$route} \"Date to\" value must survive the layout change."
            );
        }
    }
}
