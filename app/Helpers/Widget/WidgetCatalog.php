<?php

namespace App\Helpers\Widget;

use App\Models\Widget;
use App\Models\WidgetType;
use Illuminate\Support\Collection;

/**
 * Каталог виджетов для промптов.
 *
 * Полный каталог со всеми семействами, их формой данных и вариантами отрисовки
 * занимает ~32 тыс. символов — это 61% промпта выбора виджетов, из-за чего схема
 * таблиц пользователя и его запрос теряются на фоне справочника. Поэтому каталог
 * отдаётся в два приёма: сначала короткий список семейств (одна строка на
 * семейство), потом подробности ТОЛЬКО по выбранным.
 *
 * Форма данных при выборе отдаётся json-примером, а не прозой: пример короче
 * в четыре раза, а подробное описание всё равно уходит в промпт генерации кода.
 */
class WidgetCatalog
{
    /**
     * Семейства, которые закрывают большинство аналитических задач.
     * Всегда остаются доступными, даже если модель их не выбрала, — чтобы она
     * не осталась с одной экзотикой, если ошиблась на первом шаге.
     */
    public const CORE_FAMILIES = ['mini-counters', 'bar', 'line', 'table', 'pie'];

    private Collection $widgets;

    public function __construct(?Collection $widgets = null)
    {
        $this->widgets = $widgets ?? Widget::query()
            ->where('is_ai_selectable', true)
            ->with('selectableTypes')
            ->get();
    }

    /**
     * Короткий список для первого шага: имя семейства и одна строка о том,
     * для чего оно. Влезает в несколько сотен токенов.
     */
    public function briefJson(): string
    {
        $brief = $this->widgets->map(fn (Widget $widget) => [
            'name' => $widget->name,
            'purpose' => $this->firstSentence($widget->description),
        ])->values();

        return json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Каталог без форм данных: назначение семейства и варианты отрисовки.
     *
     * Для шага «что менять на дашборде» форма данных бесполезна — она нужна
     * только генератору python-кода, который получает её отдельно. Убирая её,
     * освобождаем половину промпта под описание виджетов, которые правим.
     */
    public function compactJson(): string
    {
        $compact = $this->widgets->map(fn (Widget $widget) => [
            'name' => $widget->name,
            'purpose' => $this->firstSentence($widget->description),
            'types' => $widget->selectableTypes->map(fn (WidgetType $type) => [
                'type' => $type->name,
                'when_to_use' => $type->description,
            ])->values()->all(),
        ])->values();

        return json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Подробности по выбранным семействам: назначение, компактная форма данных
     * и варианты отрисовки. Пустой список означает «все семейства».
     *
     * @param  array<int, string>  $familyNames
     */
    public function detailedJson(array $familyNames = []): string
    {
        return json_encode(
            $this->detailed($familyNames),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    /**
     * @param  array<int, string>  $familyNames
     */
    public function detailed(array $familyNames = []): array
    {
        return $this->select($familyNames)
            ->map(fn (Widget $widget) => [
                'name' => $widget->name,
                'description' => $widget->description,
                'data_shape' => $this->compactShape($widget->scheme),
                'types' => $widget->selectableTypes->map(function (WidgetType $type) {
                    $entry = [
                        'type' => $type->name,
                        'when_to_use' => $type->description,
                    ];

                    // Своя форма только у типов, которые её переопределяют.
                    if ($type->scheme) {
                        $entry['own_data_shape'] = $this->compactShape($type->scheme);
                    }

                    return $entry;
                })->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Отбирает выбранные семейства, добавляя к ним базовые.
     *
     * @param  array<int, string>  $familyNames
     */
    public function select(array $familyNames = []): Collection
    {
        $names = collect($familyNames)
            ->filter(fn ($name) => is_string($name) && $name !== '')
            ->values();

        if ($names->isEmpty()) {
            return $this->widgets;
        }

        $names = $names->merge(self::CORE_FAMILIES)->unique();

        $selected = $this->widgets->filter(fn (Widget $widget) => $names->contains($widget->name));

        // Модель могла назвать только несуществующие семейства — тогда лучше
        // отдать весь каталог, чем оставить её вообще без вариантов.
        return $selected->isEmpty() ? $this->widgets : $selected->values();
    }

    public function names(): array
    {
        return $this->widgets->pluck('name')->all();
    }

    /**
     * Пример json без отступов — форма данных, занимающая одну строку.
     */
    private function compactShape(?string $scheme): string
    {
        if (!$scheme) {
            return '';
        }

        $decoded = json_decode($scheme, true);

        return is_array($decoded)
            ? json_encode($decoded, JSON_UNESCAPED_UNICODE)
            : trim($scheme);
    }

    private function firstSentence(?string $text): string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return '';
        }

        // Описания написаны так, что первое предложение — это и есть назначение.
        $position = mb_strpos($text, '. ');

        return $position === false ? $text : mb_substr($text, 0, $position + 1);
    }
}
