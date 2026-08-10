<?php

namespace App\Helpers\Export;

/**
 * Форматы файлов, в которые платформа умеет выгружать результат запроса.
 *
 * Формат приходит с двух сторон и обеим доверять нельзя: пользователь пишет
 * его словами («в эксель», «вордовский файл»), модель — как получится
 * («excel», «xls», «Excel (xlsx)»). Поэтому нормализация и распознавание
 * живут в одном месте, а весь остальной код работает уже с одним из четырёх
 * канонических значений.
 */
final class ExportFormat
{
    public const CSV = 'csv';
    public const XLSX = 'xlsx';
    public const PDF = 'pdf';
    public const DOCX = 'docx';

    /**
     * Как пользователь и модель называют формат. Ключ — регулярное выражение,
     * значение — канонический формат.
     *
     * Порядок важен: при нескольких упоминаниях выигрывает то, что встретилось
     * в тексте раньше («сохрани в pdf, csv не надо» → pdf).
     */
    private const PATTERNS = [
        self::PDF => '/\bpdf\b|пдф|пдф\-?файл/iu',
        self::DOCX => '/\bdocx?\b|\bword\b|ворд|вордовск/iu',
        self::XLSX => '/\bxlsx?\b|excel|эксел|ексел|эксэл|таблиц[ауы]\s+excel/iu',
        self::CSV => '/\bcsv\b|цсв/iu',
    ];

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::CSV, self::XLSX, self::PDF, self::DOCX];
    }

    /**
     * Приводит произвольную строку к каноническому формату.
     */
    public static function normalize(?string $value, string $fallback = self::CSV): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $fallback;
        }

        $detected = self::detect($value);

        return $detected ?? $fallback;
    }

    /**
     * Ищет упоминание формата в свободном тексте. null — формат не назван.
     */
    public static function detect(string $text): ?string
    {
        $best = null;
        $bestOffset = PHP_INT_MAX;

        foreach (self::PATTERNS as $format => $pattern) {
            if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE) && $matches[0][1] < $bestOffset) {
                $bestOffset = $matches[0][1];
                $best = $format;
            }
        }

        return $best;
    }

    public static function extension(string $format): string
    {
        return self::normalize($format);
    }

    public static function mime(string $format): string
    {
        return match (self::normalize($format)) {
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::PDF => 'application/pdf',
            self::DOCX => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'text/csv; charset=utf-8',
        };
    }

    /**
     * Название формата для текста в чате.
     */
    public static function label(string $format): string
    {
        return match (self::normalize($format)) {
            self::XLSX => 'Excel (xlsx)',
            self::PDF => 'PDF',
            self::DOCX => 'Word (docx)',
            default => 'CSV',
        };
    }

    /**
     * Потолок строк для формата: у PDF и Word он ниже, чем у таблиц.
     */
    public static function rowLimit(string $format): int
    {
        $default = (int) config('exports.max_rows', 100000);

        $limit = match (self::normalize($format)) {
            self::PDF => (int) config('exports.pdf_max_rows', 2000),
            self::DOCX => (int) config('exports.docx_max_rows', 5000),
            default => $default,
        };

        return max(1, min($limit, $default));
    }

    /**
     * Особенности формата, о которых должна знать модель при подготовке данных.
     */
    public static function rules(string $format): string
    {
        return match (self::normalize($format)) {
            self::XLSX => <<<'TEXT'
- Формат: Excel (.xlsx). Один лист с одной таблицей.
- Числа оставляй числами (не превращай в строки вида "1 234,56") — Excel отформатирует их сам.
- Даты оставляй датами, не приводи к строке.
TEXT,
            self::PDF => <<<'TEXT'
- Формат: PDF. Это документ для чтения, а не выгрузка «сырых» данных.
- Колонок должно быть немного (не больше 8) — иначе таблица не помещается на страницу.
- Обязательно ограничивай выборку разумным числом строк (обычно то, что просил пользователь; если он не уточнил — не больше 200).
- Длинные текстовые поля сокращай в SQL, если они не нужны целиком.
TEXT,
            self::DOCX => <<<'TEXT'
- Формат: Word (.docx). Это документ для чтения, а не выгрузка «сырых» данных.
- Колонок должно быть немного (не больше 10).
- Обязательно ограничивай выборку разумным числом строк (обычно то, что просил пользователь; если он не уточнил — не больше 500).
TEXT,
            default => <<<'TEXT'
- Формат: CSV. Подходит для больших выгрузок «как есть».
- Числа оставляй числами, не форматируй их вручную (никаких пробелов между разрядами).
TEXT,
        };
    }
}
