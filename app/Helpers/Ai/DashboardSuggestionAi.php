<?php

namespace App\Helpers\Ai;

use Illuminate\Support\Facades\Log;

/**
 * Подбирает 2–4 варианта дашборда, с которых осмысленно начать работу
 * с конкретным источником данных.
 *
 * На вход идут смысловые группы таблиц (результат DataSourceGrouping) —
 * то есть модель видит не сырую схему, а уже осмысленную картину:
 * «Продажи и заказы», «Клиенты», «Склад». Этого достаточно, чтобы
 * предложить темы дашбордов, и заметно дешевле полной схемы.
 */
class DashboardSuggestionAi
{
    /** Сколько вариантов просим у модели. */
    private const MIN_SUGGESTIONS = 2;
    private const MAX_SUGGESTIONS = 4;

    /**
     * @param array $groups Компактные группы: [['name','description','tables'=>[...]], ...]
     * @param array $widgetTypes Доступные типы виджетов: [['name','description'], ...]
     *
     * @return array{total_tokens: int, suggestions: array<int, array{title: string, prompt: string, description: string}>}
     */
    public function generate(array $groups, array $widgetTypes = [], ?string $sourceName = null): array
    {
        $min = self::MIN_SUGGESTIONS;
        $max = self::MAX_SUGGESTIONS;

        $system = <<<TEXT
Ты — Senior BI-аналитик платформы DataVue.

Пользователь только что подключил источник данных и открыл пустой чат.
Он ещё не знает, что именно можно построить на своих данных.

Твоя работа — предложить {$min}–{$max} варианта дашборда, с которых стоит начать.
Ты НЕ строишь дашборд и не пишешь код — только формулируешь темы.
TEXT;

        $groupsJson = json_encode(
            $groups,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $widgetTypesJson = json_encode(
            $widgetTypes,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $sourceLine = $sourceName
            ? "Название источника: {$sourceName}"
            : 'Название источника неизвестно.';

        $prompt = <<<TEXT
========================
ИСТОЧНИК ДАННЫХ
========================
{$sourceLine}

========================
СМЫСЛОВЫЕ ГРУППЫ ТАБЛИЦ
========================
Это результат анализа схемы источника: таблицы уже объединены по смыслу.

{$groupsJson}

========================
ДОСТУПНЫЕ ТИПЫ ВИЗУАЛИЗАЦИЙ
========================
Предлагай только то, что платформа умеет построить этими типами.

{$widgetTypesJson}

========================
ЗАДАЧА
========================
Предложи от {$min} до {$max} вариантов дашборда по ЭТИМ данным.

Правила:
1. Опирайся только на группы и таблицы, которые реально есть выше.
   Не выдумывай сущности, которых в источнике нет.
2. Варианты должны отличаться по смыслу, а не формулировкой.
   Плохо: «Аналитика продаж» и «Отчёт по продажам».
   Хорошо: «Динамика продаж», «Профиль клиентов», «Загрузка склада».
3. Если данных хватает только на 2 осмысленные темы — предложи 2.
   Лучше меньше, чем натянутые варианты.
4. Каждый вариант должен опираться минимум на одну группу целиком.
5. "prompt" — это готовое сообщение от лица пользователя, которое уйдёт
   агенту при выборе варианта. Пиши его так, как написал бы человек:
   конкретно, с перечислением нужных метрик и разрезов.
   Это самое важное поле — именно по нему будет строиться дашборд.
6. "title" — коротко, до 40 символов, для кнопки.
7. "description" — одна фраза о том, что покажет дашборд.
8. Всё пиши по-русски.

========================
ФОРМАТ ОТВЕТА
========================
Верни ТОЛЬКО JSON, без пояснений и без markdown-обёртки:

{
  "suggestions": [
    {
      "title": "Динамика продаж",
      "prompt": "Построй дашборд по продажам: выручка по месяцам, количество заказов, средний чек и топ-10 клиентов по выручке",
      "description": "Как меняется выручка и кто приносит больше всего денег"
    }
  ]
}
TEXT;

        try {
            $response = (new AIService(responseFormat: 'json', tokens: 3000))
                ->ask($prompt, $system);
        } catch (\Throwable $e) {
            Log::error('DashboardSuggestionAi: запрос к модели не удался', [
                'error' => $e->getMessage(),
            ]);

            return ['total_tokens' => 0, 'suggestions' => []];
        }

        return [
            'total_tokens' => $response['total_tokens'] ?? 0,
            'suggestions' => $this->normalize($response['content'] ?? []),
        ];
    }


    private function normalize(mixed $content): array
    {
        $items = $content['suggestions'] ?? $content;

        if (!is_array($items)) {
            return [];
        }

        $result = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $promptText = trim((string) ($item['prompt'] ?? ''));

            // Вариант без готового сообщения агенту бесполезен: по клику
            // будет нечего отправить.
            if ($title === '' || $promptText === '') {
                continue;
            }

            $result[] = [
                'title' => mb_substr($title, 0, 255),
                'prompt' => $promptText,
                'description' => trim((string) ($item['description'] ?? '')),
            ];

            if (count($result) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $result;
    }
}
