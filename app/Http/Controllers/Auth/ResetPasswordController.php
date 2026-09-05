<?php
namespace App\Http\Controllers\Auth;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
class ResetPasswordController
{
    public function create(string $token): View { return view('auth.reset-password', compact('token')); }
    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        $status = Password::reset($request->validated(), function ($user, $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });
        return $status === Password::PASSWORD_RESET ? redirect()->route('login')->with('status', __($status)) : back()->withErrors(['email' => __($status)]);
    }
}
