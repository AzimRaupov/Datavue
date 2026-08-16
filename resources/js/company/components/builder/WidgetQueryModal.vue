<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from "vue";
import { Modal } from "bootstrap";
import api from "../../api.js";
import { queryTemplateFor, COLUMN_HINTS } from "./queryTemplates.js";

/**
 * Настройка виджета — конструктором или запросом.
 *
 * Устроено как в Superset и Metabase: основной режим — слоты (таблица,
 * метрики, разбивки, фильтры), запрос платформа собирает сама и показывает
 * рядом. Кто хочет большего, переключается на вкладку с SQL и пишет руками;
 * собранный конструктором запрос можно перенести туда и продолжить с него.
 *
 * Предпросмотр рисуется тем же реестром, что и дашборд, — значит увиденное
 * здесь и есть то, что появится на дашборде.
 */

const props = defineProps({
    dashboardId: { type: [String, Number], required: true },
    widget: { type: Object, default: null },
    schema: { type: Array, default: () => [] },
    dictionary: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["saved", "closed"]);

const modalEl = ref(null);
let modal = null;

const tab = ref("builder");

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

// --- Общее ------------------------------------------------------------------
const running = ref(false);
const saving = ref(false);
const errors = ref([]);
const okMessage = ref(null);
const composedSql = ref(null);

const aggregates = computed(() => props.dictionary.aggregates ?? {});
const grains = computed(() => props.dictionary.grains ?? {});
const operators = computed(() => props.dictionary.operators ?? {});

const familyName = computed(() => props.widget?.widget?.name ?? null);
const requiredColumns = computed(() => props.widget?.required_columns ?? []);
const slots = computed(() => props.widget?.slots ?? null);

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



/**
 * Чего не хватает, чтобы виджет можно было посчитать.
 *
 * Раньше кнопки просто гасли: у точечной нужно две метрики, а конструктор
 * добавлял одну — и человек не понимал, почему ничего не нажимается.
 */
const blockers = computed(() => {
    if (tab.value === "sql") {
        return query.value.trim() === "" ? ["Напишите запрос."] : [];
    }

    const problems = [];

    if (!table.value) {
        problems.push("Выберите таблицу.");

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
    // relations здесь не трогаем: это граф связей всего источника, он
    // приходит со схемой и от выбранной таблицы не зависит. Присваивание
    // в computed молча ничего не делало и роняло предупреждение Vue.
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

watch(
    () => props.widget?.id,
    () => {
        resetState();
        composedSql.value = null;

        sqlTouched.value = false;

        const builder = props.widget?.builder ?? null;

        if (builder) {
            tab.value = "builder";
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
            // с чистого листа, а запрос открывается на своей вкладке.
            tab.value = props.widget?.query ? "sql" : "builder";
            table.value = "";
            metrics.value = [];
            dimensions.value = [];
            filters.value = [];
            joins.value = [];
            sort.value = { by: "metric", dir: "desc" };
            limit.value = props.dictionary.default_limit ?? 100;
        }

        query.value = props.widget?.query || queryTemplateFor(familyName.value);

        presentation.value = props.widget?.presentation
            ? JSON.stringify(props.widget.presentation, null, 2)
            : "";
        showPresentation.value = Boolean(props.widget?.presentation);

        scheduleCompose();
    }
);

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
 * Пока не правил — вкладка SQL показывает то, что собрал конструктор, и
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
    if (tab.value === "sql" || !table.value || !metricsReady.value) return;

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

    if (tab.value === "builder") {
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
        okMessage.value = "Готово — запрос отработал, данные подходят виджету.";
    } catch (err) {
        applyFailure(err, "Не удалось выполнить.");
    } finally {
        running.value = false;
    }
}

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
        emit("saved", body.widget);
    } catch (err) {
        applyFailure(err, "Не удалось сохранить.");
    } finally {
        saving.value = false;
    }
}

/**
 * Перенос собранного запроса в режим SQL — как «Convert to SQL» в Metabase.
 * Обратно не возвращаемся: из произвольного запроса слоты уже не собрать.
 */
function editAsSql() {
    if (!composedSql.value) return;

    query.value = composedSql.value;
    tab.value = "sql";
    okMessage.value = "Запрос перенесён. Дальше правьте его руками — слоты к нему уже не применятся.";
}


function show() {
    modal?.show();
}

defineExpose({ show });

onMounted(() => {
    if (modalEl.value) {
        modal = new Modal(modalEl.value);
        modalEl.value.addEventListener("hidden.bs.modal", () => emit("closed"));
    }
});

onBeforeUnmount(() => {
    modal?.dispose();
});
</script>

<template>
    <div ref="modalEl" class="modal modal-blur fade" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        Настройка виджета
                        <span v-if="widget" class="text-secondary">— {{ widget.title }}</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>

                <div class="modal-body">
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link" :class="{ active: tab === 'builder' }" type="button"
                                    @click="tab = 'builder'">
                                Конструктор
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" :class="{ active: tab === 'sql' }" type="button"
                                    @click="tab = 'sql'">
                                SQL
                            </button>
                        </li>
                    </ul>

                    <div class="row g-3">
                        <div class="col-12">
                            <!-- ================= КОНСТРУКТОР ================= -->
                            <template v-if="tab === 'builder'">
                                <div class="mb-3">
                                    <label class="form-label">Таблица</label>
                                    <select v-model="table" class="form-select" @change="onTableChange">
                                        <option value="" disabled>Выберите таблицу</option>
                                        <option v-for="item in tables" :key="item.name" :value="item.name">
                                            {{ item.name }}
                                        </option>
                                    </select>
                                </div>

                                <template v-if="table">
                                    <!-- СВЯЗИ -->
                                    <div class="mb-3">
                                        <div class="d-flex align-items-center mb-1">
                                            <label class="form-label mb-0">Связанные таблицы</label>
                                            <button type="button" class="btn btn-sm btn-ghost-primary ms-auto"
                                                    :disabled="!joinableTables('').length"
                                                    :title="joinableTables('').length ? '' : 'С этой таблицей ничего не связано'"
                                                    @click="addJoin">
                                                + связать
                                            </button>
                                        </div>

                                        <div v-for="(join, index) in joins" :key="'j' + index"
                                             class="row g-1 mb-1 align-items-center">
                                            <!-- Только те таблицы, которые
                                                 действительно связаны с уже
                                                 участвующими. -->
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

                                            <!-- Известные связи списком: гадать,
                                                 какой ключ куда смотрит, не нужно. -->
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
                                                    <!-- Связь не обязана идти по найденному ключу:
                                                         отсюда переходят к свободному выбору полей. -->
                                                    <option value="__manual__">указать другие колонки…</option>
                                                </select>
                                            </div>

                                            <!-- Ручной выбор: когда связь не нашлась
                                                 или нужна не та, что предложена. -->
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
                                                    <option v-for="(title, key) in joinTypes" :key="key"
                                                            :value="key" :title="title">
                                                        {{ key }}
                                                    </option>
                                                </select>
                                            </div>

                                            <div class="col-auto text-end">
                                                <button type="button" class="btn btn-sm btn-ghost-secondary px-1"
                                                        :title="join.manual ? 'Выбрать из известных связей' : 'Указать колонки вручную'"
                                                        @click="join.manual = !join.manual">
                                                    {{ join.manual ? '↺' : '✎' }}
                                                </button>
                                                <button type="button" class="btn btn-sm btn-ghost-danger px-1"
                                                        aria-label="Убрать связь"
                                                        @click="removeAt(joins, index)">×</button>
                                            </div>
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

                                    <!-- МЕТРИКИ -->
                                    <div class="mb-3">
                                        <div class="d-flex align-items-center mb-1">
                                            <label class="form-label mb-0">Метрики</label>
                                            <button type="button" class="btn btn-sm btn-ghost-primary ms-auto"
                                                    @click="addMetric">
                                                + добавить
                                            </button>
                                        </div>

                                        <div v-for="(metric, index) in metrics" :key="'m' + index"
                                             class="row g-1 mb-1 align-items-center">
                                            <!-- Своя таблица у метрики: так на одном
                                                 счётчике уживаются числа из разных
                                                 таблиц, которые нечем связывать. -->
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
                                                    <option v-for="(title, key) in aggregates" :key="key" :value="key">
                                                        {{ title }}
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
                                                <button type="button" class="btn btn-sm btn-ghost-danger px-1"
                                                        aria-label="Удалить метрику"
                                                        @click="removeAt(metrics, index)">×</button>
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

                                    <!-- РАЗБИВКИ -->
                                    <div class="mb-3">
                                        <div class="d-flex align-items-center mb-1">
                                            <label class="form-label mb-0">Разбивка</label>
                                            <button type="button" class="btn btn-sm btn-ghost-primary ms-auto"
                                                    :disabled="dimensions.length >= (slots?.dimensions?.max ?? 2)"
                                                    @click="addDimension">
                                                + добавить
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
                                                    <option v-for="(title, key) in grains" :key="key" :value="key">
                                                        {{ title }}
                                                    </option>
                                                </select>
                                            </div>
                                            <div class="col-1 text-end">
                                                <button type="button" class="btn btn-sm btn-ghost-danger px-1"
                                                        aria-label="Удалить разбивку"
                                                        @click="removeAt(dimensions, index)">×</button>
                                            </div>
                                        </div>

                                        <div v-if="slots?.hint" class="text-secondary small mt-1">
                                            {{ slots.hint }}
                                        </div>
                                    </div>

                                    <!-- ФИЛЬТРЫ -->
                                    <div class="mb-3">
                                        <div class="d-flex align-items-center mb-1">
                                            <label class="form-label mb-0">Условия</label>
                                            <button type="button" class="btn btn-sm btn-ghost-primary ms-auto"
                                                    @click="addFilter">
                                                + добавить
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
                                                    <option v-for="(title, key) in operators" :key="key" :value="key">
                                                        {{ title }}
                                                    </option>
                                                </select>
                                            </div>
                                            <div class="col-3">
                                                <input v-if="operatorNeedsValue(filter.op)" v-model="filter.value"
                                                       type="text" class="form-control form-control-sm"
                                                       placeholder="значение" aria-label="Значение условия" />
                                            </div>
                                            <div class="col-1 text-end">
                                                <button type="button" class="btn btn-sm btn-ghost-danger px-1"
                                                        aria-label="Удалить условие"
                                                        @click="removeAt(filters, index)">×</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- СОРТИРОВКА И ЛИМИТ -->
                                    <div class="row g-2 mb-3">
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

                                <div v-else class="text-secondary">
                                    Выберите таблицу — дальше конструктор соберёт запрос сам.
                                </div>
                            </template>

                            <!-- ================= SQL ================= -->
                            <template v-else>
                                <label class="form-label">SQL-запрос</label>

                                <textarea v-model="query" class="form-control builder-sql" spellcheck="false"
                                          rows="16" aria-label="SQL-запрос виджета"
                                          @input="sqlTouched = true"
                                          @keydown.tab.prevent="onTab"></textarea>

                                <div v-if="!sqlTouched && composedSql" class="form-hint mt-1">
                                    Запрос собран конструктором. Правки здесь останутся —
                                    настройки его больше не перезапишут.
                                </div>

                                <div v-if="requiredColumns.length" class="mt-2">
                                    <div class="text-secondary small mb-1">Запрос должен вернуть колонки:</div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <span v-for="column in requiredColumns" :key="column"
                                              class="badge bg-blue-lt" :title="hintFor(column)">
                                            {{ column }}
                                        </span>
                                    </div>
                                </div>
                            </template>

                            <!-- ================= ОБЩЕЕ ================= -->
                            <div class="d-flex gap-2 mt-3 flex-wrap align-items-center">
                                <button type="button" class="btn btn-outline-primary"
                                        :class="{ 'btn-loading': running }"
                                        :disabled="running || saving || !canRun" @click="run">
                                    Выполнить
                                </button>
                                <button type="button" class="btn btn-primary" :class="{ 'btn-loading': saving }"
                                        :disabled="running || saving || !canRun" @click="save">
                                    Сохранить
                                </button>
                                <button v-if="tab === 'builder' && composedSql" type="button"
                                        class="btn btn-link link-secondary" @click="editAsSql">
                                    Править запросом
                                </button>
                                <button type="button" class="btn btn-link link-secondary"
                                        @click="showPresentation = !showPresentation">
                                    {{ showPresentation ? 'Скрыть оформление' : 'Оформление' }}
                                </button>
                            </div>

                            <!-- Пока чего-то не хватает, кнопки заблокированы —
                                 и здесь написано, чего именно. -->
                            <div v-if="blockers.length" class="text-secondary small mt-2">
                                {{ blockers.join(' ') }}
                            </div>

                            <div v-if="showPresentation" class="mt-2">
                                <label class="form-label">Оформление, JSON</label>
                                <textarea v-model="presentation" class="form-control builder-sql" rows="4"
                                          spellcheck="false"
                                          placeholder='{ "series_kinds": { "Выручка": "column" } }'
                                          aria-label="Оформление виджета"></textarea>
                                <div class="form-hint">Сюда попадает то, чего нет в данных.</div>
                            </div>

                            <div v-if="okMessage" class="alert alert-success mt-3 mb-0" role="status">
                                {{ okMessage }}
                            </div>

                            <div v-if="errors.length" class="alert alert-danger mt-3 mb-0" role="alert">
                                <div class="fw-bold mb-1">Не получилось:</div>
                                <pre class="mb-0 builder-errors">{{ errors.join("\n") }}</pre>
                            </div>

                        </div>

                    </div>
                </div>
            </div>
        </div>
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

.builder-errors {
    font-size: 12px;
    max-height: 200px;
    overflow: auto;
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
}

</style>
