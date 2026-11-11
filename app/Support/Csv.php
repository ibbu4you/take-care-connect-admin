<?php

namespace App\Support;

/**
 * Writing rows a spreadsheet will open safely.
 *
 * A cell that begins with =, +, - or @ is read by Excel and Sheets as a
 * formula. Business names and notes come from the public and from imports, so
 * a lead called `=HYPERLINK("http://evil.example","Click")` would become a
 * live link — or worse — in whoever opens the export. Such cells are prefixed
 * with an apostrophe, which spreadsheets show as plain text.
 */
final class Csv
{
    /**
     * @param  resource  $handle
     * @param  array<int, mixed>  $row
     */
    public static function put($handle, array $row): void
    {
        fputcsv($handle, array_map([self::class, 'cell'], $row));
    }

    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
