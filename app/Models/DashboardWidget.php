<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class DashboardWidget extends Model
{
    protected $fillable = [
        'dashboard_id', 'widget_id', 'widget_type_id', 'instruction', 'title',
        'code_path', 'code', 'code_previous', 'content_mode', 'origin',
        // query_spec обязателен в этом списке: перегенерация переносит
        // содержимое виджетов в новый дашборд массовым созданием, и без
        // него спецификация молча терялась — виджет приезжал пустым.
        'query_spec',
        'last_error', 'last_run_at', 'position', 'status', 'container', 'col', 'tables',
    ];

    protected $casts = [
        'last_run_at' => 'datetime',
        'query_spec' => 'array',
    ];

    /** См. Dashboard::$attributes — значение должно быть известно и до чтения из базы. */
    protected $attributes = [
        'origin' => self::ORIGIN_AI,
        'content_mode' => 'python',
    ];

    /**
     * Спецификация наружу не отдаётся: в ней лежит SQL виджета, а его незачем
     * знать тому, кто просто смотрит дашборд. В PHP она читается как обычно —
     * $hidden влияет только на превращение модели в JSON.
     */
    protected $hidden = ['query_spec'];

    /**
     * Оформление — единственная часть спецификации, которую фронт обязан
     * видеть: по ней виджет рисуется своими цветами (см. widgets/palette.js).
     */
    protected $appends = ['presentation'];

    /** Содержимое виджета — SQL-спецификация: запрос плюс оформление. */
    public const MODE_SQL = 'sql';

    /**
     * Содержимое собрано конструктором: в спецификации лежат и настройки
     * (таблица, метрики, разбивки, фильтры), и собранный из них запрос.
     */
    public const MODE_BUILDER = 'builder';

    /** Содержимое виджета — тело main() на Python. Режим доживающих виджетов. */
    public const MODE_PYTHON = 'python';

    /** Код виджета написала модель. */
    public const ORIGIN_AI = 'ai';

    /** Код виджета написал человек в конструкторе. */
    public const ORIGIN_MANUAL = 'manual';

    /**
     * Список таблиц виджета.
     *
     * Раньше здесь стоял обычный каст 'array', и код, который сам вызывал
     * json_encode перед записью, получал двойное кодирование: в базу уходила
     * JSON-строка вместо JSON-массива, а чтение возвращало строку. На ней
     * падал getSchema(), которому нужен массив.
     *
     * Теперь нормализация выполняется в обе стороны: что бы ни записали —
     * массив, строку или уже закодированный JSON, — в базе окажется корректный
     * JSON-массив, а чтение всегда вернёт массив.
     */
    /**
     * Оформление виджета: цвета рядов, префиксы счётчиков.
     *
     * null, когда спецификации нет или её колонку не выбирали запросом, —
     * виджет тогда рисуется общей палитрой.
     */
    protected function presentation(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->query_spec['presentation'] ?? null,
        );
    }

    protected function tables(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => self::normalizeTables($value),
            set: fn ($value) => json_encode(self::normalizeTables($value), JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Приводит значение к массиву имён таблиц.
     *
     * Разворачивает многократное кодирование: виджет, обновлённый дважды,
     * успевал получить строку вида "\"[\\\"orders\\\"]\"".
     *
     * @return array<int, string>
     */
    public static function normalizeTables(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        // Разворачиваем, пока получается строка: каждый лишний json_encode
        // добавлял ровно один слой.
        $depth = 0;

        while (is_string($value) && $depth++ < 5) {
            $decoded = json_decode($value, true);

            if ($decoded === null) {
                // Не JSON — значит это просто имя таблицы.
                return trim($value) === '' ? [] : [$value];
            }

            $value = $decoded;
        }

        return is_array($value) ? array_values($value) : [];
    }

    public function dashboard()
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function isManual(): bool
    {
        return $this->origin === self::ORIGIN_MANUAL;
    }

    /**
     * Считается ли виджет запросом.
     *
     * Признаком служит наличие спецификации, а не поле content_mode: пока
     * существуют виджеты, написанные до перехода, надёжнее смотреть на то,
     * что у виджета реально есть, чем на то, чем он себя называет.
     */
    public function usesQuerySpec(): bool
    {
        $spec = $this->query_spec;

        return is_array($spec) && ($spec['queries'] ?? $spec['query'] ?? null) !== null;
    }

    /**
     * Тело main() этого виджета.
     *
     * У ручного виджета исходник правды — колонка code: файл из неё только
     * материализуется. У сгенерированного колонка пуста, и код по-прежнему
     * читается из файла по code_path.
     */
    public function resolveCode(): ?string
    {
        if (is_string($this->code) && trim($this->code) !== '') {
            return $this->code;
        }

        if ($this->code_path && is_file($this->code_path)) {
            return file_get_contents($this->code_path);
        }

        return null;
    }

    public function hasContent(): bool
    {
        return $this->usesQuerySpec() || $this->resolveCode() !== null;
    }

    public function widget()
    {
        return $this->belongsTo(Widget::class);
    }

    public function widgetType()
    {
        return $this->belongsTo(WidgetType::class);
    }

    /**
     * Выбранный вариант отрисовки, а если он не задан — тип семейства
     * по умолчанию. Виджет без типа рисоваться всё равно должен.
     */
    public function effectiveType(): ?WidgetType
    {
        return $this->widgetType ?? $this->widget?->defaultType();
    }

    /**
     * Форма данных, под которую генерируется python-скрипт виджета:
     * переопределение типа, если оно есть, иначе форма семейства.
     */
    public function effectiveScheme(): ?string
    {
        return $this->effectiveType()?->effectiveScheme() ?? $this->widget?->scheme;
    }

    public function effectiveSchemeDescription(): ?string
    {
        return $this->effectiveType()?->effectiveSchemeDescription() ?? $this->widget?->scheme_description;
    }
}
