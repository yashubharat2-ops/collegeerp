<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View { return view('auth.login'); }
    public function store(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->validated();
        if (! Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password'], 'is_active' => true], $request->boolean('remember'))) return back()->withErrors(['email' => 'The provided credentials are invalid.'])->onlyInput('email');
        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        return redirect()->intended(route('dashboard'));
    }
}
