<?php

namespace Tests\Unit\Library;

use App\Domain\Library\Rules\ValidIsbn;
use App\Domain\Library\Support\Isbn;
use PHPUnit\Framework\TestCase;

/**
 * Library Management — ISBN normalisation and structural validation.
 */
class IsbnTest extends TestCase
{
    public function test_normalize_strips_separators_upper_cases_and_treats_blank_as_null(): void
    {
        $this->assertSame('9780134685991', Isbn::normalize('978-0-13-468599-1'));
        $this->assertSame('9780134685991', Isbn::normalize(' 978 0 13 468599 1 '));
        $this->assertSame('080442957X', Isbn::normalize('0-8044-2957-x'));
        $this->assertNull(Isbn::normalize(null));
        $this->assertNull(Isbn::normalize(''));
        $this->assertNull(Isbn::normalize('   '));
        $this->assertNull(Isbn::normalize(' - - '));
    }

    public function test_is_valid_accepts_isbn_10_and_isbn_13_structures_only(): void
    {
        $this->assertTrue(Isbn::isValid('9780134685991'));
        $this->assertTrue(Isbn::isValid('9791234567896'));
        $this->assertTrue(Isbn::isValid('0134685997'));
        $this->assertTrue(Isbn::isValid('080442957X'));

        $this->assertFalse(Isbn::isValid(null));
        $this->assertFalse(Isbn::isValid(''));
        $this->assertFalse(Isbn::isValid('12345'));
        $this->assertFalse(Isbn::isValid('978013468599'), 'Twelve digits is neither form.');
        $this->assertFalse(Isbn::isValid('9770134685991'), 'ISBN-13 must start with 978 or 979.');
        $this->assertFalse(Isbn::isValid('01346859X7'), 'X is only valid as the ISBN-10 check character.');
        $this->assertFalse(Isbn::isValid('ABCDEFGHIJ'));
    }

    public function test_the_validation_rule_normalizes_before_checking(): void
    {
        $rule = new ValidIsbn();

        $failed = null;
        $rule->validate('isbn', '978-0-13-468599-1', function () use (&$failed): void {
            $failed = true;
        });
        $this->assertNull($failed);

        $rule->validate('isbn', '978-0-13', function (string $message) use (&$failed): void {
            $failed = $message;
        });
        $this->assertIsString($failed);
        $this->assertStringContainsString('ISBN', $failed);
    }
}
