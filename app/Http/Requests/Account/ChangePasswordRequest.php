<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

/**
 * Change of the signed-in user's own password.
 *
 * The current password must be proved before the new one is written — the same
 * credential standard the login screen applies — and the new password follows the
 * rule the application already uses for password resets (app\
 * Http\Requests\Auth\ResetPasswordRequest): required, confirmed and at least twelve
 * characters. Hashing stays where it already lives, in the `hashed` cast on the User
 * model, so no plain-text password is ever held on the model or in an audit row.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'min:12'],
            // Identity and tenant fields are not this screen's to touch.
            'id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'email' => ['prohibited'],
            'name' => ['prohibited'],
            'is_active' => ['prohibited'],
            'roles' => ['prohibited'],
            'college_id' => ['prohibited'],
            'remember_token' => ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return [
            'current_password' => 'current password',
            'password' => 'new password',
            'password_confirmation' => 'password confirmation',
        ];
    }

    /**
     * Prove ownership of the account, then refuse a "change" that would keep using the
     * password that is being replaced.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();
            $current = (string) $this->input('current_password', '');

            if ($user === null || $current === '' || $validator->errors()->has('current_password')) {
                return;
            }

            if (! Hash::check($current, (string) $user->password)) {
                $validator->errors()->add('current_password', 'These credentials do not match our records.');
            }

            $proposed = (string) $this->input('password', '');
            if ($proposed !== '' && ! $validator->errors()->has('password') && Hash::check($proposed, (string) $user->password)) {
                $validator->errors()->add('password', 'Choose a different password than the one you are signing in with.');
            }
        });
    }
}
