<?php

namespace App\Helpers\Ai;

use App\Helpers\Ai\DashboardAi;
use App\Helpers\Chat\ChatContext;
use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\ReadOnlyQueryRunner;
use App\Helpers\DataSource\SchemaOptions;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatAgentAi
{

    private const MAX_STEPS = 6;

    private const MAX_SCHEMA_TABLES = 12;

    private ?ConnectionProviderRouter $router = null;

    private ?ReadOnlyQueryRunner $queryRunner = null;

    private int $totalTokens = 0;

    private bool $groundingWarned = false;

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

    public function answer(): array
    {
        $this->preselectGroups();

        $toolLog = [];

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
                    ? $this->answerRejectionReason($message, $toolLog)
                    : null;

                if ($rejection !== null) {
                    $toolLog[] = [
                        'tool' => 'system',
                        'result' => $rejection,
                    ];

                    continue;
                }

                if ($message !== '') {
                    return $this->buildAnswer($message, $content['offer'] ?? null);
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

        return $this->forceAnswer($toolLog);
    }

    private function buildAnswer(string $message, $offer): array
    {
        $type = is_array($offer) ? trim((string) ($offer['type'] ?? '')) : '';
        $summary = is_array($offer) ? trim((string) ($offer['summary'] ?? '')) : '';

        if (!in_array($type, ['dashboard', 'export', 'question', 'none'], true)) {
            $type = 'none';
            $summary = '';
        }

        if ($type === 'question' || $type === 'none') {
            $summary = '';
        }

        return [
            'message' => $message,
            'total_tokens' => $this->totalTokens,
            'offer_type' => $type,
            'offer_summary' => $summary,
        ];
    }

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

            return $this->buildAnswer(
                $message !== '' ? $message : $fallback,
                is_array($response['content'] ?? null) ? ($response['content']['offer'] ?? null) : null
            );
        } catch (Throwable $e) {
            Log::warning('ChatAgentAi: forced answer failed', ['error' => $e->getMessage()]);

            return $this->buildAnswer($fallback, null);
        }
    }

    private function answerRejectionReason(string $message, array $toolLog): ?string
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

        if (!$this->groundingWarned && $this->statesFiguresWithoutQuery($message, $toolLog)) {
            $this->groundingWarned = true;

            return 'Ты привёл конкретные значения, не выполнив ни одного запроса к данным. '
                .'Числа и списки о содержимом базы берутся ТОЛЬКО из результата action="query" — '
                .'знание похожих баз не считается фактом об этой. '
                .'Выполни запрос и ответь по его результату. '
                .'Если число взято из контекста (например количество виджетов дашборда), '
                .'а не из данных — прямо напиши, что это из настроек дашборда.';
        }

        return null;
    }

    private function statesFiguresWithoutQuery(string $message, array $toolLog): bool
    {
        foreach ($toolLog as $entry) {

            if (($entry['tool'] ?? null) === 'query' && is_array($entry['result'] ?? null)) {
                return false;
            }
        }

        return (bool) preg_match('/\d/', $message);
    }

    private function looksLikePlaceholderAnswer(string $message): bool
    {
        $patterns = [

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

    private function preselectGroups(): void
    {
        if (!$this->context->hasGroups() || !$this->context->hasDataSource()) {
            return;
        }

        if ($this->context->totalTablesCount() <= self::MAX_SCHEMA_TABLES) {

            $this->context->focusOnGroups(
                $this->context->groupsForSelection()->pluck('id')->all()
            );

            return;
        }

        try {
            $response = (new DashboardAi($this->context->dataSource))->defineGroups(
                groups: $this->context->groupsForSelection(),
                text: $this->currentMessage
            );

            $this->totalTokens += (int) ($response['total_tokens'] ?? 0);

            $groupIds = $response['content']['groups'] ?? [];

            if (is_array($groupIds) && $groupIds) {
                $this->context->focusOnGroups($groupIds);
            }

            Log::info('ChatAgentAi: groups preselected', [
                'requested' => $groupIds,
                'applied' => $this->context->focusedGroupIds(),
            ]);
        } catch (Throwable $e) {

            Log::warning('ChatAgentAi: group preselection failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

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

    private function knownTables(): array
    {
        $tables = $this->context->allTableNames();

        if ($tables || !$this->router) {
            return $tables;
        }

        try {
            return $this->router->showTables();
        } catch (Throwable $e) {
            Log::warning('ChatAgentAi: table list is unavailable', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
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

        $knownTables = $this->knownTables();

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
- Платформа умеет выгружать результат в файл (CSV, Excel, PDF, Word). Сам ты файлы не создаёшь, но если пользователь спрашивает о такой возможности или ей явно место в ответе — скажи, что достаточно попросить: «сохрани топ-10 клиентов в excel», и файл придёт ссылкой прямо в чат.
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
- "data_groups" — смысловые группы таблиц источника. У групп, отобранных под твой вопрос, состав уже раскрыт в поле "tables" (имя таблицы, описание, роль) — им инструмент не нужен, работай с ними сразу. У остальных групп видны только id, название и число таблиц: если нужного не нашлось среди раскрытых, состав такой группы получают инструментом "tables" по её id.
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
{"action": "answer", "message": "текст ответа в markdown", "offer": {"type": "none", "summary": ""}}

Поле "offer" — служебное, пользователь его не видит. В нём ты сообщаешь системе,
что именно предложил в своём ответе, чтобы она поняла короткий ответ «давай»
или «нет» без повторного разбора переписки.

"type" — строго одно из четырёх значений:
- "dashboard" — ты предложил изменить дашборд или построить новый (добавить,
  удалить, объединить виджеты, перестроить);
- "export" — ты предложил выгрузить данные файлом;
- "question" — ты задал уточняющий вопрос и ждёшь ответа;
- "none" — ты просто ответил, ничего не предлагая и ни о чём не спрашивая.

"summary" — что именно предложено, одной строкой без точек и markdown, до 60
символов, глаголами и по делу: «объединить карточки, удалить дубль со средним
чеком». Пользователь скажет «давай» — и система выполнит ровно это, поэтому
формулируй так, чтобы по summary можно было работать без твоего текста.
Для "question" и "none" оставь пустую строку.

ЖЁСТКОЕ ПРАВИЛО ПРО "none": если в твоём ответе есть ЛЮБОЕ предложение
что-то сделать — «могу добавить», «могу подготовить», «стоит удалить»,
«хотите, я сделаю», «применить?», — либо ответ заканчивается вопросом,
то "none" ЗАПРЕЩЁН. Выбирай "dashboard", "export" или "question".

"none" ставится только тогда, когда ты просто сообщил факты и ничего
не предложил и ни о чём не спросил.

Разбор на примерах:

Ответ: «...Могу подготовить виджет с более длинной цепочкой этапов или уточнить
набор этапов для новой версии воронки. Могу подготовить конфигурацию под ваш выбор.»
→ {"type": "dashboard", "summary": "заменить воронку на версию с 4-5 этапами"}
(это предложение изменить дашборд, а не просто ответ)

Ответ: «Всего в базе 122 клиента из 21 страны.»
→ {"type": "none", "summary": ""}

Ответ: «Полный список из 340 строк удобнее смотреть в файле. Подготовить выгрузку?»
→ {"type": "export", "summary": "выгрузить список клиентов в excel"}

Ответ: «Уточните, за какой период показать данные?»
→ {"type": "question", "summary": ""}

Пользователь ответит «давай» — и система выполнит то, что записано в offer.
Ошибёшься с "none" — его согласие уйдёт в пустоту, и он повторит просьбу словами.

========================
КОГДА ЧТО ИСПОЛЬЗОВАТЬ
========================
- Вопрос о том, что уже есть на дашборде, или просьба порекомендовать виджеты → как правило, достаточно контекста, отвечай сразу (action="answer").
- Вопрос о конкретных числах, значениях, топах, динамике («сколько», «какой самый», «есть ли у меня данные по X») → идёшь по воронке и отвечаешь по фактам:
  1. в раскрытых группах ("tables") находишь подходящие таблицы. Если там нужного нет — action="tables" по id другой группы;
  2. по найденным таблицам → action="schema", чтобы узнать реальные колонки и связи;
  3. по реальным колонкам пишешь SQL → action="query";
  4. отвечаешь.
- Первый шаг обычно уже сделан за тебя: группы под этот вопрос отобраны, их таблицы перед глазами. Начинай сразу со "schema" по нужной таблице.
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
ЕСЛИ СООБЩЕНИЕ НЕПОНЯТНО
========================
Если сообщение бессмысленно (случайный набор символов, одни знаки препинания)
или из него невозможно понять, о чём спрашивают, — НЕ обращайся к инструментам
и не угадывай, что пользователь мог иметь в виду. Ни одного запроса к данным:
SQL, написанный по бессмысленной фразе, не значит ничего, а пользователь ждёт
не таблицу наугад, а понятную реакцию.

Сразу верни action="answer" с коротким уточнением и парой примеров того, что
можно спросить. Одна-две строки, без разбора данных и без извинений.

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
