<?php
namespace Tests\Feature\Auth;
use Tests\TestCase;
class AuthenticationTest extends TestCase
{
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
