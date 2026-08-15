<script setup>
import { ref, computed, onMounted } from "vue";
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
        notice.value = "Виджет добавлен. Напишите ему код, чтобы появились данные.";
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
 * Перестановка соседей. Позиции пересчитываются подряд и уходят на сервер
 * одним запросом: половина сохранённого порядка хуже, чем несохранённый.
 */
async function move(index, direction) {
    const target = index + direction;

    if (target < 0 || target >= widgets.value.length) return;

    const list = [...widgets.value];
    [list[index], list[target]] = [list[target], list[index]];

    widgets.value = list.map((widget, position) => ({ ...widget, position }));

    try {
        await api.put(`/dashboards/${dashboardId.value}/reorder`, {
            widgets: widgets.value.map((widget, position) => ({ id: widget.id, position })),
        });
    } catch (err) {
        notice.value = "Порядок не сохранён — обновите страницу.";
    }
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

            <div v-else-if="!error" class="row g-3">
                <!-- ПАЛИТРА -->
                <!-- На широком мониторе каталог не растёт вместе с экраном:
                     лишнее место нужно виджетам, а не списку их названий. -->
                <div class="col-12 col-lg-3 col-xxl-2">
                    <WidgetPalette @add="addWidget" />
                </div>

                <!-- ХОЛСТ -->
                <div class="col-12 col-lg-9 col-xxl-10">
                    <div v-if="!widgets.length" class="card">
                        <div class="card-body">
                            <div class="empty">
                                <p class="empty-title">Дашборд пока пуст</p>
                                <p class="empty-subtitle text-secondary">
                                    Выберите виджет слева, а потом напишите ему код — он посчитает данные
                                    по источнику «{{ dashboard?.data_source?.name }}».
                                </p>
                            </div>
                        </div>
                    </div>

                    <div
                        v-for="(widget, index) in widgets"
                        :key="widget.id"
                        class="card mb-3"
                    >
                        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                            <input
                                class="form-control form-control-flush fw-bold w-auto flex-fill"
                                :value="widget.title"
                                aria-label="Заголовок виджета"
                                @change="renameWidget(widget, $event.target.value)"
                            />

                            <span class="badge" :class="statusOf(widget).cls">
                                {{ statusOf(widget).text }}
                            </span>

                            <select
                                v-if="typesOf(widget).length > 1"
                                class="form-select form-select-sm w-auto"
                                :value="widget.widget_type_id"
                                :aria-label="`Тип виджета «${widget.title}»`"
                                @change="changeType(widget, $event.target.value)"
                            >
                                <option v-for="type in typesOf(widget)" :key="type.id" :value="type.id">
                                    {{ type.title || type.name }}
                                </option>
                            </select>

                            <div class="btn-list">
                                <button class="btn btn-sm" type="button" title="Выше"
                                        :disabled="index === 0" @click="move(index, -1)">
                                    ↑
                                </button>
                                <button class="btn btn-sm" type="button" title="Ниже"
                                        :disabled="index === widgets.length - 1" @click="move(index, 1)">
                                    ↓
                                </button>
                                <button v-if="canWriteCode" class="btn btn-sm btn-primary" type="button"
                                        @click="openQuery(widget)">
                                    Настроить
                                </button>
                                <button v-if="canWriteCode && hasPythonCode(widget)" class="btn btn-sm"
                                        type="button" title="Виджет написан на Python до перехода на запросы"
                                        @click="openCode(widget)">
                                    Код
                                </button>
                                <button class="btn btn-sm btn-ghost-danger" type="button"
                                        :disabled="busy" @click="removeWidget(widget)">
                                    Удалить
                                </button>
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
.builder-widget-error {
    font-size: 12px;
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 160px;
    overflow: auto;
}
</style>
