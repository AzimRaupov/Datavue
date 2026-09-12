<?php

namespace App\Helpers\Alert;

use Illuminate\Support\Facades\File;

/**
 * Сохраняет результат одной проверки алерта в CSV — на диск, для письма
 * и для скачивания из истории.
 *
 * Тот же формат, что и у выгрузок чата (ChatExportGenerator/
 * ExportCodeTemplater): разделитель из exports.csv_delimiter (по умолчанию
 * «;» — Excel с русской локалью иначе схлопывает файл в одну колонку) и
 * BOM в начале файла, иначе Excel показывает кириллицу в заголовках
 * крокозябрами.
 */
class AlertCsvWriter
{
    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return int Число записанных строк данных (без заголовка)
     */
    public static function write(array $rows, string $path): int
    {
        File::ensureDirectoryExists(dirname($path));

        $delimiter = (string) config('exports.csv_delimiter', ';');

        $handle = fopen($path, 'w');

        try {
            // utf-8-sig: без BOM Excel открывает русские заголовки крокозябрами.
            fwrite($handle, "\xEF\xBB\xBF");

            if ($rows === []) {
                return 0;
            }

            // Заголовок — по первой строке: дальше на неё же равняются все
            // остальные, даже если у какой-то строки колонок вдруг меньше.
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

    /**
     * Значение ячейки как строка: числа и текст остаются как есть, всё
     * остальное (вложенный массив/объект из Python-условия) сериализуется —
     * иначе fputcsv молча превратил бы его в предупреждение PHP.
     */
    private static function cell(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
