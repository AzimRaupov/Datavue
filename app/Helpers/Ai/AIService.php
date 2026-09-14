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

    public function ask(string $prompt, ?string $systemPrompt = null): string|array
    {
        $open_ai = new OpenAi($this->apiKey);

        $systemPrompt ??= 'Ты — Senior DataSource Analyst и эксперт.';
        $systemPrompt .= ' Весь текст для пользователя (заголовки, подписи, сообщения) пиши на языке'
            .' его запроса, а не только по-русски — определяй язык по тексту запроса.';

        $response = $open_ai->chat([
            'model'             => $this->model,
            'messages'          => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $prompt],
            ],
            'temperature'       => 1.0,
            'frequency_penalty' => 0,
            'presence_penalty'  => 0,
        ]);

        $decoded = json_decode($response, true);
        $text = $decoded['choices'][0]['message']['content'] ?? '';

        AiUsage::record(
            (int) ($decoded['usage']['total_tokens'] ?? 0),
            $this->model
        );

        if ($this->responseFormat === 'text') {
            return [

                'total_tokens'=> $decoded['usage']['total_tokens'] ?? 0,
                'content'=> $text,
            ];
        }

        $clean  = str_replace(['```json', '```'], '', $text);
        $parsed = json_decode($clean, true);

        return [

            'total_tokens'=> $decoded['usage']['total_tokens'] ?? 0,
            'content'=> $parsed ?? [],
        ];

    }

}
