<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from "vue";
import MiniCounters from "../components/widgets/MiniCounters.vue";
import AiChatSidebar from "../components/chat/AiChatSidebar.vue";
import { useRoute, useRouter } from "vue-router";
import api from "../api.js";
import WidgetContainer from "../components/WidgetContainer.vue";
import Echo from "laravel-echo";
import Pusher from "pusher-js";

window.Pusher = Pusher;

// Область, которую печатаем — только сами виджеты, без чата и шапки.
const exportArea = ref(null);

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

/**
 * Ручная смена типа отрисовки виджета.
 *
 * Выбор ограничен вариантами ТОГО ЖЕ семейства (круг → кольцо, столбцы →
 * горизонтальные): сгенерированный Python-код отдаёт данные в форме
 * конкретного семейства, и таблица не нарисуется данными для круга.
 *
 * Смена применяется сразу — WidgetContainer берёт параметры отрисовки
 * из widget.widget_type, поэтому перезапрашивать данные не нужно.
 * Записывается в базу только по кнопке «Сохранить».
 */
const pendingTypes = ref({});
const savingTypes = ref(false);
const saveTypesError = ref(null);

const hasTypeChanges = computed(() => Object.keys(pendingTypes.value).length > 0);

/** Варианты отрисовки, доступные конкретному виджету. */
function typesOf(widget) {
    return widget?.widget?.types ?? [];
}

function currentTypeId(widget) {
    return widget.widget_type_id ?? widget.widget_type?.id ?? null;
}

function onTypeChange(widget, typeId) {
    const id = Number(typeId);
    const type = typesOf(widget).find(t => t.id === id);

    if (!type) return;

    // Меняем прямо в объекте виджета — превью перерисуется мгновенно.
    widget.widget_type_id = id;
    widget.widget_type = type;

    // Возврат к исходному типу снимает пометку об изменении.
    if (originalTypes.value[widget.id] === id) {
        delete pendingTypes.value[widget.id];
    } else {
        pendingTypes.value[widget.id] = id;
    }
}

// Типы на момент загрузки — чтобы отличать реальные изменения от возврата назад.
const originalTypes = ref({});

function rememberOriginalTypes() {
    const map = {};
    for (const w of widgets.value) map[w.id] = currentTypeId(w);
    originalTypes.value = map;
    pendingTypes.value = {};
}

async function saveWidgetTypes() {
    if (savingTypes.value || !hasTypeChanges.value) return;

    savingTypes.value = true;
    saveTypesError.value = null;

    try {
        await api.patch(`/dashboards/${currentDashboard.value.id}/widgets`, {
            widgets: Object.entries(pendingTypes.value).map(([id, widget_type_id]) => ({
                id: Number(id),
                widget_type_id,
            })),
        });

        rememberOriginalTypes();
    } catch (err) {
        saveTypesError.value =
            err.response?.data?.message || 'Не удалось сохранить изменения.';
    } finally {
        savingTypes.value = false;
    }
}

/** Откат к тому, что сохранено в базе. */
function resetWidgetTypes() {
    for (const w of widgets.value) {
        const original = originalTypes.value[w.id];
        if (original && currentTypeId(w) !== original) {
            const type = typesOf(w).find(t => t.id === original);
            if (type) {
                w.widget_type_id = original;
                w.widget_type = type;
            }
        }
    }
    pendingTypes.value = {};
}

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
        rememberOriginalTypes();
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
        rememberOriginalTypes();

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
            // Только перечитываем структуру дашборда. Данные виджета
            // перезапрашивает сам WidgetContainer — и только тот, у которого
            // изменился updated_at. Раньше здесь дёргался refreshToken, и на
            // каждое событие данные перезапрашивались у ВСЕХ виджетов сразу:
            // на дашборде из десяти виджетов это десять запусков Python-кода
            // вместо одного.
            refreshWidgets();
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



/**
 * Перед экспортом/печатью проходим по всем потомкам exportArea и снимаем
 * ограничения overflow/max-height/height, из-за которых видна только
 * прокрученная часть виджета (графики, таблицы, списки и т.п.).
 * Сохраняем исходные инлайн-стили, чтобы потом всё вернуть на место.
 */
const EXPAND_SELECTOR =
    "[style*='overflow'], .overflow-auto, .overflow-scroll, .table-responsive, .scroll, .chart-container, canvas, .echarts, .apexcharts-canvas";

function expandScrollableAreas(root) {
    if (!root) return [];

    const restoreList = [];

    const nodes = [root, ...root.querySelectorAll(EXPAND_SELECTOR)];

    nodes.forEach((el) => {
        const original = {
            overflow: el.style.overflow,
            overflowX: el.style.overflowX,
            overflowY: el.style.overflowY,
            maxHeight: el.style.maxHeight,
            height: el.style.height,
        };

        const computed = window.getComputedStyle(el);
        const hasClip =
            ["auto", "scroll", "hidden"].includes(computed.overflow) ||
            ["auto", "scroll", "hidden"].includes(computed.overflowY) ||
            (computed.maxHeight && computed.maxHeight !== "none");

        if (hasClip) {
            el.style.setProperty("overflow", "visible", "important");
            el.style.setProperty("overflow-x", "visible", "important");
            el.style.setProperty("overflow-y", "visible", "important");
            el.style.setProperty("max-height", "none", "important");
            if (el.scrollHeight > el.clientHeight) {
                el.style.setProperty("height", "auto", "important");
            }
            restoreList.push({ el, original });
        }
    });

    return restoreList;
}

function restoreScrollableAreas(restoreList) {
    restoreList.forEach(({ el, original }) => {
        el.style.overflow = original.overflow;
        el.style.overflowX = original.overflowX;
        el.style.overflowY = original.overflowY;
        el.style.maxHeight = original.maxHeight;
        el.style.height = original.height;
    });
}

// Экспорт в PDF и Word убран: он рендерил дашборд в картинку через
// html2canvas, из-за чего в файл уходило изображение вместо текста.
// Печать оставлена — она использует штатный вывод браузера.

let printRestoreList = [];

function handleBeforePrint() {
    printRestoreList = expandScrollableAreas(exportArea.value);
}

function handleAfterPrint() {
    restoreScrollableAreas(printRestoreList);
    printRestoreList = [];
}

function printDashboard() {
    window.print();
}

onMounted(async () => {
    document.body.classList.add("chat-page");

    await getChat();
    await getCurrentDashboard();

    subscribeToDashboardChannel();

    window.addEventListener("beforeprint", handleBeforePrint);
    window.addEventListener("afterprint", handleAfterPrint);
});

onUnmounted(() => {
    document.body.classList.remove("chat-page");

    if (currentChannelName) {
        echo.leave(currentChannelName);
    }

    window.removeEventListener("beforeprint", handleBeforePrint);
    window.removeEventListener("afterprint", handleAfterPrint);
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

/* Печать: страница обычно зажата в фиксированную высоту (100vh, overflow:hidden)
   под чат-разметку — для печати это нужно снять, иначе распечатается только
   то, что видно на экране, а не весь дашборд целиком. */
@media print {
    .d-print-none {
        display: none !important;
    }

    body.chat-page .page,
    html, body {
        height: auto !important;
        overflow: visible !important;
    }

    .dashboard-wrapper,
    .dashboard-main {
        display: block !important;
        overflow: visible !important;
        height: auto !important;
    }

    .widgets-content {
        page-break-inside: avoid;
        break-inside: avoid;
    }
}
</style>

<template>
    <div class="dashboard-wrapper">

        <div class="dashboard-main p-1" id="dashboardMain">

            <div class="page-header d-print-none mb-3 mt-2">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <!-- Чат всегда живёт на источнике — показываем, на каком именно,
                                 и даём вернуться к нему одним кликом. -->
                            <div v-if="chat.data_source" class="page-pretitle d-flex align-items-center gap-2">
                                <router-link
                                    :to="{ name: 'company.source.show', params: { id: chat.data_source.id } }"
                                    class="text-reset text-decoration-none d-inline-flex align-items-center gap-1"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                         stroke-linejoin="round">
                                        <path d="M12 6m-8 0a8 3 0 1 0 16 0a8 3 0 1 0 -16 0" />
                                        <path d="M4 6v6a8 3 0 0 0 16 0v-6" />
                                        <path d="M4 12v6a8 3 0 0 0 16 0v-6" />
                                    </svg>
                                    {{ chat.data_source.name }}
                                </router-link>
                                <span class="badge bg-secondary-lt">
                                    {{ chat.data_source.format_label }}
                                </span>
                            </div>
                            <h2 v-if="hasDashboards" class="page-title">{{ currentDashboard?.name }}</h2>
                        </div>

                        <div class="col-auto ms-auto d-print-none">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <!-- Появляется, как только тип хоть одного виджета
                                     изменён: смена применяется сразу для превью,
                                     но в базу попадает только отсюда. -->
                                <template v-if="hasTypeChanges">
                                    <button class="btn btn-link link-secondary" type="button"
                                            :disabled="savingTypes" @click="resetWidgetTypes">
                                        Отменить
                                    </button>
                                    <button class="btn btn-primary" type="button"
                                            :class="{ 'btn-loading': savingTypes }"
                                            :disabled="savingTypes" @click="saveWidgetTypes">
                                        Сохранить
                                    </button>
                                </template>

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
                                    class="btn"
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

                                <!-- ПЕЧАТЬ -->
                                <button
                                    v-if="hasDashboards"
                                    class="btn"
                                    type="button"
                                    title="Печать"
                                    @click="printDashboard"
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
                                        <path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2" />
                                        <path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4" />
                                        <path d="M7 13m0 2a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z" />
                                    </svg>

                                    Печать
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

                <div v-if="saveTypesError" class="alert alert-danger d-print-none" role="alert">
                    {{ saveTypesError }}
                </div>

                <!-- Пустые состояния — штатный компонент Tabler .empty:
                     он сам задаёт размер картинки, отступы и типографику,
                     поэтому свои 60vh и 270px больше не нужны. -->
                <div v-if="!hasDashboards" class="empty">
                    <div class="empty-img">
                        <img :src="empty_img" alt="" height="128" />
                    </div>
                    <p class="empty-title">Дашбордов пока нет</p>
                    <p class="empty-subtitle text-secondary">
                        Как только для этого чата будет создан дашборд, он появится здесь.
                    </p>
                </div>

                <div v-if="currentDashboard.status === 'generating_scheme'" class="empty">
                    <div class="empty-img">
                        <img :src="generate_img" alt="" height="128" />
                    </div>
                    <p class="empty-title">Генерируем дашборд</p>
                    <p class="empty-subtitle text-secondary">
                        Подбираем виджеты под ваш запрос.
                    </p>
                    <div class="progress progress-sm w-50">
                        <div class="progress-bar progress-bar-indeterminate"></div>
                    </div>
                </div>

                <template v-else>
                    <div ref="exportArea">
                        <div
                            v-for="widget in widgets"
                            :key="widgetKey(widget)"
                            class="row row-cards widgets-content mb-3"
                        >
                            <div class="col-12">
                                <div class="d-flex align-items-center mb-2">
                                    <!-- Заголовок виджета — обычный h3 шкалы Tabler.
                                         Класс .h3 поверх тега только дублировал размер. -->
                                    <h3 class="mb-0 flex-fill">{{ widget.title }}</h3>

                                    <!-- Выбор варианта отрисовки. В списке только
                                         варианты этого же семейства: данные виджета
                                         посчитаны под его форму. -->
                                    <select
                                        v-if="typesOf(widget).length > 1"
                                        class="form-select form-select-sm w-auto ms-2 d-print-none"
                                        :value="currentTypeId(widget)"
                                        :aria-label="`Тип виджета «${widget.title}»`"
                                        @change="onTypeChange(widget, $event.target.value)"
                                    >
                                        <option v-for="type in typesOf(widget)" :key="type.id" :value="type.id">
                                            {{ type.title || type.name }}
                                        </option>
                                    </select>
                                </div>

                                <WidgetContainer
                                    :widget="widget"
                                    :chat-id="chatId"
                                    :refresh-token="refreshToken"
                                />
                            </div>
                        </div>
                    </div>
                </template>

            </div>
        </div>


        <div class="chat-backdrop d-print-none" :class="{ 'd-none': !chatOpen }" @click="closeChat"></div>

        <AiChatSidebar
            class="d-print-none"
            :open="chatOpen"
            :chat-title="chat.title"
            :chat-id="chatId"
            :dashboard-id="selectedDashboardId"
            :suggestions="chat.suggestions ?? []"
            @close="closeChat"
        />

        <button v-if="!chatOpen" class="chat-fab d-print-none" @click="toggleChat" aria-label="Открыть чат">
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

<!-- Цвета графиков переехали в resources/css/company/app.css: определённые
     здесь, они существовали только на странице чата, а на странице дашборда
     проекта тех же переменных не было. -->
