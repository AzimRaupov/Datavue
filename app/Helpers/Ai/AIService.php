<?php

namespace App\Helpers\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Orhanerday\OpenAi\OpenAi;
use RuntimeException;

class AIService
{
    protected string $apiKey;
    protected string $model;
    protected int $maxTokens;
    protected string $responseFormat;

    public function __construct(
        string $prompt = '',
        int $tokens = 4000,
        string $responseFormat = 'text'
    ) {
        $this->apiKey = env('OPENAI_API_KEY');
        $this->model = env('GPT_MODEL', 'gpt-5-nano');
        $this->maxTokens = $tokens;
        $this->responseFormat = $responseFormat;
    }

    /**
     * Отправка запроса в OpenAI
     */
    public function ask(string $prompt, ?string $systemPrompt = null): string|array
    {
        $open_ai = new OpenAi($this->apiKey);

        $systemPrompt ??= 'Ты — Senior DataSource Analyst и эксперт.';
        $response = $open_ai->chat([
            'model'             => $this->model,
            'messages'          => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $prompt], // ← use the actual $prompt arg
            ],
            'temperature'       => 1.0,
            'frequency_penalty' => 0,
            'presence_penalty'  => 0,
        ]);

        $decoded = json_decode($response, true);
        $text = $decoded['choices'][0]['message']['content'] ?? '';

        // Учёт расхода — здесь, а не в вызывающих классах: через ask() проходят
        // все обращения к модели (роутер задач, генерация и починка виджетов,
        // группировка схемы, подбор вариантов), поэтому мимо учёта не пройдёт
        // ни один вызов, включая те, что появятся позже.
        AiUsage::record(
            (int) ($decoded['usage']['total_tokens'] ?? 0),
            $this->model
        );

        if ($this->responseFormat === 'text') {
            return [
                // ?? 0 обязателен: при ошибке или обрыве ответа блока usage
                // может не быть вовсе, и обращение к нему валило весь запрос.
                'total_tokens'=> $decoded['usage']['total_tokens'] ?? 0,
                'content'=> $text,
            ];
        }

        $clean  = str_replace(['```json', '```'], '', $text);
        $parsed = json_decode($clean, true);

        return [
            'total_tokens'=> $decoded['usage']['total_tokens'],
            'content'=> $parsed ?? [],
        ];

    }


}
