<script setup>
import { ref, computed, watch, onBeforeUnmount, nextTick } from "vue";
import api from "../../api.js";
import { queryTemplateFor, COLUMN_HINTS } from "./queryTemplates.js";
import { CHART_COLORS } from "../widgets/palette.js";
import { supportsColors } from "../widgets/registry.js";

/**
 * Настройка виджета — боковая шторка на четыре раздела.
 *
 * Раньше это было модальное окно с двумя вкладками. Модалка здесь мешала:
 * настройка виджета — не короткое подтверждение, а работа в несколько
 * подходов, при которой нужно видеть сам дашборд. Шторка открывается сбоку
 * тем же движением, что и чат, и не закрывает собой результат.
 *
 * Разделы идут в порядке работы, а не в порядке «что во что вложено»:
 *
 *   1. Данные       — откуда брать: таблица и связи с другими таблицами;
 *   2. Виджет       — как выглядит: заголовок, вид отрисовки, цвета;
 *   3. Конструктор  — что считать: метрики, разбивка, условия;
 *   4. SQL          — то же самое запросом, для тех, кому конструктора мало.
 *
 * Разделы 1 и 3 — две половины одного конструктора, поэтому сохраняются
 * вместе. Раздел 2 живёт отдельно: сменить цвет или заголовок — не повод
 * гнать запрос в базу, он уходит лёгким PATCH (см. DashboardWidgetController).
 */

const props = defineProps({
    dashboardId: { type: [String, Number], required: true },
    widget: { type: Object, default: null },
    schema: { type: Array, default: () => [] },
    dictionary: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["saved", "closed"]);

const open = ref(false);
const tab = ref("data");

const TABS = [
    { key: "data", title: "Данные", hint: "Таблица и связи" },
    { key: "look", title: "Виджет", hint: "Заголовок, вид, цвета" },
    { key: "builder", title: "Конструктор", hint: "Метрики, разбивка, условия" },
    { key: "sql", title: "SQL", hint: "Запрос руками" },
];

// --- Состояние конструктора -------------------------------------------------
const table = ref("");
const metrics = ref([]);
const dimensions = ref([]);
const filters = ref([]);
const joins = ref([]);
const sort = ref({ by: "metric", dir: "desc" });
const limit = ref(100);

// --- Состояние режима запроса ----------------------------------------------
const query = ref("");
const presentation = ref("");
const showPresentation = ref(false);

// --- Состояние раздела «Виджет» ---------------------------------------------
const title = ref("");
const typeId = ref(null);
const colors = ref([]);
const savingLook = ref(false);

// --- Общее ------------------------------------------------------------------
const running = ref(false);
const saving = ref(false);
const errors = ref([]);
const okMessage = ref(null);
const composedSql = ref(null);

/**
 * Что вернул последний прогон: сырые строки базы и то, во что платформа их
 * разложила для виджета.
 *
 * Сервер отдавал и то и другое с самого начала (rows и data в ответе
 * runQuery), но ответ выбрасывался целиком — оставалось только «Готово».
 * Автору запроса этого мало: ошибка формы («вернулось три ряда вместо
 * одного») видна лишь при сравнении сырых строк с разложенным результатом.
 */
const runResult = ref(null);

/** Какую половину результата показывать: сырые строки или готовый виджет. */
const resultView = ref("rows");

const aggregates = computed(() => props.dictionary.aggregates ?? {});
const grains = computed(() => props.dictionary.grains ?? {});
const operators = computed(() => props.dictionary.operators ?? {});

const familyName = computed(() => props.widget?.widget?.name ?? null);
const requiredColumns = computed(() => props.widget?.required_columns ?? []);
const slots = computed(() => props.widget?.slots ?? null);

/** Варианты отрисовки внутри семейства: круг или кольцо, столбцы или полосы. */
const widgetTypes = computed(() => props.widget?.widget?.types ?? []);

/**
 * Показывать ли палитру.
 *
 * Спрашиваем у реестра виджетов, а не у списка здесь: красит ряды сам
 * компонент семейства, и только он знает, читает ли он options.colors.
 * У таблицы рядов нет — восемь ячеек палитры там обещали то, чего виджет
 * не делает: цвет сохранялся, отчёт «Оформление сохранено» приходил,
 * а на виджете не менялось ничего.
 */
const canPickColors = computed(() => supportsColors(familyName.value));

// Счётчику с полосой выполнения нужна цель: от неё считается процент.
const needsTarget = computed(() => Boolean(slots.value?.needs_target));

/**
 * Виджеты, где каждая метрика — отдельная выборка: счётчики и плоские
 * списки без разбивки. Там таблица берётся у самой метрики, и связывать
 * их между собой не нужно — «Заказов», «Клиентов», «Товаров» живут
 * в разных таблицах и прекрасно стоят рядом.
 */
const metricsIndependent = computed(() => {
    const shapeless = ["mini-counters", "pie", "radial", "funnel", "treemap", "map"];

    return shapeless.includes(familyName.value) && dimensions.value.length === 0;
});

/** Колонки, доступные метрике: своей таблицы или всех связанных. */
function columnsForMetric(metric) {
    if (!metricsIndependent.value) return columns.value;

    const from = metric.table || table.value;

    return columnsOf(from).map((column) => ({
        ...column,
        table: from,
        key: `${from}.${column.name}`,
        title: column.name,
    }));
}

const tables = computed(() => props.schema ?? []);
const joinTypes = computed(() => props.dictionary.join_types ?? {});

/** Связи источника: что с чем связано и по каким колонкам. */
const relations = computed(() => props.dictionary.relations ?? []);

/**
 * Таблицы, которые есть смысл присоединять: те, что связаны хотя бы с одной
 * из уже участвующих. Показывать весь список источника — значит предлагать
 * связать несвязуемое и заставлять вспоминать ключи.
 */
function joinableTables(current) {
    const inPlay = tablesInPlay.value.filter((name) => name !== current);
    const names = new Set();

    for (const relation of relations.value) {
        if (inPlay.includes(relation.from_table) && !inPlay.includes(relation.to_table)) {
            names.add(relation.to_table);
        }

        if (inPlay.includes(relation.to_table) && !inPlay.includes(relation.from_table)) {
            names.add(relation.from_table);
        }
    }

    // Уже выбранная таблица остаётся в списке, иначе селект опустеет.
    if (current) names.add(current);

    return tables.value.filter((item) => names.has(item.name));
}

/**
 * Готовые пары колонок для связи: слева — колонка уже подключённой таблицы,
 * справа — присоединяемой.
 */
function pairsFor(join) {
    if (!join.table) return [];

    const inPlay = tablesInPlay.value.filter((name) => name !== join.table);
    const pairs = [];

    for (const relation of relations.value) {
        if (relation.from_table === join.table && inPlay.includes(relation.to_table)) {
            pairs.push({
                left_table: relation.to_table,
                left: relation.to_column,
                right: relation.from_column,
            });
        }

        if (relation.to_table === join.table && inPlay.includes(relation.from_table)) {
            pairs.push({
                left_table: relation.from_table,
                left: relation.from_column,
                right: relation.to_column,
            });
        }
    }

    return pairs;
}

/** Строка пары для селекта и для сравнения с выбранным. */
function pairKey(pair) {
    return `${pair.left_table}.${pair.left}=${pair.right}`;
}

/**
 * Колонки, доступные левой части условия: все поля таблиц, которые уже
 * в запросе. Связь не обязана идти по ключу, который нашёл источник, —
 * связывают и по коду товара, и по дате, и по чему угодно ещё.
 */
function leftColumns(join) {
    const result = [];

    for (const name of tablesInPlay.value) {
        if (name === join.table) continue;

        for (const column of columnsOf(name)) {
            result.push({ ...column, table: name, key: `${name}.${column.name}` });
        }
    }

    return result;
}

/** Левая часть условия хранится парой «таблица + колонка». */
function leftKey(join) {
    return join.left ? `${join.left_table || table.value}.${join.left}` : "";
}

function onLeftChange(join, key) {
    const { table: from, column } = splitColumn(key);

    join.left_table = from || table.value;
    join.left = column;
}

/** Таблицы, участвующие в запросе: основная и присоединённые. */
const tablesInPlay = computed(() => {
    const names = table.value ? [table.value] : [];

    for (const join of joins.value) {
        if (join.table) names.push(join.table);
    }

    return names;
});

function columnsOf(name) {
    return tables.value.find((item) => item.name === name)?.columns ?? [];
}

/**
 * Колонки всех участвующих таблиц одним списком.
 *
 * Пока таблица одна, имени колонки достаточно. Со связями его мало:
 * «orderNumber» есть и в заказах, и в их позициях, поэтому в значении
 * едет таблица, а в подписи — её имя.
 */
const columns = computed(() => {
    const result = [];

    for (const name of tablesInPlay.value) {
        for (const column of columnsOf(name)) {
            result.push({
                ...column,
                table: name,
                key: `${name}.${column.name}`,
                title: tablesInPlay.value.length > 1 ? `${name}.${column.name}` : column.name,
            });
        }
    }

    return result;
});

/** Раздел «Виджет» сохраняется отдельно от содержимого. */
const isLookTab = computed(() => tab.value === "look");

/**
 * Чем задано содержимое виджета при сохранении.
 *
 * Разделы «Данные» и «Конструктор» — две половины одной формы, поэтому оба
 * сохраняются конструктором. Отдельный режим только у SQL.
 */
const contentMode = computed(() => (tab.value === "sql" ? "sql" : "builder"));

/**
 * Чего не хватает, чтобы виджет можно было посчитать.
 *
 * Раньше кнопки просто гасли: у точечной нужно две метрики, а конструктор
 * добавлял одну — и человек не понимал, почему ничего не нажимается.
 */
const blockers = computed(() => {
    if (isLookTab.value) return [];

    if (contentMode.value === "sql") {
        return query.value.trim() === "" ? ["Напишите запрос."] : [];
    }

    const problems = [];

    if (!table.value) {
        problems.push("Выберите таблицу в разделе «Данные».");

        return problems;
    }

    const metricsMin = slots.value?.metrics?.min ?? 1;
    const dimensionsMin = slots.value?.dimensions?.min ?? 0;

    if (metrics.value.length < metricsMin) {
        problems.push(`Нужно метрик: ${metricsMin}, сейчас ${metrics.value.length}.`);
    }

    if (metrics.value.some((m) => needsColumn(m.agg) && !m.column)) {
        problems.push("У метрики не выбрана колонка.");
    }

    if (dimensions.value.length < dimensionsMin) {
        problems.push(`Нужно разбивок: ${dimensionsMin}, сейчас ${dimensions.value.length}.`);
    }

    return problems;
});

const canRun = computed(() => blockers.value.length === 0);

function hintFor(column) {
    return COLUMN_HINTS[column] ?? "";
}

function columnKind(key) {
    const exact = columns.value.find((c) => c.key === key);

    if (exact) return exact.kind;

    // Значение без префикса остаётся у виджетов, собранных до появления
    // связей: там таблицы у колонки нет вовсе.
    const { column } = splitColumn(key);

    return columns.value.find((c) => c.name === column)?.kind ?? "string";
}

/**
 * Приводит значение селекта к виду «таблица.колонка».
 *
 * Виджеты, собранные до появления связей, хранят голое имя колонки;
 * без этого их значения не совпадали бы ни с одним пунктом списка,
 * и селект показывал бы пустоту.
 */
function normalizeColumnKey(value) {
    if (!value || value.includes(".")) return value;

    return columns.value.find((c) => c.name === value)?.key ?? value;
}

/** Разбирает «таблица.колонка» обратно в пару. */
function splitColumn(key) {
    if (!key) return { table: null, column: "" };

    const index = key.indexOf(".");

    return index === -1
        ? { table: null, column: key }
        : { table: key.slice(0, index), column: key.slice(index + 1) };
}

/** Агрегату count колонка не нужна — остальным нужна. */
function needsColumn(agg) {
    return agg !== "count";
}

function resetState() {
    errors.value = [];
    okMessage.value = null;
    runResult.value = null;
}

// --- Слоты ------------------------------------------------------------------

function addMetric() {
    metrics.value.push({ agg: "count", column: "", label: "", target: "", table: table.value });
}

/** Сменили таблицу метрики — её прежняя колонка к новой не относится. */
function onMetricTableChange(metric) {
    metric.column = "";
}

function addDimension() {
    dimensions.value.push({ column: columns.value[0]?.key ?? "", grain: "" });
}

/**
 * Добавляет связь и сразу подставляет условие, если источник знает,
 * как эти таблицы связаны. Вспоминать, какой ключ куда смотрит, — не
 * работа аналитика.
 */
function addJoin() {
    joins.value.push({ table: "", type: "left", left_table: table.value, left: "", right: "", manual: false });
}

/**
 * Выбрали таблицу — подставляем единственную известную связь. Если связей
 * несколько, автор выбирает из них; если ни одной, остаётся ручной режим.
 */
function onJoinTableChange(join) {
    join.left = "";
    join.right = "";
    join.left_table = table.value;

    const pairs = pairsFor(join);

    if (pairs.length === 1) {
        Object.assign(join, pairs[0]);
    }

    // Связи нет вовсе — сразу открываем ручной выбор, чтобы человек
    // не искал, где её задать.
    join.manual = pairs.length === 0;
}

/** Выбор готовой пары из списка известных связей. */
function onPairChange(join, key) {
    if (key === "__manual__") {
        join.manual = true;

        return;
    }

    const pair = pairsFor(join).find((item) => pairKey(item) === key);

    if (pair) Object.assign(join, pair);
}

function addFilter() {
    filters.value.push({ column: columns.value[0]?.key ?? "", op: "=", value: "" });
}

function removeAt(list, index) {
    list.splice(index, 1);
}

/** Смена таблицы обнуляет всё: колонки другой таблицы здесь не действуют. */
function onTableChange() {
    metrics.value = [];
    dimensions.value = [];
    filters.value = [];
    joins.value = [];
    composedSql.value = null;
    resetState();

    // Заполняем ровно столько слотов, сколько виджету нужно: точечной нужны
    // две метрики, комбо — две. Раньше добавлялась одна, кнопки оставались
    // заблокированными, и почему — было не видно.
    const metricsNeeded = Math.max(1, slots.value?.metrics?.min ?? 1);
    const dimensionsNeeded = slots.value?.dimensions?.min ?? 0;

    for (let i = 0; i < metricsNeeded; i++) addMetric();
    for (let i = 0; i < dimensionsNeeded; i++) addDimension();
}

function operatorNeedsValue(op) {
    return op !== "is_null" && op !== "not_null";
}

// --- Загрузка состояния виджета ---------------------------------------------

watch(() => props.widget?.id, syncFromWidget);

/**
 * Переносит состояние виджета в форму.
 *
 * Вызывается и при смене виджета, и при каждом открытии шторки. Второе
 * важно: закрыть шторку, ничего не сохранив, и открыть её снова — значит
 * начать заново, а не увидеть свои же брошенные правки. Сменой виджета это
 * не ловится, id-то прежний.
 */
function syncFromWidget() {
        resetState();
        composedSql.value = null;

        sqlTouched.value = false;

        const builder = props.widget?.builder ?? null;

        if (builder) {
            table.value = builder.table ?? "";
            // Колонка без таблицы — виджет собран до появления связей;
            // подставляем основную, и он открывается как прежде.
            const columnKey = (item) =>
                item.column ? `${item.table || builder.table}.${item.column}` : "";

            joins.value = (builder.joins ?? []).map((j) => ({
                table: j.table ?? "",
                type: j.type ?? "left",
                left_table: j.on?.[0]?.left_table ?? builder.table,
                left: j.on?.[0]?.left ?? "",
                right: j.on?.[0]?.right ?? "",
            }));

            metrics.value = (builder.metrics ?? []).map((m) => ({
                agg: m.agg ?? "count",
                column: normalizeColumnKey(columnKey(m)),
                label: m.label ?? "",
                target: m.target ?? "",
                table: m.table ?? builder.table,
            }));
            dimensions.value = (builder.dimensions ?? []).map((d) => ({
                column: normalizeColumnKey(columnKey(d)),
                grain: d.grain ?? "",
            }));
            filters.value = (builder.filters ?? []).map((f) => ({
                column: normalizeColumnKey(columnKey(f)),
                op: f.op ?? "=",
                value: Array.isArray(f.value) ? f.value.join(", ") : (f.value ?? ""),
            }));
            sort.value = { by: builder.sort?.by ?? "metric", dir: builder.sort?.dir ?? "desc" };
            limit.value = builder.limit ?? props.dictionary.default_limit ?? 100;
        } else {
            // Виджет написан запросом или ещё пуст: конструктор начинается
            // с чистого листа.
            table.value = "";
            metrics.value = [];
            dimensions.value = [];
            filters.value = [];
            joins.value = [];
            sort.value = { by: "metric", dir: "desc" };
            limit.value = props.dictionary.default_limit ?? 100;
        }

        // Открываем на разделе, с которого работа и начнётся: у собранного
        // конструктором виджета — с данных, у написанного запросом — с SQL,
        // иначе автор попадал бы в пустую форму вместо своего запроса.
        tab.value = !builder && props.widget?.query ? "sql" : "data";

        query.value = props.widget?.query || queryTemplateFor(familyName.value);

        presentation.value = props.widget?.presentation
            ? JSON.stringify(props.widget.presentation, null, 2)
            : "";
        showPresentation.value = Boolean(props.widget?.presentation);

        title.value = props.widget?.title ?? "";
        typeId.value = props.widget?.widget_type_id ?? null;
        colors.value = readColors(props.widget?.presentation?.colors);

        scheduleCompose();
}

/**
 * Подхватывает то, что вернул сервер после сохранения.
 *
 * Без этого оформление в форме оставалось бы прежним, и следующее сохранение
 * запроса записало бы устаревшее: цвета, только что сохранённые в разделе
 * «Виджет», ушли бы обратно вместе с текстом запроса — и пропали.
 */
function applySaved(saved) {
    if (!saved) return;

    presentation.value = saved.presentation
        ? JSON.stringify(saved.presentation, null, 2)
        : "";
    colors.value = readColors(saved.presentation?.colors);

    if (saved.title !== undefined) title.value = saved.title ?? "";
    if (saved.widget_type_id !== undefined) typeId.value = saved.widget_type_id;
}

/**
 * Палитра виджета в виде восьми ячеек.
 *
 * Пустая ячейка значит «оставить стандартный цвет» — именно пустая, а не
 * подставленный сюда цвет палитры: иначе любое открытие шторки записывало бы
 * виджету всю палитру целиком, и он перестал бы следовать теме.
 */
function readColors(saved) {
    const list = Array.isArray(saved) ? saved : [];

    return CHART_COLORS.map((_, index) => {
        const color = list[index];

        return typeof color === "string" ? color : "";
    });
}

/** Цвет для input[type=color]: у него не бывает «пустого» значения. */
function colorValue(index) {
    return colors.value[index] || fallbackColor(index);
}

/**
 * Стандартный цвет ячейки в виде hex.
 *
 * Палитра задана переменными CSS, а input[type=color] понимает только hex,
 * поэтому значение спрашиваем у самой страницы — так образец в шторке
 * совпадает с цветом на графике при любой теме.
 */
function fallbackColor(index) {
    const variable = CHART_COLORS[index]?.match(/--[\w-]+/)?.[0];

    if (!variable) return "#206bc4";

    const value = getComputedStyle(document.documentElement)
        .getPropertyValue(variable)
        .trim();

    return value || "#206bc4";
}

function isCustomColor(index) {
    return Boolean(colors.value[index]);
}

function resetColor(index) {
    colors.value[index] = "";
}

function resetAllColors() {
    colors.value = CHART_COLORS.map(() => "");
}

// Любое изменение настроек пересобирает запрос: автор видит SQL сразу,
// а не после нажатия «Выполнить».
watch([table, joins, metrics, dimensions, filters, sort, limit], scheduleCompose, { deep: true });

/** Tab внутри запроса — отступ, а не переход к следующему полю. */
function onTab(event) {
    const field = event.target;
    const start = field.selectionStart;
    const end = field.selectionEnd;

    query.value = query.value.slice(0, start) + "    " + query.value.slice(end);

    nextTick(() => {
        field.selectionStart = field.selectionEnd = start + 4;
    });
}

function builderPayload() {
    return {
        table: table.value,
        joins: joins.value
            .filter((j) => j.table && j.left && j.right)
            .map((j) => ({
                table: j.table,
                type: j.type || "left",
                on: [{ left_table: j.left_table || table.value, left: j.left, right: j.right }],
            })),
        metrics: metrics.value.map((m) => {
            const { table: from, column } = splitColumn(m.column);

            return {
                agg: m.agg,
                column: needsColumn(m.agg) ? column : null,
                // Таблица нужна и у COUNT(*): без неё «клиентов» считалось бы
                // по таблице заказов.
                table: (needsColumn(m.agg) ? from : null) || m.table || null,
                label: m.label || null,
                target: m.target === "" ? null : m.target,
            };
        }),
        dimensions: dimensions.value
            .filter((d) => d.column)
            .map((d) => {
                const { table: from, column } = splitColumn(d.column);

                return { column, table: from, grain: d.grain || null };
            }),
        filters: filters.value
            .filter((f) => f.column)
            .map((f) => {
                const { table: from, column } = splitColumn(f.column);

                return { column, table: from, op: f.op, value: f.value };
            }),
        sort: sort.value,
        limit: Number(limit.value) || 100,
    };
}

/**
 * Правил ли автор запрос руками.
 *
 * Пока не правил — раздел SQL показывает то, что собрал конструктор, и
 * обновляется вместе с настройками. Как только автор вмешался, его текст
 * больше не затирается: перезаписать чужую правку хуже, чем показать
 * слегка устаревший запрос.
 */
const sqlTouched = ref(false);

let composeTimer = null;

/**
 * Пересобирает запрос на сервере, не выполняя его.
 *
 * Тем же кодом, что и при сохранении, — иначе показанный SQL расходился бы
 * с тем, который реально уйдёт в базу.
 */
async function refreshComposedSql() {
    if (contentMode.value === "sql" || !table.value || !metricsReady.value) return;

    try {
        const { data: body } = await api.post(
            `/dashboards/${props.dashboardId}/widgets/${props.widget.id}/query/compose`,
            { builder: builderPayload() }
        );

        composedSql.value = body.sql ?? null;

        if (!sqlTouched.value && body.sql) {
            query.value = body.sql;
        }
    } catch (err) {
        // Настройки ещё неполные — это обычное состояние на середине
        // заполнения формы, показывать ошибку рано.
        composedSql.value = null;
    }
}

/** Метрики заполнены настолько, что запрос уже можно собрать. */
const metricsReady = computed(() =>
    metrics.value.length > 0 && metrics.value.every((m) => !needsColumn(m.agg) || m.column)
);

function scheduleCompose() {
    clearTimeout(composeTimer);
    composeTimer = setTimeout(refreshComposedSql, 400);
}

function payload() {
    const body = { presentation: presentation.value.trim() || null };

    if (contentMode.value === "builder") {
        body.builder = builderPayload();
    } else {
        body.query = query.value;
    }

    return body;
}

function applyFailure(err, fallbackMessage) {
    const body = err.response?.data;

    errors.value = body?.errors?.length ? body.errors : [body?.message || fallbackMessage];
    composedSql.value = body?.sql ?? composedSql.value;

    // Даже у неудачного прогона бывают строки: база ответила, а споткнулась
    // раскладка. Без них автор видит только текст ошибки и гадает, что пришло.
    if (body && (Array.isArray(body.rows) || body.data)) {
        applyRunResult(body);
    }
}

async function run() {
    if (running.value || !canRun.value) return;

    running.value = true;
    resetState();

    try {
        const { data: body } = await api.post(
            `/dashboards/${props.dashboardId}/widgets/${props.widget.id}/query/run`,
            payload()
        );

        composedSql.value = body.sql ?? composedSql.value;
        applyRunResult(body);
        okMessage.value = "Готово — запрос отработал, данные подходят виджету.";
    } catch (err) {
        applyFailure(err, "Не удалось выполнить.");
    } finally {
        running.value = false;
    }
}

/**
 * Складывает ответ прогона так, как его читает автор.
 *
 * Неудачный прогон тоже показываем: база могла ответить строками, а споткнуться
 * уже раскладка по форме виджета — и тогда именно сырые строки объясняют, чего
 * в них не хватило.
 */
function applyRunResult(body) {
    if (!body) return;

    runResult.value = {
        rows: Array.isArray(body.rows) ? body.rows : [],
        data: body.data ?? null,
        columns: Array.isArray(body.columns) ? body.columns : [],
    };

    // По умолчанию открываем ту половину, которая сейчас важнее: разложенный
    // результат есть — смотреть надо его, нет — причина в сырых строках.
    resultView.value = runResult.value.data ? "data" : "rows";
}

/** Строки прогона в виде таблицы: заголовки берём из первой строки. */
const rowColumns = computed(() => {
    const first = runResult.value?.rows?.[0];

    return first && typeof first === "object" ? Object.keys(first) : [];
});

const shapedJson = computed(() =>
    runResult.value?.data ? JSON.stringify(runResult.value.data, null, 2) : ""
);

async function save() {
    if (saving.value || !canRun.value) return;

    saving.value = true;
    resetState();

    try {
        const { data: body } = await api.put(
            `/dashboards/${props.dashboardId}/widgets/${props.widget.id}/query`,
            payload()
        );

        composedSql.value = body.sql ?? composedSql.value;
        okMessage.value = "Сохранено, виджет работает.";
        applySaved(body.widget);
        emit("saved", body.widget);
    } catch (err) {
        applyFailure(err, "Не удалось сохранить.");
    } finally {
        saving.value = false;
    }
}

/**
 * Сохранение раздела «Виджет».
 *
 * Отдельным запросом и без перезапуска SQL: заголовок и цвета к содержимому
 * отношения не имеют, а смена вида отрисовки пересобирает запрос на сервере
 * сама (см. DashboardWidgetController::update).
 */
async function saveLook() {
    if (savingLook.value) return;

    const trimmed = title.value.trim();

    if (!trimmed) {
        errors.value = ["У виджета должен быть заголовок."];

        return;
    }

    savingLook.value = true;
    resetState();

    try {
        // Хвост пустых ячеек не отправляем: пустой список означает «цвета
        // не заданы», и оформление на сервере убирается целиком.
        const chosen = [...colors.value];

        while (chosen.length && !chosen[chosen.length - 1]) chosen.pop();

        const body = { title: trimmed, widget_type_id: typeId.value };

        // Палитры у этого семейства нет — и присылать её нельзя: пустой список
        // сервер понимает как «сбросить цвета», и сохранение заголовка стирало
        // бы оформление, которого человек даже не видел.
        if (canPickColors.value) {
            body.presentation = { colors: chosen };
        }

        const { data } = await api.patch(
            `/dashboards/${props.dashboardId}/widgets/${props.widget.id}`,
            body
        );

        okMessage.value = "Оформление сохранено.";
        applySaved(data);
        emit("saved", data);
    } catch (err) {
        applyFailure(err, "Не удалось сохранить оформление.");
    } finally {
        savingLook.value = false;
    }
}

/**
 * Перенос собранного запроса в раздел SQL — как «Convert to SQL» в Metabase.
 * Обратно не возвращаемся: из произвольного запроса слоты уже не собрать.
 */
function editAsSql() {
    if (!composedSql.value) return;

    query.value = composedSql.value;
    tab.value = "sql";
    okMessage.value = "Запрос перенесён. Дальше правьте его руками — настройки его больше не перезапишут.";
}

// --- Шторка -----------------------------------------------------------------

const MIN_WIDTH = 420;
const MAX_WIDTH = 1100;

const width = ref(Number(localStorage.getItem("widgetDrawerWidth")) || 640);
const isResizing = ref(false);

function show() {
    syncFromWidget();
    open.value = true;
}

function hide() {
    if (!open.value) return;

    open.value = false;
    emit("closed");
}

function onKeydown(event) {
    if (event.key === "Escape") hide();
}

watch(open, (value) => {
    if (value) {
        window.addEventListener("keydown", onKeydown);
    } else {
        window.removeEventListener("keydown", onKeydown);
    }
});

function startResize(event) {
    if (window.innerWidth < 992) return;

    isResizing.value = true;
    document.body.style.userSelect = "none";
    document.body.style.cursor = "col-resize";
    window.addEventListener("mousemove", onResize);
    window.addEventListener("mouseup", stopResize);
    event.preventDefault();
}

function onResize(event) {
    if (!isResizing.value) return;

    width.value = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, window.innerWidth - event.clientX));
}

function stopResize() {
    if (!isResizing.value) return;

    isResizing.value = false;
    document.body.style.userSelect = "";
    document.body.style.cursor = "";
    localStorage.setItem("widgetDrawerWidth", String(width.value));
    window.removeEventListener("mousemove", onResize);
    window.removeEventListener("mouseup", stopResize);
}

defineExpose({ show, hide });

onBeforeUnmount(() => {
    clearTimeout(composeTimer);
    window.removeEventListener("keydown", onKeydown);
    window.removeEventListener("mousemove", onResize);
    window.removeEventListener("mouseup", stopResize);
});
</script>

<template>
    <div>
        <div class="widget-drawer-backdrop" :class="{ 'is-open': open }" @click="hide"></div>

        <aside
            class="widget-drawer"
            :class="{ 'is-open': open }"
            :style="open ? { width: width + 'px' } : {}"
            aria-label="Настройка виджета"
        >
            <div class="widget-drawer-resize" @mousedown="startResize"></div>

            <!-- ШАПКА -->
            <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom flex-shrink-0">
                <div class="avatar avatar-sm rounded-2 bg-primary-lt text-primary flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                         stroke-linejoin="round">
                        <path d="M3 3v18h18" />
                        <path d="M7 16l4 -7l4 4l4 -7" />
                    </svg>
                </div>

                <div class="flex-fill overflow-hidden">
                    <div class="fw-bold text-truncate">Настройка виджета</div>
                    <div class="text-secondary small text-truncate">
                        {{ widget?.title || "Без названия" }}
                        <span v-if="familyName" class="text-muted">· {{ familyName }}</span>
                    </div>
                </div>

                <!-- Крестик закрытия — тот же .btn-close, что у offcanvas и модалок
                     Tabler, а не кнопка со своей иконкой. -->
                <button type="button" class="btn-close flex-shrink-0" title="Закрыть (Esc)"
                        aria-label="Закрыть" @click="hide"></button>
            </div>

            <!-- РАЗДЕЛЫ -->
            <ul class="nav nav-tabs widget-drawer-tabs px-2 flex-shrink-0" role="tablist">
                <li v-for="item in TABS" :key="item.key" class="nav-item">
                    <button class="nav-link" :class="{ active: tab === item.key }" type="button"
                            :title="item.hint" @click="tab = item.key">
                        {{ item.title }}
                    </button>
                </li>
            </ul>

            <!-- СОДЕРЖИМОЕ -->
            <div class="widget-drawer-body p-3">
                <!-- ============ 1. ДАННЫЕ: ТАБЛИЦЫ И СВЯЗИ ============ -->
                <template v-if="tab === 'data'">
                    <div class="mb-3">
                        <label class="form-label">Таблица</label>
                        <select v-model="table" class="form-select" @change="onTableChange">
                            <option value="" disabled>Выберите таблицу</option>
                            <option v-for="item in tables" :key="item.name" :value="item.name">
                                {{ item.name }}
                            </option>
                        </select>
                        <div class="form-hint">
                            С неё начинается запрос. Остальные подключаются связями ниже.
                        </div>
                    </div>

                    <template v-if="table">
                        <div class="mb-3">
                            <div class="d-flex align-items-center mb-1">
                                <label class="form-label mb-0">Связанные таблицы</label>
                                <button type="button" class="btn btn-sm ms-auto"
                                        :disabled="!joinableTables('').length"
                                        :title="joinableTables('').length ? '' : 'С этой таблицей ничего не связано'"
                                        @click="addJoin">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linecap="round" stroke-linejoin="round" class="icon">
                                        <path d="M12 5l0 14" />
                                        <path d="M5 12l14 0" />
                                    </svg>
                                    Связать
                                </button>
                            </div>

                            <div v-for="(join, index) in joins" :key="'j' + index"
                                 class="row g-1 mb-1 align-items-center">
                                <div class="col-3">
                                    <select v-model="join.table" class="form-select form-select-sm"
                                            aria-label="Связанная таблица"
                                            @change="onJoinTableChange(join)">
                                        <option value="" disabled>таблица</option>
                                        <option v-for="item in joinableTables(join.table)"
                                                :key="item.name" :value="item.name">
                                            {{ item.name }}
                                        </option>
                                    </select>
                                </div>

                                <div v-if="!join.manual" class="col-5">
                                    <select class="form-select form-select-sm"
                                            :disabled="!join.table"
                                            aria-label="По каким колонкам связывать"
                                            :value="join.left ? `${join.left_table}.${join.left}=${join.right}` : ''"
                                            @change="onPairChange(join, $event.target.value)">
                                        <option value="" disabled>по каким колонкам</option>
                                        <option v-for="pair in pairsFor(join)" :key="pairKey(pair)"
                                                :value="pairKey(pair)">
                                            {{ pair.left_table }}.{{ pair.left }} = {{ join.table }}.{{ pair.right }}
                                        </option>
                                        <option value="__manual__">указать другие колонки…</option>
                                    </select>
                                </div>

                                <template v-else>
                                    <div class="col-3">
                                        <select class="form-select form-select-sm"
                                                aria-label="Колонка из уже выбранных таблиц"
                                                :value="leftKey(join)"
                                                @change="onLeftChange(join, $event.target.value)">
                                            <option value="" disabled>колонка</option>
                                            <option v-for="column in leftColumns(join)"
                                                    :key="column.key" :value="column.key">
                                                {{ column.table }}.{{ column.name }}
                                            </option>
                                        </select>
                                    </div>
                                    <div class="col-auto text-secondary small">=</div>
                                    <div class="col-2">
                                        <select v-model="join.right" class="form-select form-select-sm"
                                                :disabled="!join.table"
                                                aria-label="Колонка связанной таблицы">
                                            <option value="" disabled>колонка</option>
                                            <option v-for="column in columnsOf(join.table)"
                                                    :key="column.name" :value="column.name">
                                                {{ column.name }}
                                            </option>
                                        </select>
                                    </div>
                                </template>

                                <div class="col-2">
                                    <select v-model="join.type" class="form-select form-select-sm"
                                            aria-label="Тип связи">
                                        <option v-for="(label, key) in joinTypes" :key="key"
                                                :value="key" :title="label">
                                            {{ key }}
                                        </option>
                                    </select>
                                </div>

                                <div class="col-auto">
                                    <div class="btn-list flex-nowrap">
                                        <button type="button" class="btn btn-icon btn-sm"
                                                :title="join.manual ? 'Выбрать из известных связей' : 'Указать колонки вручную'"
                                                @click="join.manual = !join.manual">
                                            <svg v-if="join.manual" xmlns="http://www.w3.org/2000/svg" width="24"
                                                 height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                 class="icon">
                                                <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
                                                <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
                                            </svg>
                                            <svg v-else xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                 class="icon">
                                                <path d="M4 20h4l10.5 -10.5a2.828 2.828 0 1 0 -4 -4l-10.5 10.5v4" />
                                                <path d="M13.5 6.5l4 4" />
                                            </svg>
                                        </button>
                                        <button type="button" class="btn btn-icon btn-sm"
                                                aria-label="Убрать связь" title="Убрать связь"
                                                @click="removeAt(joins, index)">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                 class="icon">
                                                <path d="M18 6l-12 12" />
                                                <path d="M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div v-if="!joins.length" class="text-secondary small">
                                Пока ни одной — виджет считается по одной таблице.
                            </div>

                            <div v-if="joins.length && !joinableTables('').length" class="form-hint text-warning">
                                Связанных таблиц не нашлось. Укажите колонки вручную
                                или выберите тип «cross», если связывать нечем.
                            </div>

                            <div v-if="joins.length" class="form-hint">
                                Показаны таблицы, связанные с уже выбранными, и готовые
                                условия связи. Дальше колонки берутся из всех этих таблиц.
                            </div>
                        </div>

                        <!-- Что уже участвует в запросе -->
                        <div class="mb-2">
                            <div class="text-secondary small mb-1">В запросе участвуют:</div>
                            <div class="d-flex flex-wrap gap-1">
                                <span v-for="name in tablesInPlay" :key="name" class="badge bg-secondary-lt">
                                    {{ name }}
                                </span>
                            </div>
                        </div>
                    </template>

                    <div v-else class="text-secondary">
                        Выберите таблицу — дальше конструктор соберёт запрос сам.
                    </div>
                </template>

                <!-- ============ 2. ВИДЖЕТ: ЗАГОЛОВОК, ВИД, ЦВЕТА ============ -->
                <template v-else-if="tab === 'look'">
                    <div class="mb-3">
                        <label class="form-label">Заголовок</label>
                        <input v-model="title" type="text" class="form-control" maxlength="255"
                               placeholder="Например, «Выручка по месяцам»" />
                    </div>

                    <div v-if="widgetTypes.length > 1" class="mb-3">
                        <label class="form-label">Вид отрисовки</label>
                        <select v-model="typeId" class="form-select">
                            <option v-for="type in widgetTypes" :key="type.id" :value="type.id">
                                {{ type.title || type.name }}
                            </option>
                        </select>
                        <div class="form-hint">
                            Меняет только внешний вид. Если новому виду нужны другие
                            колонки, запрос пересоберётся сам.
                        </div>
                    </div>

                    <div v-if="canPickColors" class="mb-3">
                        <div class="d-flex align-items-center mb-1">
                            <label class="form-label mb-0">Цвета рядов</label>
                            <button type="button" class="btn btn-sm ms-auto"
                                    :disabled="!colors.some(Boolean)" @click="resetAllColors">
                                Сбросить
                            </button>
                        </div>

                        <div class="row g-2">
                            <div v-for="(_, index) in colors" :key="'c' + index" class="col-auto">
                                <div class="widget-color" :class="{ 'is-custom': isCustomColor(index) }">
                                    <input
                                        type="color"
                                        class="form-control form-control-color"
                                        :value="colorValue(index)"
                                        :aria-label="`Цвет ряда ${index + 1}`"
                                        :title="isCustomColor(index) ? `Ряд ${index + 1}` : `Ряд ${index + 1} — стандартный`"
                                        @input="colors[index] = $event.target.value"
                                    />
                                    <button v-if="isCustomColor(index)" type="button"
                                            class="widget-color-reset" title="Вернуть стандартный"
                                            @click="resetColor(index)">×</button>
                                </div>
                            </div>
                        </div>

                        <div class="form-hint">
                            Порядок — как у рядов на графике: первый цвет достаётся
                            первому ряду. Нетронутые ячейки следуют теме оформления.
                        </div>
                    </div>
                </template>

                <!-- ============ 3. КОНСТРУКТОР ============ -->
                <template v-else-if="tab === 'builder'">
                    <template v-if="table">
                        <!-- МЕТРИКИ -->
                        <div class="mb-3">
                            <div class="d-flex align-items-center mb-1">
                                <label class="form-label mb-0">Метрики</label>
                                <button type="button" class="btn btn-sm ms-auto" @click="addMetric">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linecap="round" stroke-linejoin="round" class="icon">
                                        <path d="M12 5l0 14" />
                                        <path d="M5 12l14 0" />
                                    </svg>
                                    Добавить
                                </button>
                            </div>

                            <div v-for="(metric, index) in metrics" :key="'m' + index"
                                 class="row g-1 mb-1 align-items-center">
                                <div v-if="metricsIndependent" class="col-3">
                                    <select v-model="metric.table" class="form-select form-select-sm"
                                            aria-label="Таблица метрики"
                                            @change="onMetricTableChange(metric)">
                                        <option v-for="item in tables" :key="item.name"
                                                :value="item.name">
                                            {{ item.name }}
                                        </option>
                                    </select>
                                </div>
                                <div :class="metricsIndependent ? 'col-3' : 'col-4'">
                                    <select v-model="metric.agg" class="form-select form-select-sm"
                                            aria-label="Функция">
                                        <option v-for="(label, key) in aggregates" :key="key" :value="key">
                                            {{ label }}
                                        </option>
                                    </select>
                                </div>
                                <div :class="metricsIndependent ? 'col-3' : 'col-4'">
                                    <select v-if="needsColumn(metric.agg)" v-model="metric.column"
                                            class="form-select form-select-sm" aria-label="Колонка">
                                        <option value="" disabled>колонка</option>
                                        <option v-for="column in columnsForMetric(metric)"
                                                :key="column.key" :value="column.key">
                                            {{ column.title }}
                                        </option>
                                    </select>
                                    <span v-else class="text-secondary small">по всем строкам</span>
                                </div>
                                <div :class="(needsTarget || metricsIndependent) ? 'col-2' : 'col-3'">
                                    <input v-model="metric.label" type="text"
                                           class="form-control form-control-sm"
                                           placeholder="подпись" aria-label="Подпись метрики" />
                                </div>
                                <div v-if="needsTarget" class="col-1">
                                    <input v-model="metric.target" type="number" min="0"
                                           class="form-control form-control-sm"
                                           placeholder="цель"
                                           :aria-label="'Цель метрики ' + (metric.label || index + 1)" />
                                </div>
                                <div class="col-1 text-end">
                                    <button type="button" class="btn btn-icon btn-sm"
                                            aria-label="Удалить метрику" title="Удалить метрику"
                                            @click="removeAt(metrics, index)">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round" class="icon">
                                            <path d="M18 6l-12 12" />
                                            <path d="M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div v-if="!metrics.length" class="text-secondary small">
                                Пока ни одной — добавьте хотя бы одну.
                            </div>
                            <div v-else-if="metricsIndependent" class="form-hint">
                                Каждая метрика считается по своей таблице —
                                связывать их между собой не нужно.
                            </div>
                        </div>

                        <!-- РАЗБИВКА -->
                        <div class="mb-3">
                            <div class="d-flex align-items-center mb-1">
                                <label class="form-label mb-0">Разбивка</label>
                                <button type="button" class="btn btn-sm ms-auto"
                                        :disabled="dimensions.length >= (slots?.dimensions?.max ?? 2)"
                                        @click="addDimension">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linecap="round" stroke-linejoin="round" class="icon">
                                        <path d="M12 5l0 14" />
                                        <path d="M5 12l14 0" />
                                    </svg>
                                    Добавить
                                </button>
                            </div>

                            <div v-for="(dimension, index) in dimensions" :key="'d' + index"
                                 class="row g-1 mb-1 align-items-center">
                                <div class="col-6">
                                    <select v-model="dimension.column" class="form-select form-select-sm"
                                            aria-label="Колонка разбивки">
                                        <option v-for="column in columns" :key="column.key"
                                                :value="column.key">
                                            {{ column.title }}
                                        </option>
                                    </select>
                                </div>
                                <div class="col-5">
                                    <select v-if="columnKind(dimension.column) === 'date'"
                                            v-model="dimension.grain"
                                            class="form-select form-select-sm"
                                            aria-label="Округление даты">
                                        <option value="">без округления</option>
                                        <option v-for="(label, key) in grains" :key="key" :value="key">
                                            {{ label }}
                                        </option>
                                    </select>
                                </div>
                                <div class="col-1 text-end">
                                    <button type="button" class="btn btn-icon btn-sm"
                                            aria-label="Удалить разбивку" title="Удалить разбивку"
                                            @click="removeAt(dimensions, index)">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round" class="icon">
                                            <path d="M18 6l-12 12" />
                                            <path d="M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div v-if="!dimensions.length" class="text-secondary small">
                                Без разбивки — одно число на всю выборку.
                            </div>

                            <div v-if="slots?.hint" class="text-secondary small mt-1">
                                {{ slots.hint }}
                            </div>
                        </div>

                        <!-- УСЛОВИЯ -->
                        <div class="mb-3">
                            <div class="d-flex align-items-center mb-1">
                                <label class="form-label mb-0">Условия</label>
                                <button type="button" class="btn btn-sm ms-auto" @click="addFilter">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linecap="round" stroke-linejoin="round" class="icon">
                                        <path d="M12 5l0 14" />
                                        <path d="M5 12l14 0" />
                                    </svg>
                                    Добавить
                                </button>
                            </div>

                            <div v-for="(filter, index) in filters" :key="'f' + index"
                                 class="row g-1 mb-1 align-items-center">
                                <div class="col-4">
                                    <select v-model="filter.column" class="form-select form-select-sm"
                                            aria-label="Колонка условия">
                                        <option v-for="column in columns" :key="column.key"
                                                :value="column.key">
                                            {{ column.title }}
                                        </option>
                                    </select>
                                </div>
                                <div class="col-4">
                                    <select v-model="filter.op" class="form-select form-select-sm"
                                            aria-label="Условие">
                                        <option v-for="(label, key) in operators" :key="key" :value="key">
                                            {{ label }}
                                        </option>
                                    </select>
                                </div>
                                <div class="col-3">
                                    <input v-if="operatorNeedsValue(filter.op)" v-model="filter.value"
                                           type="text" class="form-control form-control-sm"
                                           placeholder="значение" aria-label="Значение условия" />
                                </div>
                                <div class="col-1 text-end">
                                    <button type="button" class="btn btn-icon btn-sm"
                                            aria-label="Удалить условие" title="Удалить условие"
                                            @click="removeAt(filters, index)">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round" class="icon">
                                            <path d="M18 6l-12 12" />
                                            <path d="M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div v-if="!filters.length" class="text-secondary small">
                                Без условий — считается по всем строкам.
                            </div>
                        </div>

                        <!-- СОРТИРОВКА И ЛИМИТ -->
                        <div class="row g-2">
                            <div class="col-5">
                                <label class="form-label">Сортировка</label>
                                <select v-model="sort.by" class="form-select form-select-sm">
                                    <option value="metric">по метрике</option>
                                    <option value="dimension">по разбивке</option>
                                    <option value="none">без сортировки</option>
                                </select>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Порядок</label>
                                <select v-model="sort.dir" class="form-select form-select-sm"
                                        :disabled="sort.by === 'none'">
                                    <option value="desc">по убыванию</option>
                                    <option value="asc">по возрастанию</option>
                                </select>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Строк</label>
                                <input v-model="limit" type="number" min="1" max="5000"
                                       class="form-control form-control-sm" />
                            </div>
                        </div>
                    </template>

                    <div v-else class="empty">
                        <div class="empty-title">Сначала выберите таблицу</div>
                        <p class="empty-subtitle text-secondary">
                            Метрики и разбивки берутся из её колонок — до этого выбирать не из чего.
                        </p>
                        <div class="empty-action">
                            <button class="btn btn-primary" type="button" @click="tab = 'data'">
                                Перейти к данным
                            </button>
                        </div>
                    </div>
                </template>

                <!-- ============ 4. SQL ============ -->
                <template v-else>
                    <!-- Запрос, и сразу под ним — всё, что его описывает: подсказка
                         про конструктор и колонки, которые он обязан вернуть.
                         Результат прогона идёт ниже, отдельным блоком: раньше он
                         вклинивался между запросом и подсказкой, и подсказка
                         читалась как описание результата. -->
                    <div class="mb-3">
                        <label class="form-label">SQL-запрос</label>

                        <textarea v-model="query" class="form-control builder-sql" spellcheck="false"
                                  rows="14" aria-label="SQL-запрос виджета"
                                  @input="sqlTouched = true"
                                  @keydown.tab.prevent="onTab"></textarea>

                        <div v-if="!sqlTouched && composedSql" class="form-hint">
                            Запрос собран конструктором. Правки здесь останутся —
                            настройки его больше не перезапишут.
                        </div>
                    </div>

                    <div v-if="requiredColumns.length" class="mb-3">
                        <div class="form-label">Запрос должен вернуть колонки</div>
                        <div class="d-flex flex-wrap gap-2">
                            <span v-for="column in requiredColumns" :key="column"
                                  class="badge bg-secondary-lt" :title="hintFor(column)">
                                {{ column }}
                            </span>
                        </div>
                    </div>

                    <!-- Результат прогона: сначала то, что вернула база, затем то,
                         во что это разложилось для виджета. Рядом, потому что
                         ошибка формы видна только при сравнении этих двух. -->
                    <div v-if="runResult" class="card mb-3">
                        <div class="card-header p-0">
                            <ul class="nav nav-tabs card-header-tabs px-2" role="tablist">
                                <li class="nav-item">
                                    <button class="nav-link" type="button"
                                            :class="{ active: resultView === 'rows' }"
                                            @click="resultView = 'rows'">
                                        Ответ базы
                                        <span class="badge bg-secondary-lt ms-1">{{ runResult.rows.length }}</span>
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" type="button"
                                            :class="{ active: resultView === 'data' }"
                                            @click="resultView = 'data'">
                                        Данные виджета
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <div class="card-body p-2">
                            <template v-if="resultView === 'rows'">
                                <div v-if="!runResult.rows.length" class="text-secondary small">
                                    Запрос не вернул ни одной строки.
                                </div>

                                <div v-else class="table-responsive builder-result">
                                    <table class="table table-sm table-vcenter mb-0">
                                        <thead>
                                            <tr>
                                                <th v-for="column in rowColumns" :key="column">{{ column }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(row, index) in runResult.rows" :key="index">
                                                <td v-for="column in rowColumns" :key="column">{{ row[column] }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </template>

                            <template v-else>
                                <div v-if="!shapedJson" class="text-secondary small">
                                    Разложить в форму виджета не удалось — смотрите ответ базы и ошибку ниже.
                                </div>
                                <pre v-else class="mb-0 builder-result builder-sql">{{ shapedJson }}</pre>
                            </template>
                        </div>
                    </div>

                    <div>
                        <button type="button" class="btn btn-sm"
                                @click="showPresentation = !showPresentation">
                            {{ showPresentation ? 'Скрыть оформление' : 'Оформление, JSON' }}
                        </button>

                        <div v-if="showPresentation" class="mt-2">
                            <textarea v-model="presentation" class="form-control builder-sql" rows="4"
                                      spellcheck="false"
                                      placeholder='{ "series_kinds": { "Выручка": "column" } }'
                                      aria-label="Оформление виджета"></textarea>
                            <div class="form-hint">Сюда попадает то, чего нет в данных.</div>
                        </div>
                    </div>
                </template>

                <!-- СООБЩЕНИЯ -->
                <div v-if="okMessage" class="alert alert-success mt-3 mb-0" role="status">
                    {{ okMessage }}
                </div>

                <div v-if="errors.length" class="alert alert-danger mt-3 mb-0" role="alert">
                    <div class="fw-bold mb-1">Не получилось:</div>
                    <pre class="mb-0 builder-errors">{{ errors.join("\n") }}</pre>
                </div>
            </div>

            <!-- ПОДВАЛ -->
            <div class="border-top p-3 flex-shrink-0">
                <!-- Раздел «Виджет» сохраняется своей кнопкой: он не трогает
                     ни запрос, ни данные. -->
                <div v-if="isLookTab" class="d-flex gap-2 align-items-center">
                    <button type="button" class="btn btn-primary" :class="{ 'btn-loading': savingLook }"
                            :disabled="savingLook || !title.trim()" @click="saveLook">
                        Сохранить
                    </button>
                    <button type="button" class="btn btn-link link-secondary" @click="hide">
                        Закрыть
                    </button>
                </div>

                <template v-else>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <button type="button" class="btn"
                                :class="{ 'btn-loading': running }"
                                :disabled="running || saving || !canRun" @click="run">
                            Выполнить
                        </button>
                        <button type="button" class="btn btn-primary" :class="{ 'btn-loading': saving }"
                                :disabled="running || saving || !canRun" @click="save">
                            Сохранить
                        </button>
                        <button v-if="contentMode === 'builder' && composedSql" type="button"
                                class="btn btn-link link-secondary" @click="editAsSql">
                            Править запросом
                        </button>
                    </div>

                    <!-- Пока чего-то не хватает, кнопки заблокированы —
                         и здесь написано, чего именно. -->
                    <div v-if="blockers.length" class="text-secondary small mt-2">
                        {{ blockers.join(' ') }}
                    </div>
                </template>
            </div>
        </aside>
    </div>
</template>

<style scoped>
.builder-sql {
    font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
    font-size: 13px;
    line-height: 1.5;
    tab-size: 4;
    white-space: pre;
    overflow-x: auto;
}

/*
 * Tabler красит ГОЛЫЙ <pre> в --tblr-bg-surface-dark (gray-900) со светлым
 * текстом: это его стиль для примеров кода в документации. Здесь pre — не
 * пример кода, а панель вывода: она стоит во вкладке рядом со светлой таблицей
 * ответа базы и внутри красного alert'а с ошибкой. Тёмный прямоугольник посреди
 * светлой шторки читался как поломка вёрстки, поэтому заливку снимаем и отдаём
 * блоку фон контейнера.
 */
pre.builder-result,
pre.builder-errors {
    background: transparent;
    color: inherit;
    padding: 0;
    border-radius: 0;
}

.builder-errors {
    font-size: 12px;
    max-height: 200px;
    overflow: auto;
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
}

/* Результат прогона не должен выталкивать запрос за экран: смотрят их вместе. */
.builder-result {
    max-height: 260px;
    overflow: auto;
    font-size: 12px;
}

/* Ячейка палитры: образец цвета и крестик «вернуть стандартный». */
.widget-color {
    position: relative;
}

.widget-color .form-control-color {
    width: 44px;
    height: 34px;
    padding: 2px;
    cursor: pointer;
}

/* Своим цветам — рамка: иначе не отличить выбранный вручную оттенок
   от стандартного, который просто совпал с ним. */
.widget-color.is-custom .form-control-color {
    border-color: var(--tblr-primary);
    box-shadow: 0 0 0 1px var(--tblr-primary);
}

.widget-color-reset {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 16px;
    height: 16px;
    line-height: 14px;
    padding: 0;
    border: 1px solid var(--tblr-border-color);
    border-radius: 50%;
    background: var(--tblr-bg-surface);
    color: var(--tblr-secondary);
    font-size: 12px;
    cursor: pointer;
}

.widget-color-reset:hover {
    color: var(--tblr-danger);
    border-color: var(--tblr-danger);
}
</style>
