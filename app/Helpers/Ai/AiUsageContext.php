<?php

namespace App\Helpers\Ai;

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

    public static function operation(string $operation): void
    {
        if (self::$current !== null) {
            self::$current['operation'] = $operation;
        }
    }
}
