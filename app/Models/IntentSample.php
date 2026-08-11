<?php

namespace App\Models;

use App\Helpers\Ai\IntentClassifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Обучающий пример для классификатора намерений.
 *
 * Появляется там, где локальная модель не была уверена и решение приняла
 * языковая. Такие фразы лежат на границе между классами — именно они и
 * двигают качество, тогда как уверенные примеры модель уже знает.
 */
class IntentSample extends Model
{
    protected $fillable = [
        'text',
        'context',
        'text_hash',
        'label',
        'predicted',
        'confidence',
        'source',
        'chat_id',
        'message_id',
        'used_in_training',
        'status',
        'reject_reason',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'used_in_training' => 'boolean',
        ];
    }

    /**
     * Записывает пример, не роняя обработку сообщения при неудаче.
     *
     * Сбор обучающих данных — побочная задача: если она не удалась, пользователь
     * всё равно должен получить ответ.
     */
    public static function remember(
        string $text,
        ?string $label,
        ?array $prediction,
        ?int $chatId,
        ?int $messageId,
        string $source = 'gpt',
        string $context = ''
    ): ?self {
        $text = trim($text);

        if ($text === '' || !in_array($label, IntentClassifier::labels(), true)) {
            return null;
        }

        // Вторая линия защиты: сюда не должны попадать бессмыслица и реплики,
        // смысл которых зависит от предыдущего сообщения. Первая линия —
        // в RouterTask, но метод публичный, и цена ошибки высока: испорченный
        // пример живёт в обучении вечно.
        if (!(new IntentClassifier())->isLearnable($text)) {
            Log::info('IntentSample: фраза не годится в обучение', ['text' => $text]);

            return null;
        }

        try {
            // Ключ по паре: одна и та же реплика при разных предложениях
            // агента — разные примеры, и затирать один другим нельзя.
            return self::query()->updateOrCreate(
                ['text_hash' => hash('sha256', mb_strtolower($text.'|'.$context, 'UTF-8'))],
                [
                    'text' => $text,
                    'context' => $context,
                    'label' => $label,
                    'predicted' => $prediction['label'] ?? null,
                    'confidence' => $prediction['confidence'] ?? null,
                    'source' => $source,
                    // Пока это лишь мнение маршрутизатора. Подтвердит его
                    // исполнитель — см. confirm()/reject().
                    'status' => 'pending',
                    'reject_reason' => null,
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    // Повторно встреченная фраза снова участвует в обучении:
                    // её метка могла измениться, если модель ошибалась раньше.
                    'used_in_training' => false,
                ]
            );
        } catch (Throwable $e) {
            Log::warning('IntentSample: пример не сохранён', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Исход подтвердил решение маршрутизатора: дашборд перестроен, файл создан,
     * агент ответил. Только такие примеры идут в обучение.
     */
    public static function confirm(?int $messageId): void
    {
        if (!$messageId) {
            return;
        }

        self::query()
            ->where('message_id', $messageId)
            ->where('status', 'pending')
            ->update(['status' => 'confirmed']);
    }

    /**
     * Исход опроверг решение маршрутизатора.
     *
     * Пример не удаляется, а помечается: по отклонённым видно, где именно
     * ошибается учитель, и это отдельная полезная метрика. В обучение они
     * не попадают.
     */
    public static function reject(?int $messageId, string $reason): void
    {
        if (!$messageId) {
            return;
        }

        $affected = self::query()
            ->where('message_id', $messageId)
            ->where('status', 'pending')
            ->update(['status' => 'rejected', 'reject_reason' => $reason]);

        if ($affected) {
            Log::info('IntentSample: пример отклонён исходом', [
                'message_id' => $messageId,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * Пригодные для обучения: подтверждённые делом.
     */
    public function scopeUsable($query)
    {
        return $query->where('status', 'confirmed');
    }

    /**
     * Примеры, где локальная модель ошиблась бы — самые полезные для разбора.
     */
    public function scopeMispredicted($query)
    {
        return $query->whereNotNull('predicted')->whereColumn('predicted', '!=', 'label');
    }
}
