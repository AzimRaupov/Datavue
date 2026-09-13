<script setup>
import { ref, computed, watch, onBeforeUnmount } from "vue";
import { useI18n } from "vue-i18n";
import api from "../../api.js";
import { useEcho } from "../../echo.js";
import DashboardWidgetsView from "../dashboard/DashboardWidgetsView.vue";
import { usePrintArea } from "../../composables/usePrintArea.js";
import { dashboardStatusInfo } from "../../utils/dashboardStatus.js";

const { t } = useI18n();

/**
 * Read-only панель дашборда поверх чата директора.
 *
 * Сознательно не переиспользует WorkspacePage: у той страницы есть шапка
 * пространства, переключатель режимов, каталог виджетов и вкладка алертов —
 * ничего из этого директору не нужно и не должно быть видно. Здесь — только
 * сами виджеты, версия дашборда и печать.
 */
const props = defineProps({
    /** Версии дашборда этого чата — id/name/status, самая новая первой. */
    dashboards: {
        type: Array,
        default: () => [],
    },
    initialDashboardId: {
        type: [String, Number],
        default: null,
    },
});

const emit = defineEmits(["close"]);

const echo = useEcho();

const selectedId = ref(props.initialDashboardId);
const loading = ref(true);
const error = ref(null);
const dashboard = ref(null);
const widgets = ref([]);
const refreshToken = ref(0);

const exportArea = ref(null);
const { printDashboard } = usePrintArea(exportArea);

const isGenerating = computed(() =>
    ["generating_scheme", "generating_widgets"].includes(dashboard.value?.status)
);

function statusOf(item) {
    return dashboardStatusInfo(item?.status, t);
}

let currentChannelName = null;

function subscribe(id) {
    if (currentChannelName) {
        echo.leave(currentChannelName);
        currentChannelName = null;
    }

    if (!id) return;

    currentChannelName = `dashboard.${id}`;

    echo.private(currentChannelName).listen(".DashboardWidgetChanged", () => {
        load(id, { silent: true });
    });
}

async function load(id, { silent = false } = {}) {
    if (!id) {
        dashboard.value = null;
        widgets.value = [];
        loading.value = false;

        return;
    }

    if (!silent) loading.value = true;
    error.value = null;

    try {
        const { data } = await api.get(`/dashboards/${id}`);
        dashboard.value = data;
        widgets.value = data.widgets ?? [];
        refreshToken.value = Date.now();
    } catch (err) {
        error.value =
            err.response?.status === 403
                ? t("director.dashboard_viewer.errors.no_access")
                : t("director.dashboard_viewer.errors.load_failed");
    } finally {
        loading.value = false;
    }
}

watch(
    selectedId,
    (id) => {
        subscribe(id);
        load(id);
    },
    { immediate: true }
);

// Новая версия, построенная агентом уже во время просмотра, — сразу
// переключаемся на неё, как это делает и WorkspacePage.
watch(
    () => props.initialDashboardId,
    (id) => {
        if (id) selectedId.value = id;
    }
);

onBeforeUnmount(() => {
    if (currentChannelName) echo.leave(currentChannelName);
});

function close() {
    emit("close");
}
</script>

<template>
    <div class="director-dashboard-backdrop" @click="close"></div>

    <div class="director-dashboard-viewer d-flex flex-column">
        <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom flex-shrink-0">
            <button class="btn btn-icon flex-shrink-0" type="button" :aria-label="t('director.dashboard_viewer.back')" @click="close">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6l6 6" /></svg>
            </button>

            <div class="flex-fill overflow-hidden">
                <div class="fw-bold text-truncate d-flex align-items-center gap-2">
                    <span class="text-truncate">{{ dashboard?.name || t('director.dashboard_viewer.untitled') }}</span>
                    <span v-if="dashboard" class="badge flex-shrink-0" :class="statusOf(dashboard).cls">
                        {{ statusOf(dashboard).text }}
                    </span>
                </div>
            </div>

            <select
                v-if="dashboards.length > 1"
                class="form-select form-select-sm w-auto flex-shrink-0"
                :value="selectedId"
                :aria-label="t('director.dashboard_viewer.version_select_aria')"
                @change="selectedId = Number($event.target.value)"
            >
                <option v-for="item in dashboards" :key="item.id" :value="item.id">
                    {{ item.name || t('director.dashboard_viewer.version_fallback', { id: item.id }) }}
                </option>
            </select>

            <button
                v-if="widgets.length"
                class="btn btn-icon flex-shrink-0"
                type="button"
                :title="t('director.dashboard_viewer.print')"
                :aria-label="t('director.dashboard_viewer.print')"
                @click="printDashboard"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2" /><path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4" /><path d="M7 13m0 2a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z" /></svg>
            </button>
        </div>

        <div class="flex-fill overflow-auto p-3" ref="exportArea">
            <div v-if="loading" class="card">
                <div class="card-body">
                    <div class="progress progress-sm">
                        <div class="progress-bar progress-bar-indeterminate"></div>
                    </div>
                </div>
            </div>

            <div v-else-if="error" class="alert alert-danger">{{ error }}</div>

            <div v-else-if="isGenerating && !widgets.length" class="empty">
                <p class="empty-title">{{ t('director.dashboard_viewer.generating.title') }}</p>
                <p class="empty-subtitle text-secondary">{{ t('director.dashboard_viewer.generating.subtitle') }}</p>
                <div class="progress progress-sm w-50 mx-auto">
                    <div class="progress-bar progress-bar-indeterminate"></div>
                </div>
            </div>

            <div v-else-if="!widgets.length" class="empty">
                <p class="empty-title">{{ t('director.dashboard_viewer.empty.title') }}</p>
                <p class="empty-subtitle text-secondary">{{ t('director.dashboard_viewer.empty.subtitle') }}</p>
            </div>

            <DashboardWidgetsView
                v-else
                :widgets="widgets"
                :refresh-token="refreshToken"
                :can-edit="false"
            />
        </div>
    </div>
</template>

<style scoped>
.director-dashboard-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1050;
    background: rgba(0, 0, 0, .4);
}

.director-dashboard-viewer {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    left: 0;
    z-index: 1051;
    background: var(--tblr-bg-surface);
}

@media (min-width: 992px) {
    .director-dashboard-viewer {
        left: auto;
        width: min(1100px, 90vw);
        box-shadow: -4px 0 24px rgba(0, 0, 0, .15);
    }
}

@media print {
    .director-dashboard-backdrop {
        display: none;
    }

    .director-dashboard-viewer {
        position: static;
        width: auto;
        height: auto;
        overflow: visible;
    }
}
</style>
