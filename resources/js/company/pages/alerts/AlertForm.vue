<script setup>
import { ref, reactive, computed, onMounted, inject } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useI18n } from "vue-i18n";
import api from "../../api.js";

/**
 * Заведение и правка алерта — отдельной страницей, а не модалкой
 * (по образцу settings/UserForm.vue): условие занимает много места и
 * заслуживает ссылки и кнопки «назад», как и правка сотрудника.
 *
 * Три способа задать условие — вкладки: конструктор метрик (платформа сама
 * собирает SQL), свой SQL и Python. ИИ здесь не участвует нигде: то, что
 * алерт проверит, целиком пишет человек.
 */

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const toast = inject("toast", null);

const workspaceId = computed(() => route.params.workspace);
const isCreate = computed(() => route.name === "company.alert.create");
const alertId = computed(() => (isCreate.value ? null : Number(route.params.alert)));

const currentUser = JSON.parse(localStorage.getItem("user") || "null");
const permissions = computed(() => currentUser?.permissions ?? []);
const canWriteCode = computed(() => permissions.value.includes("write alert code"));

const loading = ref(true);
const loadError = ref(null);
const saving = ref(false);
const formError = ref(null);
const formErrors = ref({});

const schema = ref({ tables: [], aggregates: {}, operators: {}, condition_operators: [], on_empty_options: [], intervals: [] });
const employees = ref([]);
const employeesAvailable = ref(true);

const form = reactive({
    title: "",
    description: "",
    mode: "builder",
    builder: { table: "", dimensions: [], metrics: [{ agg: "count", column: "", label: "" }], filters: [], limit: 100 },
    query: "",
    code: "",
    condition: { kind: "rows", op: ">", threshold: 0, column: "", metric_index: null, on_empty: "ok" },
    interval_minutes: 60,
    is_active: true,
    recipients: { users: [], emails: [] },
    emailsText: "",
    repeat_after_minutes: 1440,
    notify_on_resolve: true,
});

const tableColumns = computed(() => {
    const table = schema.value.tables.find((t) => t.name === form.builder.table);
    return table?.columns ?? [];
});

const metricLabels = computed(() =>
    form.builder.metrics.map((m, i) => m.label || m.column || t("alerts.form.metric_fallback", { n: i + 1 }))
);

function addMetric() {
    form.builder.metrics.push({ agg: "count", column: "", label: "" });
}

function removeMetric(index) {
    if (form.builder.metrics.length <= 1) return;
    form.builder.metrics.splice(index, 1);
}

function addDimension() {
    form.builder.dimensions.push({ column: "" });
}

function removeDimension(index) {
    form.builder.dimensions.splice(index, 1);
}

function addFilter() {
    form.builder.filters.push({ column: "", op: "=", value: "" });
}

function removeFilter(index) {
    form.builder.filters.splice(index, 1);
}

function needsColumn(agg) {
    return agg !== "count";
}

// --- Загрузка -----------------------------------------------------------

async function loadSchema() {
    const { data } = await api.get(`/workspaces/${workspaceId.value}/alerts/schema`);
    schema.value = data;
}

async function loadEmployees() {
    try {
        const { data } = await api.get("/settings/users");
        employees.value = (data.users ?? []).filter((u) => u.is_active);
    } catch {
        // Нет права 'view users' — список сотрудников просто не показываем,
        // получатель всё равно может быть задан произвольным адресом.
        employeesAvailable.value = false;
    }
}

async function loadAlert() {
    const { data } = await api.get(`/alerts/${alertId.value}`);

    form.title = data.title;
    form.description = data.description ?? "";
    form.mode = data.mode;
    form.builder = data.builder ?? form.builder;
    form.query = data.query ?? "";
    form.code = data.code ?? "";
    // Слияние, а не замена: у алертов, сохранённых до появления
    // metric_index, этого поля в данных нет — дефолт (null) должен остаться,
    // а не пропасть вместе с остальными ключами условия.
    if (data.condition) form.condition = { ...form.condition, ...data.condition };
    form.interval_minutes = data.interval_minutes;
    form.is_active = data.is_active;
    form.recipients.users = data.recipients?.users ?? [];
    form.recipients.emails = data.recipients?.emails ?? [];
    form.emailsText = form.recipients.emails.join(", ");
    form.repeat_after_minutes = data.repeat_after_minutes;
    form.notify_on_resolve = data.notify_on_resolve;
}

async function load() {
    loading.value = true;
    loadError.value = null;

    try {
        await Promise.all([loadSchema(), loadEmployees(), isCreate.value ? Promise.resolve() : loadAlert()]);
    } catch (err) {
        loadError.value = err.response?.data?.message || t("alerts.form.errors.load_failed");
    } finally {
        loading.value = false;
    }
}

// --- Проверка (превью) ---------------------------------------------------

const previewing = ref(false);
const preview = ref(null);
const previewError = ref(null);

function previewPayload() {
    return {
        mode: form.mode,
        builder: form.mode === "builder" ? form.builder : undefined,
        query: form.mode === "sql" ? form.query : undefined,
        code: form.mode === "python" ? form.code : undefined,
        condition: form.mode !== "python" ? form.condition : undefined,
    };
}

async function runPreview() {
    previewing.value = true;
    preview.value = null;
    previewError.value = null;

    try {
        const { data } = await api.post(`/workspaces/${workspaceId.value}/alerts/preview`, previewPayload());
        preview.value = data;
    } catch (err) {
        previewError.value = err.response?.data?.message || t("alerts.form.errors.preview_failed");
    } finally {
        previewing.value = false;
    }
}

// --- Сохранение -----------------------------------------------------------

function collectEmails() {
    return form.emailsText
        .split(/[,;\s]+/)
        .map((e) => e.trim())
        .filter(Boolean);
}

async function submit() {
    if (saving.value) return;

    saving.value = true;
    formError.value = null;
    formErrors.value = {};

    const payload = {
        title: form.title,
        description: form.description || null,
        mode: form.mode,
        interval_minutes: form.interval_minutes,
        is_active: form.is_active,
        recipients: {
            users: form.recipients.users,
            emails: collectEmails(),
        },
        repeat_after_minutes: form.repeat_after_minutes,
        notify_on_resolve: form.notify_on_resolve,
    };

    if (form.mode === "builder") payload.builder = form.builder;
    if (form.mode === "sql") payload.query = form.query;
    if (form.mode === "python") payload.code = form.code;
    if (form.mode !== "python") payload.condition = form.condition;

    try {
        if (isCreate.value) {
            await api.post(`/workspaces/${workspaceId.value}/alerts`, payload);
        } else {
            await api.put(`/alerts/${alertId.value}`, payload);
        }

        toast?.value?.success?.(t("alerts.form.saved"));
        router.push({ name: "company.workspace", params: { workspace: workspaceId.value }, query: { tab: "alerts" } });
    } catch (err) {
        const data = err.response?.data;

        if (data?.errors) {
            formErrors.value = data.errors;
        } else {
            formError.value = data?.message || t("alerts.form.errors.save_failed");
        }
    } finally {
        saving.value = false;
    }
}

function cancel() {
    router.push({ name: "company.workspace", params: { workspace: workspaceId.value }, query: { tab: "alerts" } });
}

onMounted(load);
</script>

<template>
    <div class="page-wrapper">
        <div class="page-header d-print-none">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <div class="page-pretitle">
                            <router-link :to="{ name: 'company.workspace', params: { workspace: workspaceId }, query: { tab: 'alerts' } }" class="text-reset">
                                {{ t("alerts.form.breadcrumb") }}
                            </router-link>
                        </div>
                        <h2 class="page-title">{{ isCreate ? t("alerts.form.title_create") : t("alerts.form.title_edit") }}</h2>
                    </div>
                    <div class="col-auto ms-auto d-print-none">
                        <button class="btn btn-link link-secondary" type="button" @click="cancel">
                            {{ t("alerts.form.back") }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <main class="page-body">
            <div class="container-xl">
                <div v-if="loading" class="card">
                    <div class="card-body">
                        <div class="progress progress-sm">
                            <div class="progress-bar progress-bar-indeterminate"></div>
                        </div>
                    </div>
                </div>

                <div v-else-if="loadError" class="alert alert-danger">{{ loadError }}</div>

                <form v-else class="row g-3" @submit.prevent="submit">
                    <div class="col-lg-8">
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label required">{{ t("alerts.form.fields.title") }}</label>
                                    <input v-model="form.title" type="text" class="form-control" :class="{ 'is-invalid': formErrors.title }" />
                                    <div v-if="formErrors.title" class="invalid-feedback">{{ formErrors.title[0] }}</div>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label">{{ t("alerts.form.fields.description") }}</label>
                                    <textarea v-model="form.description" class="form-control" rows="2"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Условие: три вкладки -->
                        <div class="card mb-3">
                            <div class="card-header">
                                <ul class="nav nav-tabs card-header-tabs">
                                    <li class="nav-item">
                                        <a class="nav-link" :class="{ active: form.mode === 'builder' }" href="#" @click.prevent="form.mode = 'builder'">
                                            {{ t("alerts.form.tabs.builder") }}
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" :class="{ active: form.mode === 'sql', disabled: !canWriteCode }"
                                           href="#" @click.prevent="canWriteCode && (form.mode = 'sql')">
                                            {{ t("alerts.form.tabs.sql") }}
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" :class="{ active: form.mode === 'python', disabled: !canWriteCode }"
                                           href="#" @click.prevent="canWriteCode && (form.mode = 'python')">
                                            {{ t("alerts.form.tabs.python") }}
                                        </a>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">
                                <p v-if="form.mode !== 'builder' && !canWriteCode" class="alert alert-warning">
                                    {{ t("alerts.form.no_code_permission") }}
                                </p>

                                <!-- Конструктор метрик -->
                                <template v-if="form.mode === 'builder'">
                                    <div class="mb-3">
                                        <label class="form-label required">{{ t("alerts.form.builder.table") }}</label>
                                        <select v-model="form.builder.table" class="form-select">
                                            <option value="" disabled>{{ t("alerts.form.builder.table_placeholder") }}</option>
                                            <option v-for="tbl in schema.tables" :key="tbl.name" :value="tbl.name">{{ tbl.name }}</option>
                                        </select>
                                        <div class="form-hint">{{ t("alerts.form.builder.table_hint") }}</div>
                                    </div>

                                    <label class="form-label">{{ t("alerts.form.builder.metrics") }}</label>
                                    <div v-for="(metric, index) in form.builder.metrics" :key="'m'+index" class="row g-2 mb-2">
                                        <div class="col-3">
                                            <select v-model="metric.agg" class="form-select form-select-sm">
                                                <option v-for="(label, key) in schema.aggregates" :key="key" :value="key">{{ label }}</option>
                                            </select>
                                        </div>
                                        <div class="col-3">
                                            <select v-if="needsColumn(metric.agg)" v-model="metric.column" class="form-select form-select-sm">
                                                <option value="" disabled>{{ t("alerts.form.builder.column_placeholder") }}</option>
                                                <option v-for="col in tableColumns" :key="col.name" :value="col.name">{{ col.name }}</option>
                                            </select>
                                            <span v-else class="text-secondary small d-block pt-2">{{ t("alerts.form.builder.all_rows") }}</span>
                                        </div>
                                        <div class="col-4">
                                            <input v-model="metric.label" type="text" class="form-control form-control-sm"
                                                   :placeholder="t('alerts.form.builder.label_placeholder')" />
                                        </div>
                                        <div class="col-2">
                                            <button type="button" class="btn btn-sm btn-icon" :disabled="form.builder.metrics.length <= 1"
                                                    @click="removeMetric(index)">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M18 6l-12 12" /><path d="M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-link mb-3 px-0" @click="addMetric">
                                        + {{ t("alerts.form.builder.add_metric") }}
                                    </button>

                                    <label class="form-label">{{ t("alerts.form.builder.dimensions") }}</label>
                                    <div v-for="(dim, index) in form.builder.dimensions" :key="'d'+index" class="row g-2 mb-2">
                                        <div class="col-9">
                                            <select v-model="dim.column" class="form-select form-select-sm">
                                                <option value="" disabled>{{ t("alerts.form.builder.column_placeholder") }}</option>
                                                <option v-for="col in tableColumns" :key="col.name" :value="col.name">{{ col.name }}</option>
                                            </select>
                                        </div>
                                        <div class="col-3">
                                            <button type="button" class="btn btn-sm btn-icon" @click="removeDimension(index)">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M18 6l-12 12" /><path d="M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    <button v-if="!form.builder.dimensions.length" type="button" class="btn btn-sm btn-link mb-3 px-0" @click="addDimension">
                                        + {{ t("alerts.form.builder.add_dimension") }}
                                    </button>

                                    <label class="form-label">{{ t("alerts.form.builder.filters") }}</label>
                                    <div v-for="(filter, index) in form.builder.filters" :key="'f'+index" class="row g-2 mb-2">
                                        <div class="col-4">
                                            <select v-model="filter.column" class="form-select form-select-sm">
                                                <option value="" disabled>{{ t("alerts.form.builder.column_placeholder") }}</option>
                                                <option v-for="col in tableColumns" :key="col.name" :value="col.name">{{ col.name }}</option>
                                            </select>
                                        </div>
                                        <div class="col-3">
                                            <select v-model="filter.op" class="form-select form-select-sm">
                                                <option v-for="(label, key) in schema.operators" :key="key" :value="key">{{ label }}</option>
                                            </select>
                                        </div>
                                        <div class="col-3">
                                            <input v-model="filter.value" type="text" class="form-control form-control-sm" />
                                        </div>
                                        <div class="col-2">
                                            <button type="button" class="btn btn-sm btn-icon" @click="removeFilter(index)">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M18 6l-12 12" /><path d="M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-link px-0" @click="addFilter">
                                        + {{ t("alerts.form.builder.add_filter") }}
                                    </button>
                                </template>

                                <!-- SQL -->
                                <template v-else-if="form.mode === 'sql'">
                                    <label class="form-label">{{ t("alerts.form.sql.label") }}</label>
                                    <textarea v-model="form.query" class="form-control font-monospace" rows="8"
                                              :disabled="!canWriteCode" placeholder="SELECT id FROM orders WHERE status = 'failed'"></textarea>
                                    <div class="form-hint">{{ t("alerts.form.sql.hint") }}</div>
                                </template>

                                <!-- Python -->
                                <template v-else>
                                    <label class="form-label">{{ t("alerts.form.python.label") }}</label>
                                    <textarea v-model="form.code" class="form-control font-monospace" rows="12" :disabled="!canWriteCode"
                                              placeholder="def main():
    rows = query(&quot;SELECT COUNT(*) AS c FROM orders WHERE status = 'failed'&quot;)
    failed = rows[0][0]
    print(json.dumps({
        &quot;triggered&quot;: failed > 0,
        &quot;value&quot;: failed,
        &quot;message&quot;: f&quot;Проваленных заказов: {failed}&quot;,
    }))"></textarea>
                                    <div class="form-hint">{{ t("alerts.form.python.hint") }}</div>
                                </template>

                                <div class="mt-3">
                                    <button type="button" class="btn" :class="{ 'btn-loading': previewing }" @click="runPreview">
                                        {{ t("alerts.form.preview_button") }}
                                    </button>
                                </div>

                                <div v-if="previewError" class="alert alert-danger mt-3">{{ previewError }}</div>

                                <div v-if="preview" class="mt-3">
                                    <div v-if="preview.sql" class="mb-2">
                                        <div class="form-hint mb-1">{{ t("alerts.form.preview.sql_label") }}</div>
                                        <pre class="bg-secondary-lt p-2 rounded small mb-0" style="white-space:pre-wrap;">{{ preview.sql }}</pre>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span v-if="preview.triggered === true" class="badge bg-red-lt">{{ t("alerts.form.preview.would_trigger") }}</span>
                                        <span v-else-if="preview.triggered === false" class="badge bg-green-lt">{{ t("alerts.form.preview.would_not_trigger") }}</span>
                                        <span v-if="preview.value !== undefined && preview.value !== null" class="text-secondary small">
                                            {{ t("alerts.form.preview.value") }}: {{ preview.value }}
                                        </span>
                                    </div>
                                    <div v-if="preview.message" class="text-secondary small mb-2">{{ preview.message }}</div>
                                    <div v-if="preview.rows && preview.rows.length" class="table-responsive">
                                        <table class="table table-sm card-table">
                                            <thead>
                                                <tr><th v-for="col in Object.keys(preview.rows[0])" :key="col">{{ col }}</th></tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="(row, i) in preview.rows" :key="i">
                                                    <td v-for="col in Object.keys(preview.rows[0])" :key="col">{{ row[col] }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Условие срабатывания -->
                        <div v-if="form.mode !== 'python'" class="card mb-3">
                            <div class="card-header"><h3 class="card-title">{{ t("alerts.form.condition.title") }}</h3></div>
                            <div class="card-body row g-2 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label">{{ t("alerts.form.condition.kind") }}</label>
                                    <select v-model="form.condition.kind" class="form-select">
                                        <option value="rows">{{ t("alerts.form.condition.kind_rows") }}</option>
                                        <option value="value">{{ t("alerts.form.condition.kind_value") }}</option>
                                    </select>
                                </div>
                                <div v-if="form.condition.kind === 'value'" class="col-md-3">
                                    <label class="form-label">{{ t("alerts.form.condition.column") }}</label>
                                    <!-- Builder: выбирается МЕТРИКА (её номер), а не имя колонки —
                                         имя в SQL решает сам конструктор, и сервер сам подставляет
                                         его при сохранении (см. AlertQueryBuilder::resolveConditionColumn).
                                         Раньше здесь угадывался текст алиаса на глаз, и он расходился
                                         с тем, что реально собирал конструктор. -->
                                    <select v-if="form.mode === 'builder'" v-model.number="form.condition.metric_index" class="form-select">
                                        <option :value="null" disabled>{{ t("alerts.form.condition.column_placeholder") }}</option>
                                        <option v-for="(label, i) in metricLabels" :key="i" :value="i">{{ label }}</option>
                                    </select>
                                    <input v-else v-model="form.condition.column" type="text" class="form-control" />
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">{{ t("alerts.form.condition.op") }}</label>
                                    <select v-model="form.condition.op" class="form-select">
                                        <option v-for="op in schema.condition_operators" :key="op" :value="op">{{ op }}</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">{{ t("alerts.form.condition.threshold") }}</label>
                                    <input v-model.number="form.condition.threshold" type="number" step="any" class="form-control" />
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">{{ t("alerts.form.condition.on_empty") }}</label>
                                    <select v-model="form.condition.on_empty" class="form-select">
                                        <option v-for="opt in schema.on_empty_options" :key="opt" :value="opt">
                                            {{ t(`alerts.form.condition.on_empty_${opt}`) }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="card mb-3">
                            <div class="card-header"><h3 class="card-title">{{ t("alerts.form.schedule.title") }}</h3></div>
                            <div class="card-body">
                                <label class="form-label">{{ t("alerts.form.schedule.interval") }}</label>
                                <select v-model.number="form.interval_minutes" class="form-select mb-3">
                                    <option v-for="m in schema.intervals" :key="m" :value="m">
                                        {{ t(`alerts.intervals.${ {15:'m15',30:'m30',60:'h1',180:'h3',360:'h6',720:'h12',1440:'h24'}[m] || 'minutes' }`, { count: m }) }}
                                    </option>
                                </select>

                                <label class="form-check form-switch">
                                    <input v-model="form.is_active" class="form-check-input" type="checkbox" />
                                    <span class="form-check-label">{{ t("alerts.form.schedule.is_active") }}</span>
                                </label>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-header"><h3 class="card-title">{{ t("alerts.form.recipients.title") }}</h3></div>
                            <div class="card-body">
                                <div v-if="employeesAvailable && employees.length" class="mb-3" style="max-height:220px;overflow-y:auto;">
                                    <label v-for="employee in employees" :key="employee.id" class="form-check">
                                        <input v-model="form.recipients.users" class="form-check-input" type="checkbox" :value="employee.id" />
                                        <span class="form-check-label">{{ employee.name }} <span class="text-secondary">({{ employee.email }})</span></span>
                                    </label>
                                </div>

                                <label class="form-label">{{ t("alerts.form.recipients.emails") }}</label>
                                <textarea v-model="form.emailsText" class="form-control" rows="2" :class="{ 'is-invalid': formErrors['recipients.emails.0'] }"
                                          :placeholder="t('alerts.form.recipients.emails_placeholder')"></textarea>
                                <div class="form-hint">{{ t("alerts.form.recipients.emails_hint") }}</div>
                                <div v-if="formErrors.recipients" class="text-danger small mt-1">{{ formErrors.recipients[0] }}</div>
                            </div>
                        </div>

                        <div class="card mb-3">
                            <div class="card-header"><h3 class="card-title">{{ t("alerts.form.repeat.title") }}</h3></div>
                            <div class="card-body">
                                <label class="form-label">{{ t("alerts.form.repeat.after_minutes") }}</label>
                                <input v-model.number="form.repeat_after_minutes" type="number" min="15" class="form-control mb-3" />
                                <div class="form-hint mb-3">{{ t("alerts.form.repeat.after_minutes_hint") }}</div>

                                <label class="form-check form-switch">
                                    <input v-model="form.notify_on_resolve" class="form-check-input" type="checkbox" />
                                    <span class="form-check-label">{{ t("alerts.form.repeat.notify_on_resolve") }}</span>
                                </label>
                            </div>
                        </div>

                        <div v-if="formError" class="alert alert-danger">{{ formError }}</div>

                        <button type="submit" class="btn btn-primary w-100" :class="{ 'btn-loading': saving }">
                            {{ isCreate ? t("alerts.form.create_button") : t("alerts.form.save_button") }}
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</template>
