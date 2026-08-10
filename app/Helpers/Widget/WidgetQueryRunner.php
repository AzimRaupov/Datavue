<?php

namespace App\Helpers\Widget;

use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\ReadOnlySqlGuard;
use App\Helpers\DataSource\SqlParameterBinder;
use App\Models\DataSource;
use RuntimeException;
use Throwable;

/**
 * Выполняет запрос виджета с учётом фильтров и собирает результат под его схему.
 *
 * Пришло на смену запуску сгенерированного Python-скрипта.
 *
 * Разделение ответственности между моделью и платформой:
 *
 *   Модель пишет базовый запрос — «что считать». Условия по датам она
 *   закладывает плейсхолдерами внутрь запроса, потому что фильтровать нужно
 *   ДО агрегации: иначе отсекались бы уже посчитанные группы.
 *
 *   Платформа оборачивает результат — «сколько показать и какую страницу».
 *   Поиск и пагинация работают поверх готовых строк, модель о них не знает
 *   и ошибиться в них не может.
 */
class WidgetQueryRunner
{
    /**
     * Потолок строк, когда пагинация не включена.
     *
     * Раньше этот лимит дописывался молча, и на большом наборе часть данных
     * просто исчезала: маппер добросовестно заполнял пропуски нулями, а график
     * показывал провал там, где строки отрезали. Теперь при упоре в потолок
     * возвращается ошибка с предложением включить постраничный вывод —
     * неверные числа хуже отказа.
     */
    public const MAX_ROWS = 5000;

    /** Размер страницы по умолчанию. */
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE = 200;

    /**
     * Сколько строк берём в режиме проверки.
     *
     * Проверка виджета смотрит на СТРУКТУРУ результата, а не на данные:
     * есть ли ряды, совпадают ли длины с осью. Для этого хватает нескольких
     * строк. Раньше проверка тянула весь набор — до 5001 строки на виджет,
     * а на большом наборе ещё и падала по лимиту и запускала починку
     * через модель там, где виджет был полностью исправен.
     */
    public const SAMPLE_ROWS = 20;

    private SqlParameterBinder $binder;

    public function __construct(
        private DataSource $dataSource,
        private ?ConnectionProviderRouter $router = null
    ) {
        $this->router ??= new ConnectionProviderRouter($this->dataSource->id);

        // DuckDB выполняется через CLI и подготовленных выражений не имеет —
        // там значения подставляются в текст после строгой проверки типа.
        $this->binder = new SqlParameterBinder(
            supportsBindings: ($this->dataSource->type->name ?? null) !== 'duckdb'
        );
    }

    /**
     * @param array $spec    Спецификация из dashboard_widgets.query_spec
     * @param array $filters Включённые фильтры: ключ => настройки
     * @param array $input   Значения фильтров от пользователя
     *
     * @return array{ok: bool, data?: array, meta?: array, error?: string}
     */
    /**
     * @param bool $sample Режим проверки: взять несколько строк и не считать
     *                     общее число. Используется при валидации виджета.
     */
    public function run(
        array $spec,
        string $family,
        ?string $type = null,
        array $filters = [],
        array $input = [],
        bool $sample = false
    ): array {
        $shape = $spec['shape'] ?? null;

        if (!in_array($shape, WidgetShapeMapper::SHAPES, true)) {
            return ['ok' => false, 'error' => 'Неизвестная форма данных: '.json_encode($shape)];
        }

        $queries = $this->normalizeQueries($spec['queries'] ?? null);

        if ($queries === []) {
            return ['ok' => false, 'error' => 'В спецификации нет ни одного запроса.'];
        }

        try {
            // Несколько запросов — их строки склеиваются в один набор.
            // Пагинация и поиск при этом не применяются: они осмысленны для
            // одной выборки, а не для склейки нескольких.
            $multi = count($queries) > 1;

            if ($multi) {
                $rows = [];

                foreach ($queries as $name => $sql) {
                    $prepared = $this->prepare($sql, $filters, $input, stripLimit: false);

                    foreach ($this->fetch($prepared, $this->sampleMeta(self::MAX_ROWS)) as $row) {
                        $rows[] = $row;
                    }
                }

                $meta = $this->sampleMeta(self::MAX_ROWS);
            } else {
                $prepared = $this->prepare(reset($queries), $filters, $input);

                $meta = $sample
                    ? $this->sampleMeta()
                    : $this->buildMeta($prepared, $filters, $input);

                $rows = $this->fetch($prepared, $meta);
            }
        } catch (Throwable $e) {
            // Ошибку отдаём как есть: база называет причину точно,
            // и по ней работает самопочинка при генерации.
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $data = (new WidgetShapeMapper())->map(
                family: $family,
                type: $type,
                shape: $shape,
                rows: $rows,
                presentation: $spec['presentation'] ?? []
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Не удалось разложить результат: '.$e->getMessage()];
        }

        return ['ok' => true, 'data' => $data, 'meta' => $meta];
    }

    /**
     * Приводит queries к списку «имя => SQL».
     *
     * Допускаются и строка (один запрос), и объект с любым числом запросов.
     *
     * @return array<string, string>
     */
    private function normalizeQueries(mixed $queries): array
    {
        if (is_string($queries) && trim($queries) !== '') {
            return ['main' => $queries];
        }

        if (!is_array($queries)) {
            return [];
        }

        $result = [];

        foreach ($queries as $name => $sql) {
            if (is_string($sql) && trim($sql) !== '') {
                $result[(string) $name] = $sql;
            }
        }

        // main всегда первым: у форм с одним запросом он и есть результат,
        // а у счётчиков порядок определяет порядок плиток.
        if (isset($result['main'])) {
            $result = ['main' => $result['main']] + $result;
        }

        return $result;
    }

    /**
     * Подставляет значения фильтров, работающих внутри запроса (даты).
     *
     * @return array{sql: string, bindings: array}
     */
    private function prepare(string $sql, array $filters, array $input, bool $stripLimit = true): array
    {
        $values = [];
        $types = [];

        foreach ($filters as $key => $config) {
            match ($key) {
                'date_range' => [
                    $values['date_from'] = $input['date_from'] ?? ($config['date_from'] ?? null),
                    $values['date_to'] = $input['date_to'] ?? ($config['date_to'] ?? null),
                    $types['date_from'] = SqlParameterBinder::TYPE_DATE,
                    $types['date_to'] = SqlParameterBinder::TYPE_DATE,
                ],
                'day' => [
                    $values['day'] = $input['day'] ?? ($config['day'] ?? null),
                    $types['day'] = SqlParameterBinder::TYPE_DATE,
                ],
                default => null,
            };
        }

        // Страховка: всё, что осталось незаполненным, становится NULL.
        // Иначе плейсхолдер уедет в базу как есть и уронит запрос.
        foreach ($this->placeholderNames($sql) as $name) {
            if (!array_key_exists($name, $values)) {
                $values[$name] = null;
            }
        }

        $applied = $this->binder->apply($sql, $values, $types);

        // null — не дописывать LIMIT: ограничение поставит обёртка.
        $clean = ReadOnlySqlGuard::sanitize($applied['sql'], null);

        // При постраничном выводе снимаем собственный LIMIT базового запроса:
        // иначе общее число строк упрётся в него, и дальше первых страниц
        // пользователь не уйдёт — часть данных окажется недоступной.
        if ($stripLimit && array_key_exists('paginate', $filters)) {
            $clean = ReadOnlySqlGuard::stripTrailingLimit($clean);
        }

        return ['sql' => $clean, 'bindings' => $applied['bindings']];
    }

    /**
     * Считает, сколько всего строк и какую страницу отдавать.
     *
     * @return array{paginated: bool, total: ?int, page: int, per_page: int, pages: ?int, search: ?string}
     */
    private function buildMeta(array $prepared, array $filters, array $input): array
    {
        $paginated = array_key_exists('paginate', $filters);
        $search = array_key_exists('search', $filters)
            ? trim((string) ($input['search'] ?? ''))
            : '';

        $perPage = (int) ($input['per_page'] ?? $filters['paginate']['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $page = max(1, (int) ($input['page'] ?? 1));

        // Фильтр «сколько показывать»: пользователь сам выбирает топ-5/10/20.
        // Был заведён в каталоге, но не применялся — модель могла его включить,
        // а он молча ничего не делал.
        $limit = null;

        if (array_key_exists('limit', $filters)) {
            $limit = (int) ($input['limit'] ?? $filters['limit']['default'] ?? 10);
            $limit = max(1, min($limit, self::MAX_ROWS));
        }

        // Колонки поиска узнаём у базы один раз: они нужны и запросу подсчёта,
        // и запросу страницы. Раньше пробный запрос выполнялся дважды.
        $searchColumns = $search !== '' ? $this->searchColumns($prepared) : [];

        // COUNT выполняем ТОЛЬКО при постраничном выводе — там без общего
        // числа не построить навигацию. Обычному графику он не нужен: сколько
        // строк пришло, видно по самому результату, а лишний запрос удваивал
        // нагрузку на базу при каждой отрисовке каждого виджета.
        $total = $paginated
            ? $this->countRows($prepared, $search, $searchColumns)
            : null;

        return [
            'paginated' => $paginated,
            'limit' => $limit,
            'search_columns' => $searchColumns,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $paginated && $perPage > 0 ? (int) ceil($total / $perPage) : null,
            'truncated' => false,
            'search' => $search !== '' ? $search : null,
        ];
    }

    /**
     * Метаданные для режима проверки: несколько строк, без подсчёта.
     */
    private function sampleMeta(?int $limit = null): array
    {
        return [
            'paginated' => false,
            'limit' => $limit ?? self::SAMPLE_ROWS,
            'search_columns' => [],
            'total' => null,
            'page' => 1,
            'per_page' => self::SAMPLE_ROWS,
            'pages' => null,
            'search' => null,
            'truncated' => false,
        ];
    }

    private function countRows(array $prepared, string $search, array $searchColumns): int
    {
        $wrapped = $this->wrap($prepared, $search, $searchColumns, null, null);

        $sql = 'SELECT COUNT(*) AS total FROM ('.$wrapped['sql'].') AS widget_count';

        $rows = ReadOnlySqlGuard::normalizeRows($this->router->query($sql, $wrapped['bindings']));

        return (int) ($rows[0]['total'] ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetch(array $prepared, array $meta): array
    {
        $limit = null;
        $offset = null;

        if ($meta['paginated']) {
            $limit = $meta['per_page'];
            $offset = ($meta['page'] - 1) * $meta['per_page'];
        } else {
            // Берём на одну строку больше потолка: если она пришла — значит
            // данные не поместились. Так упор в предел ловится тем же запросом,
            // без отдельного COUNT на каждую отрисовку.
            $limit = $meta['limit'] ?? (self::MAX_ROWS + 1);
        }

        $wrapped = $this->wrap(
            $prepared,
            $meta['search'] ?? '',
            $meta['search_columns'] ?? [],
            $limit,
            $offset
        );

        $rows = ReadOnlySqlGuard::normalizeRows(
            $this->router->query($wrapped['sql'], $wrapped['bindings'])
        );

        // Данные не поместились — сообщаем явно. Молча отдать усечённый набор
        // нельзя: маппер заполнит недостающие пересечения нулями, и график
        // покажет провал там, где строки просто отрезали.
        if (!$meta['paginated'] && $meta['limit'] === null && count($rows) > self::MAX_ROWS) {
            throw new RuntimeException(sprintf(
                'Запрос вернул больше %s строк. Включите постраничный вывод или сузьте '
                . 'выборку фильтрами: иначе часть данных не попадёт на график.',
                number_format(self::MAX_ROWS, 0, '.', ' ')
            ));
        }

        return $rows;
    }

    /**
     * Оборачивает базовый запрос: поиск, страница.
     *
     * Обёртка вместо правки самого запроса — принципиальный выбор. Модель
     * пишет только «что считать», а «сколько и какую страницу» добавляется
     * поверх готового результата. Ошибиться в LIMIT/OFFSET она не может,
     * потому что вообще их не пишет.
     *
     * @return array{sql: string, bindings: array}
     */
    private function wrap(
        array $prepared,
        string $search,
        array $searchColumns,
        ?int $limit,
        ?int $offset
    ): array {
        $bindings = $prepared['bindings'];

        $sql = 'SELECT * FROM ('.$prepared['sql'].') AS widget_base';

        if ($search !== '' && $searchColumns !== []) {
            $conditions = [];

            // Шаблон поиска подставляем через тот же биндер, что и остальные
            // значения: для MySQL/PostgreSQL/SQLite это «?» с привязкой,
            // для DuckDB — экранированный литерал. Раньше здесь всегда стоял
            // «?», и на DuckDB, который привязки теряет, поиск ломался.
            $applied = $this->binder->apply(
                ':widget_search',
                ['widget_search' => '%'.$search.'%'],
                ['widget_search' => SqlParameterBinder::TYPE_STRING]
            );

            foreach ($searchColumns as $column) {
                $conditions[] = $this->castToText($this->quote($column)).' LIKE '.$applied['sql'];

                foreach ($applied['bindings'] as $binding) {
                    $bindings[] = $binding;
                }
            }

            $sql .= ' WHERE '.implode(' OR ', $conditions);
        }

        if ($limit !== null) {
            $sql .= ' LIMIT '.(int) $limit;

            if ($offset) {
                $sql .= ' OFFSET '.(int) $offset;
            }
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    /**
     * По каким колонкам искать: узнаём их у самой базы одной пробной строкой.
     */
    private function searchColumns(array $prepared): array
    {
        try {
            $probe = 'SELECT * FROM ('.$prepared['sql'].') AS widget_probe LIMIT 1';

            $rows = ReadOnlySqlGuard::normalizeRows(
                $this->router->query($probe, $prepared['bindings'])
            );

            return $rows === [] ? [] : array_keys($rows[0]);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Имена всех плейсхолдеров запроса.
     *
     * @return array<int, string>
     */
    private function placeholderNames(string $sql): array
    {
        preg_match_all('/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Все плейсхолдеры запроса со значением null — для пробного выполнения.
     *
     * @return array<string, null>
     */
    private function nullPlaceholders(string $sql): array
    {
        return array_fill_keys($this->placeholderNames($sql), null);
    }

    /**
     * Приведение к тексту для поиска: синтаксис у диалектов разный.
     */
    private function castToText(string $expression): string
    {
        return match ($this->dataSource->type->name ?? null) {
            'mysql' => "CAST({$expression} AS CHAR)",
            'postgres' => "{$expression}::text",
            default => "CAST({$expression} AS VARCHAR)",
        };
    }

    private function quote(string $identifier): string
    {
        $type = $this->dataSource->type->name ?? null;

        return $type === 'mysql'
            ? '`'.str_replace('`', '``', $identifier).'`'
            : '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * Выполняет один запрос с запретом на запись и ограничением строк.
     *
     * @return array<int, array<string, mixed>>
     */
    public function execute(string $sql, array $bindings = []): array
    {
        $safe = ReadOnlySqlGuard::sanitize($sql, self::MAX_ROWS);

        return ReadOnlySqlGuard::normalizeRows($this->router->query($safe, $bindings));
    }

    /**
     * Проверяет запрос, не вытаскивая данные.
     *
     * Нужно на этапе генерации: синтаксис и существование колонок подтверждает
     * сама база, и делать это дешевле до сохранения виджета, чем ловить ошибку
     * при первой отрисовке у пользователя.
     *
     * @param array<string, mixed> $probeValues Значения плейсхолдеров на время проверки
     *
     * @return array{ok: bool, columns?: array<int, string>, error?: string}
     */
    public function probe(string $sql, array $probeValues = []): array
    {
        try {
            // Плейсхолдеры фильтров на этапе проверки заменяем NULL: запрос
            // обязан быть корректным и без выбранного периода. Идиома
            // «(:date_from IS NULL OR ...)» при этом просто пропускает условие.
            $probeValues += $this->nullPlaceholders($sql);

            $applied = $this->binder->apply($sql, $probeValues, []);

            $safe = ReadOnlySqlGuard::sanitize($applied['sql'], self::MAX_ROWS);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $probeSql = 'SELECT * FROM ('.$safe.') AS widget_probe LIMIT 1';

            $rows = ReadOnlySqlGuard::normalizeRows(
                $this->router->query($probeSql, $applied['bindings'])
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return [
            'ok' => true,
            // Пустой результат — не ошибка: колонки просто неизвестны,
            // проверку формы в этом случае пропускаем.
            'columns' => $rows === [] ? [] : array_keys($rows[0]),
        ];
    }
}
