<?php

namespace App\Helpers\Ai;

use App\Helpers\Ai\Providers\ProviderAiFactory;
use App\Helpers\Ai\Providers\SqlProviderAi;
use App\Helpers\Export\ExportFormat;

/**
 * Обращения к модели, из которых собирается выгрузка результата в файл.
 *
 * Три шага: понять, что и в каком формате просят; написать main(); починить
 * main(), если запуск упал. Тексты промптов зависят от диалекта источника
 * и живут в SqlProviderAi — здесь только вызовы и разбор ответа.
 */
class ExportAi
{
    private SqlProviderAi $providerAi;

    public function __construct($dataSource)
    {
        $this->providerAi = ProviderAiFactory::for($dataSource);
    }

    /**
     * Что именно выгружать и в каком виде.
     *
     * Отдельный дешёвый шаг перед генерацией кода: из фразы «посчитай топ-10
     * клиентов и сохрани в эксель» нужно вытащить и задачу для SQL, и формат,
     * и заголовок документа, и имя файла. Формат мы потом ещё раз проверяем
     * по тексту пользователя — модель регулярно «улучшает» его на свой вкус.
     *
     * @param  array{message: string, history: string, context: string}  $data
     * @return array{content: array, total_tokens: int}
     */
    public function defineSpec(array $data): array
    {
        $formats = implode(', ', ExportFormat::all());

        $system = <<<TEXT
Ты — часть аналитической платформы DataVue. Пользователь попросил выгрузить данные в файл.

Твоя задача — превратить его просьбу в точное техническое задание на выгрузку:
что посчитать, как назвать документ и в каком формате его сохранить.

Ты не пишешь код и не отвечаешь пользователю. Ты возвращаешь только JSON.
TEXT;

        $prompt = <<<TEXT
========================
ИСТОРИЯ ЧАТА
========================
{$data['history']}

========================
СООБЩЕНИЕ ПОЛЬЗОВАТЕЛЯ
========================
{$data['message']}

========================
КОНТЕКСТ СИСТЕМЫ
========================
{$data['context']}

Контекст нужен только чтобы понять, о каких данных идёт речь. Настройки существующих
виджетов не являются пожеланием пользователя.

========================
ЧТО ЗАПОЛНИТЬ
========================

format — один из: {$formats}
  csv  — простая выгрузка данных «как есть», значение по умолчанию, если формат не назван;
  xlsx — если сказано «эксель», «excel», «xls», «таблица»;
  pdf  — если сказано «pdf», «пдф»;
  docx — если сказано «word», «ворд», «docx».

instruction — задача для генератора SQL: что посчитать, по каким сущностям,
  с какими фильтрами, сортировкой и ограничением количества строк.
  Пиши по делу и без выдумок: только то, что просил пользователь (с учётом истории чата,
  если он ссылается на предыдущий ответ — «сохрани это», «то же самое, но за год»).
  Не добавляй колонки, периоды и лимиты, которых пользователь не называл.
  Если он назвал желаемые названия колонок — перечисли их дословно.

title — заголовок документа, 2-6 слов на языке пользователя. Без слов «файл», «выгрузка», «экспорт».

file_name — имя файла без расширения: латиница в нижнем регистре, слова через дефис,
  не длиннее 40 символов (например "top-10-clients").

========================
ФОРМАТ ОТВЕТА
========================
Только валидный JSON без markdown:

{
  "format": "",
  "instruction": "",
  "title": "",
  "file_name": ""
}
TEXT;

        return (new AIService(responseFormat: 'json', tokens: 2000))->ask($prompt, $system);
    }

    /**
     * Код main(), который готовит данные и вызывает save_result().
     *
     * @return array{code: string, total_tokens: int}
     */
    public function generateCode(array $data): array
    {
        $prompts = $this->providerAi->resultExport($data);

        $response = (new AIService(responseFormat: 'text', tokens: 8000))
            ->ask($prompts['prompt'], $prompts['system']);

        return [
            'code' => $this->extractMain((string) ($response['content'] ?? '')),
            'total_tokens' => (int) ($response['total_tokens'] ?? 0),
        ];
    }

    /**
     * Исправленный main() по ошибке запуска.
     *
     * @return array{code: string, message: string, total_tokens: int}
     */
    public function fixCode(array $data): array
    {
        $prompts = $this->providerAi->reViewErrorsExport($data);

        $response = (new AIService(responseFormat: 'json', tokens: 6000))
            ->ask($prompts['prompt'], $prompts['system']);

        $content = is_array($response['content'] ?? null) ? $response['content'] : [];

        return [
            'code' => $this->extractMain((string) ($content['code_main'] ?? '')),
            'message' => trim((string) ($content['message'] ?? '')),
            'total_tokens' => (int) ($response['total_tokens'] ?? 0),
        ];
    }

    /**
     * Достаёт из ответа модели саму функцию main().
     */
    private function extractMain(string $code): string
    {
        $code = trim($code);
        $code = preg_replace('/^```(?:python)?\s*/i', '', $code);
        $code = preg_replace('/\s*```$/', '', $code);
        $code = trim($code);

        if (!preg_match('/^\s*def\s+main\s*\(\s*\)\s*:/', $code)
            && preg_match('/def\s+main\s*\(\s*\)\s*:.*/s', $code, $matches)) {
            $code = $matches[0];
        }

        return $code;
    }
}
