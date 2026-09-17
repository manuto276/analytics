<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application;

/**
 * Flat CSV of report rows (RFC 4180, UTF-8 with BOM so spreadsheets detect the encoding).
 */
final class CsvExporter
{
    /** @param list<array<string, mixed>> $rows */
    public static function fromRows(array $rows): string
    {
        if ($rows === []) {
            return "\u{FEFF}";
        }
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the CSV buffer.');
        }
        $columns = array_keys($rows[0]);
        fputcsv($handle, $columns, ',', '"', '\\', "\r\n");
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $line[] = match (true) {
                    $value === null => '',
                    \is_bool($value) => $value ? 'true' : 'false',
                    \is_scalar($value) => (string) $value,
                    default => json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                };
            }
            fputcsv($handle, $line, ',', '"', '\\', "\r\n");
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}" . $csv;
    }
}
