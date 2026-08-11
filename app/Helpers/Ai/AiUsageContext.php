<?php

namespace App\Helpers\Ai;

/**
 * Кому записать расход токенов.
 *
 * AIService вызывается из двух десятков мест — от роутера задач до починки
 * виджетов, — и почти нигде под рукой нет компании: классы работают с чатом,
 * дашбордом или источником. Передавать компанию через все конструкторы значило
 * бы переписать половину пайплайна и всё равно однажды забыть про новый вызов.
 *
 * Поэтому контекст выставляется один раз на границе работы (в job или
 * контроллере), а AIService читает его сам. Мимо учёта не пройдёт ни один
 * вызов модели.
 *
 * ВАЖНО: очередь — долгоживущий процесс, и контекст обязан сбрасываться после
 * каждой задачи, иначе расход следующей компании запишется предыдущей.
 * Для этого есть run(), который сбрасывает контекст даже при исключении.
 */
class AiUsageContext
{
    private static ?array $current = null;

    public static function set(
        ?int $companyId,
        ?int $chatId = null,
        ?int $messageId = null,
        ?string $operation = null
    ): void {
        if (!$companyId) {
            self::$current = null;

            return;
        }

        self::$current = [
            'company_id' => $companyId,
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'operation' => $operation,
        ];
    }

    public static function clear(): void
    {
        self::$current = null;
    }

    public static function current(): ?array
    {
        return self::$current;
    }

    /**
     * Выполняет работу с выставленным контекстом и гарантированно возвращает
     * прежний по завершении — в том числе при исключении.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(
        ?int $companyId,
        callable $callback,
        ?int $chatId = null,
        ?int $messageId = null,
        ?string $operation = null
    ) {
        $previous = self::$current;

        self::set($companyId, $chatId, $messageId, $operation);

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    /**
     * Уточняет операцию, не трогая остальной контекст: одна задача может
     * состоять из нескольких разных обращений к модели.
     */
    public static function operation(string $operation): void
    {
        if (self::$current !== null) {
            self::$current['operation'] = $operation;
        }
    }
}
