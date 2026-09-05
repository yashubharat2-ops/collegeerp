<?php
namespace Tests\Unit\Authorization;
use App\Models\User;
use App\Support\Authorization\PermissionChecker;
use Tests\TestCase;
class PermissionCheckerTest extends TestCase
{
    public function test_permission_checker_delegates_to_database_rbac(): void
    {
        $user = User::first();
        $this->assertTrue(app(PermissionChecker::class)->allows($user, 'campuses.view'));
        $this->assertFalse(app(PermissionChecker::class)->allows($user, 'not-a-real-permission'));
    }
}
