<?php

namespace Tests\Feature\Transport;

use App\Models\{College, Permission, Role, User};
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Transport Phase 2 — Navigation: the Transport group shows exactly the nine
 * required entries in order, with Routes and Stops as SEPARATE items, each
 * individually permission-gated, and the whole group hidden when the user has
 * none of the transport permissions.
 */
class TransportNavigationTest extends TestCase
{
    /** The nine entries in their exact required order: label => routes shown. */
    private const ENTRIES = [
        'Transport Dashboard' => 'transport.dashboard',
        'Vehicles' => 'vehicles.index',
        'Vehicle Documents' => 'vehicle-documents.index',
        'Drivers' => 'transport-drivers.index',
        'Routes' => 'transport-routes.index',
        'Stops' => 'transport-stops.list',
        'Student Transport Assignment' => 'transport-assignments.index',
        'Transport Fees' => 'transport-fees.index',
        'Transport Reports' => 'transport-reports.index',
    ];

    private const VIEW_PERMISSIONS = [
        'transport_dashboard.view', 'vehicles.view', 'vehicle_documents.view', 'transport_drivers.view',
        'transport_routes.view', 'student_transport_assignments.view', 'transport_fees.view', 'transport_reports.view',
    ];

    private function college(string $code): College
    {
        return College::create(['name' => $code, 'code' => $code, 'slug' => strtolower($code), 'status' => 'active']);
    }

    private function user(College $college, ?array $permissions = null): User
    {
        $user = User::create(['name' => 'Nav Staff', 'email' => Str::uuid().'@example.test', 'password' => 'password', 'is_active' => true]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        $role = Role::create(['college_id' => $college->id, 'name' => 'Nav', 'slug' => (string) Str::uuid(), 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissions ?? self::VIEW_PERMISSIONS)->pluck('id'));
        $user->roles()->attach($role->id, ['college_id' => $college->id]);
        return $user;
    }

    private function login(College $college, ?array $permissions = null): User
    {
        $user = $this->user($college, $permissions);
        $this->actingAs($user)->withSession(['active_college_id' => $college->id]);
        return $user;
    }

    private function transportNavBlock(string $html): string
    {
        $this->assertStringContainsString('Transport Management', $html);
        preg_match('/Transport Management<\/div>(.*?)>(Platform|Academics|Admissions|Examinations|Finance|HR|Library|Settings)/s', $html, $matches);
        $this->assertNotEmpty($matches, 'Transport nav block could not be located in the layout.');
        return $matches[1];
    }

    public function test_transport_navigation_shows_exactly_nine_entries_in_order(): void
    {
        $a = $this->college('NAV9');
        $this->login($a);
        $response = $this->get(route('transport.dashboard'))->assertOk();
        $block = $this->transportNavBlock($response->getContent());

        preg_match_all('/class="nav-link"/', $block, $hits);
        $this->assertCount(9, $hits[0], 'The Transport group must contain exactly nine navigation entries.');

        $response->assertSeeInOrder(array_map(
            fn (string $route) => 'href="'.route($route).'"',
            array_values(self::ENTRIES),
        ), false);
    }

    public function test_routes_and_stops_are_separate_navigation_entries(): void
    {
        $a = $this->college('NAVRS');
        $this->login($a, ['transport_routes.view']);
        $response = $this->get(route('transport-routes.index'))->assertOk();
        $block = $this->transportNavBlock($response->getContent());

        // Two separate entries — never one combined "Routes / Stops" item.
        $this->assertStringNotContainsString('Routes / Stops', $response->getContent());
        $this->assertStringContainsString('<span>Routes</span>', $block);
        $this->assertStringContainsString('<span>Stops</span>', $block);
        $this->assertStringContainsString('href="'.route('transport-routes.index').'"', $block);
        $this->assertStringContainsString('href="'.route('transport-stops.list').'"', $block);

        // The Stops entry opens the flat cross-route listing.
        $this->get(route('transport-stops.list'))->assertOk();
    }

    public function test_navigation_items_are_individually_permission_gated(): void
    {
        // permission => the routes its nav entry/entries link to.
        $gates = [
            'transport_dashboard.view' => ['transport.dashboard'],
            'vehicles.view' => ['vehicles.index'],
            'vehicle_documents.view' => ['vehicle-documents.index'],
            'transport_drivers.view' => ['transport-drivers.index'],
            // Routes and Stops share the routes permission but stay separate items.
            'transport_routes.view' => ['transport-routes.index', 'transport-stops.list'],
            'student_transport_assignments.view' => ['transport-assignments.index'],
            'transport_fees.view' => ['transport-fees.index'],
            'transport_reports.view' => ['transport-reports.index'],
        ];

        foreach ($gates as $permission => $own) {
            $a = $this->college('NAVG-'.str_replace('.', '-', $permission));
            $this->login($a, [$permission]);
            $response = $this->get(route($own[0]))->assertOk();
            $block = $this->transportNavBlock($response->getContent());

            foreach ($own as $route) {
                $this->assertStringContainsString('href="'.route($route).'"', $block, "Missing {$route} for {$permission}");
            }
            foreach (self::ENTRIES as $entryRoute) {
                if (in_array($entryRoute, $own, true)) {
                    continue;
                }
                $this->assertStringNotContainsString('href="'.route($entryRoute).'"', $block, "Leaked {$entryRoute} for {$permission}");
            }
        }
    }

    public function test_transport_group_is_hidden_without_any_transport_permission(): void
    {
        $a = $this->college('NAVH');
        // A user with a non-transport permission sees none of the group.
        $this->login($a, ['books.view']);
        $response = $this->get(route('books.index'))->assertOk();
        $this->assertStringNotContainsString('Transport Management', $response->getContent());
        foreach (self::ENTRIES as $route) {
            $this->assertStringNotContainsString('href="'.route($route).'"', $response->getContent());
        }
    }

    public function test_super_admin_sees_the_complete_transport_menu(): void
    {
        $a = $this->college('NAVSUP');
        $user = $this->user($a, []);
        $super = Role::where('slug', 'super-admin')->whereNull('college_id')->firstOrFail();
        $user->roles()->attach($super->id, ['college_id' => null]);
        $this->actingAs($user)->withSession(['active_college_id' => $a->id]);

        $response = $this->get(route('vehicles.index'))->assertOk();
        $block = $this->transportNavBlock($response->getContent());
        preg_match_all('/class="nav-link"/', $block, $hits);
        $this->assertCount(9, $hits[0]);
        foreach (self::ENTRIES as $label => $route) {
            $this->assertStringContainsString('href="'.route($route).'"', $block);
            $this->assertStringContainsString('<span>'.$label.'</span>', $block);
        }
    }
}
