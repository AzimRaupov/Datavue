<?php

namespace App\Helpers\Alert;

use Illuminate\Support\Facades\File;

class AlertCsvWriter
{

    public static function write(array $rows, string $path): int
    {
        File::ensureDirectoryExists(dirname($path));

        $delimiter = (string) config('exports.csv_delimiter', ';');

        $handle = fopen($path, 'w');

        try {

            fwrite($handle, "\xEF\xBB\xBF");

            if ($rows === []) {
                return 0;
            }

            $header = array_keys(self::toArray($rows[0]));
            fputcsv($handle, $header, $delimiter);

            foreach ($rows as $row) {
                $row = self::toArray($row);
                $line = [];

                foreach ($header as $column) {
                    $line[] = self::cell($row[$column] ?? null);
                }

                fputcsv($handle, $line, $delimiter);
            }

            return count($rows);
        } finally {
            fclose($handle);
        }
    }

    private static function toArray(mixed $row): array
    {
        return is_array($row) ? $row : (array) $row;
    }

    private static function cell(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
