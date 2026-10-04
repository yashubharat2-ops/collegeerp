<?php

namespace App\Domain\Student\Rules;

use App\Domain\Student\Support\Aadhaar;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a submitted Aadhaar number is structurally correct: twelve
 * digits, first digit 2-9, and a valid Verhoeff checksum (see {@see Aadhaar}).
 *
 * Spaces and hyphens are accepted in the input because that is how the number
 * is printed and typed; the canonical digits are what gets validated. An empty
 * value passes here — whether the field is required is the `nullable` /
 * `required` rules' concern, not this rule's.
 */
final class ValidAadhaar implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = Aadhaar::normalise($value);

        if ($digits === null) {
            return;
        }

        if (! Aadhaar::isValid($digits)) {
            $fail('The :attribute must be a valid 12-digit Aadhaar number (spaces are ignored; the checksum is verified).');
        }
    }
}
