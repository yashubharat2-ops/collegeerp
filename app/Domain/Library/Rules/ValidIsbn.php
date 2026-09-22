<?php

namespace App\Domain\Library\Rules;

use App\Domain\Library\Support\Isbn;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a submitted ISBN has the structure of an ISBN-10 or ISBN-13
 * once hyphens and spaces are removed (see Isbn for what is and is not checked).
 */
final class ValidIsbn implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Isbn::isValid(Isbn::normalize($value))) {
            $fail('The :attribute must be a valid ISBN-10 or ISBN-13 (digits, optionally separated by hyphens or spaces).');
        }
    }
}
