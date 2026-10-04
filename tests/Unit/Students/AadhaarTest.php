<?php

namespace Tests\Unit\Students;

use App\Domain\Student\Rules\ValidAadhaar;
use App\Domain\Student\Support\Aadhaar;
use App\Domain\Student\Support\SensitiveIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Student Management — Aadhaar normalisation, Verhoeff checksum and masking.
 *
 * The numbers used here are Verhoeff-valid fixtures (the twelfth digit is the
 * checksum of the eleven before it, which is what UIDAI issues), so the tests
 * prove the checksum is actually computed rather than the length alone.
 *
 * Encryption/decryption is exercised in the feature test
 * (Tests\Feature\Students\StudentProfileFormTest), which runs with a booted
 * application and therefore with an application key.
 */
class AadhaarTest extends TestCase
{
    /** Verhoeff-valid 12-digit fixtures. */
    private const VALID = '999941057058';

    public function test_normalise_strips_the_separators_a_clerk_types(): void
    {
        $this->assertSame('999941057058', Aadhaar::normalise('9999 4105 7058'));
        $this->assertSame('999941057058', Aadhaar::normalise('9999-4105-7058'));
        $this->assertSame('999941057058', Aadhaar::normalise(' 9999 4105 7058 '));

        // Nothing usable was submitted: this is "no Aadhaar", not an error.
        $this->assertNull(Aadhaar::normalise(null));
        $this->assertNull(Aadhaar::normalise(''));
        $this->assertNull(Aadhaar::normalise('   '));
        $this->assertNull(Aadhaar::normalise(' - - '));

        // A hand-crafted request can deliver an array: treated as absent, never
        // as a value to hash or persist.
        $this->assertNull(Aadhaar::normalise(['999941057058']));
    }

    public function test_is_valid_requires_twelve_digits_a_2_to_9_start_and_the_verhoeff_checksum(): void
    {
        $this->assertTrue(Aadhaar::isValid('999941057058'));
        $this->assertTrue(Aadhaar::isValid('222233334444'));

        $this->assertFalse(Aadhaar::isValid(null));
        $this->assertFalse(Aadhaar::isValid(''));
        $this->assertFalse(Aadhaar::isValid('99994105705'), 'Eleven digits is not an Aadhaar number.');
        $this->assertFalse(Aadhaar::isValid('9999410570581'), 'Thirteen digits is not an Aadhaar number.');
        $this->assertFalse(Aadhaar::isValid('99994105705X'), 'Only digits are allowed.');
        $this->assertFalse(Aadhaar::isValid('099941057058'), 'Aadhaar never starts with 0.');
        $this->assertFalse(Aadhaar::isValid('199941057058'), 'Aadhaar never starts with 1.');
        // Twelve digits and a 2-9 start, but the twelfth digit is not the
        // Verhoeff checksum of the first eleven.
        $this->assertFalse(Aadhaar::isValid('999941057059'));
    }

    public function test_last4_and_mask_never_expose_more_than_the_tail(): void
    {
        $this->assertSame('7058', Aadhaar::last4(self::VALID));
        $this->assertSame('XXXX XXXX 7058', Aadhaar::mask('7058'));

        $this->assertNull(Aadhaar::mask(null));
        $this->assertNull(Aadhaar::mask(''));
        $this->assertNull(Aadhaar::mask('  '));
        $this->assertNull(Aadhaar::mask('705'), 'A truncated tail is not displayed as a masked number.');
    }

    public function test_mask_tail_never_reveals_a_value_shorter_than_the_visible_window(): void
    {
        $this->assertSame('XXXX1234', SensitiveIdentity::maskTail('ABCD1234'));
        $this->assertSame('XXCDEF', SensitiveIdentity::maskTail('ABCDEF'));
        $this->assertSame('XXXXXX234F', SensitiveIdentity::maskTail('ABCDE1234F'));

        // Shorter than the visible window: mask all of it, never expose it whole.
        $this->assertSame('XX', SensitiveIdentity::maskTail('AB'));
        $this->assertNull(SensitiveIdentity::maskTail(null));
        $this->assertNull(SensitiveIdentity::maskTail('  '));
    }

    public function test_the_rule_accepts_spaced_input_rejects_a_bad_checksum_and_ignores_blanks(): void
    {
        $rule = new ValidAadhaar();

        $failure = $this->runRule($rule, '9999 4105 7058');
        $this->assertNull($failure);

        $failure = $this->runRule($rule, '123456789012');
        $this->assertIsString($failure);
        $this->assertStringContainsString('Aadhaar', $failure);

        // Blank is the `nullable` rule's business, not this rule's.
        $this->assertNull($this->runRule($rule, null));
        $this->assertNull($this->runRule($rule, ''));
        $this->assertNull($this->runRule($rule, '   '));
    }

    private function runRule(ValidAadhaar $rule, mixed $value): ?string
    {
        $failure = null;

        $rule->validate('aadhaar_number', $value, function (string $message) use (&$failure): void {
            $failure = $message;
        });

        return $failure;
    }
}
