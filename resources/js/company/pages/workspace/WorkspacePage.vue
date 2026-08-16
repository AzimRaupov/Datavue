<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from "vue";
import { Modal, Offcanvas } from "bootstrap";
import { useRoute, useRouter } from "vue-router";
import Sortable from "sortablejs";
import Echo from "laravel-echo";
import Pusher from "pusher-js";

import api from "../../api.js";
import WidgetContainer from "../../components/WidgetContainer.vue";
import WidgetPalette from "../../components/builder/WidgetPalette.vue";
import WidgetQueryModal from "../../components/builder/WidgetQueryModal.vue";
import WidgetCodeModal from "../../components/builder/WidgetCodeModal.vue";
import AiChatSidebar from "../../components/chat/AiChatSidebar.vue";

/**
 * Рабочее пространство: его дашборды, чат с агентом и конструктор на одной
 * странице.
 *
 * Раньше это были три отдельных адреса — чат со своим списком дашбордов,
 * просмотр дашборда и конструктор. Любое «а поправлю-ка я виджет» означало уход
 * со страницы и возврат обратно, а поговорить с агентом мог только дашборд,
 * который из чата и вырос: собранный руками такой возможности не имел вовсе.
 *
 * Теперь это одно место. Пространство — задача, которую завёл человек: внутри
 * него сколько угодно дашбордов, один разговор и один источник данных.
 * Дашборды переключаются селектором, режим просмотра и сборки — кнопкой, чат
 * живёт сбоку и никуда не уводит. Всё, что делает страница, делается над ОДНИМ
 * открытым дашбордом, поэтому и чат, и конструктор говорят об одном и том же.
 */

window.Pusher = Pusher;

const route = useRoute();
const router = useRouter();

const empty_img = "/static/illustrations/light/chart-circle.png";
const generate_img = "/static/illustrations/light/boy-and-laptop.png";

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

// --- Права ------------------------------------------------------------------

const currentUser = JSON.parse(localStorage.getItem("user") || "null");
const permissions = computed(() => currentUser?.permissions ?? []);
const canEdit = computed(() => permissions.value.includes("edit dashboards"));
const canCreate = computed(() => permissions.value.includes("create dashboards"));
const canWriteCode = computed(() => permissions.value.includes("write widget code"));
const canChat = computed(() => permissions.value.includes("create chats"));

// --- Состояние пространства -------------------------------------------------

const workspace = ref({ workspace: null, data_source: null, dashboards: [], chat: null });
const dashboard = ref(null);
const widgets = ref([]);

const isLoading = ref(true);
const error = ref(null);
const notice = ref(null);
const busy = ref(false);
const isRefreshing = ref(false);
const refreshToken = ref(0);

/**
 * Режим живёт в адресе, а не в памяти компонента: ссылка «открой мне это
 * в конструкторе» должна открывать конструктор, и возврат назад в браузере
 * тоже обязан работать.
 */
const mode = computed(() => (route.query.mode === "edit" && canEdit.value ? "edit" : "view"));
const isEditing = computed(() => mode.value === "edit");

const dashboardId = computed(() => dashboard.value?.id ?? null);
const dashboards = computed(() => workspace.value.dashboards ?? []);
const space = computed(() => workspace.value.workspace ?? null);
const workspaceId = computed(() => space.value?.id ?? null);
const dataSource = computed(() => workspace.value.data_source ?? null);
const chat = computed(() => workspace.value.chat ?? null);

const isGenerating = computed(() =>
    ["generating_scheme", "generating_widgets"].includes(dashboard.value?.status)
);

const DASHBOARD_STATUS = {
    empty: { text: "Пустой", cls: "bg-secondary-lt" },
    generating_scheme: { text: "Генерируется", cls: "bg-azure-lt" },
    generating_widgets: { text: "Считает виджеты", cls: "bg-azure-lt" },
    reviewing: { text: "Проверяется", cls: "bg-azure-lt" },
    completed: { text: "Готов", cls: "bg-green-lt" },
    failed: { text: "Ошибка", cls: "bg-red-lt" },
};

const WIDGET_STATUS = {
    draft: { text: "Нет данных", cls: "bg-secondary-lt" },
    active: { text: "Работает", cls: "bg-green-lt" },
    failed: { text: "Ошибка", cls: "bg-red-lt" },
    inactive: { text: "Выключен", cls: "bg-secondary-lt" },
};

function statusOf(widget) {
    return WIDGET_STATUS[widget.status] ?? WIDGET_STATUS.draft;
}

function dashboardStatus(item) {
    return DASHBOARD_STATUS[item?.status] ?? { text: item?.status ?? "", cls: "bg-secondary-lt" };
}

function typesOf(widget) {
    return widget?.widget?.types ?? [];
}

function currentTypeId(widget) {
    return widget.widget_type_id ?? widget.widget_type?.id ?? null;
}

// --- Загрузка ---------------------------------------------------------------

/**
 * Пространство и открытый в нём дашборд.
 *
 * Оба запроса всегда идут вместе: состав пространства меняется сам собой —
 * агент, отвечая на «добавь график», создаёт СЛЕДУЮЩУЮ версию дашборда,
 * и список обязан это увидеть.
 */
async function loadWorkspace() {
    // Три входа в одно и то же место: по самому пространству, по дашборду
    // и по разговору. Последние два нужны ссылкам, которые знают только их, —
    // из общего списка дашбордов и из прежней версии интерфейса.
    const url = route.params.workspace
        ? `/workspaces/${route.params.workspace}`
            + (route.params.dashboard ? `?dashboard=${route.params.dashboard}` : "")
        : route.params.chat
            ? `/workspaces/by-chat/${route.params.chat}`
            : `/workspaces/by-dashboard/${route.params.dashboard}`;

    const { data } = await api.get(url);

    workspace.value = data;

    return data.current_dashboard_id ?? null;
}

/**
 * Содержимое дашборда.
 *
 * Редактору отдаём расширенную выдачу конструктора: в ней те же виджеты плюс
 * запрос, код и последняя ошибка. Так холст один на оба режима — переключение
 * «просмотр ↔ сборка» не перезапрашивает данные и не мигает.
 */
async function loadDashboard(id) {
    if (!id) {
        dashboard.value = null;
        widgets.value = [];

        return;
    }

    const { data } = await api.get(
        canEdit.value ? `/dashboards/${id}/edit` : `/dashboards/${id}`
    );

    dashboard.value = data;
    widgets.value = data.widgets ?? [];
}

async function load() {
    isLoading.value = true;
    error.value = null;

    try {
        const current = await loadWorkspace();

        await loadDashboard(current);

        subscribeToDashboard();

        // Вход по дашборду или по чату — это ссылка со стороны; адрес приводим
        // к каноническому, чтобы обновление страницы и «назад» вели туда же.
        if (!route.params.workspace && workspaceId.value) {
            await router.replace({
                name: "company.workspace",
                params: { workspace: workspaceId.value, dashboard: current ?? undefined },
                query: route.query,
            });
        }
    } catch (err) {
        error.value =
            err.response?.status === 403
                ? "Нет доступа к этому рабочему пространству."
                : "Не удалось открыть рабочее пространство.";
    } finally {
        isLoading.value = false;
    }

    await nextTick();
    setupSortable();
}

/** Перечитывает структуру дашборда, не трогая содержимое виджетов. */
async function refreshDashboard() {
    if (!dashboardId.value || isRefreshing.value) return;

    isRefreshing.value = true;

    try {
        const id = dashboardId.value;

        await loadDashboard(id);

        // Состав пространства тоже мог измениться: у дашборда сменился статус
        // или агент создал следующую версию.
        if (workspaceId.value) {
            const { data } = await api.get(`/workspaces/${workspaceId.value}?dashboard=${id}`);
            workspace.value = data;
        }
    } catch (err) {
        console.error("Не удалось обновить дашборд:", err);
    } finally {
        isRefreshing.value = false;
    }

    await nextTick();
    setupSortable();
}

async function onRefreshClick() {
    await refreshDashboard();
    refreshToken.value = Date.now();
}

// --- Реалтайм ---------------------------------------------------------------

let currentChannelName = null;

function subscribeToDashboard() {
    if (currentChannelName) {
        echo.leave(currentChannelName);
        currentChannelName = null;
    }

    if (!dashboardId.value) return;

    currentChannelName = `dashboard.${dashboardId.value}`;

    echo.channel(currentChannelName).listen(".DashboardWidgetChanged", () => {
        // Только структура: данные перезапросит сам WidgetContainer и только
        // у виджетов, у которых изменился updated_at.
        refreshDashboard();
    });
}

// --- Переключение дашбордов -------------------------------------------------

function openDashboard(id) {
    if (!id || Number(id) === dashboardId.value) return;

    router.push({
        name: "company.workspace",
        params: { workspace: workspaceId.value, dashboard: Number(id) },
        query: route.query.mode ? { mode: route.query.mode } : {},
    });
}

function setMode(next) {
    if (next === mode.value) return;

    router.replace({
        name: route.name,
        params: route.params,
        query: next === "edit" ? { ...route.query, mode: "edit" } : { ...route.query, mode: undefined },
    });
}

// Адрес — единственный источник правды: и первый заход, и переключение
// дашборда идут одним путём.
watch(
    () => [route.params.workspace, route.params.dashboard, route.params.chat],
    () => {
        // Приведение адреса к каноническому не должно грузить страницу второй
        // раз: открыто ровно то, что в адресе, — поменялась только форма ссылки.
        const sameWorkspace = Number(route.params.workspace) === workspaceId.value;
        const sameDashboard = !route.params.dashboard
            || Number(route.params.dashboard) === dashboardId.value;

        if (sameWorkspace && sameDashboard) return;

        load();
    },
    { immediate: true }
);

// Конструктор появляется и исчезает вместе с режимом — вместе с ним
// появляется и перетаскивание карточек.
watch(isEditing, async () => {
    await nextTick();
    setupSortable();

    if (isEditing.value && canEdit.value) await loadSchema();
});

// --- Схема источника для конструктора ---------------------------------------

const schema = ref([]);
const dictionary = ref({});
let schemaLoadedFor = null;

async function loadSchema() {
    if (!dashboardId.value || schemaLoadedFor === dashboardId.value) return;

    try {
        const { data } = await api.get(`/dashboards/${dashboardId.value}/schema`);

        schema.value = data.tables ?? [];
        dictionary.value = {
            aggregates: data.aggregates ?? {},
            grains: data.grains ?? {},
            operators: data.operators ?? {},
            join_types: data.join_types ?? {},
            relations: data.relations ?? [],
            default_limit: data.default_limit ?? 100,
        };

        schemaLoadedFor = dashboardId.value;
    } catch (err) {
        // Без схемы конструктор соберёт запрос только на вкладке SQL —
        // страница из-за этого открываться не перестаёт.
        schema.value = [];
    }
}

watch(dashboardId, () => {
    schemaLoadedFor = null;

    if (isEditing.value && canEdit.value) loadSchema();
});

// --- Правка виджетов --------------------------------------------------------

const paletteEl = ref(null);
let palette = null;

const canvas = ref(null);
let sortable = null;

const queryModal = ref(null);
const codeModal = ref(null);
const editingWidget = ref(null);

function replaceWidget(updated) {
    widgets.value = widgets.value.map((widget) =>
        widget.id === updated.id ? { ...widget, ...updated } : widget
    );
}

function openPalette() {
    palette?.show();
}

async function addWidget({ widget_id, widget_type_id, family }) {
    if (busy.value || !dashboardId.value) return;

    busy.value = true;
    notice.value = null;

    try {
        const { data } = await api.post(`/dashboards/${dashboardId.value}/widgets`, {
            widget_id,
            widget_type_id,
            title: `Новый виджет — ${family.name}`,
        });

        widgets.value.push(data);
        notice.value = "Виджет добавлен. Настройте данные, чтобы он посчитался.";
        palette?.hide();

        await nextTick();
        setupSortable();
    } catch (err) {
        notice.value = err.response?.data?.message || "Не удалось добавить виджет.";
    } finally {
        busy.value = false;
    }
}

async function renameWidget(widget, title) {
    const trimmed = (title ?? "").trim();

    if (!trimmed || trimmed === widget.title) return;

    try {
        const { data } = await api.patch(
            `/dashboards/${dashboardId.value}/widgets/${widget.id}`,
            { title: trimmed }
        );

        replaceWidget(data);
    } catch (err) {
        notice.value = err.response?.data?.message || "Не удалось переименовать виджет.";
    }
}

/**
 * Смена вида сохраняется сразу.
 *
 * Отложенное сохранение «поменял несколько — нажал Сохранить» здесь только
 * мешало бы: вместе с видом сервер пересобирает запрос виджета (счётчику
 * с полосой выполнения нужен процент, пузырьковой — размер точки), и держать
 * половину дашборда в несохранённом состоянии незачем.
 */
async function changeType(widget, typeId) {
    try {
        const { data } = await api.patch(
            `/dashboards/${dashboardId.value}/widgets/${widget.id}`,
            { widget_type_id: Number(typeId) }
        );

        replaceWidget(data);
    } catch (err) {
        notice.value = err.response?.data?.message || "Не удалось сменить вид.";
    }
}

async function removeWidget(widget) {
    if (busy.value) return;

    busy.value = true;

    try {
        await api.delete(`/dashboards/${dashboardId.value}/widgets/${widget.id}`);
        widgets.value = widgets.value.filter((item) => item.id !== widget.id);
    } catch (err) {
        notice.value = err.response?.data?.message || "Не удалось удалить виджет.";
    } finally {
        busy.value = false;
    }
}

/**
 * Перестановка мышью. Позиции пересчитываются подряд и уходят на сервер одним
 * запросом: половина сохранённого порядка хуже, чем несохранённый.
 */
async function moveWidget(from, to) {
    if (from === to || from < 0 || to < 0) return;

    const list = [...widgets.value];
    const [moved] = list.splice(from, 1);
    list.splice(to, 0, moved);

    widgets.value = list.map((widget, position) => ({ ...widget, position }));

    try {
        await api.put(`/dashboards/${dashboardId.value}/reorder`, {
            widgets: widgets.value.map((widget, position) => ({ id: widget.id, position })),
        });
    } catch (err) {
        notice.value = "Порядок не сохранён — обновите страницу.";
    }
}

function setupSortable() {
    sortable?.destroy();
    sortable = null;

    if (!isEditing.value || !canvas.value) return;

    sortable = Sortable.create(canvas.value, {
        handle: ".builder-drag",
        draggable: ".builder-card",
        // Заголовок, выбор вида и меню лежат в самой ручке: без этого клик
        // по ним начинал бы перетаскивание вместо редактирования.
        filter: "input, select, button, .dropdown-menu",
        preventOnFilter: false,
        animation: 150,
        ghostClass: "builder-card--ghost",
        onEnd: (event) => {
            const { oldIndex, newIndex, item, from } = event;

            if (oldIndex === newIndex) return;

            // Sortable уже переставил узел в DOM, а список рисует Vue по своим
            // данным. Возвращаем узел на место и меняем порядок в данных —
            // иначе карточка «прыгает» дважды.
            const anchor = from.children[oldIndex > newIndex ? oldIndex + 1 : oldIndex];
            from.insertBefore(item, anchor ?? null);

            moveWidget(oldIndex, newIndex);
        },
    });
}

function openQuery(widget) {
    editingWidget.value = widget;
    queryModal.value?.show();
}

function openCode(widget) {
    editingWidget.value = widget;
    codeModal.value?.show();
}

function hasPythonCode(widget) {
    return Boolean(widget.code);
}

function onWidgetSaved(updated) {
    if (!updated) return;

    replaceWidget(updated);
    editingWidget.value = { ...editingWidget.value, ...updated };

    // Данные виджета перезапрашиваются принудительно: запрос изменился,
    // а WidgetContainer сам об этом не узнает.
    refreshToken.value = Date.now();
}

// --- Новый дашборд в пространстве -------------------------------------------

const createModalEl = ref(null);
let createModal = null;
const createName = ref("");
const creating = ref(false);
const createError = ref(null);

async function openCreateModal() {
    createName.value = "";
    createError.value = null;

    await nextTick();
    createModal?.show();
}

async function submitCreate() {
    if (creating.value) return;

    creating.value = true;
    createError.value = null;

    try {
        const { data } = await api.post("/dashboards", {
            name: createName.value,
            workspace_id: workspaceId.value,
        });

        createModal?.hide();

        // Новый дашборд пуст — открываем сразу в конструкторе: смотреть в нём
        // пока не на что.
        router.push({
            name: "company.workspace",
            params: { workspace: workspaceId.value, dashboard: data.id },
            query: { mode: "edit" },
        });
    } catch (err) {
        createError.value = err.response?.data?.message || "Не удалось создать дашборд.";
    } finally {
        creating.value = false;
    }
}

// --- Чат --------------------------------------------------------------------

const chatOpen = ref(localStorage.getItem("workspaceChatOpen") !== "0");
const openingChat = ref(false);

watch(chatOpen, (value) => localStorage.setItem("workspaceChatOpen", value ? "1" : "0"));

function closeChat() {
    chatOpen.value = false;
}

/**
 * Открывает разговор пространства.
 *
 * Он один на всю задачу и заводится на месте — без ухода на другую страницу
 * и без выбора «а к какому чату это относится». Именно это делает дашборд,
 * собранный руками, обсуждаемым: раньше агент умел править только то,
 * что сам и построил.
 */
async function openAssistant() {
    if (chat.value) {
        chatOpen.value = true;

        return;
    }

    if (!workspaceId.value || openingChat.value || !canChat.value) return;

    openingChat.value = true;
    notice.value = null;

    try {
        const { data } = await api.post(`/workspaces/${workspaceId.value}/chat`);

        workspace.value = { ...workspace.value, chat: data.chat };
        chatOpen.value = true;
    } catch (err) {
        notice.value = err.response?.data?.message || "Не удалось открыть чат.";
    } finally {
        openingChat.value = false;
    }
}

/**
 * Агент построил дашборд.
 *
 * Перегенерация не правит дашборд на месте, а создаёт следующую версию,
 * поэтому переходим на неё. Раньше это был переход на другую страницу —
 * теперь просто смена открытого дашборда, чат при этом не перезагружается.
 */
function onChatDashboard(id) {
    if (!id) return;

    openDashboard(id);
}

// --- Печать -----------------------------------------------------------------

const exportArea = ref(null);

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

// --- Жизненный цикл ---------------------------------------------------------

onMounted(async () => {
    document.body.classList.add("chat-page");

    await nextTick();

    if (paletteEl.value) palette = new Offcanvas(paletteEl.value);
    if (createModalEl.value) createModal = new Modal(createModalEl.value);

    if (isEditing.value && canEdit.value) await loadSchema();

    window.addEventListener("beforeprint", handleBeforePrint);
    window.addEventListener("afterprint", handleAfterPrint);
});

onBeforeUnmount(() => {
    document.body.classList.remove("chat-page");

    sortable?.destroy();
    palette?.dispose();
    createModal?.dispose();

    if (currentChannelName) echo.leave(currentChannelName);

    window.removeEventListener("beforeprint", handleBeforePrint);
    window.removeEventListener("afterprint", handleAfterPrint);
});
</script>

<template>
    <div class="dashboard-wrapper">
        <div class="dashboard-main p-1">
            <!-- ШАПКА -->
            <div class="page-header d-print-none mb-3 mt-2">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <!-- Где мы находимся: пространство, а внутри него —
                                 открытый дашборд. Источник рядом, потому что
                                 по нему считают все дашборды пространства. -->
                            <div class="page-pretitle d-flex align-items-center gap-2 flex-wrap">
                                <router-link :to="{ name: 'company.workspaces' }"
                                             class="text-reset text-decoration-none">
                                    Пространства
                                </router-link>
                                <span class="text-secondary">/</span>
                                <span class="fw-bold text-truncate">{{ space?.name }}</span>

                                <template v-if="dataSource">
                                    <span class="text-secondary">·</span>
                                    <router-link
                                        :to="{ name: 'company.source.show', params: { id: dataSource.id } }"
                                        class="text-reset text-decoration-none d-inline-flex align-items-center gap-1"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 6m-8 0a8 3 0 1 0 16 0a8 3 0 1 0 -16 0" />
                                            <path d="M4 6v6a8 3 0 0 0 16 0v-6" />
                                            <path d="M4 12v6a8 3 0 0 0 16 0v-6" />
                                        </svg>
                                        {{ dataSource.name }}
                                    </router-link>
                                </template>
                            </div>

                            <h2 v-if="dashboard" class="page-title d-flex align-items-center gap-2">
                                {{ dashboard.name || `Дашборд #${dashboard.id}` }}
                                <span class="badge" :class="dashboardStatus(dashboard).cls">
                                    {{ dashboardStatus(dashboard).text }}
                                </span>
                            </h2>
                            <h2 v-else class="page-title">{{ space?.name || "Рабочее пространство" }}</h2>
                        </div>

                        <div class="col-auto ms-auto d-print-none">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <!-- Дашборды пространства. Переключение остаётся
                                     на странице: уходить ради этого некуда. -->
                                <select
                                    v-if="dashboards.length > 1"
                                    class="form-select"
                                    style="width: 240px; max-width: 100%;"
                                    :value="dashboardId"
                                    aria-label="Дашборд рабочего пространства"
                                    @change="openDashboard($event.target.value)"
                                >
                                    <option v-for="item in dashboards" :key="item.id" :value="item.id">
                                        {{ item.name || `Дашборд #${item.id}` }}
                                        ({{ item.widgets_count ?? 0 }})
                                    </option>
                                </select>

                                <button v-if="canCreate && workspaceId" class="btn" type="button"
                                        title="Новый дашборд в этом пространстве" @click="openCreateModal">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linecap="round" stroke-linejoin="round" class="icon">
                                        <path d="M12 5l0 14" />
                                        <path d="M5 12l14 0" />
                                    </svg>
                                </button>

                                <!-- Просмотр и сборка — два состояния одной
                                     страницы, а не две страницы. -->
                                <div v-if="canEdit && dashboard" class="btn-group" role="group"
                                     aria-label="Режим работы">
                                    <button type="button" class="btn"
                                            :class="{ active: !isEditing }" @click="setMode('view')">
                                        Просмотр
                                    </button>
                                    <button type="button" class="btn"
                                            :class="{ active: isEditing }" @click="setMode('edit')">
                                        Конструктор
                                    </button>
                                </div>

                                <button v-if="isEditing" class="btn btn-primary" type="button"
                                        :disabled="!dashboard" @click="openPalette">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linecap="round" stroke-linejoin="round" class="icon me-1">
                                        <path d="M12 5l0 14" />
                                        <path d="M5 12l14 0" />
                                    </svg>
                                    Виджет
                                </button>

                                <button v-if="dashboard" class="btn" type="button" title="Обновить дашборд"
                                        :disabled="isRefreshing" @click="onRefreshClick">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linecap="round" stroke-linejoin="round"
                                         :class="{ 'icon-spin': isRefreshing }">
                                        <path d="M20 11a8.1 8.1 0 0 0-15.5-2M4 5v4h4" />
                                        <path d="M4 13a8.1 8.1 0 0 0 15.5 2M20 19v-4h-4" />
                                    </svg>
                                </button>

                                <button v-if="dashboard && !isEditing" class="btn" type="button" title="Печать"
                                        @click="printDashboard">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linecap="round" stroke-linejoin="round" class="icon">
                                        <path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2" />
                                        <path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4" />
                                        <path d="M7 13m0 2a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z" />
                                    </svg>
                                </button>

                                <button
                                    v-if="!chatOpen || !chat"
                                    class="btn btn-primary d-inline-flex align-items-center text-nowrap px-3"
                                    :class="{ 'btn-loading': openingChat }"
                                    :disabled="openingChat || (!chat && (!canChat || !workspaceId))"
                                    title="AI ассистент"
                                    @click="openAssistant"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linecap="round" stroke-linejoin="round" class="icon me-2">
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
                <div v-if="error" class="alert alert-danger d-print-none">{{ error }}</div>

                <div v-if="notice" class="alert alert-info alert-dismissible d-print-none" role="status">
                    {{ notice }}
                    <button type="button" class="btn-close" aria-label="Закрыть" @click="notice = null"></button>
                </div>

                <div v-if="isLoading" class="card">
                    <div class="card-body">
                        <div class="progress progress-sm">
                            <div class="progress-bar progress-bar-indeterminate"></div>
                        </div>
                    </div>
                </div>

                <!-- Пространство без дашбордов: так выглядит только что созданный
                     разговор — дашборд появится, как только его попросят. -->
                <div v-else-if="!dashboard" class="empty">
                    <div class="empty-img">
                        <img :src="empty_img" alt="" height="128" />
                    </div>
                    <p class="empty-title">Дашбордов пока нет</p>
                    <p class="empty-subtitle text-secondary">
                        Опишите нужное словами в чате справа — или соберите дашборд сами.
                    </p>
                    <div v-if="canCreate && workspaceId" class="empty-action">
                        <button class="btn btn-primary" type="button" @click="openCreateModal">
                            Создать дашборд
                        </button>
                    </div>
                </div>

                <div v-else-if="isGenerating && !widgets.length" class="empty">
                    <div class="empty-img">
                        <img :src="generate_img" alt="" height="128" />
                    </div>
                    <p class="empty-title">Генерируем дашборд</p>
                    <p class="empty-subtitle text-secondary">Подбираем виджеты под ваш запрос.</p>
                    <div class="progress progress-sm w-50">
                        <div class="progress-bar progress-bar-indeterminate"></div>
                    </div>
                </div>

                <div v-else-if="!widgets.length" class="card">
                    <div class="card-body">
                        <div class="empty">
                            <p class="empty-title">Дашборд пока пуст</p>
                            <p class="empty-subtitle text-secondary">
                                Добавьте виджет и настройте данные — или попросите агента
                                собрать дашборд за вас.
                            </p>
                            <div v-if="canEdit" class="empty-action">
                                <button class="btn btn-primary" type="button"
                                        @click="isEditing ? openPalette() : setMode('edit')">
                                    Добавить виджет
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ХОЛСТ. Один на оба режима: в сборке к карточке добавляются
                     хват, заголовок и меню, всё остальное — то же самое, что
                     увидит смотрящий. -->
                <div v-else ref="exportArea">
                    <div ref="canvas">
                        <template v-for="widget in widgets" :key="widget.id">
                            <!-- Режим сборки -->
                            <div v-if="isEditing" class="card mb-3 builder-card">
                                <div class="card-header builder-drag d-flex align-items-center gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linecap="round" stroke-linejoin="round"
                                         class="text-muted builder-grip flex-shrink-0" aria-hidden="true">
                                        <path d="M9 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                                        <path d="M9 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                                        <path d="M9 19m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                                        <path d="M15 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                                        <path d="M15 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                                        <path d="M15 19m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                                    </svg>

                                    <input
                                        class="form-control form-control-flush fw-bold flex-fill builder-title"
                                        :value="widget.title"
                                        aria-label="Заголовок виджета"
                                        @change="renameWidget(widget, $event.target.value)"
                                    />

                                    <span class="badge flex-shrink-0" :class="statusOf(widget).cls">
                                        {{ statusOf(widget).text }}
                                    </span>

                                    <select
                                        v-if="typesOf(widget).length > 1"
                                        class="form-select form-select-sm w-auto flex-shrink-0"
                                        :value="currentTypeId(widget)"
                                        :aria-label="`Вид виджета «${widget.title}»`"
                                        @change="changeType(widget, $event.target.value)"
                                    >
                                        <option v-for="type in typesOf(widget)" :key="type.id" :value="type.id">
                                            {{ type.title || type.name }}
                                        </option>
                                    </select>

                                    <div class="dropdown flex-shrink-0">
                                        <button class="btn btn-sm btn-ghost-secondary px-2" type="button"
                                                data-bs-toggle="dropdown" aria-label="Действия с виджетом"
                                                aria-expanded="false">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                                                 viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                 aria-hidden="true">
                                                <path d="M12 6m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                                                <path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                                                <path d="M12 18m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                                            </svg>
                                        </button>

                                        <div class="dropdown-menu dropdown-menu-end">
                                            <button v-if="canWriteCode" class="dropdown-item" type="button"
                                                    @click="openQuery(widget)">
                                                Настроить данные
                                            </button>

                                            <button v-if="canWriteCode && hasPythonCode(widget)"
                                                    class="dropdown-item" type="button" @click="openCode(widget)">
                                                Код на Python
                                            </button>

                                            <div class="dropdown-divider"></div>
                                            <button class="dropdown-item text-danger" type="button"
                                                    :disabled="busy" @click="removeWidget(widget)">
                                                Удалить виджет
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="card-body">
                                    <div v-if="widget.last_error" class="alert alert-danger">
                                        <div class="fw-bold">Виджет не считается</div>
                                        <pre class="mb-0 workspace-widget-error">{{ widget.last_error }}</pre>
                                    </div>

                                    <WidgetContainer
                                        :widget="widget"
                                        :chat-id="chat?.id ?? null"
                                        :refresh-token="refreshToken"
                                    />

                                    <div v-if="widget.status === 'draft' && canWriteCode" class="mt-2">
                                        <button class="btn btn-sm" type="button" @click="openQuery(widget)">
                                            Настроить данные
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Режим просмотра -->
                            <div v-else class="row row-cards widgets-content mb-3">
                                <div class="col-12">
                                    <div class="d-flex align-items-center mb-2">
                                        <h3 class="mb-0 flex-fill">{{ widget.title }}</h3>

                                        <select
                                            v-if="canEdit && typesOf(widget).length > 1"
                                            class="form-select form-select-sm w-auto ms-2 d-print-none"
                                            :value="currentTypeId(widget)"
                                            :aria-label="`Вид виджета «${widget.title}»`"
                                            @change="changeType(widget, $event.target.value)"
                                        >
                                            <option v-for="type in typesOf(widget)" :key="type.id" :value="type.id">
                                                {{ type.title || type.name }}
                                            </option>
                                        </select>
                                    </div>

                                    <WidgetContainer
                                        :widget="widget"
                                        :chat-id="chat?.id ?? null"
                                        :refresh-token="refreshToken"
                                    />
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- КАТАЛОГ ВИДЖЕТОВ -->
        <div v-if="canEdit" ref="paletteEl" class="offcanvas offcanvas-end" tabindex="-1"
             aria-labelledby="workspace-palette-title">
            <div class="offcanvas-header">
                <h2 class="offcanvas-title" id="workspace-palette-title">Добавить виджет</h2>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Закрыть"></button>
            </div>
            <div class="offcanvas-body">
                <WidgetPalette embedded @add="addWidget" />
            </div>
        </div>

        <!-- НОВЫЙ ДАШБОРД -->
        <div v-if="canCreate" ref="createModalEl" class="modal modal-blur fade" tabindex="-1"
             role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <form @submit.prevent="submitCreate">
                        <div class="modal-header">
                            <h5 class="modal-title">Новый дашборд</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Закрыть"></button>
                        </div>

                        <div class="modal-body">
                            <div v-if="createError" class="alert alert-danger" role="alert">
                                {{ createError }}
                            </div>

                            <label class="form-label required">Название</label>
                            <input v-model="createName" type="text" class="form-control"
                                   placeholder="Продажи по регионам" maxlength="255" required />
                            <small class="form-hint">
                                Считать будет по источнику «{{ dataSource?.name }}» — тому же,
                                что и остальные дашборды пространства «{{ space?.name }}».
                            </small>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">
                                Отмена
                            </button>
                            <button type="submit" class="btn btn-primary" :class="{ 'btn-loading': creating }"
                                    :disabled="creating || !createName.trim()">
                                Создать и открыть
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- НАСТРОЙКА ВИДЖЕТА -->
        <WidgetQueryModal
            v-if="canWriteCode && dashboardId"
            ref="queryModal"
            :dashboard-id="dashboardId"
            :widget="editingWidget"
            :schema="schema"
            :dictionary="dictionary"
            @saved="onWidgetSaved"
        />

        <WidgetCodeModal
            v-if="canWriteCode && dashboardId"
            ref="codeModal"
            :dashboard-id="dashboardId"
            :widget="editingWidget"
            :schema="schema"
            @saved="onWidgetSaved"
        />

        <!-- ЧАТ -->
        <div class="chat-backdrop d-print-none" :class="{ 'd-none': !chatOpen || !chat }"
             @click="closeChat"></div>

        <AiChatSidebar
            v-if="chat"
            :key="chat.id"
            class="d-print-none"
            :open="chatOpen"
            :chat-title="chat.title"
            :chat-id="chat.id"
            :dashboard-id="dashboardId"
            :suggestions="chat.suggestions ?? []"
            @close="closeChat"
            @dashboard="onChatDashboard"
        />

        <button v-if="chat && !chatOpen" class="chat-fab d-print-none" @click="chatOpen = true"
                aria-label="Открыть чат">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 8a4 4 0 0 1 4 4" />
                <path d="M12 4a8 8 0 0 1 8 8" />
                <path d="M12 20a8 8 0 0 1-8-8" />
                <circle cx="12" cy="12" r="1" />
            </svg>
        </button>
    </div>
</template>

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

/* Печать: страница зажата в 100vh под разметку с чатом — для печати это нужно
   снять, иначе распечатается только видимая часть, а не весь дашборд. */
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

<style scoped>
/* Шапка карточки — она же ручка перетаскивания. */
.builder-drag {
    cursor: grab;
}

.builder-drag:active {
    cursor: grabbing;
}

.builder-title {
    cursor: text;
}

.builder-drag select {
    cursor: pointer;
}

.builder-card--ghost {
    opacity: 0.4;
}

/* Точки-хват заметны только когда карточка под курсором: в покое они
   не отвлекают от самих данных. */
.builder-grip {
    opacity: 0;
    transition: opacity 0.15s;
}

.builder-card:hover .builder-grip {
    opacity: 1;
}

.workspace-widget-error {
    font-size: 12px;
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 160px;
    overflow: auto;
}
</style>
