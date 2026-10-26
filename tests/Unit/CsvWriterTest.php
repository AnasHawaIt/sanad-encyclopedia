<?php

namespace Tests\Unit;

use App\Services\Exports\CsvWriter;
use PHPUnit\Framework\TestCase;

class CsvWriterTest extends TestCase
{
    private function parse(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'BOM كي يفتح Excel العربية سليمة');
        $rows = [];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, substr($csv, 3));
        rewind($fh);
        while (($r = fgetcsv($fh, null, ',', '"', '\\')) !== false) {
            $rows[] = $r;
        }

        return $rows;
    }

    public function test_round_trips_arabic_commas_quotes_and_newlines(): void
    {
        $rows = $this->parse((new CsvWriter)->write(['أ', 'ب'], [['محمد، بن "علي"', "سطر1\nسطر2"], [null, 5]]));

        $this->assertSame([['أ', 'ب'], ['محمد، بن "علي"', "سطر1\nسطر2"], ['', '5']], $rows);
    }

    public function test_formula_injection_is_neutralised_in_cells_and_headers(): void
    {
        $rows = $this->parse((new CsvWriter)->write(['=bad'], [['=1+1'], ['+cmd'], ['-5x'], ['@SUM(A1)'], ["\tx"], ['عادي'], ['a=b']]));

        $this->assertSame("'=bad", $rows[0][0]);
        $this->assertSame(["'=1+1", "'+cmd", "'-5x", "'@SUM(A1)", "'\tx", 'عادي', 'a=b'], array_column(array_slice($rows, 1), 0));
    }

    public function test_booleans_are_arabic_words_and_zero_is_kept(): void
    {
        $rows = $this->parse((new CsvWriter)->write(['x'], [[true], [false], [0], ['0']]));

        $this->assertSame(['نعم', 'لا', '0', '0'], array_column(array_slice($rows, 1), 0));
    }
}
