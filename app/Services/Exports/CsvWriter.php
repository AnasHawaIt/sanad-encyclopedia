<?php

namespace App\Services\Exports;

/**
 * CSV بترميز UTF-8 مع BOM كي يعرض Excel العربية سليمة، ومع حماية من حقن الصيغ:
 * أي خلية تبدأ بـ = + - @ أو مسافة جدولة/رجوع تُسبق بفاصلة عليا فلا تُنفَّذ كصيغة.
 */
class CsvWriter
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<list<scalar|null>>  $rows
     */
    public function write(array $headers, iterable $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map($this->cell(...), $headers), ',', '"', '\\', "\r\n");
        foreach ($rows as $row) {
            fputcsv($out, array_map($this->cell(...), $row), ',', '"', '\\', "\r\n");
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    public function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'نعم' : 'لا';
        }
        $text = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) ? "'".$text : $text;
    }
}
