<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Modal } from 'bootstrap';
import { useRoute, useRouter } from 'vue-router';
import api from '../../api.js';

const route = useRoute();
const router = useRouter();

const sourceId = route.params.id;

const source = ref(null);
const groups = ref([]);
const tablesCount = ref(0);
const loading = ref(false);
const pageError = ref(null);

// Новый чат
const chatModalEl = ref(null);
let chatModal = null;
const chatTitle = ref('');
const creatingChat = ref(false);
const chatError = ref(null);

// Удаление чата
const deleteModalEl = ref(null);
let deleteModal = null;
const pendingDelete = ref(null);
const deleting = ref(false);

// Обновление данных
const refreshModalEl = ref(null);
let refreshModal = null;
const refreshing = ref(false);
const refreshError = ref(null);
const refreshFile = ref(null);
const refreshFileEl = ref(null);

const currentUser = JSON.parse(localStorage.getItem('user') || 'null');
const permissions = computed(() => currentUser?.permissions ?? []);
const canCreateChats = computed(() => permissions.value.includes('create chats'));
const canDeleteChats = computed(() => permissions.value.includes('delete chats'));
const canManageSources = computed(() => permissions.value.includes('manage data sources'));

const canRefresh = computed(() => canManageSources.value && !!source.value);

const isGoogleSheet = computed(() => source.value?.origin_format === 'google_sheets');
const isRemoteSource = computed(() => source.value?.connection_type === 'remote');

// Файл нужен только для загруженного файла: Google-таблица тянется по ссылке,
// внешняя база — по живому подключению.
const refreshNeedsFile = computed(() => !isRemoteSource.value && !isGoogleSheet.value);

// Результат последнего обновления: что изменилось в составе таблиц.
const refreshResult = ref(null);

// Группировка устарела — состав таблиц изменился после обновления.
const groupingStale = computed(() => source.value?.grouping_status === 'stale');
const regrouping = ref(false);

function formatDateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('ru-RU', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

const chats = computed(() => source.value?.chats ?? []);

const dashboardsTotal = computed(() =>
    chats.value.reduce((sum, chat) => sum + (chat.dashboards?.length ?? 0), 0)
);

const DASHBOARD_STATUS = {
    empty: { label: 'Пустой', badge: 'bg-secondary-lt' },
    generating_scheme: { label: 'Проектируется', badge: 'bg-azure-lt' },
    generating_widgets: { label: 'Строится', badge: 'bg-azure-lt' },
    reviewing: { label: 'Проверяется', badge: 'bg-yellow-lt' },
    completed: { label: 'Готов', badge: 'bg-success-lt' },
    failed: { label: 'Ошибка', badge: 'bg-danger-lt' },
};

function dashboardStatus(status) {
    return DASHBOARD_STATUS[status] ?? { label: status ?? '—', badge: 'bg-secondary-lt' };
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

async function fetchSource() {
    loading.value = true;
    pageError.value = null;

    try {
        const { data } = await api.get(`/data_source/${sourceId}`);
        source.value = data.data_source;
        groups.value = data.groups ?? [];
        tablesCount.value = data.tables_count ?? 0;
    } catch (err) {
        pageError.value =
            err.response?.status === 404
                ? 'Источник данных не найден.'
                : 'Не удалось загрузить источник данных.';
    } finally {
        loading.value = false;
    }
}

async function openChatModal() {
    chatTitle.value = '';
    chatError.value = null;
    await nextTick();
    chatModal?.show();
}

async function createChat() {
    if (creatingChat.value) return;

    creatingChat.value = true;
    chatError.value = null;

    try {
        const { data } = await api.post('/chats', {
            data_source_id: source.value.id,
            title: chatTitle.value || undefined,
        });

        chatModal?.hide();
        router.push({ name: 'company.chat', params: { id: data.chat.id } });
    } catch (err) {
        chatError.value = err.response?.data?.message || 'Не удалось создать чат.';
    } finally {
        creatingChat.value = false;
    }
}

async function openRefreshModal() {
    refreshError.value = null;
    refreshResult.value = null;
    refreshFile.value = null;
    if (refreshFileEl.value) refreshFileEl.value.value = '';
    await nextTick();
    refreshModal?.show();
}

/** Пересобрать группировку — предлагается, когда состав таблиц изменился. */
async function regroup() {
    if (regrouping.value) return;

    regrouping.value = true;

    try {
        await api.post(`/data_source/${sourceId}/grouping`, { force: 1 });
        await fetchSource();
    } catch (err) {
        pageError.value =
            err.response?.data?.message || 'Не удалось запустить группировку.';
    } finally {
        regrouping.value = false;
    }
}

function handleRefreshFile(event) {
    refreshFile.value = event.target.files[0] ?? null;
}

/**
 * Обновление данных источника.
 *
 * Google-таблица перечитывается по сохранённой ссылке — файл не нужен.
 * Для загруженного файла присылаем новую версию того же формата.
 */
async function submitRefresh() {
    if (refreshing.value) return;
    if (refreshNeedsFile.value && !refreshFile.value) {
        refreshError.value = 'Выберите файл.';
        return;
    }

    refreshing.value = true;
    refreshError.value = null;
    refreshResult.value = null;

    try {
        const payload = new FormData();
        if (refreshFile.value) payload.append('data_file', refreshFile.value);

        const { data } = await api.post(`/data_source/${sourceId}/refresh`, payload);

        // Окно не закрываем сразу: если состав таблиц изменился, это нужно
        // показать — иначе пользователь не узнает, что группировка устарела.
        refreshResult.value = data;
        await fetchSource();
    } catch (err) {
        refreshError.value =
            err.response?.data?.message || 'Не удалось обновить данные.';
    } finally {
        refreshing.value = false;
    }
}

async function askDeleteChat(chat) {
    pendingDelete.value = chat;
    await nextTick();
    deleteModal?.show();
}

async function confirmDeleteChat() {
    if (!pendingDelete.value || deleting.value) return;

    deleting.value = true;

    try {
        await api.delete(`/chats/${pendingDelete.value.id}`);
        deleteModal?.hide();
        pendingDelete.value = null;
        await fetchSource();
    } catch (err) {
        pageError.value = err.response?.data?.message || 'Не удалось удалить чат.';
        deleteModal?.hide();
    } finally {
        deleting.value = false;
    }
}

onMounted(async () => {
    await fetchSource();
    await nextTick();

    // static/keyboard:false — окно нельзя закрыть кликом по фону или Esc,
    // пока идёт подготовка вариантов: запрос этим не отменится, а пользователь
    // решит, что всё пропало, и нажмёт «Создать» второй раз.
    if (chatModalEl.value) {
        chatModal = new Modal(chatModalEl.value, { backdrop: 'static', keyboard: false });
    }
    if (deleteModalEl.value) deleteModal = new Modal(deleteModalEl.value);
    if (refreshModalEl.value) {
        // Разбор файла идёт синхронно — закрывать окно на полпути нельзя.
        refreshModal = new Modal(refreshModalEl.value, { backdrop: 'static', keyboard: false });
    }
});

onBeforeUnmount(() => {
    chatModal?.dispose();
    deleteModal?.dispose();
    refreshModal?.dispose();
});
</script>

<template>
    <div class="page-wrapper">
        <!-- BEGIN PAGE HEADER -->
        <div class="page-header d-print-none">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <div class="page-pretitle">
                            <router-link :to="{ name: 'company.sources' }" class="text-reset text-decoration-none">
                                ← Источники данных
                            </router-link>
                        </div>
                        <h2 class="page-title">
                            {{ source?.name ?? 'Источник данных' }}
                            <span v-if="source" class="badge bg-primary-lt ms-2">
                                {{ source.format_label }}
                            </span>
                        </h2>
                        <div class="text-secondary mt-1">
                            {{ chats.length }} чат(ов) · {{ dashboardsTotal }} дашборд(ов)
                        </div>
                    </div>

                    <div class="col-auto ms-auto d-print-none">
                        <div class="btn-list">
                        <!-- Обновление данных доступно только у файловых источников
                             и Google-таблиц: внешняя база читается вживую. -->
                        <button v-if="canRefresh" class="btn" :class="{ 'btn-loading': refreshing }"
                                :disabled="refreshing" @click="openRefreshModal">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" class="icon icon-2">
                                <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
                                <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
                            </svg>
                            Обновить данные
                        </button>
                        <button v-if="canCreateChats && source" class="btn btn-primary" @click="openChatModal">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" aria-hidden="true" focusable="false" class="icon icon-2">
                                <path d="M12 5l0 14" />
                                <path d="M5 12l14 0" />
                            </svg>
                            Новый чат
                        </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- END PAGE HEADER -->

        <!-- BEGIN PAGE BODY -->
        <main class="page-body">
            <div class="container-xl">
                <div v-if="pageError" class="alert alert-danger" role="alert">{{ pageError }}</div>

                <!-- Состав таблиц изменился после обновления: агент работает
                     по старому снимку схемы и новых таблиц не видит. -->
                <div v-if="groupingStale" class="alert alert-warning" role="alert">
                    <div class="d-flex align-items-center">
                        <div class="flex-fill">
                            <h4 class="alert-heading">Группировка устарела</h4>
                            <div class="alert-description">
                                После обновления состав таблиц изменился. Пока группировка
                                не пересобрана, агент не увидит новые таблицы.
                            </div>
                        </div>
                        <button v-if="canManageSources" class="btn btn-warning ms-3"
                                :class="{ 'btn-loading': regrouping }"
                                :disabled="regrouping" @click="regroup">
                            Пересобрать
                        </button>
                    </div>
                </div>

                <div v-if="loading" class="card">
                    <div class="card-body">
                        <div class="progress progress-sm">
                            <div class="progress-bar progress-bar-indeterminate"></div>
                        </div>
                    </div>
                </div>

                <div v-else-if="source" class="row row-cards">
                    <!-- ЛЕВАЯ КОЛОНКА: параметры подключения и разобранная схема -->
                    <div class="col-lg-4">
                        <div class="card mb-3">
                            <div class="card-header">
                                <h3 class="card-title">Подключение</h3>
                            </div>
                            <div class="card-body">
                                <!-- .datagrid — штатный компонент Tabler под пары
                                     «подпись — значение». Раньше здесь был dl.row
                                     с col-5/col-7: подписи набирались обычным
                                     текстом и по весу спорили со значениями. -->
                                <div class="datagrid">
                                    <div class="datagrid-item">
                                        <div class="datagrid-title">Формат</div>
                                        <div class="datagrid-content">{{ source.format_label }}</div>
                                    </div>

                                    <div class="datagrid-item">
                                        <div class="datagrid-title">Способ</div>
                                        <div class="datagrid-content">
                                            {{ isRemoteSource ? 'Внешняя база' : 'Загруженный файл' }}
                                        </div>
                                    </div>

                                    <template v-if="isRemote">
                                        <div class="datagrid-item">
                                            <div class="datagrid-title">Хост</div>
                                            <div class="datagrid-content text-break">
                                                {{ source.host }}:{{ source.port }}
                                            </div>
                                        </div>

                                        <div class="datagrid-item">
                                            <div class="datagrid-title">База</div>
                                            <div class="datagrid-content text-break">{{ source.database }}</div>
                                        </div>
                                    </template>

                                    <div v-if="source.version" class="datagrid-item">
                                        <div class="datagrid-title">Версия</div>
                                        <div class="datagrid-content">{{ source.version }}</div>
                                    </div>

                                    <div class="datagrid-item">
                                        <div class="datagrid-title">Добавлен</div>
                                        <div class="datagrid-content">{{ formatDate(source.created_at) }}</div>
                                    </div>

                                    <!-- Файловый источник — снимок данных, поэтому
                                         дата обновления важнее даты добавления:
                                         дашборд на данных месячной давности выглядит
                                         так же убедительно, как на свежих. -->
                                    <div v-if="source.connection_type === 'local'" class="datagrid-item">
                                        <div class="datagrid-title">Данные от</div>
                                        <div class="datagrid-content">
                                            {{ formatDateTime(source.refreshed_at) }}
                                        </div>
                                    </div>

                                    <div v-if="source.creator" class="datagrid-item">
                                        <div class="datagrid-title">Кем добавлен</div>
                                        <div class="datagrid-content">{{ source.creator.name }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Схема данных</h3>
                            </div>

                            <!-- Схему разбирает ИИ при первом вопросе: до этого показывать нечего,
                                 и лучше честно объяснить, а не оставлять пустой блок. -->
                            <div v-if="!groups.length" class="card-body">
                                <p class="text-secondary mb-0">
                                    Схема ещё не разобрана. Она будет построена автоматически,
                                    когда вы зададите первый вопрос в чате на этом источнике.
                                </p>
                            </div>

                            <template v-else>
                                <div class="card-body pb-2">
                                    <div class="text-secondary">
                                        {{ groups.length }} смысловых групп · {{ tablesCount }} таблиц
                                    </div>
                                </div>
                                <div class="list-group list-group-flush">
                                    <div v-for="group in groups" :key="group.id" class="list-group-item">
                                        <div class="d-flex align-items-center">
                                            <div class="flex-fill">
                                                <div class="font-weight-medium">{{ group.name }}</div>
                                                <div v-if="group.description" class="text-secondary small">
                                                    {{ group.description }}
                                                </div>
                                            </div>
                                            <span class="badge bg-secondary-lt ms-2">
                                                {{ group.tables_count }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- ПРАВАЯ КОЛОНКА: чаты источника -->
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Чаты на этом источнике</h3>
                            </div>

                            <div v-if="!chats.length" class="card-body">
                                <div class="empty">
                                    <p class="empty-title">Чатов пока нет</p>
                                    <p class="empty-subtitle text-secondary">
                                        Создайте чат и опишите словами, что хотите увидеть —
                                        дашборд соберётся сам.
                                    </p>
                                    <div class="empty-action" v-if="canCreateChats">
                                        <button class="btn btn-primary" @click="openChatModal">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
                                                 focusable="false" class="icon icon-2">
                                                <path d="M12 5l0 14" />
                                                <path d="M5 12l14 0" />
                                            </svg>
                                            Новый чат
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!--
                              Таблица, а не список: у каждого чата есть действия и
                              несколько атрибутов. Тот же card-table + table-vcenter,
                              что на странице сотрудников, — чтобы списки в приложении
                              выглядели одинаково. Раньше здесь был list-group-item с
                              вложенным row и лентой кнопок-дашбордов под ним: строки
                              получались разной высоты и разъезжались.
                            -->
                            <div v-else class="table-responsive">
                                <table class="table table-vcenter card-table">
                                    <thead>
                                    <tr>
                                        <th>Чат</th>
                                        <th>Дашборды</th>
                                        <th>Создан</th>
                                        <th class="w-1"></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <tr v-for="chat in chats" :key="chat.id">
                                        <td>
                                            <div class="d-flex py-1 align-items-center">
                                                <span class="avatar avatar-sm me-2 bg-primary-lt">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                                                         viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                         stroke-width="2" stroke-linecap="round"
                                                         stroke-linejoin="round">
                                                        <path d="M8 9h8" />
                                                        <path d="M8 13h6" />
                                                        <path d="M18 4a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3h-5l-5 3v-3h-2a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3z" />
                                                    </svg>
                                                </span>
                                                <div class="flex-fill">
                                                    <router-link
                                                        :to="{ name: 'company.chat', params: { id: chat.id } }"
                                                        class="text-reset font-weight-medium"
                                                    >
                                                        {{ chat.title || `Чат #${chat.id}` }}
                                                    </router-link>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <!-- Дашборды чата ссылками: с них удобнее
                                                 начинать, чем с самого чата. -->
                                            <div v-if="chat.dashboards?.length" class="btn-list">
                                                <router-link
                                                    v-for="dashboard in chat.dashboards"
                                                    :key="dashboard.id"
                                                    :to="{
                                                        name: 'company.chat',
                                                        params: { id: chat.id, dashboard: dashboard.id },
                                                    }"
                                                    class="badge text-truncate"
                                                    :class="dashboardStatus(dashboard.status).badge"
                                                    :title="dashboardStatus(dashboard.status).label"
                                                    style="max-width: 200px"
                                                >
                                                    {{ dashboard.name || `Дашборд #${dashboard.id}` }}
                                                </router-link>
                                            </div>
                                            <span v-else class="text-secondary">—</span>
                                        </td>

                                        <td class="text-secondary">{{ formatDate(chat.created_at) }}</td>

                                        <td>
                                            <div class="btn-list flex-nowrap justify-content-end">
                                                <router-link
                                                    :to="{ name: 'company.chat', params: { id: chat.id } }"
                                                    class="btn btn-sm"
                                                >
                                                    Открыть
                                                </router-link>
                                                <button
                                                    v-if="canDeleteChats"
                                                    class="btn btn-sm btn-ghost-danger"
                                                    @click="askDeleteChat(chat)"
                                                >
                                                    Удалить
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        <!-- END PAGE BODY -->

        <!-- BEGIN MODAL: обновление данных -->
        <div ref="refreshModalEl" class="modal modal-blur fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Обновить данные</h5>
                        <button v-if="!refreshing" type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="Закрыть"></button>
                    </div>

                    <div v-if="refreshing" class="modal-body text-center py-4">
                        <div class="spinner-border text-primary mb-3" role="status"></div>
                        <h3 class="mb-1">
                            {{ isRemoteSource ? 'Перечитываем схему' : 'Перечитываем данные' }}
                        </h3>
                        <div class="text-secondary">
                            Дашборды при этом не ломаются — обновятся только цифры.
                        </div>
                    </div>

                    <!-- Итог: главное здесь — изменился ли состав таблиц -->
                    <div v-else-if="refreshResult" class="modal-body">
                        <div class="alert alert-success" role="alert">{{ refreshResult.message }}</div>

                        <template v-if="refreshResult.schema_changed">
                            <div v-if="refreshResult.added_tables.length" class="mb-2">
                                <div class="text-secondary mb-1">Появились таблицы:</div>
                                <div class="d-flex flex-wrap gap-1">
                                    <span v-for="t in refreshResult.added_tables" :key="t"
                                          class="badge bg-green-lt">{{ t }}</span>
                                </div>
                            </div>
                            <div v-if="refreshResult.removed_tables.length" class="mb-2">
                                <div class="text-secondary mb-1">Пропали таблицы:</div>
                                <div class="d-flex flex-wrap gap-1">
                                    <span v-for="t in refreshResult.removed_tables" :key="t"
                                          class="badge bg-red-lt">{{ t }}</span>
                                </div>
                            </div>
                            <div class="alert alert-warning mb-0" role="alert">
                                Состав таблиц изменился — группировку стоит пересобрать,
                                иначе агент не увидит новые таблицы.
                            </div>
                        </template>

                        <p v-else class="text-secondary mb-0">Состав таблиц не изменился.</p>
                    </div>

                    <div v-else class="modal-body">
                        <template v-if="isRemoteSource">
                            <p class="mb-0">
                                Данные во внешней базе всегда актуальны — запросы идут в неё
                                напрямую. Устаревает другое: <strong>снимок схемы</strong>.
                                Список таблиц сохранён на момент подключения, и появившиеся
                                с тех пор таблицы агенту не видны.
                            </p>
                            <p class="text-secondary mt-2 mb-0">
                                Обновление проверит подключение и перечитает состав схемы.
                            </p>
                        </template>

                        <template v-else-if="isGoogleSheet">
                            <p class="mb-0">
                                Таблица будет перечитана по сохранённой ссылке.
                                Убедитесь, что доступ по ссылке всё ещё открыт.
                            </p>
                        </template>

                        <template v-else>
                            <div class="mb-1">
                                <label class="form-label required">Новая версия файла</label>
                                <input ref="refreshFileEl" type="file" class="form-control"
                                       @change="handleRefreshFile" />
                                <div class="form-hint">
                                    Формат должен совпадать с исходным
                                    (<strong>{{ source?.format_label }}</strong>) — иначе
                                    состав колонок изменится и готовые виджеты перестанут работать.
                                </div>
                            </div>
                        </template>

                        <div v-if="refreshError" class="alert alert-danger mt-3 mb-0" role="alert">
                            {{ refreshError }}
                        </div>
                    </div>

                    <div v-if="!refreshing" class="modal-footer">
                        <template v-if="refreshResult">
                            <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">
                                Закрыть
                            </button>
                            <button v-if="refreshResult.schema_changed" type="button"
                                    class="btn btn-primary ms-auto" data-bs-dismiss="modal" @click="regroup">
                                Пересобрать группировку
                            </button>
                        </template>
                        <template v-else>
                            <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">
                                Отмена
                            </button>
                            <button type="button" class="btn btn-primary ms-auto" @click="submitRefresh">
                                Обновить
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
        <!-- END MODAL -->

        <!-- BEGIN MODAL: новый чат -->
        <div ref="chatModalEl" class="modal modal-blur fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Новый чат</h5>
                        <button v-if="!creatingChat" type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="Закрыть"></button>
                    </div>

                    <!-- Пока готовятся варианты дашбордов, форма заменяется на прогресс:
                         работа идёт на бэкенде (группировка таблиц + подбор тем) и может
                         занять до минуты. Закрыть окно в это время нельзя — иначе
                         пользователь решит, что ничего не происходит, и нажмёт ещё раз. -->
                    <div v-if="creatingChat" class="modal-body text-center py-4">
                        <div class="spinner-border text-primary mb-3" role="status"></div>
                        <h3 class="mb-1">Готовим варианты дашбордов</h3>
                        <div class="text-secondary">
                            Разбираем структуру источника «{{ source?.name }}» и подбираем,
                            что на нём стоит построить. Обычно это занимает до минуты —
                            для следующих чатов на этом источнике будет мгновенно.
                        </div>
                        <div class="progress progress-sm mt-3">
                            <div class="progress-bar progress-bar-indeterminate"></div>
                        </div>
                    </div>

                    <div v-else class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Источник данных</label>
                            <input type="text" class="form-control" :value="source?.name" disabled />
                        </div>
                        <div class="mb-1">
                            <label class="form-label">Название чата</label>
                            <input v-model="chatTitle" type="text" class="form-control"
                                   placeholder="Например: Анализ продаж за квартал"
                                   @keydown.enter.prevent="createChat" />
                            <small class="form-hint">
                                Можно не заполнять — название подставится автоматически.
                            </small>
                        </div>
                        <div v-if="chatError" class="alert alert-danger mt-3 mb-0" role="alert">{{ chatError }}</div>
                    </div>

                    <div v-if="!creatingChat" class="modal-footer">
                        <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="button" class="btn btn-primary ms-auto" @click="createChat">
                            Создать и открыть
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <!-- END MODAL -->

        <!-- BEGIN MODAL: удаление чата -->
        <div ref="deleteModalEl" class="modal modal-blur fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-status bg-danger"></div>
                    <div class="modal-body text-center py-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             class="icon mb-2 text-danger icon-lg">
                            <path d="M12 9v4" />
                            <path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z" />
                            <path d="M12 16h.01" />
                        </svg>
                        <h3>Удалить чат?</h3>
                        <div class="text-secondary">
                            Чат «{{ pendingDelete?.title }}» и его дашборды будут удалены.
                            Источник данных при этом сохранится.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <div class="w-100">
                            <div class="row">
                                <div class="col">
                                    <button class="btn w-100" data-bs-dismiss="modal">Отмена</button>
                                </div>
                                <div class="col">
                                    <button class="btn btn-danger w-100" :disabled="deleting" @click="confirmDeleteChat">
                                        {{ deleting ? 'Удаление…' : 'Удалить' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- END MODAL -->
    </div>
</template>
