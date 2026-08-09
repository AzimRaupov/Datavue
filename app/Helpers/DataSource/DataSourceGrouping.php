<?php

namespace App\Helpers\DataSource;

use App\Helpers\Ai\DataSourceAi;
use App\Models\DataSource;
use App\Models\DataSourceGroup;
use App\Models\DataSourceTable;
use App\Helpers\Ai\AIService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DataSourceGrouping
{
    public DataSource $dataSource;

    public ConnectionProviderRouter $connectionProviderRouter;

    public array $schema = [];

    public array $generatedGroups = [];

    /**
     * Кэш результата getGroups() — заполняется один раз внутри handle(),
     * повторные вызовы getGroups() просто отдают готовый массив.
     */
    public array $groups = [];

    /**
     * Кэш результата getTables() — заполняется один раз внутри handle(),
     * повторные вызовы getTables() просто отдают готовый массив.
     */
    public array $tables = [];
   public $dataSourceAi;
    /**
     * true после того, как handle() отработал хотя бы раз.
     * Нужен, чтобы отличить "группировка ещё не запускалась"
     * от "группировка запускалась, но вернула пустой результат".
     */
    private bool $generated = false;

    /**
     * Куда сообщать о ходе работы.
     *
     * Группировка большой схемы идёт минутами, и без обратной связи мастер
     * подключения показывает пользователю неподвижный спиннер. Колбэк
     * получает (код этапа, подпись, номер шага, всего шагов) и вызывается
     * на границах этапов; null — работаем молча (например, из очереди
     * генератора дашбордов, где прогресс никому не показывается).
     *
     * @var null|callable(string, string, int, int): void
     */
    private $onProgress = null;

    public function __construct(int $dataSourceId)
    {
        $this->dataSource = DataSource::findOrFail($dataSourceId);

        $this->connectionProviderRouter = new ConnectionProviderRouter(
            $this->dataSource->id
        );
    }

    /**
     * @param callable(string, string, int, int): void $callback
     */
    public function onProgress(callable $callback): static
    {
        $this->onProgress = $callback;

        return $this;
    }

    private function reportProgress(string $stage, string $label, int $step, int $total): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($stage, $label, $step, $total);
        }
    }

    /**
     * Основной метод
     */
    public function handle(): array
    {
        /**
         * Получаем схему базы данных
         */
        $this->reportProgress('read_schema', 'Читаем структуру источника', 1, 3);

        $this->schema = $this->connectionProviderRouter->getSchema(
            tables: [],
            options: [
                'count_rows',
                'columns',

                'relations' => [
                    'column' => [
                        'type',
                        'nullable',
                        'key',
                        'default',
                    ],

                    'relation' => [
                        'column',
                        'table',
                        'confidence',
                        'match_rate',
                    ],
                ],
            ]
        );

        /**
         * Делим схему на части по бюджету токенов,
         * с учётом связей между таблицами
         */
        $this->reportProgress('analyze_tables', 'Анализируем таблицы и связи', 2, 3);

        $this->generatedGroups = $this->startGrouping(
            scheme: $this->schema,
            tokenBudgetPerChunk: 6000
        );

        /**
         * Считаем компактные представления (группы отдельно,
         * таблицы отдельно) один раз сразу после генерации,
         * чтобы getGroups()/getTables() просто отдавали
         * готовый результат без повторной генерации.
         */
        $this->reportProgress('build_groups', 'Собираем смысловые группы', 3, 3);

        $this->groups = $this->buildGroups($this->generatedGroups);
        $this->tables = $this->buildTables($this->generatedGroups);

        $this->generated = true;

        return $this->generatedGroups;
    }

    /**
     * Возвращает группы в компактном формате:
     *
     * [
     *     [
     *         "name" => "Продажи и заказы",
     *         "description" => "...",
     *         "tables" => ["orders", "orderdetails"],
     *     ],
     *     ...
     * ]
     *
     * Требует, чтобы перед этим уже был вызван handle().
     */
    public function getGroups(): array
    {
        $this->assertGenerated();

        return $this->groups;
    }

    /**
     * Возвращает плоский список всех таблиц из всех групп,
     * без самих групп:
     *
     * [
     *     [
     *         "name" => "achievement_places",
     *         "description" => "Справочник мест/контекстов, связанных с достижениями; определяет место, где достижение может быть получено.",
     *         "role" => "Справочник мест достижений",
     *     ],
     *     ...
     * ]
     *
     * Требует, чтобы перед этим уже был вызван handle().
     */
    public function getTables(): array
    {
        $this->assertGenerated();

        return $this->tables;
    }

    /**
     * Сохраняет результат группировки в БД
     * (таблицы data_sources_groups и data_sources_tables).
     *
     * Перед сохранением удаляет предыдущий результат группировки
     * для этого DataSource — save() не накапливает старые группы,
     * а полностью заменяет их актуальными.
     *
     * Требует, чтобы перед этим уже был вызван handle().
     */
    public function save(): void
    {
        $this->assertGenerated();

        DB::transaction(function () {

            /**
             * Удаляем предыдущий результат группировки этого DataSource.
             *
             * Таблицы удаляем отдельным запросом (а не полагаемся на
             * каскад через data_source_group_id), потому что в таблицы
             * пишется ещё и data_source_id напрямую — это позволяет
             * не терять данные, если у какой-то таблицы группа почему-то
             * не была указана.
             */
            DataSourceTable::where('data_source_id', $this->dataSource->id)
                ->delete();

            DataSourceGroup::where('data_source_id', $this->dataSource->id)
                ->delete();

            foreach ($this->generatedGroups as $group) {

                if (!isset($group['name'])) {
                    continue;
                }

                $groupModel = DataSourceGroup::create([
                    'data_source_id' => $this->dataSource->id,
                    'name' => $group['name'],
                    'description' => $group['description'] ?? null,
                ]);

                foreach (($group['tables'] ?? []) as $table) {

                    if (!isset($table['name'])) {
                        continue;
                    }

                    DataSourceTable::create([
                        'data_source_id' => $this->dataSource->id,
                        'data_source_group_id' => $groupModel->id,
                        'name' => $table['name'],
                        'description' => $table['description'] ?? null,
                        'role' => $table['role'] ?? null,
                    ]);
                }
            }
        });

        Log::info('Datasource grouping saved', [
            'data_source_id' => $this->dataSource->id,
            'groups_count' => count($this->generatedGroups),
        ]);
    }

    /**
     * Загружает ранее сохранённый (через save()) результат группировки
     * из БД, без повторного обращения к AI.
     *
     * Заполняет generatedGroups / groups / tables так же, как это
     * делает handle() — после load() можно сразу пользоваться
     * getGroups()/getTables().
     *
     * Возвращает true, если для этого DataSource в БД что-то нашлось,
     * иначе false (например, группировка ещё ни разу не запускалась
     * и не сохранялась) — в этом случае нужно вызвать handle().
     */
    public function load(): bool
    {
        $groupModels = DataSourceGroup::query()
            ->where('data_source_id', $this->dataSource->id)
            ->with(['tables' => function ($query) {
                $query->orderBy('id');
            }])
            ->orderBy('id')
            ->get();

        if ($groupModels->isEmpty()) {
            return false;
        }

        $this->generatedGroups = $groupModels->map(function (DataSourceGroup $group) {
            return [
                'name' => $group->name,
                'description' => $group->description,
                'tables' => $group->tables->map(fn (DataSourceTable $table) => [
                    'name' => $table->name,
                    'description' => $table->description,
                    'role' => $table->role,
                ])->all(),
            ];
        })->all();

        $this->groups = $this->buildGroups($this->generatedGroups);
        $this->tables = $this->buildTables($this->generatedGroups);

        $this->generated = true;

        return true;
    }

    /**
     * Строит компактное представление групп (только имена таблиц)
     * из полной структуры $generatedGroups.
     */
    private function buildGroups(array $generatedGroups): array
    {
        $groups = [];

        foreach ($generatedGroups as $group) {

            $groups[] = [
                'name' => $group['name'] ?? '',
                'description' => $group['description'] ?? '',
                'tables' => array_values(array_filter(array_map(
                    static fn (array $table) => $table['name'] ?? null,
                    $group['tables'] ?? []
                ))),
            ];
        }

        return $groups;
    }

    /**
     * Строит плоский список таблиц (без групп) из полной
     * структуры $generatedGroups.
     *
     * Если одна и та же таблица встретилась в нескольких группах
     * (см. mergeGroups — такое возможно для таблиц-"мостов"),
     * в результат она попадёт один раз, с описанием из первого
     * вхождения.
     */
    private function buildTables(array $generatedGroups): array
    {
        $tables = [];
        $seen = [];

        foreach ($generatedGroups as $group) {
            foreach (($group['tables'] ?? []) as $table) {

                $name = $table['name'] ?? null;

                if (!$name || isset($seen[$name])) {
                    continue;
                }

                $seen[$name] = true;

                $tables[] = [
                    'name' => $name,
                    'description' => $table['description'] ?? '',
                    'role' => $table['role'] ?? '',
                ];
            }
        }

        return $tables;
    }

    /**
     * Бросает исключение, если группы/таблицы запрашивают
     * до вызова handle().
     */
    private function assertGenerated(): void
    {
        if (!$this->generated) {
            throw new \RuntimeException(
                'Grouping has not been generated yet. Call handle() first.'
            );
        }
    }

    /**
     * Разбивает схему на части.
     *
     * В отличие от прежней версии, размер части определяется
     * не фиксированным количеством таблиц, а приблизительным
     * бюджетом токенов на часть — так промпт остаётся
     * предсказуемого размера независимо от того, 15 в базе
     * таблиц или 500, а на маленьких базах (укладывающихся
     * в один бюджет) чанкование вообще не потребуется —
     * это устраняет фрагментацию логических групп по чанкам
     * и лишние round-trip'ы к AI.
     *
     * Таблицы стараемся группировать по компонентам связности
     * (через relations), чтобы связанные таблицы с высокой
     * вероятностью попадали в один и тот же чанк — иначе модель
     * в разных запросах может по-разному называть одну и ту же
     * логическую группу.
     */
    public function startGrouping(
        array $scheme,
        int $tokenBudgetPerChunk = 6000
    ): array {
        $this->dataSourceAi = new DataSourceAi();
        $orderedTableNames = $this->orderTablesByConnectivity($scheme);

        $schemeGroups = $this->splitByTokenBudget(
            scheme: $scheme,
            orderedTableNames: $orderedTableNames,
            tokenBudgetPerChunk: $tokenBudgetPerChunk
        );

        $generatedGroups = [];

        foreach ($schemeGroups as $index => $group) {

            Log::info('Analyzing database schema group', [
                'group_number' => $index + 1,
                'total_groups' => count($schemeGroups),
                'tables_count' => count($group),
            ]);

            // Самая долгая часть: по запросу к модели на каждую порцию схемы.
            // Без отчёта отсюда мастер молчит всё это время.
            if (count($schemeGroups) > 1) {
                $this->reportProgress(
                    'analyze_tables',
                    sprintf(
                        'Анализируем таблицы и связи (часть %d из %d)',
                        $index + 1,
                        count($schemeGroups)
                    ),
                    2,
                    3
                );
            }

            $generatedGroups = $this->generateGrouping(
                currentGroups: $generatedGroups,
                scheme: $group
            );
        }

        return $generatedGroups;
    }

    /**
     * Упорядочивает имена таблиц через обход графа связей (BFS),
     * чтобы связанные таблицы шли рядом друг с другом и с большей
     * вероятностью попадали в один чанк.
     */
    private function orderTablesByConnectivity(array $scheme): array
    {
        $adjacency = [];

        foreach ($scheme as $tableName => $table) {
            $adjacency[$tableName] ??= [];

            foreach (($table['relations'] ?? []) as $column => $columnData) {
                $relatedTable = $columnData['relation']['table'] ?? null;

                if ($relatedTable && isset($scheme[$relatedTable])) {
                    $adjacency[$tableName][] = $relatedTable;
                    $adjacency[$relatedTable] ??= [];
                    $adjacency[$relatedTable][] = $tableName;
                }
            }
        }

        $visited = [];
        $ordered = [];

        foreach (array_keys($scheme) as $startTable) {

            if (isset($visited[$startTable])) {
                continue;
            }

            $queue = [$startTable];
            $visited[$startTable] = true;

            while ($queue) {
                $current = array_shift($queue);
                $ordered[] = $current;

                foreach (($adjacency[$current] ?? []) as $neighbor) {
                    if (!isset($visited[$neighbor])) {
                        $visited[$neighbor] = true;
                        $queue[] = $neighbor;
                    }
                }
            }
        }

        return $ordered;
    }

    /**
     * Режет схему на чанки, ориентируясь на примерный размер
     * (в токенах) каждой части, а не на фиксированное число таблиц.
     */
    private function splitByTokenBudget(
        array $scheme,
        array $orderedTableNames,
        int $tokenBudgetPerChunk
    ): array {

        $chunks = [];
        $currentChunk = [];
        $currentTokens = 0;

        foreach ($orderedTableNames as $tableName) {

            $tableData = $scheme[$tableName];

            $tableTokens = $this->estimateTokens($tableData);

            /**
             * Если одна таблица сама по себе больше бюджета —
             * всё равно кладём её в чанк, дальше резать некуда.
             */
            if (
                $currentChunk &&
                ($currentTokens + $tableTokens) > $tokenBudgetPerChunk
            ) {
                $chunks[] = $currentChunk;
                $currentChunk = [];
                $currentTokens = 0;
            }

            $currentChunk[$tableName] = $tableData;
            $currentTokens += $tableTokens;
        }

        if ($currentChunk) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    /**
     * Грубая оценка количества токенов для куска данных.
     * Используем эмпирическое соотношение ~4 символа на токен
     * для JSON-подобного текста — этого достаточно, чтобы
     * держать чанки предсказуемого размера, точная оценка тут
     * не требуется.
     */
    private function estimateTokens(array $tableData): int
    {
        $json = json_encode($tableData, JSON_UNESCAPED_UNICODE);

        return (int) ceil(mb_strlen($json) / 4);
    }

    /**
     * Отправляет одну часть схемы в AI
     */
    public function generateGrouping(
        array $currentGroups,
        array $scheme
    ): array {

        /**
         * JSON схемы текущей части
         */
        $schemeJson = json_encode(
            $scheme,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        /**
         * Компактные списки уже существующих групп/таблиц.
         *
         * Модели не нужна полная структура групп с описаниями —
         * merge всё равно делает PHP (mergeGroups). Ей достаточно
         * имён, чтобы не плодить дубли. Полная структура только
         * раздувала бы промпт токенами по мере роста числа групп.
         */
        $existingGroupNames = array_values(array_unique(array_map(
            static fn (array $group) => $group['name'] ?? '',
            $currentGroups
        )));

        $alreadyGroupedTables = [];

        foreach ($currentGroups as $group) {
            foreach (($group['tables'] ?? []) as $table) {
                if (isset($table['name'])) {
                    $alreadyGroupedTables[] = $table['name'];
                }
            }
        }

        $existingGroupNamesJson = json_encode(
            $existingGroupNames,
            JSON_UNESCAPED_UNICODE
        );

        $alreadyGroupedTablesJson = json_encode(
            $alreadyGroupedTables,
            JSON_UNESCAPED_UNICODE
        );

        $response=$this->dataSourceAi->generateGrouping($existingGroupNamesJson, $alreadyGroupedTablesJson, $schemeJson);

        $content = $response['content'] ?? null;


        /**
         * Если AIService уже вернул распарсенный массив —
         * используем его как есть.
         */
        if (is_array($content)) {
            $result = $content;
        } else {

            /**
             * Удаляем возможный Markdown
             */
            $content = trim((string) $content);

            $content = preg_replace(
                '/^```json\s*/',
                '',
                $content
            );

            $content = preg_replace(
                '/\s*```$/',
                '',
                $content
            );

            /**
             * Парсим JSON
             */
            $result = json_decode(
                $content,
                true
            );
        }

        /**
         * Проверяем JSON
         */
        if (!is_array($result)) {

            Log::error('AI returned invalid grouping JSON', [
                'response' => $content ?? null,
            ]);

            throw new \RuntimeException(
                'AI returned invalid JSON while grouping datasource schema.'
            );
        }

        /**
         * Проверяем наличие groups
         */
        if (!isset($result['groups']) || !is_array($result['groups'])) {

            Log::error('AI response does not contain valid groups', [
                'response' => $content ?? null,
            ]);

            throw new \RuntimeException(
                'AI response does not contain valid groups.'
            );
        }

        /**
         * Объединяем новые группы со старыми
         */
        return $this->mergeGroups(
            currentGroups: $currentGroups,
            newGroups: $result['groups']
        );
    }

    /**
     * Объединяет группы от разных AI-запросов
     */
    private function mergeGroups(
        array $currentGroups,
        array $newGroups
    ): array {

        foreach ($newGroups as $newGroup) {

            if (
                !isset($newGroup['name']) ||
                !isset($newGroup['tables'])
            ) {
                continue;
            }

            $groupIndex = null;

            /**
             * Ищем уже существующую группу
             */
            foreach ($currentGroups as $index => $currentGroup) {

                if (
                    mb_strtolower(trim($currentGroup['name']))
                    ===
                    mb_strtolower(trim($newGroup['name']))
                ) {
                    $groupIndex = $index;
                    break;
                }
            }

            /**
             * Если группа уже существует
             */
            if ($groupIndex !== null) {

                /**
                 * Добавляем описание группы,
                 * если его ещё нет
                 */
                if (
                    empty($currentGroups[$groupIndex]['description'])
                    &&
                    !empty($newGroup['description'])
                ) {
                    $currentGroups[$groupIndex]['description']
                        = $newGroup['description'];
                }

                /**
                 * Добавляем таблицы без дубликатов
                 */
                foreach ($newGroup['tables'] as $newTable) {

                    if (!isset($newTable['name'])) {
                        continue;
                    }

                    $exists = false;

                    foreach (
                        $currentGroups[$groupIndex]['tables']
                        as $currentTable
                    ) {

                        if (
                            ($currentTable['name'] ?? null)
                            ===
                            $newTable['name']
                        ) {
                            $exists = true;
                            break;
                        }
                    }

                    if (!$exists) {
                        $currentGroups[$groupIndex]['tables'][] = $newTable;
                    }
                }

            } else {

                /**
                 * Создаём новую группу
                 */
                $currentGroups[] = $newGroup;
            }
        }

        return $currentGroups;
    }
}
