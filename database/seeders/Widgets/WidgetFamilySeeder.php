<?php

namespace Database\Seeders\Widgets;

use App\Models\Widget;
use App\Models\WidgetType;
use Illuminate\Database\Seeder;

abstract class WidgetFamilySeeder extends Seeder
{

    abstract protected function family(): array;

    abstract protected function types(): array;

    public function run(): void
    {
        $family = $this->family();

        $widget = Widget::query()->updateOrCreate(
            ['name' => $family['name']],
            [
                'description' => $family['description'],
                'scheme' => $this->encode($family['scheme']),
                'scheme_description' => trim($family['scheme_description']),
                'is_ai_selectable' => $family['is_ai_selectable'] ?? true,
            ]
        );

        $keptIds = [];
        $position = 0;

        foreach ($this->types() as $type) {
            $row = WidgetType::query()->updateOrCreate(
                [
                    'widget_id' => $widget->id,
                    'name' => $type['name'],
                ],
                [
                    'title' => $type['title'],
                    'description' => trim($type['description']),
                    'scheme' => isset($type['scheme']) ? $this->encode($type['scheme']) : null,
                    'scheme_description' => isset($type['scheme_description'])
                        ? trim($type['scheme_description'])
                        : null,
                    'options' => $type['options'] ?? [],
                    'is_default' => $type['is_default'] ?? false,
                    'is_ai_selectable' => $type['is_ai_selectable'] ?? true,
                    'position' => $position++,
                ]
            );

            $keptIds[] = $row->id;
        }

        WidgetType::query()
            ->where('widget_id', $widget->id)
            ->whereNotIn('id', $keptIds)
            ->delete();
    }

    private function encode(array $scheme): string
    {
        return json_encode($scheme, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
