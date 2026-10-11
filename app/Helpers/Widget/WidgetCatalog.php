<?php

namespace App\Helpers\Widget;

use App\Models\Widget;
use App\Models\WidgetType;
use Illuminate\Support\Collection;

class WidgetCatalog
{

    private Collection $widgets;

    public function __construct(?Collection $widgets = null)
    {
        $this->widgets = $widgets ?? Widget::query()
            ->where('is_ai_selectable', true)
            ->with('selectableTypes')
            ->get();
    }

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

    public function detailedJson(): string
    {
        return json_encode(
            $this->detailed(),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    public function detailed(): array
    {
        return $this->widgets
            ->map(fn (Widget $widget) => [
                'name' => $widget->name,
                'description' => $widget->description,
                'data_shape' => $this->compactShape($widget->scheme),
                'types' => $widget->selectableTypes->map(function (WidgetType $type) {
                    $entry = [
                        'type' => $type->name,
                        'when_to_use' => $type->description,
                    ];

                    if ($type->scheme) {
                        $entry['own_data_shape'] = $this->compactShape($type->scheme);
                    }

                    return $entry;
                })->values()->all(),
            ])
            ->values()
            ->all();
    }

    public function names(): array
    {
        return $this->widgets->pluck('name')->all();
    }

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

        $position = mb_strpos($text, '. ');

        return $position === false ? $text : mb_substr($text, 0, $position + 1);
    }
}
