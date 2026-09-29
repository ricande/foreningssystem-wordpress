<?php

declare(strict_types=1);

namespace Foreningssystem\Application\People;

/**
 * Reads a foreign member spreadsheet. Cells are hostile text. The parser never splits
 * on a delimiter itself; PHP's CSV reader handles quotes, escaped quotes and line breaks.
 */
final class MemberCsvParser
{
    public const MAX_BYTES = 2097152;

    public const MAX_ROWS = 2000;

    public const MAX_COLUMNS = 40;

    public const MAX_CELL = 500;

    public function parse(string $csv, ?string $delimiter = null): MemberCsvTable
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw new MemberCsvException('too_large');
        }

        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }

        if (str_contains($csv, "\0") || ! mb_check_encoding($csv, 'UTF-8')) {
            throw new MemberCsvException('not_utf8');
        }

        $delimiter = $delimiter ?? $this->detectDelimiter($csv);

        if ($delimiter !== ',' && $delimiter !== ';') {
            throw new MemberCsvException('bad_delimiter');
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new MemberCsvException('unreadable');
        }

        fwrite($handle, $csv);
        rewind($handle);
        $header = fgetcsv($handle, 0, $delimiter, '"', '\\');

        if (! is_array($header) || $header === [null] || $header === []) {
            fclose($handle);

            throw new MemberCsvException('unreadable');
        }

        $headers = [];

        foreach ($header as $cell) {
            $headers[] = is_string($cell) ? trim($cell) : '';
        }

        if (count($headers) > self::MAX_COLUMNS) {
            fclose($handle);

            throw new MemberCsvException('too_many_columns');
        }

        $rows = [];
        $line = 1;

        while (($record = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $line++;

            if (! is_array($record) || $this->blank($record)) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);

                throw new MemberCsvException('too_many_rows');
            }

            if (count($record) > self::MAX_COLUMNS) {
                fclose($handle);

                throw new MemberCsvException('too_many_columns');
            }

            $cells = [];
            $oversize = false;

            foreach ($headers as $index => $unused) {
                unset($unused);
                $value = $record[$index] ?? '';
                $value = is_string($value) ? trim($value) : '';

                if (strlen($value) > self::MAX_CELL) {
                    $oversize = true;
                    $value = '';
                }

                $cells[] = $value;
            }

            $rows[] = ['line' => $line, 'cells' => $cells, 'oversize' => $oversize];
        }

        fclose($handle);

        return new MemberCsvTable($delimiter, $headers, $rows);
    }

    public function detectDelimiter(string $csv): string
    {
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }

        $comma = $this->width($csv, ',');
        $semicolon = $this->width($csv, ';');

        if ($semicolon > $comma) {
            return ';';
        }

        if ($comma > $semicolon) {
            return ',';
        }

        return ';';
    }

    private function width(string $csv, string $delimiter): int
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return 0;
        }

        fwrite($handle, $csv);
        rewind($handle);
        $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
        fclose($handle);

        return is_array($header) ? count($header) : 0;
    }

    /**
     * @param list<string|null> $record
     */
    private function blank(array $record): bool
    {
        foreach ($record as $cell) {
            if (is_string($cell) && trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
