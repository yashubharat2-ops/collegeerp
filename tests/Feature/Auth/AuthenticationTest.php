<?php
namespace Tests\Feature\Auth;
use Tests\TestCase;
class AuthenticationTest extends TestCase
{
    public function test_guest_pages_render_without_vite_assets(): void
    {
        // A fresh checkout has no build or Vite dev server. Isolate this from
        // any frontend assets a developer may have built in their working copy.
        $originalPublicPath = public_path();
        $emptyPublicPath = sys_get_temp_dir().'/collegeerp-public-'.bin2hex(random_bytes(8));
        mkdir($emptyPublicPath);

        try {
            app()->usePublicPath($emptyPublicPath);

            $this->get(route('login'))->assertOk()->assertSee('Welcome back');
            $this->get(route('password.request'))->assertOk();
        } finally {
            app()->usePublicPath($originalPublicPath);
            rmdir($emptyPublicPath);
        }
    }

    public function test_user_can_login_and_logout(): void
    {
        $this->post('/login', ['email' => 'test@example.com', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
    public function test_invalid_login_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) $this->post('/login', ['email' => 'test@example.com', 'password' => 'wrong']);
        $this->post('/login', ['email' => 'test@example.com', 'password' => 'wrong'])->assertStatus(429);
    }
}
