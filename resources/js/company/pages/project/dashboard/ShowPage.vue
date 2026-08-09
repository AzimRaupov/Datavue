<script setup>
import { ref, computed, onMounted, onUnmounted } from "vue";
import { useRoute } from "vue-router";
import api from "../../../api.js";
import WidgetContainer from "../../../components/WidgetContainer.vue";
import Echo from "laravel-echo";
import Pusher from "pusher-js";

window.Pusher = Pusher;

const empty_img = "/static/illustrations/light/chart-circle.png";
const generate_img = "/static/illustrations/light/boy-and-laptop.png";

const route = useRoute();

const dashboardId = computed(() => Number(route.params.dashboard));

const currentDashboard = ref({});
const widgets = ref([]);
const error = ref(null);
const isLoading = ref(false);
const isRefreshing = ref(false);
/**
 * Ручная смена типа отрисовки виджета — см. ChatPage.vue.
 * Выбор ограничен вариантами того же семейства: данные виджета
 * посчитаны под его форму.
 */
const pendingTypes = ref({});
const originalTypes = ref({});
const savingTypes = ref(false);
const saveTypesError = ref(null);

const hasTypeChanges = computed(() => Object.keys(pendingTypes.value).length > 0);

function typesOf(widget) {
    return widget?.widget?.types ?? [];
}

function currentTypeId(widget) {
    return widget.widget_type_id ?? widget.widget_type?.id ?? null;
}

function rememberOriginalTypes() {
    const map = {};
    for (const w of widgets.value) map[w.id] = currentTypeId(w);
    originalTypes.value = map;
    pendingTypes.value = {};
}

function onTypeChange(widget, typeId) {
    const id = Number(typeId);
    const type = typesOf(widget).find(t => t.id === id);
    if (!type) return;

    widget.widget_type_id = id;
    widget.widget_type = type;

    if (originalTypes.value[widget.id] === id) {
        delete pendingTypes.value[widget.id];
    } else {
        pendingTypes.value[widget.id] = id;
    }
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

let currentChannelName = null;

// Токен ручного обновления контента виджетов.
const refreshToken = ref(0);

function widgetKey(widget) {
    return widget.id;
}

// Ссылка на область дашборда, которую будем экспортировать/печатать
const exportArea = ref(null);

async function getCurrentDashboard() {
    if (!dashboardId.value) {
        error.value = "Дашборд не найден";
        return;
    }

    isLoading.value = true;
    error.value = null;

    try {
        const { data } = await api.get(`/dashboards/${dashboardId.value}`);
        currentDashboard.value = data;
        widgets.value = data.widgets ?? [];
        rememberOriginalTypes();
    } catch (err) {
        console.error(err);
        error.value = "Не удалось загрузить дашборд";
    } finally {
        isLoading.value = false;
    }
}

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

            // См. ChatPage.vue: только структура. Данные перезапросит сам
            // WidgetContainer, и только у виджетов с изменившимся updated_at.
            refreshWidgets();
        });
}

onMounted(async () => {
    await getCurrentDashboard();
    subscribeToDashboardChannel();

    window.addEventListener("beforeprint", handleBeforePrint);
    window.addEventListener("afterprint", handleAfterPrint);
});

onUnmounted(() => {
    if (currentChannelName) {
        echo.leave(currentChannelName);
    }
    window.removeEventListener("beforeprint", handleBeforePrint);
    window.removeEventListener("afterprint", handleAfterPrint);
});
</script>

<style>
.icon-spin {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Печать: скрываем служебные элементы и разворачиваем весь контент,
   чтобы печаталось не то, что видно на экране, а весь дашборд целиком */
@media print {
    .d-print-none {
        display: none !important;
    }

    html, body {
        height: auto !important;
        overflow: visible !important;
    }

    .page,
    .page-body,
    .container-xl,
    .widgets-content {
        overflow: visible !important;
        height: auto !important;
        max-height: none !important;
    }

    .widgets-content {
        page-break-inside: avoid;
        break-inside: avoid;
    }
}
</style>

<template>
    <div class="page">
        <div class="page-header d-print-none mb-3 mt-2">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <h2 v-if="currentDashboard?.name" class="page-title">
                            {{ currentDashboard.name }}
                        </h2>
                    </div>

                    <div class="col-auto ms-auto d-print-none">
                        <div class="d-flex align-items-center gap-2 flex-wrap">

                            <!-- Появляется, как только изменён тип хотя бы одного
                                 виджета: превью меняется сразу, в базу — отсюда. -->
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

                            <!-- КНОПКА ОБНОВЛЕНИЯ -->
                            <button
                                v-if="currentDashboard?.id"
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
                                v-if="currentDashboard?.id"
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

                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-xl">

            <div
                v-if="saveTypesError"
                class="alert alert-danger d-print-none"
                role="alert"
            >
                {{ saveTypesError }}
            </div>

            <!-- См. ChatPage.vue: пустые состояния на штатном .empty. -->
            <div v-if="error" class="empty">
                <div class="empty-img">
                    <img :src="empty_img" alt="" height="128" />
                </div>
                <p class="empty-title">{{ error }}</p>
            </div>

            <div v-else-if="currentDashboard.status === 'generating_scheme'" class="empty">
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

            <!-- Область, которая экспортируется в PDF/Word и печатается -->
            <template v-else>
                <div ref="exportArea">
                    <div
                        v-for="widget in widgets"
                        :key="widgetKey(widget)"
                        class="row row-cards widgets-content mb-3"
                    >
                        <div class="col-12">
                            <div class="d-flex align-items-center mb-2">
                                <h3 class="mb-0 flex-fill">{{ widget.title }}</h3>
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
                                :chat-id="currentDashboard.chat_id"
                                :refresh-token="refreshToken"
                            />
                        </div>
                    </div>
                </div>
            </template>

        </div>
    </div>
</template>
