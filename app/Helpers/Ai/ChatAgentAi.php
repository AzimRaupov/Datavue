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
    /**
     * Максимум обращений к инструментам за один ответ (защита от зацикливания).
     *
     * Полный путь на большом источнике — tables → schema → query → answer,
     * плюс запас на исправление ошибочного SQL.
     */
    private const MAX_STEPS = 6;

    /**
     * Сколько таблиц агент может запросить схемой за раз.
     *
     * Схема одной таблицы — это десятки строк JSON с колонками и связями, так
     * что на источнике в 300 таблиц запрос «схему всего» не помещается ни в
     * контекст модели, ни в разумное время ответа.
     */
    private const MAX_SCHEMA_TABLES = 12;

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

        $toolLog = [];

        // Подписи уже выполненных обращений: модель склонна повторять один и тот
        // же запрос, проедая шаги и токены на данных, которые уже лежат в логе.
        $seen = [];

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $response = (new AIService(responseFormat: 'json', tokens: 6000))
                ->ask($this->buildPrompt($toolLog), $this->systemPrompt());

            $this->totalTokens += (int) ($response['total_tokens'] ?? 0);
            $content = $response['content'] ?? [];

            if (!is_array($content)) {
                break;
            }

            $action = $content['action'] ?? 'answer';

            // Ход рассуждения агента не виден ни в UI, ни в ответе — без этого
            // журнала невозможно понять, откуда в ответе взялось конкретное число.
            Log::info('ChatAgentAi: step', [
                'step' => $step,
                'action' => $action,
                'tables' => $content['tables'] ?? null,
                'groups' => $content['groups'] ?? null,
                'sql' => $content['sql'] ?? null,
            ]);

            if ($action === 'answer') {
                $message = trim((string) ($content['message'] ?? ''));

                $rejection = ($message !== '' && $this->queryRunner)
                    ? $this->answerRejectionReason($message)
                    : null;

                if ($rejection !== null) {
                    $toolLog[] = [
                        'tool' => 'system',
                        'result' => $rejection,
                    ];

                    continue;
                }

                if ($message !== '') {
                    return ['message' => $message, 'total_tokens' => $this->totalTokens];
                }

                break;
            }

            if (in_array($action, ['tables', 'schema', 'query'], true)) {
                $signature = $action.'|'.json_encode(
                    $content['groups'] ?? $content['tables'] ?? $content['sql'] ?? null
                );

                if (isset($seen[$signature])) {
                    $toolLog[] = [
                        'tool' => 'system',
                        'result' => 'Этот запрос ты уже выполнял — его результат есть выше. '
                            .'Не повторяйся: используй полученные факты или запроси что-то другое.',
                    ];

                    continue;
                }

                $seen[$signature] = true;
            }

            if ($action === 'tables') {
                $toolLog[] = $this->runTablesTool($content['groups'] ?? []);
                continue;
            }

            if ($action === 'schema') {
                $toolLog[] = $this->runSchemaTool($content['tables'] ?? []);
                continue;
            }

            if ($action === 'query') {
                $toolLog[] = $this->runQueryTool((string) ($content['sql'] ?? ''));
                continue;
            }

            $toolLog[] = [
                'tool' => 'unknown',
                'requested_action' => $action,
                'result' => 'Неизвестное действие. Допустимы "tables", "schema", "query" и "answer".',
            ];
        }

        // Шаги кончились, а окончательного ответа нет. Молча отдать заглушку
        // нельзя: к этому моменту факты уже собраны, и пользователь заплатил
        // за них ожиданием — просим модель ответить тем, что есть.
        return $this->forceAnswer($toolLog);
    }

    /**
     * Последний вызов: только ответ, никаких инструментов.
     */
    private function forceAnswer(array $toolLog): array
    {
        $fallback = 'Не удалось подготовить ответ. Попробуйте переформулировать вопрос.';

        $instruction = <<<'TEXT'

========================
ЭТО ПОСЛЕДНИЙ ШАГ
========================
Обращения к инструментам закончились. Верни ТОЛЬКО {"action": "answer", "message": "..."}.
Ответь тем, что уже собрано выше: назови полученные факты, а про то, что выяснить
не успел, честно скажи — чего не хватило и что можно уточнить следующим вопросом.
Заглушки и заполнители по-прежнему запрещены.
TEXT;

        try {
            $response = (new AIService(responseFormat: 'json', tokens: 6000))
                ->ask($this->buildPrompt($toolLog).$instruction, $this->systemPrompt());

            $this->totalTokens += (int) ($response['total_tokens'] ?? 0);

            $message = is_array($response['content'] ?? null)
                ? trim((string) ($response['content']['message'] ?? ''))
                : '';

            Log::info('ChatAgentAi: forced answer', [
                'steps_exhausted' => true,
                'answered' => $message !== '',
            ]);

            return [
                'message' => $message !== '' ? $message : $fallback,
                'total_tokens' => $this->totalTokens,
            ];
        } catch (Throwable $e) {
            Log::warning('ChatAgentAi: forced answer failed', ['error' => $e->getMessage()]);

            return ['message' => $fallback, 'total_tokens' => $this->totalTokens];
        }
    }

    /**
     * Причина, по которой ответ нельзя показывать пользователю, или null.
     *
     * Возвращается агенту как результат "инструмента" — так он получает шанс
     * доделать работу вместо того, чтобы пользователь увидел заглушку.
     */
    private function answerRejectionReason(string $message): ?string
    {
        if ($this->looksLikeDeferredAnswer($message)) {
            return 'Ты описал намерение выполнить запрос вместо того, чтобы его выполнить. '
                .'Никогда не обещай сходить в базу — либо верни action="query" с готовым SQL, '
                .'либо action="answer" с окончательным ответом по уже известным фактам.';
        }

        if ($this->looksLikePlaceholderAnswer($message)) {
            return 'В ответе стоит заполнитель вместо реального значения (N, X, «число» и т.п.). '
                .'Пользователю такой ответ бесполезен. Получи настоящее значение: '
                .'action="tables" → action="schema" → action="query". '
                .'Если получить его невозможно — прямо напиши, почему, и не подставляй заглушку.';
        }

        return null;
    }

    /**
     * Ответ вида «Количество строк = ***N***» — модель отдала шаблон, не сходив в данные.
     */
    private function looksLikePlaceholderAnswer(string $message): bool
    {
        $patterns = [
            // "= N", ": **X**", "— <N>" на месте числа
            '/[=:—-]\s*[*_`<\[]*\s*[NXYНХ]\s*[*_`>\]]*\s*(?=[.,;)]|$)/mu',
            '/[<\[\({]\s*(число|значение|количество|сумма|value|count|number)\s*[>\]\)}]/iu',
            '/\b(TBD|TODO|xxx|nnn)\b/iu',
            '/_{3,}/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

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

    /**
     * Состав выбранных смысловых групп: имена таблиц с описаниями и ролями.
     *
     * Это первый шаг воронки — он сужает 300 таблиц источника до десятка
     * относящихся к вопросу, схему которых уже можно запросить целиком.
     */
    private function runTablesTool($groups): array
    {
        $groups = is_array($groups) ? $groups : [];

        if (!$this->context->hasGroups()) {
            return [
                'tool' => 'tables',
                'groups' => $groups,
                'result' => 'Группы таблиц для этого источника не построены. '
                    .'Запрашивай схему по именам таблиц напрямую (action="schema").',
            ];
        }

        $result = $this->context->tablesForGroups($groups);

        if (empty($result['tables'])) {
            return [
                'tool' => 'tables',
                'groups' => $groups,
                'result' => 'По этим id групп ничего не найдено. Используй id из "data_groups" контекста.',
            ];
        }

        $payload = ['tables' => $result['tables']];

        if (!empty($result['unknown_groups'])) {
            $payload['ignored_unknown_group_ids'] = $result['unknown_groups'];
        }

        if ($result['truncated']) {
            $payload['note'] = 'Список обрезан до '.ChatContext::MAX_TABLES_PER_REQUEST
                .' таблиц — запрашивай группы по одной, если нужно больше.';
        }

        return [
            'tool' => 'tables',
            'groups' => $groups,
            'result' => $payload,
        ];
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

        $knownTables = $this->context->allTableNames();

        // Пустой список раньше означал «схему всех таблиц». На источнике из
        // сотен таблиц это гарантированно выходит за лимит контекста, поэтому
        // сначала требуем сузить круг через группы.
        if (empty($tables) && count($knownTables) > self::MAX_SCHEMA_TABLES) {
            return [
                'tool' => 'schema',
                'tables' => $tables,
                'result' => 'В источнике '.count($knownTables).' таблиц — схему всех сразу получить нельзя. '
                    .'Выбери подходящие группы (action="tables"), затем запроси схему нужных таблиц по именам '
                    .'(не больше '.self::MAX_SCHEMA_TABLES.' за раз).',
            ];
        }

        $note = null;

        if (count($tables) > self::MAX_SCHEMA_TABLES) {
            $note = 'Запрошено '.count($tables).' таблиц, схема отдана только для первых '
                .self::MAX_SCHEMA_TABLES.'. Остальные запроси следующим шагом.';

            $tables = array_slice($tables, 0, self::MAX_SCHEMA_TABLES);
        }

        try {
            $schema = $this->router->getSchema($tables, SchemaOptions::basic());

            $entry = [
                'tool' => 'schema',
                'tables' => $tables,
                'result' => $schema,
            ];

            if ($note !== null) {
                $entry['note'] = $note;
            }

            return $entry;
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

Ты — не отговорка и не автоответчик: ты видишь текущий дашборд, его виджеты, смысловые группы таблиц источника данных и каталог доступных типов виджетов. При необходимости ты можешь раскрыть состав групп, запросить схему таблиц и выполнить read-only SQL, чтобы ответить по реальным данным.

Главные принципы:
- Отвечай конкретно и по существу, опираясь на переданный контекст и полученные факты.
- Никогда не выдумывай таблицы, колонки, виджеты и числа. Если данных не хватает — сначала воспользуйся инструментом, и только потом отвечай.
- В источнике могут быть сотни таблиц. Не пытайся охватить их все: сначала выбери подходящие смысловые группы, потом работай с их таблицами.
- Если пользователь просит совет — давай осмысленные, аргументированные рекомендации, привязанные к его реальным данным и уже существующим виджетам (не дублируй то, что уже есть).
- Пиши на языке пользователя. Поле message — markdown: заголовки, списки, **выделение**, `имена таблиц и колонок` в обратных кавычках, таблицы для числовых сводок.
TEXT;
    }

    private function buildPrompt(array $toolLog): string
    {
        $contextJson = $this->context->toJson();

        $historyJson = json_encode(
            $this->history,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        $maxSchemaTables = self::MAX_SCHEMA_TABLES;

        $toolsAvailable = $this->router
            ? 'Инструменты доступны: можно раскрывать группы таблиц, запрашивать схему и выполнять SELECT-запросы.'
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
- "data_groups" — смысловые группы таблиц источника: id, описание, сколько в группе таблиц и несколько имён для примера. Это ОБЗОР, а не полный список — полный состав группы получают инструментом "tables" по её id.
- "data_groups_total_tables" — сколько всего таблиц в источнике.
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

1) Раскрыть состав смысловых групп (имена таблиц, описания, роли):
{"action": "tables", "groups": [3, 7]}
id берутся из "data_groups" контекста. Это способ сузить сотни таблиц источника до нужного десятка.

2) Получить схему таблиц (колонки, типы, связи):
{"action": "schema", "tables": ["orders", "customers"]}
Только по конкретным именам и не больше {$maxSchemaTables} таблиц за раз. Имена бери из контекста или из результата "tables" — вслепую не угадывай.

3) Выполнить читающий SQL-запрос по данным пользователя:
{"action": "query", "sql": "SELECT country, COUNT(*) AS cnt FROM customers GROUP BY country ORDER BY cnt DESC"}
Разрешён только один SELECT/WITH без ";". Результат ограничен 200 строками.
Перед написанием SQL убедись, что знаешь реальные имена таблиц и колонок — при сомнении сначала запроси схему.

4) Ответить пользователю:
{"action": "answer", "message": "текст ответа в markdown"}

========================
КОГДА ЧТО ИСПОЛЬЗОВАТЬ
========================
- Вопрос о том, что уже есть на дашборде, или просьба порекомендовать виджеты → как правило, достаточно контекста, отвечай сразу (action="answer").
- Вопрос о конкретных числах, значениях, топах, динамике («сколько», «какой самый», «есть ли у меня данные по X») → идёшь по воронке и отвечаешь по фактам:
  1. по названиям и описаниям групп выбираешь 1-3 подходящие → action="tables";
  2. из полученных таблиц берёшь нужные → action="schema";
  3. по реальным колонкам пишешь SQL → action="query";
  4. отвечаешь.
- Шаги воронки можно пропускать, если нужное уже известно: имена таблиц видны в "tables_preview" или уже получены на прошлом шаге.
- Не используй инструменты без необходимости: если ответ уже есть в контексте, просто отвечай.

ЖЁСТКОЕ ПРАВИЛО: в ответе не бывает заполнителей. Никаких «= N», «**X**»,
«<число>», «___» на месте значения. Каждое число в ответе — либо из результата
твоего запроса, либо из контекста. Не знаешь числа — не пиши его вообще:
сходи за ним инструментом или честно скажи, что получить его не удалось и почему.

ЖЁСТКОЕ ПРАВИЛО: никогда не пиши в "message" обещание что-то сделать
(«сейчас выполню запрос», «нужно проверить», «дайте минуту»). У тебя нет
следующей реплики, чтобы вернуться к пользователю — он увидит ровно то, что
ты написал. Если для ответа нужны данные, в ЭТОМ ЖЕ ответе верни
action="tables", action="schema" или action="query". Поле "message"
заполняется только тогда, когда ответ окончательный и содержит сам результат.

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
Сам ответ — ТОЛЬКО валидный JSON, ровно одного из четырёх видов выше, без обёртки в блок кода.

Поле "message" — текст для пользователя в markdown:
- **жирным** — ключевые выводы и числа, по которым и задавали вопрос;
- списки (- или 1.) — перечисления, рекомендации, разборы виджетов;
- `обратные кавычки` — имена таблиц, колонок и значения из данных;
- таблица markdown — когда сравниваешь несколько чисел по строкам;
- `### Заголовок` — только если ответ длинный и делится на смысловые части.

Не перегружай разметкой: на короткий вопрос — короткий абзац без списков и заголовков.
Внутри "message" не должно быть JSON и блоков ```.
TEXT;
    }
}
