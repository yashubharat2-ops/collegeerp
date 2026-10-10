<?php

namespace Tests\Unit\Export;

use App\Support\Export\CsvStreamExport;
use Tests\TestCase;

/**
 * Formula-injection neutralisation used by the Phase D admission exports.
 */
class CsvStreamExportSafeCellTest extends TestCase
{
    public function test_formula_leading_characters_are_prefixed_with_an_apostrophe(): void
    {
        $this->assertSame("'=SUM(A1)", CsvStreamExport::safeCell('=SUM(A1)'));
        $this->assertSame("'+61 2 555", CsvStreamExport::safeCell('+61 2 555'));
        $this->assertSame("'-5", CsvStreamExport::safeCell('-5'));
        $this->assertSame("'@cmd", CsvStreamExport::safeCell('@cmd'));
        $this->assertSame("'\tx", CsvStreamExport::safeCell("\tx"));
        $this->assertSame("'\rx", CsvStreamExport::safeCell("\rx"));
    }

    public function test_ordinary_values_are_returned_unchanged(): void
    {
        $this->assertSame('Riya Verma', CsvStreamExport::safeCell('Riya Verma'));
        $this->assertSame('a=b', CsvStreamExport::safeCell('a=b'));
        $this->assertSame('', CsvStreamExport::safeCell(''));
        $this->assertNull(CsvStreamExport::safeCell(null));
        $this->assertSame(7, CsvStreamExport::safeCell(7));
        $this->assertSame(1.5, CsvStreamExport::safeCell(1.5));
    }

    public function test_safe_row_applies_the_rule_to_every_cell(): void
    {
        $this->assertSame(
            ["'=bad", 'ok', null, 3],
            CsvStreamExport::safeRow(['=bad', 'ok', null, 3]),
        );
    }
}
