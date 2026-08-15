<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from "vue";
import { Offcanvas } from "bootstrap";
import Sortable from "sortablejs";
import { useRoute, useRouter } from "vue-router";
import api from "../../../api.js";
import WidgetContainer from "../../../components/WidgetContainer.vue";
import WidgetPalette from "../../../components/builder/WidgetPalette.vue";
import WidgetQueryModal from "../../../components/builder/WidgetQueryModal.vue";
import WidgetCodeModal from "../../../components/builder/WidgetCodeModal.vue";

/**
 * Рабочее место сборки дашборда.
 *
 * Второй способ получить дашборд: не через чат и модель, а руками — выбрать
 * виджет из каталога и написать ему код. Виджеты рисуются тем же
 * WidgetContainer, что и на готовом дашборде, поэтому здесь видно ровно то,
 * что увидит зритель.
 *
 * Раскладка пока линейная: порядок задаётся position, перестановка — стрелками.
 * Свободная сетка с перетаскиванием — отдельная задача, и её отсутствие ничего
 * не ломает: просмотр строится по тому же порядку.
 */

const route = useRoute();
const router = useRouter();

const dashboardId = computed(() => Number(route.params.dashboard));

const dashboard = ref(null);
const widgets = ref([]);
const schema = ref([]);
const dictionary = ref({});
const isLoading = ref(true);
const error = ref(null);
const notice = ref(null);
const busy = ref(false);
const refreshToken = ref(0);

const queryModal = ref(null);
const codeModal = ref(null);
const editingWidget = ref(null);

/**
 * Каталог виджетов лежит в выдвижной панели справа и открывается кнопкой.
 * Постоянно висящий список занимает место и отвлекает: на дашборд смотрят
 * чаще, чем добавляют виджеты.
 */
const paletteEl = ref(null);
let palette = null;

/** Холст с виджетами — по нему работает перетаскивание. */
const canvas = ref(null);
let sortable = null;

const currentUser = JSON.parse(localStorage.getItem("user") || "null");
const permissions = computed(() => currentUser?.permissions ?? []);
const canWriteCode = computed(() => permissions.value.includes("write widget code"));

const statusLabels = {
    draft: { text: "Нет кода", cls: "bg-secondary-lt" },
    active: { text: "Работает", cls: "bg-green-lt" },
    failed: { text: "Ошибка", cls: "bg-red-lt" },
    inactive: { text: "Выключен", cls: "bg-secondary-lt" },
};

function statusOf(widget) {
    return statusLabels[widget.status] ?? statusLabels.draft;
}

function typesOf(widget) {
    return widget?.widget?.types ?? [];
}

async function load() {
    isLoading.value = true;
    error.value = null;

    try {
        const { data } = await api.get(`/dashboards/${dashboardId.value}/edit`);
        dashboard.value = data;
        widgets.value = data.widgets ?? [];
    } catch (err) {
        error.value =
            err.response?.status === 403
                ? "Нет прав на редактирование этого дашборда."
                : "Не удалось загрузить дашборд.";
    } finally {
        isLoading.value = false;
    }
}

async function loadSchema() {
    try {
        const { data } = await api.get(`/dashboards/${dashboardId.value}/schema`);

        schema.value = data.tables ?? [];

        // Функции, округления дат и условия приходят с сервера — панель не
        // держит их копию, которая разошлась бы со сборщиком запроса.
        dictionary.value = {
            aggregates: data.aggregates ?? {},
            grains: data.grains ?? {},
            operators: data.operators ?? {},
            default_limit: data.default_limit ?? 100,
        };
    } catch (err) {
        // Без схемы конструктор работать не может, но страница открыться должна:
        // виджеты видно, порядок меняется, а запрос пишется на вкладке SQL.
        schema.value = [];
    }
}

async function addWidget({ widget_id, widget_type_id, family }) {
    if (busy.value) return;

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

        // Панель закрывается сама: добавили — вернулись к дашборду.
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

async function changeType(widget, typeId) {
    const id = Number(typeId);

    try {
        const { data } = await api.patch(
            `/dashboards/${dashboardId.value}/widgets/${widget.id}`,
            { widget_type_id: id }
        );

        replaceWidget(data);
    } catch (err) {
        notice.value = err.response?.data?.message || "Не удалось сменить тип.";
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
 * Перестановка мышью. Позиции пересчитываются подряд и уходят на сервер
 * одним запросом: половина сохранённого порядка хуже, чем несохранённый.
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

/**
 * Перетаскивание карточек.
 *
 * Хват — за шапку карточки: тянуть за область графика неудобно, там
 * начинаются собственные жесты диаграммы.
 */
function setupSortable() {
    sortable?.destroy();

    if (!canvas.value) return;

    sortable = Sortable.create(canvas.value, {
        handle: ".builder-drag",
        draggable: ".builder-card",
        // Заголовок, выбор вида и меню лежат в самой ручке: без этого
        // клик по ним начинал бы перетаскивание вместо редактирования.
        filter: "input, select, button, .dropdown-menu",
        preventOnFilter: false,
        animation: 150,
        ghostClass: "builder-card--ghost",
        onEnd: (event) => {
            const { oldIndex, newIndex, item, from } = event;

            if (oldIndex === newIndex) return;

            // Sortable уже переставил узел в DOM, а список рисует Vue по
            // своим данным. Возвращаем узел на место и меняем порядок в
            // данных — иначе карточка «прыгает» дважды.
            const anchor = from.children[oldIndex > newIndex ? oldIndex + 1 : oldIndex];
            from.insertBefore(item, anchor ?? null);

            moveWidget(oldIndex, newIndex);
        },
    });
}

function openPalette() {
    palette?.show();
}

function replaceWidget(updated) {
    widgets.value = widgets.value.map((widget) =>
        widget.id === updated.id ? { ...widget, ...updated } : widget
    );
}

function openQuery(widget) {
    editingWidget.value = widget;
    queryModal.value?.show();
}

/**
 * Редактор Python открывается только у виджетов, написанных до перехода
 * на запросы: новые задаются SQL, и второго способа для них нет.
 */
function openCode(widget) {
    editingWidget.value = widget;
    codeModal.value?.show();
}

function hasPythonCode(widget) {
    return Boolean(widget.code);
}

function onCodeSaved(updated) {
    if (!updated) return;

    replaceWidget(updated);
    editingWidget.value = { ...editingWidget.value, ...updated };

    // Данные виджета перезапрашиваются принудительно: код изменился, а
    // WidgetContainer сам об этом не узнает.
    refreshToken.value = Date.now();
}

onMounted(async () => {
    await load();

    if (!error.value) {
        await loadSchema();
    }

    await nextTick();

    if (paletteEl.value) palette = new Offcanvas(paletteEl.value);

    setupSortable();
});

// Список виджетов пересобрался — заново вешаем перетаскивание.
watch(() => widgets.value.length, async () => {
    await nextTick();
    setupSortable();
});

onBeforeUnmount(() => {
    sortable?.destroy();
    palette?.dispose();
});
</script>

<template>
    <div class="page">
        <div class="page-header mb-3 mt-2">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <div class="page-pretitle">Конструктор</div>
                        <h2 class="page-title">{{ dashboard?.name || "Дашборд" }}</h2>
                        <div v-if="dashboard?.data_source" class="text-secondary">
                            Источник: {{ dashboard.data_source.name }}
                        </div>
                    </div>

                    <div class="col-auto ms-auto">
                        <div class="btn-list">
                            <router-link
                                class="btn"
                                :to="{ name: 'project.dashboard.show', params: { dashboard: dashboardId } }"
                            >
                                Открыть просмотр
                            </router-link>
                            <button class="btn btn-primary" type="button" @click="openPalette">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                     stroke-linecap="round" stroke-linejoin="round" class="icon icon-2">
                                    <path d="M12 5l0 14" />
                                    <path d="M5 12l14 0" />
                                </svg>
                                Виджет
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-xl">
            <div v-if="error" class="alert alert-danger">{{ error }}</div>

            <div v-if="notice" class="alert alert-info alert-dismissible" role="status">
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

            <div v-else-if="!error">
                <div v-if="!widgets.length" class="card">
                    <div class="card-body">
                        <div class="empty">
                            <p class="empty-title">Дашборд пока пуст</p>
                            <p class="empty-subtitle text-secondary">
                                Добавьте виджет и настройте данные — он посчитает их
                                по источнику «{{ dashboard?.data_source?.name }}».
                            </p>
                            <div class="empty-action">
                                <button class="btn btn-primary" type="button" @click="openPalette">
                                    Добавить виджет
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ХОЛСТ: карточки переставляются мышью за шапку -->
                <div ref="canvas">
                    <div
                        v-for="widget in widgets"
                        :key="widget.id"
                        class="card mb-3 builder-card"
                    >
                        <div class="card-header builder-drag d-flex align-items-center gap-2">
                            <!-- Хват: за него карточку тянут -->
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" class="text-muted builder-grip flex-shrink-0"
                                 aria-hidden="true">
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

                            <!-- Статус остаётся на виду: по нему сразу видно,
                                 посчитался виджет или нет. -->
                            <span class="badge flex-shrink-0" :class="statusOf(widget).cls">
                                {{ statusOf(widget).text }}
                            </span>

                            <!-- Вид меняют часто и сравнивают на глаз, поэтому
                                 выбор остаётся под рукой, а не в меню. -->
                            <select
                                v-if="typesOf(widget).length > 1"
                                class="form-select form-select-sm w-auto flex-shrink-0"
                                :value="widget.widget_type_id"
                                :aria-label="`Тип виджета «${widget.title}»`"
                                @change="changeType(widget, $event.target.value)"
                            >
                                <option v-for="type in typesOf(widget)" :key="type.id" :value="type.id">
                                    {{ type.title || type.name }}
                                </option>
                            </select>

                            <!-- Всё управление спрятано в меню: на дашборд смотрят
                                 чаще, чем правят, и кнопки мешали смотреть. -->
                            <div class="dropdown flex-shrink-0">
                                <button class="btn btn-sm btn-ghost-secondary px-2" type="button"
                                        data-bs-toggle="dropdown" aria-label="Действия с виджетом"
                                        aria-expanded="false">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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

                                    <button v-if="canWriteCode && hasPythonCode(widget)" class="dropdown-item"
                                            type="button" @click="openCode(widget)">
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
                                <pre class="mb-0 builder-widget-error">{{ widget.last_error }}</pre>
                            </div>

                            <WidgetContainer
                                :widget="widget"
                                :refresh-token="refreshToken"
                            />

                            <div v-if="widget.status === 'draft' && canWriteCode" class="mt-2">
                                <button class="btn btn-sm" type="button" @click="openQuery(widget)">
                                    Настроить данные
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- КАТАЛОГ ВИДЖЕТОВ: выдвигается справа по кнопке -->
        <div ref="paletteEl" class="offcanvas offcanvas-end" tabindex="-1"
             aria-labelledby="builder-palette-title">
            <div class="offcanvas-header">
                <h2 class="offcanvas-title" id="builder-palette-title">Добавить виджет</h2>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas"
                        aria-label="Закрыть"></button>
            </div>
            <div class="offcanvas-body">
                <WidgetPalette embedded @add="addWidget" />
            </div>
        </div>

        <WidgetQueryModal
            ref="queryModal"
            :dashboard-id="dashboardId"
            :widget="editingWidget"
            :schema="schema"
            :dictionary="dictionary"
            @saved="onCodeSaved"
        />

        <WidgetCodeModal
            ref="codeModal"
            :dashboard-id="dashboardId"
            :widget="editingWidget"
            :schema="schema"
            @saved="onCodeSaved"
        />
    </div>
</template>

<style scoped>
/* Шапка карточки — она же ручка перетаскивания. */
.builder-drag {
    cursor: grab;
}

.builder-drag:active {
    cursor: grabbing;
}

/* Поле заголовка внутри ручки не должно перехватывать перетаскивание
   курсором, но должно оставаться редактируемым. */
.builder-title {
    cursor: text;
}

/* Выбор вида — тоже элемент управления, а не место для хвата. */
.builder-drag select {
    cursor: pointer;
}

.builder-card--ghost {
    opacity: 0.4;
}

/* Точки-хват заметны только когда карточка под курсором: в покое
   они не отвлекают от самих данных. */
.builder-grip {
    opacity: 0;
    transition: opacity 0.15s;
}

.builder-card:hover .builder-grip {
    opacity: 1;
}

.builder-widget-error {
    font-size: 12px;
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 160px;
    overflow: auto;
}
</style>
