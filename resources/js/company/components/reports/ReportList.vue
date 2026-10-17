<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, inject } from "vue";
import { Offcanvas } from "bootstrap";
import { useI18n } from "vue-i18n";
import api from "../../api.js";

/**
 * Вкладка «Отчёты» рабочего пространства: дашборд в PDF или Word на почту
 * по расписанию — раз в день, неделю или месяц.
 *
 * Список и форма живут здесь же (форма короткая, отдельная страница не нужна).
 * Сам отчёт делает сервер: тот же экспорт, что и кнопка «Экспорт» у дашборда,
 * только запускается по расписанию (см. ScheduledReportJob).
 */

const props = defineProps({
    workspaceId: { type: [Number, String], required: true },
    dashboards: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const { t } = useI18n();
const toast = inject("toast", null);

const reports = ref([]);
const loading = ref(true);
const error = ref(null);
const runningId = ref(null);

// --- Подписи ---------------------------------------------------------------

function formatDate(value) {
    if (!value) return "—";

    return new Date(value).toLocaleString();
}

function dayLabel(report) {
    return report.day_of_month === 0
        ? t("reports.day_last")
        : t("reports.day_number", { day: report.day_of_month });
}

function scheduleLabel(report) {
    const params = {
        time: report.send_time,
        day: report.frequency === "monthly" ? dayLabel(report) : t(`reports.weekdays.${report.weekday}`),
    };

    return t(`reports.schedule.${report.frequency}`, params);
}

const STATUS_BADGE = {
    sent: { key: "reports.status.sent", cls: "bg-green-lt" },
    failed: { key: "reports.status.failed", cls: "bg-red-lt" },
    running: { key: "reports.status.running", cls: "bg-blue-lt" },
};

function statusBadge(status) {
    const entry = STATUS_BADGE[status];

    return entry ? { text: t(entry.key), cls: entry.cls } : null;
}

// --- Загрузка и действия ---------------------------------------------------

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const { data } = await api.get(`/workspaces/${props.workspaceId}/scheduled-reports`);
        reports.value = data;
    } catch (err) {
        error.value = err.response?.data?.message || t("reports.errors.load_failed");
    } finally {
        loading.value = false;
    }
}

async function toggleReport(report) {
    try {
        const { data } = await api.post(`/scheduled-reports/${report.id}/toggle`);
        Object.assign(report, data);
    } catch (err) {
        toast?.value?.error?.(err.response?.data?.message || t("reports.errors.toggle_failed"));
    }
}

async function removeReport(report) {
    if (!confirm(t("reports.confirm_delete", { title: report.title }))) return;

    try {
        await api.delete(`/scheduled-reports/${report.id}`);
        reports.value = reports.value.filter((item) => item.id !== report.id);
    } catch (err) {
        toast?.value?.error?.(err.response?.data?.message || t("reports.errors.delete_failed"));
    }
}

async function sendNow(report) {
    if (runningId.value) return;

    if (!confirm(t("reports.confirm_send_now", { title: report.title }))) return;

    runningId.value = report.id;

    try {
        await api.post(`/scheduled-reports/${report.id}/run`);
        toast?.value?.info?.(t("reports.send_started"));

        // Отправка идёт в очереди — через несколько секунд обновим статус.
        setTimeout(load, 8000);
    } catch (err) {
        toast?.value?.error?.(err.response?.data?.message || t("reports.errors.run_failed"));
    } finally {
        runningId.value = null;
    }
}

// --- Форма -----------------------------------------------------------------

const formEl = ref(null);
let formOffcanvas = null;

const editingId = ref(null);
const saving = ref(false);
const formErrors = ref({});
const formMessage = ref(null);
const employees = ref([]);

const browserTimezone = (() => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";
    } catch {
        return "UTC";
    }
})();

const timezones = (() => {
    try {
        const all = Intl.supportedValuesOf("timeZone");

        return all.includes(browserTimezone) ? all : [browserTimezone, ...all];
    } catch {
        return [browserTimezone];
    }
})();

function emptyForm() {
    return {
        title: "",
        dashboard_id: props.dashboards[0]?.id ?? null,
        format: "pdf",
        frequency: "weekly",
        weekday: 1,
        day_of_month: 1,
        send_time: "09:00",
        timezone: browserTimezone,
        users: [],
        emailsText: "",
        is_active: true,
    };
}

const form = ref(emptyForm());

const dashboardOptions = computed(() =>
    props.dashboards.map((item) => ({
        id: item.id,
        name: item.name || t("workspacePage.dashboard_fallback_name", { id: item.id }),
    }))
);

async function loadEmployees() {
    if (employees.value.length) return;

    try {
        const { data } = await api.get("/settings/users");
        employees.value = data.users ?? [];
    } catch {
        // Нет права видеть сотрудников — остаётся ввод адресов вручную.
        employees.value = [];
    }
}

function parseEmails(text) {
    return [...new Set(
        text
            .split(/[\s,;]+/)
            .map((item) => item.trim())
            .filter(Boolean)
    )];
}

function openCreate() {
    editingId.value = null;
    form.value = emptyForm();
    formErrors.value = {};
    formMessage.value = null;
    loadEmployees();
    formOffcanvas?.show();
}

function openEdit(report) {
    editingId.value = report.id;
    form.value = {
        title: report.title,
        dashboard_id: report.dashboard_id,
        format: report.format,
        frequency: report.frequency,
        weekday: report.weekday ?? 1,
        day_of_month: report.day_of_month ?? 1,
        send_time: report.send_time,
        timezone: report.timezone,
        users: [...(report.recipients?.users ?? [])],
        emailsText: (report.recipients?.emails ?? []).join(", "),
        is_active: report.is_active,
    };
    formErrors.value = {};
    formMessage.value = null;
    loadEmployees();
    formOffcanvas?.show();
}

function payload() {
    const value = form.value;

    return {
        title: value.title.trim(),
        dashboard_id: value.dashboard_id,
        format: value.format,
        frequency: value.frequency,
        weekday: value.frequency === "weekly" ? Number(value.weekday) : null,
        day_of_month: value.frequency === "monthly" ? Number(value.day_of_month) : null,
        send_time: value.send_time,
        timezone: value.timezone,
        is_active: value.is_active,
        recipients: {
            users: value.users,
            emails: parseEmails(value.emailsText),
        },
    };
}

async function save() {
    if (saving.value) return;

    saving.value = true;
    formErrors.value = {};
    formMessage.value = null;

    try {
        if (editingId.value) {
            await api.put(`/scheduled-reports/${editingId.value}`, payload());
        } else {
            await api.post(`/workspaces/${props.workspaceId}/scheduled-reports`, payload());
        }

        formOffcanvas?.hide();
        toast?.value?.success?.(t("reports.saved"));
        await load();
    } catch (err) {
        formErrors.value = err.response?.data?.errors ?? {};
        formMessage.value = err.response?.data?.message || t("reports.errors.save_failed");
    } finally {
        saving.value = false;
    }
}

function fieldError(name) {
    return formErrors.value[name]?.[0] ?? null;
}

// --- История ---------------------------------------------------------------

const historyEl = ref(null);
let historyOffcanvas = null;
const historyReport = ref(null);
const historyItems = ref([]);
const historyLoading = ref(false);

async function openHistory(report) {
    historyReport.value = report;
    historyItems.value = [];
    historyLoading.value = true;
    historyOffcanvas?.show();

    try {
        const { data } = await api.get(`/scheduled-reports/${report.id}/history`);
        historyItems.value = data.data ?? [];
    } catch {
        historyItems.value = [];
    } finally {
        historyLoading.value = false;
    }
}

function sizeLabel(bytes) {
    if (!bytes) return "";

    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} ${t("reports.kb")}`
        : `${(bytes / 1024 / 1024).toFixed(1)} ${t("reports.mb")}`;
}

onMounted(async () => {
    load();
    await nextTick();

    if (formEl.value) formOffcanvas = new Offcanvas(formEl.value);
    if (historyEl.value) historyOffcanvas = new Offcanvas(historyEl.value);
});

onBeforeUnmount(() => {
    formOffcanvas?.dispose();
    historyOffcanvas?.dispose();
});

defineExpose({ load });
</script>

<template>
    <div>
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h3 class="mb-0">{{ t("reports.title") }}</h3>
            <button
                v-if="canManage"
                class="btn btn-primary"
                type="button"
                :disabled="!dashboards.length"
                :title="dashboards.length ? '' : t('reports.errors.no_dashboards')"
                @click="openCreate"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon me-1">
                    <path d="M12 5l0 14" /><path d="M5 12l14 0" />
                </svg>
                {{ t("reports.new_button") }}
            </button>
        </div>

        <div v-if="loading" class="card">
            <div class="card-body">
                <div class="progress progress-sm">
                    <div class="progress-bar progress-bar-indeterminate"></div>
                </div>
            </div>
        </div>

        <div v-else-if="error" class="alert alert-danger">{{ error }}</div>

        <div v-else-if="!reports.length" class="card">
            <div class="card-body empty">
                <p class="empty-title">{{ t("reports.empty.title") }}</p>
                <p class="empty-subtitle text-secondary">{{ t("reports.empty.subtitle") }}</p>
                <div v-if="canManage" class="empty-action">
                    <button class="btn btn-primary" type="button" :disabled="!dashboards.length" @click="openCreate">
                        {{ t("reports.new_button") }}
                    </button>
                </div>
            </div>
        </div>

        <div v-else class="card">
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>{{ t("reports.columns.title") }}</th>
                            <th>{{ t("reports.columns.schedule") }}</th>
                            <th>{{ t("reports.columns.next") }}</th>
                            <th>{{ t("reports.columns.last") }}</th>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="report in reports" :key="report.id">
                            <td>
                                <div class="fw-bold">{{ report.title }}</div>
                                <div class="text-secondary small">
                                    {{ report.dashboard_name }} ·
                                    {{ report.format === "pdf" ? "PDF" : "Word" }}
                                </div>
                                <div v-if="report.disabled_reason" class="text-danger small mt-1" style="max-width:260px;">
                                    {{ report.disabled_reason }}
                                </div>
                            </td>
                            <td>
                                <div>{{ scheduleLabel(report) }}</div>
                                <div class="text-secondary small">{{ report.timezone }}</div>
                            </td>
                            <td class="text-secondary">
                                <span v-if="report.is_active">{{ formatDate(report.next_run_at) }}</span>
                                <span v-else class="badge bg-secondary-lt">{{ t("reports.status.disabled") }}</span>
                            </td>
                            <td>
                                <span v-if="statusBadge(report.last_status)" class="badge"
                                      :class="statusBadge(report.last_status).cls">
                                    {{ statusBadge(report.last_status).text }}
                                </span>
                                <div class="text-secondary small mt-1">{{ formatDate(report.last_run_at) }}</div>
                            </td>
                            <td>
                                <div class="btn-list flex-nowrap">
                                    <button v-if="canManage" class="btn btn-sm btn-icon" type="button"
                                            :title="t('reports.actions.send_now')" :disabled="runningId === report.id"
                                            @click="sendNow(report)">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M10 14l11 -11" /><path d="M21 3l-6.5 18a.55 .55 0 0 1 -1 0l-3.5 -7l-7 -3.5a.55 .55 0 0 1 0 -1l18 -6.5" />
                                        </svg>
                                    </button>
                                    <button class="btn btn-sm btn-icon" type="button" :title="t('reports.actions.history')"
                                            @click="openHistory(report)">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 8l0 4l2 2" /><path d="M3.05 11a9 9 0 1 1 .5 4" /><path d="M3 4v4h4" />
                                        </svg>
                                    </button>
                                    <template v-if="canManage">
                                        <label class="form-check form-switch mb-0 mx-1" :title="t('reports.actions.toggle')">
                                            <input class="form-check-input" type="checkbox" :checked="report.is_active"
                                                   @change="toggleReport(report)" />
                                        </label>
                                        <button class="btn btn-sm btn-icon" type="button" :title="t('reports.actions.edit')"
                                                @click="openEdit(report)">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M7 7h-1a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-1" />
                                                <path d="M20.385 6.585a2.1 2.1 0 0 0 -2.97 -2.97l-8.415 8.385v3h3l8.385 -8.415z" />
                                            </svg>
                                        </button>
                                        <button class="btn btn-sm btn-icon text-danger" type="button"
                                                :title="t('reports.actions.delete')" @click="removeReport(report)">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" />
                                                <path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" />
                                                <path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" />
                                            </svg>
                                        </button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Форма отчёта -->
        <div ref="formEl" class="offcanvas offcanvas-end" tabindex="-1" style="width: 460px; max-width: 100vw;">
            <div class="offcanvas-header">
                <h2 class="offcanvas-title">
                    {{ editingId ? t("reports.form.edit_title") : t("reports.form.create_title") }}
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" :aria-label="t('workspacePage.close')"></button>
            </div>

            <div class="offcanvas-body">
                <div v-if="formMessage" class="alert alert-danger">{{ formMessage }}</div>

                <div class="mb-3">
                    <label class="form-label required">{{ t("reports.form.title") }}</label>
                    <input v-model="form.title" type="text" class="form-control" :class="{ 'is-invalid': fieldError('title') }"
                           :placeholder="t('reports.form.title_placeholder')" />
                    <div v-if="fieldError('title')" class="invalid-feedback">{{ fieldError("title") }}</div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-12 col-sm-8">
                        <label class="form-label required">{{ t("reports.form.dashboard") }}</label>
                        <select v-model="form.dashboard_id" class="form-select" :class="{ 'is-invalid': fieldError('dashboard_id') }">
                            <option v-for="item in dashboardOptions" :key="item.id" :value="item.id">{{ item.name }}</option>
                        </select>
                        <div v-if="fieldError('dashboard_id')" class="invalid-feedback">{{ fieldError("dashboard_id") }}</div>
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label required">{{ t("reports.form.format") }}</label>
                        <select v-model="form.format" class="form-select">
                            <option value="pdf">PDF</option>
                            <option value="docx">Word</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label required">{{ t("reports.form.frequency") }}</label>
                    <select v-model="form.frequency" class="form-select">
                        <option value="daily">{{ t("reports.frequency.daily") }}</option>
                        <option value="weekly">{{ t("reports.frequency.weekly") }}</option>
                        <option value="monthly">{{ t("reports.frequency.monthly") }}</option>
                    </select>
                </div>

                <div v-if="form.frequency === 'weekly'" class="mb-3">
                    <label class="form-label required">{{ t("reports.form.weekday") }}</label>
                    <select v-model="form.weekday" class="form-select" :class="{ 'is-invalid': fieldError('weekday') }">
                        <option v-for="day in 7" :key="day" :value="day">{{ t(`reports.weekdays.${day}`) }}</option>
                    </select>
                </div>

                <div v-if="form.frequency === 'monthly'" class="mb-3">
                    <label class="form-label required">{{ t("reports.form.day_of_month") }}</label>
                    <select v-model="form.day_of_month" class="form-select" :class="{ 'is-invalid': fieldError('day_of_month') }">
                        <option v-for="day in 28" :key="day" :value="day">{{ t("reports.day_number", { day }) }}</option>
                        <option :value="0">{{ t("reports.day_last") }}</option>
                    </select>
                    <div class="form-hint">{{ t("reports.form.day_hint") }}</div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-12 col-sm-5">
                        <label class="form-label required">{{ t("reports.form.time") }}</label>
                        <input v-model="form.send_time" type="time" class="form-control"
                               :class="{ 'is-invalid': fieldError('send_time') }" />
                    </div>
                    <div class="col-12 col-sm-7">
                        <label class="form-label required">{{ t("reports.form.timezone") }}</label>
                        <select v-model="form.timezone" class="form-select" :class="{ 'is-invalid': fieldError('timezone') }">
                            <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label required">{{ t("reports.form.recipients") }}</label>

                    <div v-if="employees.length" class="mb-2" style="max-height: 180px; overflow-y: auto;">
                        <label v-for="employee in employees" :key="employee.id" class="form-check mb-1">
                            <input v-model="form.users" class="form-check-input" type="checkbox" :value="employee.id" />
                            <span class="form-check-label">
                                {{ employee.name }}
                                <span class="text-secondary small">{{ employee.email }}</span>
                            </span>
                        </label>
                    </div>

                    <textarea v-model="form.emailsText" class="form-control" rows="2"
                              :class="{ 'is-invalid': fieldError('recipients.emails.0') }"
                              :placeholder="t('reports.form.emails_placeholder')"></textarea>
                    <div class="form-hint">{{ t("reports.form.emails_hint") }}</div>
                    <div v-if="fieldError('recipients')" class="text-danger small mt-1">{{ fieldError("recipients") }}</div>
                    <div v-if="fieldError('recipients.emails.0')" class="text-danger small mt-1">
                        {{ fieldError("recipients.emails.0") }}
                    </div>
                </div>

                <label class="form-check form-switch mb-4">
                    <input v-model="form.is_active" class="form-check-input" type="checkbox" />
                    <span class="form-check-label">{{ t("reports.form.active") }}</span>
                </label>

                <div class="d-flex gap-2">
                    <button class="btn btn-primary" type="button" :disabled="saving" @click="save">
                        <span v-if="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
                        {{ t("reports.form.save") }}
                    </button>
                    <button class="btn" type="button" data-bs-dismiss="offcanvas">{{ t("reports.form.cancel") }}</button>
                </div>
            </div>
        </div>

        <!-- История отправок -->
        <div ref="historyEl" class="offcanvas offcanvas-end" tabindex="-1">
            <div class="offcanvas-header">
                <h2 class="offcanvas-title">{{ t("reports.history.title") }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" :aria-label="t('workspacePage.close')"></button>
            </div>

            <div class="offcanvas-body">
                <div v-if="historyReport" class="mb-3">
                    <div class="fw-bold">{{ historyReport.title }}</div>
                    <div class="text-secondary small">{{ scheduleLabel(historyReport) }}</div>
                </div>

                <div v-if="historyLoading" class="progress progress-sm">
                    <div class="progress-bar progress-bar-indeterminate"></div>
                </div>

                <div v-else-if="!historyItems.length" class="text-secondary">{{ t("reports.history.empty") }}</div>

                <div v-else class="list-group list-group-flush">
                    <div v-for="item in historyItems" :key="item.id" class="list-group-item px-0">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge" :class="statusBadge(item.status)?.cls">{{ statusBadge(item.status)?.text }}</span>
                            <span class="text-secondary small">{{ formatDate(item.started_at) }}</span>
                            <span v-if="item.trigger === 'manual'" class="badge bg-azure-lt ms-auto">
                                {{ t("reports.history.manual") }}
                            </span>
                        </div>
                        <div v-if="item.status === 'sent'" class="small">
                            {{ item.delivery === "link" ? t("reports.history.by_link") : t("reports.history.attached") }}
                            <span v-if="item.file_size">· {{ sizeLabel(item.file_size) }}</span>
                            <div class="text-secondary">{{ (item.recipients ?? []).join(", ") }}</div>
                        </div>
                        <div v-else-if="item.error" class="small text-danger">{{ item.error }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
