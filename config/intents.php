<?php

return [

    'enabled' => (bool) env('INTENT_CLASSIFIER_ENABLED', true),

    // Рубильник определения задачи: "local" — сначала пробуем локальную ML-модель
    // (ml/intents/model.json), к GPT идём только если она не уверена (как сейчас).
    // "api" — локальную модель для маршрутизации не используем вовсе, задачу
    // для каждого сообщения определяет GPT (DefineTaskAi).
    'mode' => env('TASK_ROUTING_MODE', 'local'),

    'model_path' => base_path('ml/intents/model.json'),

    'threshold' => (float) env('INTENT_THRESHOLD', 0.70),

    'threshold_dashboard' => (float) env('INTENT_THRESHOLD_DASHBOARD', 0.80),

    'infer_offer_threshold' => (float) env('INTENT_INFER_OFFER_THRESHOLD', 0.60),

    'infer_offer_chars' => (int) env('INTENT_INFER_OFFER_CHARS', 220),

    'unintelligible' => [

        'min_length' => (int) env('INTENT_GIBBERISH_MIN_LENGTH', 12),

        'max_coverage' => (float) env('INTENT_GIBBERISH_MAX_COVERAGE', 0.15),

        'max_word_length' => (int) env('INTENT_GIBBERISH_MAX_WORD', 12),

        'min_bigram_diversity' => (float) env('INTENT_GIBBERISH_MIN_DIVERSITY', 0.5),
    ],

    'learning' => [
        'enabled' => (bool) env('INTENT_LEARNING_ENABLED', true),

        'min_samples' => (int) env('INTENT_RETRAIN_MIN_SAMPLES', 50),

        'max_accuracy_drop' => (float) env('INTENT_MAX_ACCURACY_DROP', 0.005),

        'min_coverage' => (float) env('INTENT_LEARN_MIN_COVERAGE', 0.10),

        'single_word_min_coverage' => (float) env('INTENT_LEARN_SINGLE_WORD_COVERAGE', 0.40),

        'timeout' => (int) env('INTENT_RETRAIN_TIMEOUT', 900),
    ],

];
