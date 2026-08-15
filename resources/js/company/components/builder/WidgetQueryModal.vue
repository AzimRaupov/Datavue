<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from "vue";
import { Modal } from "bootstrap";
import api from "../../api.js";
import { queryTemplateFor, COLUMN_HINTS } from "./queryTemplates.js";
import { familyOf, propsFor, hasData } from "../widgets/registry.js";

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
const rows = ref([]);
const data = ref(null);
const composedSql = ref(null);

const aggregates = computed(() => props.dictionary.aggregates ?? {});
const grains = computed(() => props.dictionary.grains ?? {});
const operators = computed(() => props.dictionary.operators ?? {});

const familyName = computed(() => props.widget?.widget?.name ?? null);
const family = computed(() => familyOf(familyName.value));
const requiredColumns = computed(() => props.widget?.required_columns ?? []);
const slots = computed(() => props.widget?.slots ?? null);

const tables = computed(() => props.schema ?? []);
const currentTable = computed(() => tables.value.find((t) => t.name === table.value) ?? null);
const columns = computed(() => currentTable.value?.columns ?? []);
const dateColumns = computed(() => columns.value.filter((c) => c.kind === "date"));

const typeOptions = computed(() => {
    const chosen = props.widget?.widget_type;

    if (chosen?.options) return chosen.options;

    return (props.widget?.widget?.types ?? []).find((t) => t.is_default)?.options ?? {};
});

const previewProps = computed(() => propsFor(familyName.value, data.value, typeOptions.value));
const previewReady = computed(() => Boolean(family.value) && hasData(familyName.value, data.value));
const rowColumns = computed(() => (rows.value.length ? Object.keys(rows.value[0]) : []));

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

function columnKind(name) {
    return columns.value.find((c) => c.name === name)?.kind ?? "string";
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
    metrics.value.push({ agg: "count", column: "", label: "" });
}

function addDimension() {
    dimensions.value.push({ column: columns.value[0]?.name ?? "", grain: "" });
}

function addFilter() {
    filters.value.push({ column: columns.value[0]?.name ?? "", op: "=", value: "" });
}

function removeAt(list, index) {
    list.splice(index, 1);
}

/** Смена таблицы обнуляет всё: колонки другой таблицы здесь не действуют. */
function onTableChange() {
    metrics.value = [];
    dimensions.value = [];
    filters.value = [];
    rows.value = [];
    data.value = null;
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
        rows.value = [];
        data.value = null;
        composedSql.value = null;

        const builder = props.widget?.builder ?? null;

        if (builder) {
            tab.value = "builder";
            table.value = builder.table ?? "";
            metrics.value = (builder.metrics ?? []).map((m) => ({
                agg: m.agg ?? "count",
                column: m.column ?? "",
                label: m.label ?? "",
            }));
            dimensions.value = (builder.dimensions ?? []).map((d) => ({
                column: d.column ?? "",
                grain: d.grain ?? "",
            }));
            filters.value = (builder.filters ?? []).map((f) => ({
                column: f.column ?? "",
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
            sort.value = { by: "metric", dir: "desc" };
            limit.value = props.dictionary.default_limit ?? 100;
        }

        query.value = props.widget?.query || queryTemplateFor(familyName.value);

        presentation.value = props.widget?.presentation
            ? JSON.stringify(props.widget.presentation, null, 2)
            : "";
        showPresentation.value = Boolean(props.widget?.presentation);
    }
);

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
        metrics: metrics.value.map((m) => ({
            agg: m.agg,
            column: needsColumn(m.agg) ? m.column : null,
            label: m.label || null,
        })),
        dimensions: dimensions.value
            .filter((d) => d.column)
            .map((d) => ({ column: d.column, grain: d.grain || null })),
        filters: filters.value
            .filter((f) => f.column)
            .map((f) => ({ column: f.column, op: f.op, value: f.value })),
        sort: sort.value,
        limit: Number(limit.value) || 100,
    };
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
    rows.value = body?.rows ?? [];
    data.value = body?.data ?? null;
    composedSql.value = body?.sql ?? null;
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

        rows.value = body.rows ?? [];
        data.value = body.data ?? null;
        composedSql.value = body.sql ?? null;
        okMessage.value = "Готово — так виджет и будет выглядеть.";
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

        data.value = body.data ?? null;
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

function insertTable(item) {
    if (tab.value === "builder") {
        table.value = item.name;
        onTableChange();

        return;
    }

    const names = (item.columns ?? []).slice(0, 3).map((c) => c.name).join(", ");

    query.value = `SELECT ${names || "*"}\nFROM ${item.name}\nLIMIT 10`;
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
                        <!-- ЛЕВО -->
                        <div class="col-lg-7">
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
                                            <div class="col-4">
                                                <select v-model="metric.agg" class="form-select form-select-sm"
                                                        aria-label="Функция">
                                                    <option v-for="(title, key) in aggregates" :key="key" :value="key">
                                                        {{ title }}
                                                    </option>
                                                </select>
                                            </div>
                                            <div class="col-4">
                                                <select v-if="needsColumn(metric.agg)" v-model="metric.column"
                                                        class="form-select form-select-sm" aria-label="Колонка">
                                                    <option value="" disabled>колонка</option>
                                                    <option v-for="column in columns" :key="column.name"
                                                            :value="column.name">
                                                        {{ column.name }}
                                                    </option>
                                                </select>
                                                <span v-else class="text-secondary small">по всем строкам</span>
                                            </div>
                                            <div class="col-3">
                                                <input v-model="metric.label" type="text"
                                                       class="form-control form-control-sm"
                                                       placeholder="подпись" aria-label="Подпись метрики" />
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
                                                    <option v-for="column in columns" :key="column.name"
                                                            :value="column.name">
                                                        {{ column.name }}
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
                                                    <option v-for="column in columns" :key="column.name"
                                                            :value="column.name">
                                                        {{ column.name }}
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
                                          rows="14" aria-label="SQL-запрос виджета"
                                          @keydown.tab.prevent="onTab"></textarea>

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

                            <!-- Собранный запрос виден всегда: понимать, что ушло
                                 в базу, важнее, чем прятать это за кнопкой. -->
                            <div v-if="tab === 'builder' && composedSql" class="mt-3">
                                <div class="form-label">Собранный запрос</div>
                                <pre class="builder-composed">{{ composedSql }}</pre>
                            </div>
                        </div>

                        <!-- ПРАВО -->
                        <div class="col-lg-5">
                            <div v-if="data" class="mb-3">
                                <div class="form-label">Так виджет будет выглядеть</div>
                                <div class="border rounded p-2">
                                    <component v-if="previewReady" :is="family.component" v-bind="previewProps" />
                                    <div v-else class="text-secondary small">
                                        Данных нет — виджет останется пустым.
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="form-label">Что вернула база</div>
                                <div v-if="rows.length" class="table-responsive builder-rows">
                                    <table class="table table-sm table-vcenter">
                                        <thead>
                                        <tr>
                                            <th v-for="column in rowColumns" :key="column">{{ column }}</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <tr v-for="(row, index) in rows" :key="index">
                                            <td v-for="column in rowColumns" :key="column">{{ row[column] }}</td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div v-else class="text-secondary small">
                                    Нажмите «Выполнить», чтобы увидеть строки.
                                </div>
                            </div>

                            <div>
                                <div class="form-label">Таблицы источника</div>
                                <div class="builder-schema">
                                    <div v-for="item in schema" :key="item.name" class="mb-2">
                                        <button type="button" class="btn btn-sm btn-ghost-secondary p-0"
                                                @click="insertTable(item)">
                                            {{ item.name }}
                                        </button>
                                        <div class="text-secondary small">
                                            {{ (item.columns ?? []).map(c => c.name).join(", ") }}
                                        </div>
                                    </div>
                                    <div v-if="!schema.length" class="text-secondary small">
                                        Схема не загружена.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.builder-sql,
.builder-composed {
    font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
    font-size: 13px;
    line-height: 1.5;
    tab-size: 4;
}

.builder-sql {
    white-space: pre;
    overflow-x: auto;
}

.builder-composed {
    background: var(--tblr-bg-surface-secondary);
    border-radius: 4px;
    padding: 8px;
    margin: 0;
    max-height: 200px;
    overflow: auto;
}

.builder-errors {
    font-size: 12px;
    max-height: 200px;
    overflow: auto;
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
}

.builder-rows,
.builder-schema {
    max-height: 240px;
    overflow: auto;
}
</style>
