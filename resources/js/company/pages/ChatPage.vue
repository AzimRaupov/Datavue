<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from "vue";
import DonutWidget from "../components/widgets/DonutWidget.vue";
import MultiSeriesTrend from "../components/widgets/MultiSeriesTrend.vue";
import MiniCounters from "../components/widgets/MiniCounters.vue";
import AiChatSidebar from "../components/chat/AiChatSidebar.vue";
import { useRoute, useRouter } from "vue-router";
import api from "../api.js";
import WidgetContainer from "../components/WidgetContainer.vue";
import Echo from "laravel-echo";
import Pusher from "pusher-js";

window.Pusher = Pusher;

const isExporting = ref(false);

const echo = new Echo({
    broadcaster: "reverb",
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST || "127.0.0.1",
    wsPort: import.meta.env.VITE_REVERB_PORT || 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT || 8080,
    forceTLS: false,
    disableStats: true,
    enabledTransports: ["ws"],
});

const empty_img = "/static/illustrations/light/chart-circle.png";
const generate_img = "/static/illustrations/light/boy-and-laptop.png";

const route = useRoute();
const router = useRouter();

const chatId = route.params.id;

const chat = ref({});
const dashboards = ref([]);
const error = ref(null);

const selectedDashboardId = ref(
    route.params.dashboard ? Number(route.params.dashboard) : null
);

const currentDashboard = ref({});
const widgets = ref([]);

let currentChannelName = null;

const isRefreshing = ref(false);

// Токен ручного обновления контента виджетов.
// Меняется ТОЛЬКО по клику на кнопку "Обновить".
// WidgetContainer следит за ним и перезапрашивает свои данные,
// независимо от того, поменялся ли widget.updated_at на бэке.
const refreshToken = ref(0);

const hasDashboards = computed(() => dashboards.value.length > 0);

const showDashboardSelect = computed(() =>
    hasDashboards.value && currentDashboard.value?.status !== "generating_scheme"
);

// Ключ теперь стабильный — только id виджета.
// Компонент WidgetContainer НЕ пересоздаётся при каждом обновлении дашборда,
// поэтому не мигает skeleton-заглушками по всем виджетам разом.
function widgetKey(widget) {
    return widget.id;
}

function toId(val) {
    return val === null || val === undefined ? null : Number(val);
}

async function getCurrentDashboard() {
    if (!hasDashboards.value) {
        selectedDashboardId.value = null;
        currentDashboard.value = {};
        widgets.value = [];
        return;
    }

    if (!selectedDashboardId.value) {
        selectedDashboardId.value = toId(dashboards.value[0]?.id) ?? null;
    }

    if (!selectedDashboardId.value) {
        error.value = "Дашборд не найден";
        return;
    }

    try {
        const { data } = await api.get(`/dashboards/${selectedDashboardId.value}`);
        currentDashboard.value = data;
        widgets.value = data.widgets ?? [];
    } catch (err) {
        console.error(err);
        error.value = "Не удалось загрузить дашборд";
    }
}

// Обновляет метаданные дашборда/список виджетов (структуру),
// но НЕ форсирует перезагрузку контента внутри WidgetContainer.
async function refreshWidgets() {
    if (!currentDashboard.value?.id) return;
    if (isRefreshing.value) return;

    isRefreshing.value = true;

    try {
        const { data } = await api.get(
            `/dashboards/${currentDashboard.value.id}`,
            { params: { refresh: Date.now() } }
        );

        widgets.value = data.widgets ?? [];
        currentDashboard.value = data;

        const idx = dashboards.value.findIndex(d => d.id === toId(data.id));
        if (idx !== -1) {
            dashboards.value[idx] = {
                ...dashboards.value[idx],
                name: data.name,
                status: data.status,
            };
        }
    } catch (err) {
        console.error("Не удалось обновить виджеты:", err);
    } finally {
        isRefreshing.value = false;
    }
}


async function onRefreshClick() {
    await refreshWidgets();
    refreshToken.value = Date.now();
}

async function getChat() {
    try {
        const { data } = await api.get(`/chats/${chatId}`);

        chat.value = data;

        dashboards.value = (data.dashboards ?? []).map(d => ({
            ...d,
            id: toId(d.id),
        }));

        if (selectedDashboardId.value) {
            const exists = dashboards.value.some(d => d.id === selectedDashboardId.value);
            if (!exists) {
                selectedDashboardId.value = dashboards.value[0]?.id ?? null;
            }
        } else if (dashboards.value.length > 0) {
            selectedDashboardId.value = dashboards.value[0].id;
        }
    } catch (err) {
        console.error(err);
        error.value = "Не удалось загрузить чат";
    }
}

async function onDashboardChange() {
    if (!selectedDashboardId.value) return;

    router.replace({
        name: "company.chat",
        params: {
            id: chatId,
            dashboard: selectedDashboardId.value,
        },
    });

    await getCurrentDashboard();
    subscribeToDashboardChannel();
}

const chatOpen = ref(true);

function toggleChat() {
    chatOpen.value = !chatOpen.value;
}

function closeChat() {
    chatOpen.value = false;
}

function subscribeToDashboardChannel() {
    if (currentChannelName) {
        echo.leave(currentChannelName);
        currentChannelName = null;
    }

    if (!currentDashboard.value?.id) return;

    currentChannelName = `dashboard.${currentDashboard.value.id}`;

    echo.channel(currentChannelName)
        .listen(".DashboardWidgetChanged", (e) => {
            console.log("--- РЕАЛТАЙМ ИЗМЕНЕНИЕ ДАШБОРДА ПОЙМАНО ---", e);
            refreshWidgets();

            // Принудительно обновляем данные всех WidgetContainer
            refreshToken.value = Date.now();
        });
}

watch(
    () => route.params.dashboard,
    async (newVal) => {
        if (!newVal) return;

        const newId = toId(newVal);
        if (newId === selectedDashboardId.value) return;

        const exists = dashboards.value.some(d => d.id === newId);
        if (!exists) {
            await getChat();
        }

        selectedDashboardId.value = newId;
        await getCurrentDashboard();
        subscribeToDashboardChannel();
    }
);

onMounted(async () => {
    document.body.classList.add("chat-page");

    await getChat();
    await getCurrentDashboard();

    subscribeToDashboardChannel();
});

onUnmounted(() => {
    document.body.classList.remove("chat-page");

    if (currentChannelName) {
        echo.leave(currentChannelName);
    }
});
</script>

<style>
body.chat-page .page {
    height: 100vh;
    min-height: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.icon-spin {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

<template>
    <div class="dashboard-wrapper">

        <div class="dashboard-main p-1" id="dashboardMain">

            <div class="page-header d-print-none mb-3 mt-2">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <h1 v-if="hasDashboards" class="page-title">{{ currentDashboard?.name }}</h1>
                        </div>

                        <div class="col-auto ms-auto d-print-none">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <select
                                    v-if="showDashboardSelect"
                                    class="form-select"
                                    style="width: 220px; max-width: 100%;"
                                    v-model="selectedDashboardId"
                                    @change="onDashboardChange"
                                    aria-label="Выбор дашборда"
                                >
                                    <option
                                        v-for="d in dashboards"
                                        :key="d.id"
                                        :value="d.id"
                                    >
                                        {{ d.name }}
                                    </option>
                                </select>

                                <!-- КНОПКА ОБНОВЛЕНИЯ -->
                                <button
                                    v-if="hasDashboards"
                                    class="btn btn-outline-primary"
                                    type="button"
                                    title="Обновить дашборд"
                                    @click="onRefreshClick"
                                    :disabled="isRefreshing"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="18"
                                        height="18"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        :class="{ 'icon-spin': isRefreshing }"
                                    >
                                        <path d="M20 11a8.1 8.1 0 0 0-15.5-2M4 5v4h4" />
                                        <path d="M4 13a8.1 8.1 0 0 0 15.5 2M20 19v-4h-4" />
                                    </svg>

                                    {{ isRefreshing ? "Обновление..." : "Обновить" }}
                                </button>

                                <button
                                    v-if="!chatOpen"
                                    class="btn btn-primary d-none d-lg-inline-flex align-items-center text-nowrap flex-shrink-0 px-3"
                                    title="Открыть AI ассистента"
                                    @click="toggleChat"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="18"
                                        height="18"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="icon me-2"
                                    >
                                        <path d="M12 8a4 4 0 0 1 4 4" />
                                        <path d="M12 4a8 8 0 0 1 8 8" />
                                        <path d="M12 20a8 8 0 0 1-8-8" />
                                        <circle cx="12" cy="12" r="1" />
                                    </svg>

                                    AI Ассистент
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="container-xl">

                <div
                    v-if="!hasDashboards"
                    class="d-flex align-items-center justify-content-center"
                    style="min-height: 60vh;"
                >
                    <div class="text-center">
                        <img
                            :src="empty_img"
                            alt="chart"
                            class="img-fluid d-block mx-auto mb-4"
                            style="max-width: 270px; width: 100%;"
                        >
                        <h3 class="mb-2">Дашбордов пока нет</h3>
                        <p class="text-muted mb-0">
                            Как только для этого чата будет создан дашборд, он появится здесь
                        </p>
                    </div>
                </div>

                <div
                    v-if="currentDashboard.status === 'generating_scheme'"
                    class="d-flex align-items-center justify-content-center"
                    style="min-height: 60vh;"
                >
                    <div class="text-center">
                        <img
                            :src="generate_img"
                            alt="generating"
                            class="img-fluid d-block mx-auto mb-4"
                            style="max-width: 270px; width: 100%;"
                        >
                        <div class="text-secondary mb-3">Генерация дашборда...</div>
                        <div class="progress progress-sm">
                            <div class="progress-bar progress-bar-indeterminate"></div>
                        </div>
                    </div>
                </div>

                <template v-else>
                    <div
                        v-for="widget in widgets"
                        :key="widgetKey(widget)"
                        class="row row-cards widgets-content"
                    >
                        <div class="col-12 mt-4">
                            <h3 class="h3">{{ widget.title }}</h3>

                            <WidgetContainer
                                :widget="widget"
                                :chat-id="chatId"
                                :refresh-token="refreshToken"
                            />
                        </div>
                    </div>
                </template>

            </div>
        </div>


        <div class="chat-backdrop" :class="{ 'd-none': !chatOpen }" @click="closeChat"></div>

        <AiChatSidebar
            :open="chatOpen"
            :chat-title="chat.title"
            :chat-id="chatId"
            :dashboard-id="selectedDashboardId"
            @close="closeChat"
        />

        <button v-if="!chatOpen" class="chat-fab" @click="toggleChat" aria-label="Открыть чат">
            <svg
                xmlns="http://www.w3.org/2000/svg"
                width="22"
                height="22"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path d="M12 8a4 4 0 0 1 4 4" />
                <path d="M12 4a8 8 0 0 1 8 8" />
                <path d="M12 20a8 8 0 0 1-8-8" />
                <circle cx="12" cy="12" r="1" />
            </svg>
        </button>

    </div>
</template>

<style>
:root {
    --chart-scatter-color-0: color-mix(in srgb, transparent, var(--tblr-primary) 100%);
    --chart-scatter-color-1: color-mix(in srgb, transparent, var(--tblr-pink) 100%);
}
</style>
