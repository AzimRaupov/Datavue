<?php

namespace App\Helpers\Ai;

class DefineTaskAi
{
    public $currentMessage;
    public $messages;
    public $task_list;

    public function __construct($messages, $currentMessage, $task_list)
    {
        $this->currentMessage = $currentMessage;
        $this->messages = $messages;
        $this->task_list = $task_list;
    }

    public function defineTask($widgets = null)
    {
        $system = "
Ты — AI-агент, который определяет намерение пользователя и выбирает подходящую задачу.

Ты НЕ выполняешь задачу самостоятельно.
Твоя единственная задача — определить, какую задачу необходимо вызвать.
";

        $messages = json_encode(
            $this->messages,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        $currentMessage = $this->currentMessage;

        $tasks = json_encode(
            $this->task_list,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );


        $widgetsBlock = '';

        if (!empty($widgets)) {
            $widgetsJson = json_encode(
                $widgets,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );

            $widgetsBlock = <<<TEXT

========================
Виджеты текущего дашборда
========================
{$widgetsJson}

TEXT;
        }


        $prompt = <<<TEXT
Роль: Ты — AI агент для платформы DataVue визуализации данных.

Твоя единственная задача — определить НАМЕРЕНИЕ пользователя и выбрать ОДНУ наиболее подходящую задачу из списка, учитывая историю чата и текущее сообщение.

Ты НЕ выполняешь задачи самостоятельно и не генерируешь их результат.

========================
ИСТОРИЯ ЧАТА
========================
Формат json: каждая запись содержит "message" — сообщение от пользователя и "answer" — ответ ИИ-агента.

Если история пустая — это первое сообщение пользователя.

{$messages}

========================
ТЕКУЩЕЕ СООБЩЕНИЕ ПОЛЬЗОВАТЕЛЯ
========================
{$currentMessage}

========================
ДОСТУПНЫЕ ЗАДАЧИ
========================
{$tasks}

{$widgetsBlock}

========================
ПРАВИЛА ВЫБОРА
========================
1. Определи конечную цель пользователя с учётом истории чата и текущего сообщения.
2. Выбери ОДНУ наиболее подходящую задачу из списка ДОСТУПНЫЕ ЗАДАЧИ.
3. Если специализированная задача подходит по смыслу — выбирай её, а не response_in_chat.
4. Если подходят сразу несколько задач — выбери наиболее узкоспециализированную.
5. Если ни одна задача не подходит или недостаточно данных — выбери response_in_chat и задай один уточняющий вопрос.
6. Не придумывай задачи. task_name должен точно совпадать с name из списка задач или быть "response_in_chat".

========================
ЗАПОЛНЕНИЕ ПОЛЕЙ
========================

task_name:
Имя выбранной задачи.

task_title:
Короткий заголовок задачи (2-5 слов).

task_instruction:
Только информация, которую можно взять из сообщения пользователя.
Не добавляй детали из description задачи или своих предположений.

message:
Если выбрана специализированная задача — короткое подтверждение запуска.
Если response_in_chat — ответ или уточняющий вопрос пользователю.

Пиши на языке пользователя.

========================
ФОРМАТ ОТВЕТА
========================

Верни ТОЛЬКО валидный JSON без markdown и пояснений.

{
  "task_name": "",
  "task_title": "",
  "task_instruction": "",
  "message": ""
}
TEXT;


        $response = (new AIService(
            responseFormat: 'json'
        ))->ask($prompt, $system);

        return $response;
    }
}
