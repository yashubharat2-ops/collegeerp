<?php
namespace Tests\Feature\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;
class PasswordResetTest extends TestCase
{
    public function test_reset_link_request_uses_password_broker(): void
    {
        Password::shouldReceive('sendResetLink')->once()->andReturn(Password::RESET_LINK_SENT);
        $this->post('/forgot-password', ['email' => User::first()->email])->assertSessionHas('status');
    }
}
