<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DashboardWidget extends Model
{
    protected $fillable = ['dashboard_id', 'widget_id', 'widget_type_id', 'instruction', 'title', 'code_path', 'position', 'status', 'container', 'tables'];

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

    public function tablesRole()
    {
        return $this->hasMany();
    }
}
