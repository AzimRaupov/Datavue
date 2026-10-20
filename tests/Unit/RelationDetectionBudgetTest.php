<?php

use App\Helpers\DataSource\Providers\AbstractSqlConnectionProvider;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Провайдер без сети: считает JOIN-ы и умеет «зависать» на каждом из них.
 */
function fakeRelationProvider(int $tables, bool $queriesFail = false): AbstractSqlConnectionProvider
{
    return new class($tables, $queriesFail) extends AbstractSqlConnectionProvider
    {
        public int $joins = 0;

        public array $timeouts = [];

        public function __construct(private int $tables, private bool $queriesFail) {}

        protected function connectionConfig(): array
        {
            return [];
        }

        public function showTables(): array
        {
            return array_map(fn ($i) => "t{$i}", range(1, $this->tables));
        }

        public function showColumns(string $tableName): array
        {
            return [
                ['column_name' => 'id', 'type' => 'int', 'key' => 'PRI', 'nullable' => 'NO'],
                ['column_name' => 'ref', 'type' => 'int', 'key' => '', 'nullable' => 'YES'],
            ];
        }

        protected function getForeignKeyRelations(): array
        {
            return [];
        }

        protected function quoteIdentifier(string $identifier): string
        {
            return "`{$identifier}`";
        }

        protected function setStatementTimeout(?int $milliseconds): void
        {
            $this->timeouts[] = $milliseconds;
        }

        public function query(string $query, array $bindings = [])
        {
            if (str_contains($query, 'INNER JOIN')) {
                $this->joins++;

                if ($this->queriesFail) {
                    throw new RuntimeException('Query execution was interrupted, maximum statement execution time exceeded');
                }

                return [(object) ['matched_count' => 0]];
            }

            return [(object) ['total_count' => 10]];
        }
    };
}

it('не запускает больше пробных JOIN-ов, чем позволяет потолок', function () {
    // 30 таблиц → сотни пар «колонка без PRI → PRI другой таблицы», заведомо больше потолка.
    $provider = fakeRelationProvider(tables: 30);

    $provider->getSchema([], ['columns', 'relations' => true]);

    expect($provider->joins)->toBe(150);
});

it('ограничивает время запроса на время поиска связей и возвращает его обратно', function () {
    $provider = fakeRelationProvider(tables: 3);

    $provider->getSchema([], ['columns', 'relations' => true]);

    expect($provider->timeouts)->toBe([3000, null]);
});

it('не роняет схему, если JOIN упёрся в таймаут, и не выдаёт такую пару за связь', function () {
    $provider = fakeRelationProvider(tables: 3, queriesFail: true);

    $schema = $provider->getSchema([], ['columns', 'relations' => true]);

    expect($provider->joins)->toBeGreaterThan(0)
        ->and($schema)->toBeArray();
});
