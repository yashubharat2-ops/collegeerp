<?php
namespace App\Http\Controllers\Auth;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
class ForgotPasswordController
{
    public function create(): View { return view('auth.forgot-password'); }
    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        $status = Password::sendResetLink($request->validated());
        return back()->with('status', __($status));
    }
}
