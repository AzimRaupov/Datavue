<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick, inject } from "vue";
import { Offcanvas } from "bootstrap";
import { useRouter } from "vue-router";
import { useI18n } from "vue-i18n";
import api from "../../api.js";

/**
 * Вкладка «Алерты» рабочего пространства.
 *
 * Список — не конструктор: правка условия целиком живёт на отдельной
 * странице (AlertForm), а здесь только то, что нужно на каждый день —
 * состояние, включение/выключение, «проверить сейчас» и история проверок.
 */

const props = defineProps({
    workspaceId: { type: [Number, String], required: true },
    canManage: { type: Boolean, default: false },
    hasDataSource: { type: Boolean, default: true },
});

const router = useRouter();
const { t } = useI18n();
const toast = inject("toast", null);

const alerts = ref([]);
const loading = ref(true);
const error = ref(null);
const runningId = ref(null);

const STATE_BADGE = {
    unknown: { key: "alerts.state.unknown", cls: "bg-secondary-lt" },
    ok: { key: "alerts.state.ok", cls: "bg-green-lt" },
    firing: { key: "alerts.state.firing", cls: "bg-red-lt" },
    error: { key: "alerts.state.error", cls: "bg-orange-lt" },
};

function stateBadge(alert) {
    if (!alert.is_active) {
        return { text: t("alerts.state.disabled"), cls: "bg-secondary-lt" };
    }

    const entry = STATE_BADGE[alert.state] ?? STATE_BADGE.unknown;

    return { text: t(entry.key), cls: entry.cls };
}

const INTERVAL_KEYS = {
    15: "alerts.intervals.m15",
    30: "alerts.intervals.m30",
    60: "alerts.intervals.h1",
    180: "alerts.intervals.h3",
    360: "alerts.intervals.h6",
    720: "alerts.intervals.h12",
    1440: "alerts.intervals.h24",
};

function intervalLabel(minutes) {
    const key = INTERVAL_KEYS[minutes];

    return key ? t(key) : t("alerts.intervals.minutes", { count: minutes });
}

function modeLabel(mode) {
    return t(`alerts.mode.${mode}`);
}

function formatDate(value) {
    if (!value) return "—";

    return new Date(value).toLocaleString();
}

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const { data } = await api.get(`/workspaces/${props.workspaceId}/alerts`);
        alerts.value = data;
    } catch (err) {
        error.value = err.response?.data?.message || t("alerts.errors.load_failed");
    } finally {
        loading.value = false;
    }
}

function createAlert() {
    router.push({ name: "company.alert.create", params: { workspace: props.workspaceId } });
}

function editAlert(alert) {
    router.push({ name: "company.alert.edit", params: { workspace: props.workspaceId, alert: alert.id } });
}

async function toggleAlert(alert) {
    try {
        const { data } = await api.post(`/alerts/${alert.id}/toggle`);
        Object.assign(alert, data);
    } catch (err) {
        toast?.value?.error?.(err.response?.data?.message || t("alerts.errors.toggle_failed"));
    }
}

async function removeAlert(alert) {
    if (!confirm(t("alerts.confirm_delete", { title: alert.title }))) return;

    try {
        await api.delete(`/alerts/${alert.id}`);
        alerts.value = alerts.value.filter((a) => a.id !== alert.id);
    } catch (err) {
        toast?.value?.error?.(err.response?.data?.message || t("alerts.errors.delete_failed"));
    }
}

async function runNow(alert) {
    if (runningId.value) return;

    runningId.value = alert.id;

    try {
        const { data } = await api.post(`/alerts/${alert.id}/run`);

        if (data.status === "ok") {
            toast?.value?.success?.(t("alerts.run_result.ok"));
        } else if (data.status === "triggered") {
            toast?.value?.error?.(t("alerts.run_result.triggered"));
        } else {
            toast?.value?.error?.(t("alerts.run_result.error", { error: data.error }));
        }

        await load();
    } catch (err) {
        toast?.value?.error?.(err.response?.data?.message || t("alerts.errors.run_failed"));
    } finally {
        runningId.value = null;
    }
}

// --- История -----------------------------------------------------------

const historyEl = ref(null);
let historyOffcanvas = null;
const historyAlert = ref(null);
const historyItems = ref([]);
const historyLoading = ref(false);

async function openHistory(alert) {
    historyAlert.value = alert;
    historyItems.value = [];
    historyLoading.value = true;
    historyOffcanvas?.show();

    try {
        const { data } = await api.get(`/alerts/${alert.id}/history`);
        historyItems.value = data.data ?? [];
    } catch {
        historyItems.value = [];
    } finally {
        historyLoading.value = false;
    }
}

const HISTORY_STATUS = {
    ok: { key: "alerts.history.status.ok", cls: "bg-green-lt" },
    triggered: { key: "alerts.history.status.triggered", cls: "bg-red-lt" },
    error: { key: "alerts.history.status.error", cls: "bg-orange-lt" },
};

function historyBadge(item) {
    const entry = HISTORY_STATUS[item.status] ?? HISTORY_STATUS.ok;

    return { text: t(entry.key), cls: entry.cls };
}

onMounted(async () => {
    load();

    await nextTick();
    if (historyEl.value) historyOffcanvas = new Offcanvas(historyEl.value);
});

onBeforeUnmount(() => {
    historyOffcanvas?.dispose();
});

defineExpose({ load });
</script>

<template>
    <div>
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h3 class="mb-0">{{ t("alerts.title") }}</h3>

            <button
                v-if="canManage"
                class="btn btn-primary"
                type="button"
                :disabled="!hasDataSource"
                :title="hasDataSource ? '' : t('alerts.errors.no_data_source')"
                @click="createAlert"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon me-1">
                    <path d="M12 5l0 14" /><path d="M5 12l14 0" />
                </svg>
                {{ t("alerts.new_button") }}
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

        <div v-else-if="!alerts.length" class="card">
            <div class="card-body empty">
                <p class="empty-title">{{ t("alerts.empty.title") }}</p>
                <p class="empty-subtitle text-secondary">{{ t("alerts.empty.subtitle") }}</p>
                <div v-if="canManage" class="empty-action">
                    <button class="btn btn-primary" type="button" :disabled="!hasDataSource" @click="createAlert">
                        {{ t("alerts.new_button") }}
                    </button>
                </div>
            </div>
        </div>

        <div v-else class="card">
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>{{ t("alerts.columns.title") }}</th>
                            <th>{{ t("alerts.columns.state") }}</th>
                            <th>{{ t("alerts.columns.schedule") }}</th>
                            <th>{{ t("alerts.columns.last_checked") }}</th>
                            <th>{{ t("alerts.columns.last_triggered") }}</th>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="alert in alerts" :key="alert.id">
                            <td>
                                <div class="fw-bold">{{ alert.title }}</div>
                                <div class="text-secondary small">{{ modeLabel(alert.mode) }}</div>
                            </td>
                            <td>
                                <span class="badge" :class="stateBadge(alert).cls">{{ stateBadge(alert).text }}</span>
                                <div v-if="alert.disabled_reason" class="text-secondary small mt-1" style="max-width:220px;">
                                    {{ alert.disabled_reason }}
                                </div>
                            </td>
                            <td>{{ intervalLabel(alert.interval_minutes) }}</td>
                            <td class="text-secondary">{{ formatDate(alert.last_checked_at) }}</td>
                            <td class="text-secondary">{{ formatDate(alert.last_triggered_at) }}</td>
                            <td>
                                <div class="btn-list flex-nowrap">
                                    <button class="btn btn-sm btn-icon" type="button" :title="t('alerts.actions.run_now')"
                                            :disabled="runningId === alert.id" @click="runNow(alert)">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                             stroke-linejoin="round" :class="{ 'icon-spin': runningId === alert.id }">
                                            <path d="M20 11a8.1 8.1 0 0 0-15.5-2M4 5v4h4" />
                                            <path d="M4 13a8.1 8.1 0 0 0 15.5 2M20 19v-4h-4" />
                                        </svg>
                                    </button>
                                    <button class="btn btn-sm btn-icon" type="button" :title="t('alerts.actions.history')"
                                            @click="openHistory(alert)">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 8l0 4l2 2" /><path d="M3.05 11a9 9 0 1 1 .5 4" /><path d="M3 4v4h4" />
                                        </svg>
                                    </button>
                                    <template v-if="canManage">
                                        <label class="form-check form-switch mb-0 mx-1" :title="t('alerts.actions.toggle')">
                                            <input class="form-check-input" type="checkbox" :checked="alert.is_active"
                                                   @change="toggleAlert(alert)" />
                                        </label>
                                        <button class="btn btn-sm btn-icon" type="button" :title="t('alerts.actions.edit')"
                                                @click="editAlert(alert)">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M7 7h-1a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-1" />
                                                <path d="M20.385 6.585a2.1 2.1 0 0 0 -2.97 -2.97l-8.415 8.385v3h3l8.385 -8.415z" />
                                            </svg>
                                        </button>
                                        <button class="btn btn-sm btn-icon text-danger" type="button" :title="t('alerts.actions.delete')"
                                                @click="removeAlert(alert)">
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

        <!-- История проверок -->
        <div ref="historyEl" class="offcanvas offcanvas-end" tabindex="-1">
            <div class="offcanvas-header">
                <h2 class="offcanvas-title">{{ t("alerts.history.title") }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" :aria-label="t('workspacePage.close')"></button>
            </div>
            <div class="offcanvas-body">
                <div v-if="historyAlert" class="mb-3">
                    <div class="fw-bold">{{ historyAlert.title }}</div>
                </div>

                <div v-if="historyLoading" class="progress progress-sm">
                    <div class="progress-bar progress-bar-indeterminate"></div>
                </div>

                <div v-else-if="!historyItems.length" class="text-secondary">
                    {{ t("alerts.history.empty") }}
                </div>

                <div v-else class="divide-y">
                    <div v-for="item in historyItems" :key="item.id" class="py-2">
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="badge" :class="historyBadge(item).cls">{{ historyBadge(item).text }}</span>
                            <span class="text-secondary small">{{ formatDate(item.checking_at) }}</span>
                        </div>
                        <div v-if="item.value !== null" class="small mt-1">
                            {{ t("alerts.history.value") }}: <strong>{{ item.value }}</strong>
                        </div>
                        <div v-if="item.error" class="small text-danger mt-1">{{ item.error }}</div>
                        <div v-if="item.notified" class="small text-secondary mt-1">
                            {{ t("alerts.history.notified", { emails: (item.recipients || []).join(", ") }) }}
                        </div>
                        <div v-else-if="item.notify_error" class="small text-warning mt-1">
                            {{ t("alerts.history.notify_failed") }}: {{ item.notify_error }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
