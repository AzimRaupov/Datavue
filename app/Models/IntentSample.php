<?php

namespace App\Models;

use App\Helpers\Ai\IntentClassifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        if (!(new IntentClassifier())->isLearnable($text)) {
            Log::info('IntentSample: фраза не годится в обучение', ['text' => $text]);

            return null;
        }

        try {

            return self::query()->updateOrCreate(
                ['text_hash' => hash('sha256', mb_strtolower($text.'|'.$context, 'UTF-8'))],
                [
                    'text' => $text,
                    'context' => $context,
                    'label' => $label,
                    'predicted' => $prediction['label'] ?? null,
                    'confidence' => $prediction['confidence'] ?? null,
                    'source' => $source,

                    'status' => 'pending',
                    'reject_reason' => null,
                    'chat_id' => $chatId,
                    'message_id' => $messageId,

                    'used_in_training' => false,
                ]
            );
        } catch (Throwable $e) {
            Log::warning('IntentSample: пример не сохранён', ['error' => $e->getMessage()]);

            return null;
        }
    }

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

    public function scopeUsable($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeMispredicted($query)
    {
        return $query->whereNotNull('predicted')->whereColumn('predicted', '!=', 'label');
    }
}
