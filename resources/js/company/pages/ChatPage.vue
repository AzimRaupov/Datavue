<script setup>
import { ref, computed, onMounted, onUnmounted } from "vue";
import DonutWidget from "../components/widgets/DonutWidget.vue";
import MultiSeriesTrend from "../components/widgets/MultiSeriesTrend.vue";
import MiniCounters from "../components/widgets/MiniCounters.vue";
import AiChatSidebar from "../components/chat/AiChatSidebar.vue";
import { useRoute, useRouter } from "vue-router";
import api from "../api.js";
import WidgetContainer from "../components/WidgetContainer.vue";
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';



window.Pusher = Pusher;


const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST || '127.0.0.1',
    wsPort: import.meta.env.VITE_REVERB_PORT || 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT || 8080,
    forceTLS: false,
    disableStats: true,
    enabledTransports: ['ws'],
});

const route = useRoute();
const router = useRouter();
const chatId = route.params.id;
let dashboardId = route.params.dashboard ?? null;
const chat = ref({});
const dashboards = ref([]);
const error = ref(null);
const selectedDashboardId = ref(null);
const currentDashboard = ref({});
const widgets = ref([]); // исправлена опечатка "wedgets" -> "widgets"

let currentChannelName = null; // чтобы можно было отписаться при смене дашборда
let isRefreshing = false; // защита от параллельных перезагрузок

// Составной ключ для v-for. widget.id один и тот же при UPDATE,
// поэтому Vue не пересоздаёт компонент без изменения ключа.
// Добавляем поля, которые реально меняются при генерации контента виджета:
// status, code_path, updated_at (и position на всякий случай).
function widgetKey(widget) {
    return [
        widget.id,
        widget.status,
        widget.code_path,
        widget.updated_at,
        widget.position,
    ].join('-');
}

async function getCurrentDashboard() {
    // если id дашборда не передан в роуте — берём выбранный (или первый из списка)
    if (!dashboardId) {
        dashboardId = selectedDashboardId.value ?? dashboards.value[0]?.id ?? null;
    }

    if (!dashboardId) {
        error.value = "Дашборд не найден";
        return;
    }

    try {
        const { data } = await api.get(`/dashboards/${dashboardId}`);

        currentDashboard.value = data;
        widgets.value = data.widgets ?? [];

        console.log(currentDashboard.value);
    } catch (err) {
        console.error(err);
        error.value = "Не удалось загрузить дашборд";
    }
}

// Отдельная функция именно для «мягкого» обновления виджетов по сокет-событию,
// чтобы не перезатирать error/currentDashboard лишний раз и не мигать UI.
async function refreshWidgets() {
    if (!currentDashboard.value?.id) return;
    if (isRefreshing) return; // если уже идёт обновление — пропускаем повторный вызов

    isRefreshing = true;
    try {
        const { data } = await api.get(`/dashboards/${currentDashboard.value.id}`);
        // Полная замена массива новыми объектами — важно, чтобы это были
        // НОВЫЕ объекты (а не мутация старых), тогда composite key из
        // widgetKey() посчитается заново и Vue пересоздаст изменившиеся
        // WidgetContainer.
        widgets.value = data.widgets ?? [];
    } catch (err) {
        console.error("Не удалось обновить виджеты:", err);
    } finally {
        isRefreshing = false;
    }
}

async function getChat() {
    try {
        const { data } = await api.get(`/chats/${chatId}`);

        chat.value = data;
        dashboards.value = data.dashboards ?? [];

        // если дашборды есть — выбираем первый
        if (dashboards.value.length > 0) {
            selectedDashboardId.value = dashboards.value[0].id;
        }
    } catch (err) {
        console.error(err);
        error.value = "Не удалось загрузить чат";
    }
}

function onDashboardChange() {
    if (!selectedDashboardId.value) return;

    router.push(`/dashboard/${selectedDashboardId.value}`);
}

const chatOpen = ref(true);

function toggleChat() {
    chatOpen.value = !chatOpen.value;
}

function closeChat() {
    chatOpen.value = false;
}

function subscribeToDashboardChannel() {
    // отписываемся от предыдущего канала, если он был
    if (currentChannelName) {
        echo.leave(currentChannelName);
        currentChannelName = null;
    }

    if (!currentDashboard.value?.id) return;

    // канал, который бродкастит событие на бекенде — `dashboard.{id}`
    currentChannelName = `dashboard.${currentDashboard.value.id}`;

    // Подписываемся на публичный канал дашборда и слушаем событие, которое
    // возвращает back-end через `broadcastAs()` — `DashboardWidgetChanged`.
    echo.channel(currentChannelName)
        .listen('.DashboardWidgetChanged', (e) => {
            console.log('--- РЕАЛТАЙМ ИЗМЕНЕНИЕ ДАШБОРДА ПОЙМАНО ---', e);

            // при получении события — подтягиваем свежие данные виджетов
            refreshWidgets();
        });
}

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
</style>
<template>

    <div class="dashboard-wrapper">

        <div class="dashboard-main p-1" id="dashboardMain">

            <!-- ===================== ЗАГОЛОВОК + ВЫБОР ДАШБОРДА ===================== -->
            <div class="page-header d-print-none mb-3 mt-2">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <h1 class="page-title">{{ currentDashboard?.name }}</h1>
                        </div>

                        <div class="col-auto ms-auto d-print-none">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <select
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
                                        <path d="M12 8a4 4 0 0 1 4 4"/>
                                        <path d="M12 4a8 8 0 0 1 8 8"/>
                                        <path d="M12 20a8 8 0 0 1 -8 -8"/>
                                        <circle cx="12" cy="12" r="1"/>
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
                    v-for="widget in widgets"
                    :key="widgetKey(widget)"
                    class="row row-cards widgets-content"
                >
                    <div class="col-12">
                        <h3 class="h3">{{ widget.title }}</h3>


                        <WidgetContainer
                            :widget="widget"
                            :chat-id="chatId"
                        />

                    </div>
                </div>
            </div>
        </div>

        <!-- Backdrop, mobile only -->
        <div class="chat-backdrop" :class="{ 'd-none': !chatOpen }" @click="closeChat"></div>

        <!-- ===================== ЧАТ (компонент) ===================== -->
        <AiChatSidebar
            :open="chatOpen"
            :chat-title="chat.title"
            :chat-id="chatId"
            @close="closeChat"
        />

        <!-- Floating button to reopen chat on mobile -->
        <button v-if="!chatOpen" class="chat-fab" @click="toggleChat" aria-label="Открыть чат">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8a4 4 0 0 1 4 4"/><path d="M12 4a8 8 0 0 1 8 8"/><path d="M12 20a8 8 0 0 1 -8 -8"/><circle cx="12" cy="12" r="1"/></svg>
        </button>

    </div>

    <!-- ...settings offcanvas unchanged... -->

</template>

<style>
:root {
    --chart-scatter-color-0: color-mix(in srgb, transparent, var(--tblr-primary) 100%);
    --chart-scatter-color-1: color-mix(in srgb, transparent, var(--tblr-pink) 100%);
}
</style>
