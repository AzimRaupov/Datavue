<script setup>
import { ref, computed, onMounted, onUnmounted } from "vue";
import DonutWidget from "../components/widgets/DonutWidget.vue";
import MultiSeriesTrend from "../components/widgets/MultiSeriesTrend.vue";
import MiniCounters from "../components/widgets/MiniCounters.vue";
import AiChatSidebar from "../components/chat/AiChatSidebar.vue";
import { useRoute, useRouter } from "vue-router";
import api from "../api.js";
import WidgetContainer from "../components/WidgetContainer.vue";
const route = useRoute();
const router = useRouter();

const chatId = route.params.id;
// `let`, а не `const` — значение может быть переопределено ниже
let dashboardId = route.params.dashboard ?? null;

const chat = ref({});
const dashboards = ref([]);
const error = ref(null);

const selectedDashboardId = ref(null);

const currentDashboard = ref({});
const widgets = ref([]); // исправлена опечатка "wedgets" -> "widgets"

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

onMounted(async () => {
    document.body.classList.add("chat-page");
    await getChat();
    await getCurrentDashboard();
});

onUnmounted(() => {
    document.body.classList.remove("chat-page");
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
                    :key="widget.id"
                    class="row row-cards widgets-content"
                >
                    <div class="col-12">
                        <h3 class="h3">{{ widget.title }}</h3>

                        <div class="card">
                            <div class="card-body">
                                    <WidgetContainer
                                    :widget="widget"
                                    :chat-id="chatId"
                                    />
                            </div>
                        </div>
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
