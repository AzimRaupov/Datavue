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

    public array $groups = [];

    public array $tables = [];
   public $dataSourceAi;

    private bool $generated = false;

    private $onProgress = null;

    public function __construct(int $dataSourceId)
    {
        $this->dataSource = DataSource::findOrFail($dataSourceId);

        $this->connectionProviderRouter = new ConnectionProviderRouter(
            $this->dataSource->id
        );
    }

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

    public function handle(): array
    {

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

        $this->reportProgress('analyze_tables', 'Анализируем таблицы и связи', 2, 3);

        $this->generatedGroups = $this->startGrouping(
            scheme: $this->schema,
            tokenBudgetPerChunk: 6000
        );

        $this->reportProgress('build_groups', 'Собираем смысловые группы', 3, 3);

        $this->groups = $this->buildGroups($this->generatedGroups);
        $this->tables = $this->buildTables($this->generatedGroups);

        $this->generated = true;

        return $this->generatedGroups;
    }

    public function getGroups(): array
    {
        $this->assertGenerated();

        return $this->groups;
    }

    public function getTables(): array
    {
        $this->assertGenerated();

        return $this->tables;
    }

    public function save(): void
    {
        $this->assertGenerated();

        DB::transaction(function () {

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

    private function assertGenerated(): void
    {
        if (!$this->generated) {
            throw new \RuntimeException(
                'Grouping has not been generated yet. Call handle() first.'
            );
        }
    }

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

    private function estimateTokens(array $tableData): int
    {
        $json = json_encode($tableData, JSON_UNESCAPED_UNICODE);

        return (int) ceil(mb_strlen($json) / 4);
    }

    public function generateGrouping(
        array $currentGroups,
        array $scheme
    ): array {

        $schemeJson = json_encode(
            $scheme,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

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

        if (is_array($content)) {
            $result = $content;
        } else {

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

            $result = json_decode(
                $content,
                true
            );
        }

        if (!is_array($result)) {

            Log::error('AI returned invalid grouping JSON', [
                'response' => $content ?? null,
            ]);

            throw new \RuntimeException(
                'AI returned invalid JSON while grouping datasource schema.'
            );
        }

        if (!isset($result['groups']) || !is_array($result['groups'])) {

            Log::error('AI response does not contain valid groups', [
                'response' => $content ?? null,
            ]);

            throw new \RuntimeException(
                'AI response does not contain valid groups.'
            );
        }

        return $this->mergeGroups(
            currentGroups: $currentGroups,
            newGroups: $result['groups']
        );
    }

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

            if ($groupIndex !== null) {

                if (
                    empty($currentGroups[$groupIndex]['description'])
                    &&
                    !empty($newGroup['description'])
                ) {
                    $currentGroups[$groupIndex]['description']
                        = $newGroup['description'];
                }

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

                $currentGroups[] = $newGroup;
            }
        }

        return $currentGroups;
    }
}
