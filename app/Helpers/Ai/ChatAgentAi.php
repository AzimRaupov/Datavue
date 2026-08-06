<?php

namespace App\Helpers\Ai;

use App\Helpers\Chat\ChatContext;
use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\ReadOnlyQueryRunner;
use App\Helpers\DataSource\SchemaOptions;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Настоящий чат-агент DataVue.
 *
 * Раньше ответ пользователю брался из поля "message" роутера задач — модель
 * писала его вслепую, не зная ни виджетов дашборда, ни схемы, ни данных.
 * Поэтому на вопросы «что у меня в дашборде», «что посоветуешь добавить»
 * получался бессодержательный ответ.
 *
 * Теперь агент видит полный контекст (ChatContext) и, если вопрос требует
 * фактов из данных, может сам запросить схему таблиц и выполнить read-only
 * SQL — то есть отвечает по реальным данным, а не по догадкам.
 */
class ChatAgentAi
{
    /** Максимум обращений к инструментам за один ответ (защита от зацикливания). */
    private const MAX_STEPS = 4;

    private ?ConnectionProviderRouter $router = null;

    private ?ReadOnlyQueryRunner $queryRunner = null;

    private int $totalTokens = 0;

    public function __construct(
        private ChatContext $context,
        private $history,
        private string $currentMessage
    ) {
        if ($this->context->hasDataSource()) {
            try {
                $this->router = new ConnectionProviderRouter($this->context->dataSource->id);
                $this->queryRunner = new ReadOnlyQueryRunner($this->router);
            } catch (Throwable $e) {
                // Источник может быть недоступен (упало подключение, удалён файл).
                // Это не повод ронять ответ — агент просто ответит без данных.
                Log::warning('ChatAgentAi: data source is unavailable, answering without data access', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array{message: string, total_tokens: int}
     */
    public function answer(): array
    {
        // Журнал обращений к инструментам — накапливается между шагами
        // и передаётся модели, чтобы она видела уже полученные факты.
        $toolLog = [];

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $response = (new AIService(responseFormat: 'json', tokens: 6000))
                ->ask($this->buildPrompt($toolLog), $this->systemPrompt());

            $this->totalTokens += (int) ($response['total_tokens'] ?? 0);
            $content = $response['content'] ?? [];

            if (!is_array($content)) {
                break;
            }

            $action = $content['action'] ?? 'answer';

            if ($action === 'answer' || $step === self::MAX_STEPS - 1) {
                $message = trim((string) ($content['message'] ?? ''));

                // Модель иногда вместо вызова инструмента пишет «сейчас выполню
                // запрос» и на этом останавливается — пользователь получает
                // обещание вместо ответа. Ловим это и возвращаем модель к работе.
                if (
                    $message !== ''
                    && $step < self::MAX_STEPS - 1
                    && $this->queryRunner
                    && $this->looksLikeDeferredAnswer($message)
                ) {
                    $toolLog[] = [
                        'tool' => 'system',
                        'result' => 'Ты описал намерение выполнить запрос вместо того, чтобы его выполнить. '
                            .'Никогда не обещай сходить в базу — либо верни action="query" с готовым SQL, '
                            .'либо action="answer" с окончательным ответом по уже известным фактам.',
                    ];

                    continue;
                }

                if ($message !== '') {
                    return ['message' => $message, 'total_tokens' => $this->totalTokens];
                }

                break;
            }

            if ($action === 'schema') {
                $toolLog[] = $this->runSchemaTool($content['tables'] ?? []);
                continue;
            }

            if ($action === 'query') {
                $toolLog[] = $this->runQueryTool((string) ($content['sql'] ?? ''));
                continue;
            }

            // Неизвестное действие — просим модель ответить текстом на следующем шаге.
            $toolLog[] = [
                'tool' => 'unknown',
                'requested_action' => $action,
                'result' => 'Неизвестное действие. Ответь пользователю текстом (action="answer").',
            ];
        }

        return [
            'message' => 'Не удалось подготовить ответ. Попробуйте переформулировать вопрос.',
            'total_tokens' => $this->totalTokens,
        ];
    }

    /**
     * Ответ-обещание («сейчас выполню запрос», «нужно проверить») вместо
     * реального вызова инструмента.
     */
    private function looksLikeDeferredAnswer(string $message): bool
    {
        $patterns = [
            '/сейчас\s+(выполню|проверю|запрошу|узнаю|посмотрю|сделаю)/iu',
            '/(выполню|сделаю|отправлю)\s+(этот\s+)?запрос/iu',
            '/нужно\s+(проверить|уточнить|выполнить\s+запрос)/iu',
            '/(дайте|дай)\s+(мне\s+)?(момент|минуту|секунду)/iu',
            '/(одну|пару)\s+(минуту|секунду|минут)/iu',
            '/let me (check|run|query|look)/i',
            '/i(\'ll| will) (check|run|query|look)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    private function runSchemaTool($tables): array
    {
        $tables = is_array($tables) ? array_values(array_filter(array_map('strval', $tables))) : [];

        if (!$this->router) {
            return [
                'tool' => 'schema',
                'tables' => $tables,
                'result' => 'Источник данных недоступен — схему получить нельзя.',
            ];
        }

        try {
            $schema = $this->router->getSchema($tables, SchemaOptions::basic());

            return [
                'tool' => 'schema',
                'tables' => $tables,
                'result' => $schema,
            ];
        } catch (Throwable $e) {
            return [
                'tool' => 'schema',
                'tables' => $tables,
                'result' => 'Ошибка получения схемы: '.$e->getMessage(),
            ];
        }
    }

    private function runQueryTool(string $sql): array
    {
        if (!$this->queryRunner) {
            return [
                'tool' => 'query',
                'sql' => $sql,
                'result' => 'Источник данных недоступен — выполнить запрос нельзя.',
            ];
        }

        $result = $this->queryRunner->run($sql);

        return [
            'tool' => 'query',
            'sql' => $sql,
            'result' => $result['ok']
                ? [
                    'rows' => $result['rows'],
                    'row_count' => $result['row_count'],
                    'truncated' => $result['truncated'],
                ]
                : 'Ошибка выполнения: '.$result['error'],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<TEXT
Ты — AI-аналитик платформы DataVue. Ты общаешься с пользователем в чате рядом с его дашбордом.

Ты — не отговорка и не автоответчик: ты видишь текущий дашборд, его виджеты, смысловые группы таблиц источника данных и каталог доступных типов виджетов. При необходимости ты можешь запросить схему таблиц и выполнить read-only SQL, чтобы ответить по реальным данным.

Главные принципы:
- Отвечай конкретно и по существу, опираясь на переданный контекст и полученные факты.
- Никогда не выдумывай таблицы, колонки, виджеты и числа. Если данных не хватает — сначала воспользуйся инструментом, и только потом отвечай.
- Если пользователь просит совет — давай осмысленные, аргументированные рекомендации, привязанные к его реальным данным и уже существующим виджетам (не дублируй то, что уже есть).
- Пиши на языке пользователя, живым человеческим текстом, без markdown-разметки и без JSON внутри поля message.
TEXT;
    }

    private function buildPrompt(array $toolLog): string
    {
        $contextJson = $this->context->toJson();

        $historyJson = json_encode(
            $this->history,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        $toolsAvailable = $this->router
            ? 'Инструменты доступны: можно запрашивать схему и выполнять SELECT-запросы.'
            : 'ВНИМАНИЕ: источник данных недоступен — инструменты использовать нельзя, отвечай только по контексту.';

        $toolLogBlock = '';

        if (!empty($toolLog)) {
            $toolLogJson = json_encode(
                $toolLog,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );

            $toolLogBlock = <<<TEXT

========================
РЕЗУЛЬТАТЫ ТВОИХ ЗАПРОСОВ (уже выполнены)
========================
{$toolLogJson}

Используй эти факты в ответе. Не запрашивай повторно то, что уже получил.
TEXT;
        }

        return <<<TEXT
========================
КОНТЕКСТ (текущее состояние системы)
========================
{$contextJson}

Пояснение к контексту:
- "current_dashboard.widgets" — виджеты, которые пользователь СЕЙЧАС видит на экране; "what_it_shows" описывает, какие данные виджет отображает.
- "data_groups" — смысловые группы таблиц источника данных с описаниями и ролями таблиц.
- "available_widget_types" — типы визуализаций, которые платформа умеет строить. Рекомендовать можно ТОЛЬКО их.

========================
ИСТОРИЯ ЧАТА
========================
{$historyJson}

========================
СООБЩЕНИЕ ПОЛЬЗОВАТЕЛЯ
========================
{$this->currentMessage}
{$toolLogBlock}

========================
ИНСТРУМЕНТЫ
========================
{$toolsAvailable}

1) Получить схему таблиц (колонки, типы, связи):
{"action": "schema", "tables": ["orders", "customers"]}
Передай пустой массив tables, чтобы получить схему всех таблиц.

2) Выполнить читающий SQL-запрос по данным пользователя:
{"action": "query", "sql": "SELECT country, COUNT(*) AS cnt FROM customers GROUP BY country ORDER BY cnt DESC"}
Разрешён только один SELECT/WITH без ";". Результат ограничен 200 строками.
Перед написанием SQL убедись, что знаешь реальные имена таблиц и колонок — при сомнении сначала запроси схему.

3) Ответить пользователю:
{"action": "answer", "message": "текст ответа"}

========================
КОГДА ЧТО ИСПОЛЬЗОВАТЬ
========================
- Вопрос о том, что уже есть на дашборде, или просьба порекомендовать виджеты → как правило, достаточно контекста, отвечай сразу (action="answer").
- Вопрос о конкретных числах, значениях, топах, динамике («сколько», «какой самый», «есть ли у меня данные по X») → сначала schema/query, потом ответ по фактам.
- Не используй инструменты без необходимости: если ответ уже есть в контексте, просто отвечай.

ЖЁСТКОЕ ПРАВИЛО: никогда не пиши в "message" обещание что-то сделать
(«сейчас выполню запрос», «нужно проверить», «дайте минуту»). У тебя нет
следующей реплики, чтобы вернуться к пользователю — он увидит ровно то, что
ты написал. Если для ответа нужны данные, в ЭТОМ ЖЕ ответе верни
action="query" или action="schema". Поле "message" заполняется только тогда,
когда ответ окончательный и содержит сам результат.

========================
ОТВЕЧАЙ НА ВЕСЬ ВОПРОС ЦЕЛИКОМ
========================
Если в сообщении пользователя несколько частей («что лишнее ИЛИ что добавить»,
«объясни и посоветуй»), ответь на КАЖДУЮ из них. Пропустить половину вопроса — ошибка.

========================
КАК ДАВАТЬ РЕКОМЕНДАЦИИ ПО ВИДЖЕТАМ
========================
Если пользователь просит порекомендовать, что ДОБАВИТЬ на дашборд:
1. Посмотри, что уже есть в "current_dashboard.widgets" — не предлагай дубли того, что уже отображается.
2. Посмотри, какие данные реально доступны в "data_groups".
3. Предложи 3-5 конкретных виджетов: что показывать, на основе каких таблиц и какой тип визуализации из "available_widget_types" подойдёт, и коротко — зачем это бизнесу.

Если пользователь спрашивает, что ЛИШНЕЕ, что убрать, что не так с дашбордом,
или просит оценить/раскритиковать его — разбери существующие виджеты и честно назови кандидатов на удаление:
- виджеты со статусом, отличным от "active" (например "failed") — они не работают и сейчас бесполезны пользователю;
- два виджета, показывающие фактически одну и ту же метрику в одном разрезе, — дубли;
- виджеты, тип которых плохо подходит их данным (например круговая диаграмма на десятках категорий,
  где читаемее столбчатая);
- виджеты, не относящиеся к теме дашборда.
Если убирать реально нечего — так и скажи прямо, не выдумывай недостатки ради ответа.

В конце спроси, применить ли предложенное — но НЕ меняй дашборд сам, ты только отвечаешь в чате.

========================
ФОРМАТ ОТВЕТА
========================
Верни ТОЛЬКО валидный JSON без markdown, ровно одного из трёх видов выше.
Поле "message" — обычный человеческий текст (можно с переносами строк и нумерацией), без markdown-звёздочек и без вложенного JSON.
TEXT;
    }
}
