<?php

namespace Database\Seeders\Widgets;

class RadialSeeder extends WidgetFamilySeeder
{
    protected function family(): array
    {
        return [
            'name' => 'radial',
            'description' => 'Достижение цели в процентах: выполнение плана, заполненность, доля от предела, конверсия. '
                .'Берите ТОЛЬКО когда у метрики есть осмысленная база 100% — план, лимит, общее число. '
                .'Если базы нет, число нужно показать через "mini-counters", а доли категорий — через "pie".',

            'scheme' => [
                'series' => [72.5],
                'labels' => ['string'],
            ],

            'scheme_description' => <<<TEXT
series — массив процентов от 0 до 100 (int или float).
labels — массив подписей (строки).

Правила:
- длина labels равна длине series, series[i] относится к labels[i];
- значения — именно проценты, посчитанные от реальной базы из данных
  (факт / план * 100), а не абсолютные величины;
- значение больше 100 допустимо (перевыполнение), отрицательное — нет.
TEXT,
        ];
    }

    protected function types(): array
    {
        return [
            [
                'name' => 'gauge',
                'title' => 'Один индикатор',
                'description' => 'Одно кольцо с процентом в центре. Выбор по умолчанию, когда показатель один.',
                'options' => ['chartType' => 'radialBar', 'multiple' => false],
                'is_default' => true,
            ],
            [
                'name' => 'multi',
                'title' => 'Несколько колец',
                'description' => 'Вложенные кольца, по одному на показатель. Берите, когда сравниваете выполнение '
                    .'2-4 планов рядом (по отделам, по продуктам). Больше четырёх колец не читается.',
                'options' => ['chartType' => 'radialBar', 'multiple' => true],
            ],
        ];
    }
}
