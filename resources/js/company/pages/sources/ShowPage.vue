<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Modal } from 'bootstrap';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import api from '../../api.js';
import SqlEditor from '../../components/SqlEditor.vue';

const route = useRoute();
const { t } = useI18n();

const sourceId = route.params.id;

const source = ref(null);
const groups = ref([]);
const tablesCount = ref(0);
const loading = ref(false);
const pageError = ref(null);

// SQL-консоль: пробный SELECT прямо к источнику, тот же ReadOnlyQueryRunner,
// что и у пробного запроса в конструкторе виджета.
const sqlQuery = ref('');
const sqlRunning = ref(false);
const sqlError = ref(null);
const sqlResult = ref(null);
const sqlSchema = ref([]);
const sqlDialect = ref('duckdb');
const sqlEditor = ref(null);
const sqlElapsed = ref(null);

// Браузер схемы: поиск по таблицам и колонкам, раскрытие таблицы кликом.
const tableSearch = ref('');
const expandedTables = ref(new Set());
const schemaLoading = ref(false);

// Недавние запросы живут в браузере: это удобство одного человека, а не данные источника.
const HISTORY_LIMIT = 15;
const historyKey = `datavue.sqlHistory.${sourceId}`;
const sqlHistory = ref([]);

// Обновление данных
const refreshModalEl = ref(null);
let refreshModal = null;
const refreshing = ref(false);
const refreshError = ref(null);
const refreshFile = ref(null);
const refreshFileEl = ref(null);

const currentUser = JSON.parse(localStorage.getItem('user') || 'null');
const permissions = computed(() => currentUser?.permissions ?? []);
const canManageSources = computed(() => permissions.value.includes('manage data sources'));

const canRefresh = computed(() => canManageSources.value && !!source.value);

const isGoogleSheet = computed(() => source.value?.origin_format === 'google_sheets');
const isRemoteSource = computed(() => source.value?.connection_type === 'remote');

// Файл нужен только для загруженного файла: Google-таблица тянется по ссылке,
// внешняя база — по живому подключению.
const refreshNeedsFile = computed(() => !isRemoteSource.value && !isGoogleSheet.value);

// Результат последнего обновления: что изменилось в составе таблиц.
const refreshResult = ref(null);

// Группировка устарела — состав таблиц изменился после обновления.
const groupingStale = computed(() => source.value?.grouping_status === 'stale');
const regrouping = ref(false);

function formatDateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('ru-RU', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

async function fetchSource() {
    loading.value = true;
    pageError.value = null;

    try {
        const { data } = await api.get(`/data_source/${sourceId}`);
        source.value = data.data_source;
        groups.value = data.groups ?? [];
        tablesCount.value = data.tables_count ?? 0;
    } catch (err) {
        pageError.value =
            err.response?.status === 404
                ? t('sourcesShow.errors.not_found')
                : t('sourcesShow.errors.load_failed');
    } finally {
        loading.value = false;
    }
}

/**
 * Схема кормит и подсказки редактора, и браузер таблиц слева. Не загрузилась —
 * консоль работает как обычное поле, поэтому ошибку не показываем.
 */
async function fetchSqlSchema() {
    if (!canManageSources.value) return;

    schemaLoading.value = true;

    try {
        const { data } = await api.get(`/data_source/${sourceId}/schema`);
        sqlSchema.value = data.tables ?? [];
        sqlDialect.value = data.dialect ?? 'duckdb';
    } catch (err) {
        sqlSchema.value = [];
    } finally {
        schemaLoading.value = false;
    }
}

const filteredTables = computed(() => {
    const needle = tableSearch.value.trim().toLowerCase();

    if (!needle) return sqlSchema.value;

    return sqlSchema.value.filter((table) =>
        table.name.toLowerCase().includes(needle)
        || (table.columns ?? []).some((column) => column.name.toLowerCase().includes(needle))
    );
});

// При поиске раскрываем таблицы, в которых совпала колонка, — иначе совпадение не видно.
function isExpanded(table) {
    if (expandedTables.value.has(table.name)) return true;

    const needle = tableSearch.value.trim().toLowerCase();

    return needle !== ''
        && !table.name.toLowerCase().includes(needle)
        && (table.columns ?? []).some((column) => column.name.toLowerCase().includes(needle));
}

function toggleTable(table) {
    const next = new Set(expandedTables.value);

    if (next.has(table.name)) next.delete(table.name);
    else next.add(table.name);

    expandedTables.value = next;
}

function quoteIdentifier(name) {
    if (/^[a-z_][a-z0-9_]*$/i.test(name)) return name;

    return sqlDialect.value === 'mysql'
        ? '`' + name.replaceAll('`', '``') + '`'
        : '"' + name.replaceAll('"', '""') + '"';
}

function insertName(name) {
    sqlEditor.value?.insert(quoteIdentifier(name));
}

/** Первые строки таблицы одним кликом: самый частый первый шаг при знакомстве с данными. */
function previewTable(table) {
    sqlQuery.value = `SELECT *\nFROM ${quoteIdentifier(table.name)}\nLIMIT 100`;

    return runSqlConsoleQuery();
}

function loadHistory() {
    try {
        const stored = JSON.parse(localStorage.getItem(historyKey) || '[]');
        sqlHistory.value = Array.isArray(stored) ? stored.filter((item) => typeof item === 'string') : [];
    } catch (err) {
        sqlHistory.value = [];
    }
}

function rememberQuery(query) {
    const text = query.trim();

    sqlHistory.value = [text, ...sqlHistory.value.filter((item) => item !== text)].slice(0, HISTORY_LIMIT);

    try {
        localStorage.setItem(historyKey, JSON.stringify(sqlHistory.value));
    } catch (err) {
        // Хранилище недоступно (приватное окно) — история просто не сохранится.
    }
}

function historyLabel(query) {
    const flat = query.replace(/\s+/g, ' ');

    return flat.length > 70 ? flat.slice(0, 70) + '…' : flat;
}

function pickHistory(event) {
    const index = Number(event.target.value);

    if (Number.isInteger(index) && sqlHistory.value[index] !== undefined) {
        sqlQuery.value = sqlHistory.value[index];
        sqlEditor.value?.focus();
    }

    event.target.value = '';
}

function formatCell(value) {
    if (value === null || value === undefined) return null;

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

function downloadCsv() {
    const rows = sqlResult.value?.rows ?? [];

    if (!rows.length) return;

    const columns = Object.keys(rows[0]);
    const escape = (value) => {
        const text = formatCell(value) ?? '';

        return /[",\n\r;]/.test(text) ? '"' + text.replaceAll('"', '""') + '"' : text;
    };

    const lines = [columns.map(escape).join(',')];

    for (const row of rows) lines.push(columns.map((column) => escape(row[column])).join(','));

    // BOM, чтобы Excel не ломал кириллицу.
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = `${source.value?.name || 'query'}.csv`;
    link.click();

    URL.revokeObjectURL(url);
}

async function runSqlConsoleQuery() {
    if (sqlRunning.value || !sqlQuery.value.trim()) return;

    sqlRunning.value = true;
    sqlError.value = null;
    sqlResult.value = null;
    sqlElapsed.value = null;

    const startedAt = performance.now();

    try {
        const { data } = await api.post(`/data_source/${sourceId}/connection`, {
            query: sqlQuery.value,
        });

        sqlElapsed.value = ((performance.now() - startedAt) / 1000).toFixed(2);
        sqlResult.value = data;
        rememberQuery(sqlQuery.value);
    } catch (err) {
        sqlError.value = err.response?.data?.message || t('sourcesShow.sqlConsole.error_default');
    } finally {
        sqlRunning.value = false;
    }
}

async function openRefreshModal() {
    refreshError.value = null;
    refreshResult.value = null;
    refreshFile.value = null;
    if (refreshFileEl.value) refreshFileEl.value.value = '';
    await nextTick();
    refreshModal?.show();
}

/** Пересобрать группировку — предлагается, когда состав таблиц изменился. */
async function regroup() {
    if (regrouping.value) return;

    regrouping.value = true;

    try {
        await api.post(`/data_source/${sourceId}/grouping`, { force: 1 });
        await fetchSource();
    } catch (err) {
        pageError.value =
            err.response?.data?.message || t('sourcesShow.errors.regroup_failed');
    } finally {
        regrouping.value = false;
    }
}

function handleRefreshFile(event) {
    refreshFile.value = event.target.files[0] ?? null;
}

/**
 * Обновление данных источника.
 *
 * Google-таблица перечитывается по сохранённой ссылке — файл не нужен.
 * Для загруженного файла присылаем новую версию того же формата.
 */
async function submitRefresh() {
    if (refreshing.value) return;
    if (refreshNeedsFile.value && !refreshFile.value) {
        refreshError.value = t('sourcesShow.refresh_modal.select_file_error');
        return;
    }

    refreshing.value = true;
    refreshError.value = null;
    refreshResult.value = null;

    try {
        const payload = new FormData();
        if (refreshFile.value) payload.append('data_file', refreshFile.value);

        const { data } = await api.post(`/data_source/${sourceId}/refresh`, payload);

        // Окно не закрываем сразу: если состав таблиц изменился, это нужно
        // показать — иначе пользователь не узнает, что группировка устарела.
        refreshResult.value = data;
        await fetchSource();
    } catch (err) {
        refreshError.value =
            err.response?.data?.message || t('sourcesShow.errors.update_failed');
    } finally {
        refreshing.value = false;
    }
}

onMounted(async () => {
    loadHistory();
    fetchSqlSchema();
    await fetchSource();
    await nextTick();

    if (refreshModalEl.value) {
        // Разбор файла идёт синхронно — закрывать окно на полпути нельзя.
        refreshModal = new Modal(refreshModalEl.value, { backdrop: 'static', keyboard: false });
    }
});

onBeforeUnmount(() => {
    refreshModal?.dispose();
});
</script>

<template>
    <div class="page-wrapper">
        <!-- BEGIN PAGE HEADER -->
        <div class="page-header d-print-none">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <div class="page-pretitle">
                            <router-link :to="{ name: 'company.sources' }" class="text-reset text-decoration-none">
                                ← {{ t('sourcesShow.back_to_sources') }}
                            </router-link>
                        </div>
                        <h2 class="page-title">
                            {{ source?.name ?? t('sourcesShow.title_fallback') }}
                            <span v-if="source" class="badge bg-primary-lt ms-2">
                                {{ source.format_label }}
                            </span>
                        </h2>
                    </div>

                    <div class="col-auto ms-auto d-print-none">
                        <div class="btn-list">
                        <!-- Обновление данных доступно только у файловых источников
                             и Google-таблиц: внешняя база читается вживую. -->
                        <button v-if="canRefresh" class="btn" :class="{ 'btn-loading': refreshing }"
                                :disabled="refreshing" @click="openRefreshModal">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" class="icon icon-2">
                                <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
                                <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
                            </svg>
                            {{ t('sourcesShow.actions.refresh') }}
                        </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- END PAGE HEADER -->

        <!-- BEGIN PAGE BODY -->
        <main class="page-body">
            <div class="container-xl">
                <div v-if="pageError" class="alert alert-danger" role="alert">{{ pageError }}</div>

                <!-- Состав таблиц изменился после обновления: агент работает
                     по старому снимку схемы и новых таблиц не видит. -->
                <div v-if="groupingStale" class="alert alert-warning" role="alert">
                    <div class="d-flex flex-column flex-md-row align-items-md-center gap-2">
                        <div class="flex-fill">
                            <h4 class="alert-heading">{{ t('sourcesShow.stale.title') }}</h4>
                            <div class="alert-description">
                                {{ t('sourcesShow.stale.body') }}
                            </div>
                        </div>
                        <button v-if="canManageSources" class="btn btn-warning ms-md-3"
                                :class="{ 'btn-loading': regrouping }"
                                :disabled="regrouping" @click="regroup">
                            {{ t('sourcesShow.stale.action') }}
                        </button>
                    </div>
                </div>

                <div v-if="loading" class="card">
                    <div class="card-body">
                        <div class="progress progress-sm">
                            <div class="progress-bar progress-bar-indeterminate"></div>
                        </div>
                    </div>
                </div>

                <div v-else-if="source" class="row row-cards">
                    <!-- ЛЕВАЯ КОЛОНКА: параметры подключения и разобранная схема -->
                    <div class="col-lg-4">
                        <div class="card mb-3">
                            <div class="card-header">
                                <h3 class="card-title">{{ t('sourcesShow.connection.title') }}</h3>
                            </div>
                            <div class="card-body">
                                <!-- .datagrid — штатный компонент Tabler под пары
                                     «подпись — значение». Раньше здесь был dl.row
                                     с col-5/col-7: подписи набирались обычным
                                     текстом и по весу спорили со значениями. -->
                                <div class="datagrid">
                                    <div class="datagrid-item">
                                        <div class="datagrid-title">{{ t('sourcesShow.connection.format') }}</div>
                                        <div class="datagrid-content">{{ source.format_label }}</div>
                                    </div>

                                    <div class="datagrid-item">
                                        <div class="datagrid-title">{{ t('sourcesShow.connection.method') }}</div>
                                        <div class="datagrid-content">
                                            {{ isRemoteSource ? t('sourcesShow.connection.method_remote') : t('sourcesShow.connection.method_local') }}
                                        </div>
                                    </div>

                                    <template v-if="isRemoteSource">
                                        <div class="datagrid-item">
                                            <div class="datagrid-title">{{ t('sourcesShow.connection.host') }}</div>
                                            <div class="datagrid-content text-break">
                                                {{ source.host }}:{{ source.port }}
                                            </div>
                                        </div>

                                        <div class="datagrid-item">
                                            <div class="datagrid-title">{{ t('sourcesShow.connection.database') }}</div>
                                            <div class="datagrid-content text-break">{{ source.database }}</div>
                                        </div>
                                    </template>

                                    <div v-if="source.version" class="datagrid-item">
                                        <div class="datagrid-title">{{ t('sourcesShow.connection.version') }}</div>
                                        <div class="datagrid-content">{{ source.version }}</div>
                                    </div>

                                    <div class="datagrid-item">
                                        <div class="datagrid-title">{{ t('sourcesShow.connection.added') }}</div>
                                        <div class="datagrid-content">{{ formatDate(source.created_at) }}</div>
                                    </div>

                                    <!-- Файловый источник — снимок данных, поэтому
                                         дата обновления важнее даты добавления:
                                         дашборд на данных месячной давности выглядит
                                         так же убедительно, как на свежих. -->
                                    <div v-if="source.connection_type === 'local'" class="datagrid-item">
                                        <div class="datagrid-title">{{ t('sourcesShow.connection.data_from') }}</div>
                                        <div class="datagrid-content">
                                            {{ formatDateTime(source.refreshed_at) }}
                                        </div>
                                    </div>

                                    <div v-if="source.creator" class="datagrid-item">
                                        <div class="datagrid-title">{{ t('sourcesShow.connection.added_by') }}</div>
                                        <div class="datagrid-content">{{ source.creator.name }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">{{ t('sourcesShow.schema.title') }}</h3>
                            </div>

                            <!-- Смысловые группы строит ИИ при первом вопросе в чате. Это то, как
                                 агент видит источник, поэтому они первыми и раскрыты. -->
                            <details v-if="groups.length" class="schema-section" open>
                                <summary class="px-3 py-2 schema-summary">
                                    {{ t('sourcesShow.schema.summary', { groups: groups.length, tables: tablesCount }) }}
                                </summary>
                                <div class="list-group list-group-flush border-top">
                                    <div v-for="group in groups" :key="group.id" class="list-group-item">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-fill">
                                                <div class="font-weight-medium">{{ group.name }}</div>
                                                <div v-if="group.description" class="text-secondary small">
                                                    {{ group.description }}
                                                </div>
                                            </div>
                                            <span class="badge bg-secondary-lt ms-2">{{ group.tables_count }}</span>
                                        </div>
                                    </div>
                                </div>
                            </details>

                            <div v-else class="card-body py-2">
                                <p class="text-secondary small mb-0">{{ t('sourcesShow.schema.not_parsed') }}</p>
                            </div>

                            <!-- Все таблицы базы свёрнуты под группами: это справочник для запросов,
                                 а не первое, что нужно видеть. Без групп раскрыты — иначе в карточке
                                 нечего показать. -->
                            <details class="schema-section border-top" :open="!groups.length">
                                <summary class="px-3 py-2 schema-summary">
                                    {{ t('sourcesShow.schema.all_tables') }}<template v-if="sqlSchema.length"> · {{ sqlSchema.length }}</template>
                                </summary>

                                <!-- Браузер таблиц: что есть в источнике и как это написать в запросе.
                                     Доступен тем же, кто видит консоль: схема отдаётся по праву
                                     «manage data sources». -->
                                <template v-if="canManageSources">
                                    <div v-if="schemaLoading" class="card-body text-secondary">
                                        {{ t('sourcesShow.schema.loading') }}
                                    </div>

                                    <div v-else-if="!sqlSchema.length" class="card-body">
                                        <p class="text-secondary mb-0">{{ t('sourcesShow.schema.unavailable') }}</p>
                                    </div>

                                    <template v-else>
                                        <div class="card-body py-2 border-bottom">
                                            <input v-model="tableSearch" type="search" class="form-control form-control-sm"
                                                   :placeholder="t('sourcesShow.schema.search_placeholder')"
                                                   :aria-label="t('sourcesShow.schema.search_placeholder')" />
                                        </div>

                                        <div class="list-group list-group-flush schema-browser">
                                            <div v-if="!filteredTables.length" class="list-group-item text-secondary">
                                                {{ t('sourcesShow.schema.no_match') }}
                                            </div>

                                            <div v-for="table in filteredTables" :key="table.name" class="list-group-item p-0">
                                                <div class="schema-table d-flex align-items-center gap-1 ps-3 pe-2 py-1">
                                                    <button type="button" class="schema-table-name flex-fill"
                                                            :aria-expanded="isExpanded(table)"
                                                            :title="table.name"
                                                            @click="toggleTable(table)">
                                                        <span class="schema-caret" :class="{ open: isExpanded(table) }" aria-hidden="true">▸</span>
                                                        <span class="text-truncate font-weight-medium">{{ table.name }}</span>
                                                        <span class="text-secondary small ms-2 flex-shrink-0">{{ table.columns.length }}</span>
                                                    </button>
                                                    <button type="button" class="schema-action"
                                                            :title="t('sourcesShow.schema.insert_name')"
                                                            :aria-label="t('sourcesShow.schema.insert_name')"
                                                            @click="insertName(table.name)">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                             stroke-linejoin="round" aria-hidden="true" focusable="false">
                                                            <path d="M12 5v14" /><path d="M5 12h14" />
                                                        </svg>
                                                    </button>
                                                    <button type="button" class="schema-action schema-action-primary"
                                                            :title="t('sourcesShow.schema.preview')"
                                                            :aria-label="t('sourcesShow.schema.preview')"
                                                            :disabled="sqlRunning"
                                                            @click="previewTable(table)">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                             stroke-linejoin="round" aria-hidden="true" focusable="false">
                                                            <path d="M7 4v16l13 -8z" />
                                                        </svg>
                                                    </button>
                                                </div>

                                                <ul v-if="isExpanded(table)" class="list-unstyled schema-columns mb-0">
                                                    <li v-for="column in table.columns" :key="column.name">
                                                        <button type="button" class="schema-column d-flex w-100 text-start px-3 py-1"
                                                                :title="t('sourcesShow.schema.insert_name')"
                                                                @click="insertName(column.name)">
                                                            <span class="flex-fill text-truncate">{{ column.name }}</span>
                                                            <span class="text-secondary small ms-2 flex-shrink-0">{{ column.type }}</span>
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </template>
                                </template>

                                <div v-else class="card-body">
                                    <p class="text-secondary mb-0">{{ t('sourcesShow.sqlConsole.no_permission') }}</p>
                                </div>
                            </details>
                        </div>
                    </div>

                    <!-- ПРАВАЯ КОЛОНКА: SQL-консоль источника -->
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <div class="w-100">
                                    <h3 class="card-title mb-1">{{ t('sourcesShow.sqlConsole.title') }}</h3>
                                    <div class="text-secondary small">{{ t('sourcesShow.sqlConsole.subtitle') }}</div>
                                </div>
                            </div>

                            <!-- Запрос идёт напрямую в базу источника через ReadOnlyQueryRunner:
                                 только SELECT/WITH, один запрос, до 200 строк. Право то же,
                                 что у обновления данных, — «manage data sources». -->
                            <div v-if="!canManageSources" class="card-body">
                                <p class="text-secondary mb-0">{{ t('sourcesShow.sqlConsole.no_permission') }}</p>
                            </div>

                            <template v-else>
                                <div class="card-body">
                                    <SqlEditor
                                        ref="sqlEditor"
                                        v-model="sqlQuery"
                                        :rows="6"
                                        :max-rows="20"
                                        :schema="sqlSchema"
                                        :dialect="sqlDialect"
                                        :placeholder="t('sourcesShow.sqlConsole.placeholder')"
                                        :aria-label="t('sourcesShow.sqlConsole.aria')"
                                        @run="runSqlConsoleQuery"
                                    />

                                    <div class="d-flex align-items-center gap-2 mt-2">
                                        <button type="button" class="btn btn-primary"
                                                :class="{ 'btn-loading': sqlRunning }"
                                                :disabled="sqlRunning || !sqlQuery.trim()"
                                                @click="runSqlConsoleQuery">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
                                                 focusable="false" class="icon icon-2">
                                                <path d="M7 4v16l13 -8z" />
                                            </svg>
                                            {{ t('sourcesShow.sqlConsole.run_button') }}
                                        </button>
                                        <select v-if="sqlHistory.length" class="form-select form-select-sm w-auto"
                                                :aria-label="t('sourcesShow.sqlConsole.history_label')"
                                                @change="pickHistory">
                                            <option value="">{{ t('sourcesShow.sqlConsole.history_label') }}</option>
                                            <option v-for="(item, index) in sqlHistory" :key="index" :value="index">
                                                {{ historyLabel(item) }}
                                            </option>
                                        </select>
                                        <span class="text-secondary small ms-auto d-none d-md-inline">{{ t('sourcesShow.sqlConsole.hint') }}</span>
                                    </div>

                                    <div v-if="sqlError" class="alert alert-danger mt-3 mb-0" role="alert">
                                        {{ sqlError }}
                                    </div>
                                </div>

                                <template v-if="sqlResult">
                                    <div v-if="!sqlResult.rows.length" class="card-body border-top">
                                        <p class="text-secondary mb-0">{{ t('sourcesShow.sqlConsole.no_rows') }}</p>
                                    </div>

                                    <div v-else class="table-responsive sql-console-result border-top">
                                        <table class="table table-vcenter card-table table-sm">
                                            <thead>
                                            <tr>
                                                <th v-for="col in Object.keys(sqlResult.rows[0])" :key="col">{{ col }}</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <tr v-for="(row, index) in sqlResult.rows" :key="index">
                                                <td v-for="(value, key) in row" :key="key">
                                                    <span v-if="formatCell(value) === null" class="text-secondary fst-italic">NULL</span>
                                                    <template v-else>{{ formatCell(value) }}</template>
                                                </td>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="card-footer d-flex align-items-center text-secondary small">
                                        <span>
                                            {{ t('sourcesShow.sqlConsole.rows_label', { count: sqlResult.row_count }) }}
                                            <span v-if="sqlResult.truncated"> · {{ t('sourcesShow.sqlConsole.truncated_label') }}</span>
                                            <span v-if="sqlElapsed"> · {{ t('sourcesShow.sqlConsole.elapsed_label', { seconds: sqlElapsed }) }}</span>
                                        </span>
                                        <button v-if="sqlResult.rows.length" type="button" class="btn btn-sm ms-auto"
                                                @click="downloadCsv">
                                            {{ t('sourcesShow.sqlConsole.export_csv') }}
                                        </button>
                                    </div>
                                </template>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        <!-- END PAGE BODY -->

        <!-- BEGIN MODAL: обновление данных -->
        <div ref="refreshModalEl" class="modal modal-blur fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ t('sourcesShow.refresh_modal.title') }}</h5>
                        <button v-if="!refreshing" type="button" class="btn-close" data-bs-dismiss="modal"
                                :aria-label="t('sourcesShow.refresh_modal.close')"></button>
                    </div>

                    <div v-if="refreshing" class="modal-body text-center py-4">
                        <div class="spinner-border text-primary mb-3" role="status"></div>
                        <h3 class="mb-1">
                            {{ isRemoteSource ? t('sourcesShow.refresh_modal.reading_schema') : t('sourcesShow.refresh_modal.reading_data') }}
                        </h3>
                        <div class="text-secondary">
                            {{ t('sourcesShow.refresh_modal.reading_note') }}
                        </div>
                    </div>

                    <!-- Итог: главное здесь — изменился ли состав таблиц -->
                    <div v-else-if="refreshResult" class="modal-body">
                        <div class="alert alert-success" role="alert">{{ refreshResult.message }}</div>

                        <template v-if="refreshResult.schema_changed">
                            <div v-if="refreshResult.added_tables.length" class="mb-2">
                                <div class="text-secondary mb-1">{{ t('sourcesShow.refresh_modal.added_tables') }}</div>
                                <div class="d-flex flex-wrap gap-1">
                                    <span v-for="tbl in refreshResult.added_tables" :key="tbl"
                                          class="badge bg-green-lt">{{ tbl }}</span>
                                </div>
                            </div>
                            <div v-if="refreshResult.removed_tables.length" class="mb-2">
                                <div class="text-secondary mb-1">{{ t('sourcesShow.refresh_modal.removed_tables') }}</div>
                                <div class="d-flex flex-wrap gap-1">
                                    <span v-for="tbl in refreshResult.removed_tables" :key="tbl"
                                          class="badge bg-red-lt">{{ tbl }}</span>
                                </div>
                            </div>
                            <div class="alert alert-warning mb-0" role="alert">
                                {{ t('sourcesShow.refresh_modal.schema_changed_warning') }}
                            </div>
                        </template>

                        <p v-else class="text-secondary mb-0">{{ t('sourcesShow.refresh_modal.no_change') }}</p>
                    </div>

                    <div v-else class="modal-body">
                        <template v-if="isRemoteSource">
                            <p class="mb-0" v-html="t('sourcesShow.refresh_modal.remote_info')"></p>
                            <p class="text-secondary mt-2 mb-0">
                                {{ t('sourcesShow.refresh_modal.remote_note') }}
                            </p>
                        </template>

                        <template v-else-if="isGoogleSheet">
                            <p class="mb-0">
                                {{ t('sourcesShow.refresh_modal.google_sheet_info') }}
                            </p>
                        </template>

                        <template v-else>
                            <div class="mb-1">
                                <label class="form-label required">{{ t('sourcesShow.refresh_modal.file_label') }}</label>
                                <input ref="refreshFileEl" type="file" class="form-control"
                                       @change="handleRefreshFile" />
                                <div class="form-hint" v-html="t('sourcesShow.refresh_modal.file_hint', { format: source?.format_label })"></div>
                            </div>
                        </template>

                        <div v-if="refreshError" class="alert alert-danger mt-3 mb-0" role="alert">
                            {{ refreshError }}
                        </div>
                    </div>

                    <div v-if="!refreshing" class="modal-footer">
                        <template v-if="refreshResult">
                            <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">
                                {{ t('sourcesShow.refresh_modal.close_btn') }}
                            </button>
                            <button v-if="refreshResult.schema_changed" type="button"
                                    class="btn btn-primary ms-auto" data-bs-dismiss="modal" @click="regroup">
                                {{ t('sourcesShow.refresh_modal.rebuild_grouping') }}
                            </button>
                        </template>
                        <template v-else>
                            <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">
                                {{ t('sourcesShow.refresh_modal.cancel') }}
                            </button>
                            <button type="button" class="btn btn-primary ms-auto" @click="submitRefresh">
                                {{ t('sourcesShow.refresh_modal.submit') }}
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
        <!-- END MODAL -->
    </div>
</template>

<style scoped>
.sql-console-result {
    max-height: 420px;
    overflow: auto;
}

/* Шапка остаётся на виду, пока листаешь длинный результат. */
.sql-console-result thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--tblr-bg-surface, #fff);
    box-shadow: 0 1px 0 var(--tblr-border-color, #e6e7e9);
}

.schema-browser {
    max-height: 460px;
    overflow-y: auto;
}

.schema-table-name {
    display: flex;
    align-items: center;
    min-width: 0;
    padding: 6px 0;
    border: 0;
    background: transparent;
    color: inherit;
    text-align: left;
}

.schema-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: var(--tblr-secondary, #667382);
}

.schema-action:hover:not(:disabled) {
    background: rgba(var(--tblr-primary-rgb, 32, 107, 196), 0.1);
    color: var(--tblr-primary, #206bc4);
}

.schema-action:disabled {
    opacity: 0.4;
}

.schema-caret {
    display: inline-block;
    width: 1em;
    transition: transform 0.12s;
}

.schema-caret.open {
    transform: rotate(90deg);
}

.schema-columns {
    background: var(--tblr-bg-surface-secondary, #f6f8fb);
    padding-bottom: 4px;
}

.schema-column {
    border: 0;
    background: transparent;
    font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
    font-size: 12.5px;
    color: inherit;
    padding-left: 2rem !important;
}

.schema-column:hover {
    background: rgba(var(--tblr-primary-rgb, 32, 107, 196), 0.08);
}

.schema-summary {
    cursor: pointer;
    font-weight: 500;
}
</style>
