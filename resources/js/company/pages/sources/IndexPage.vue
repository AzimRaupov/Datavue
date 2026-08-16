<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Modal } from 'bootstrap';
import { useRouter } from 'vue-router';
import api from '../../api.js';

const router = useRouter();

const sources = ref([]);
const loading = ref(false);
const listError = ref(null);
const search = ref('');

// Редактирование
const editModalEl = ref(null);
let editModal = null;
const editing = ref(null);
const saving = ref(false);
const formError = ref(null);
const formErrors = ref({});
const editForm = reactive({
    name: '',
    version: '',
    host: '',
    port: '',
    database: '',
    username: '',
    password: '',
});

// Удаление
const deleteModalEl = ref(null);
let deleteModal = null;
const pendingDelete = ref(null);
const deleting = ref(false);

// Новый чат
const chatModalEl = ref(null);
let chatModal = null;
const chatSource = ref(null);
const chatTitle = ref('');
const creatingChat = ref(false);
const chatError = ref(null);

const currentUser = JSON.parse(localStorage.getItem('user') || 'null');
const permissions = computed(() => currentUser?.permissions ?? []);
const canManage = computed(() => permissions.value.includes('manage data sources'));
const canCreateChats = computed(() => permissions.value.includes('create chats'));

const filteredSources = computed(() => {
    const query = search.value.trim().toLowerCase();
    if (!query) return sources.value;

    return sources.value.filter(
        (s) =>
            (s.name ?? '').toLowerCase().includes(query) ||
            (s.database ?? '').toLowerCase().includes(query) ||
            (s.format_label ?? '').toLowerCase().includes(query)
    );
});

// Цвета бейджей — из палитры Tabler
const typeBadgeClass = (name) =>
    ({
        mysql: 'bg-azure-lt',
        postgres: 'bg-indigo-lt',
        sqlite: 'bg-teal-lt',
        csv: 'bg-green-lt',
        txt: 'bg-green-lt',
        xls: 'bg-green-lt',
        xlsx: 'bg-green-lt',
        sql: 'bg-orange-lt',
        google_sheets: 'bg-lime-lt',
    }[name] ?? 'bg-secondary-lt');

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

/** Короткое описание «откуда данные» для строки таблицы. */
function locationOf(source) {
    if (source.connection_type === 'remote') {
        return `${source.host}:${source.port}/${source.database}`;
    }
    return 'Файл на сервере';
}

async function fetchSources() {
    loading.value = true;
    listError.value = null;

    try {
        const { data } = await api.get('/data_source');
        sources.value = data ?? [];
    } catch (err) {
        listError.value =
            err.response?.status === 403
                ? 'У вас нет прав на просмотр источников данных.'
                : 'Не удалось загрузить источники данных.';
    } finally {
        loading.value = false;
    }
}

async function openEdit(source) {
    editing.value = source;
    formError.value = null;
    formErrors.value = {};

    Object.assign(editForm, {
        name: source.name ?? '',
        version: source.version ?? '',
        host: source.host ?? '',
        port: source.port ?? '',
        database: source.database ?? '',
        username: source.username ?? '',
        // Пароль наружу не отдаётся — пустое поле означает «оставить прежний».
        password: '',
    });

    await nextTick();
    editModal?.show();
}

async function submitEdit() {
    if (!editing.value || saving.value) return;

    saving.value = true;
    formError.value = null;
    formErrors.value = {};

    const payload = { name: editForm.name, version: editForm.version };

    if (editing.value.connection_type === 'remote') {
        Object.assign(payload, {
            host: editForm.host,
            port: editForm.port,
            database: editForm.database,
            username: editForm.username,
        });

        if (editForm.password) payload.password = editForm.password;
    }

    try {
        await api.put(`/data_source/${editing.value.id}`, payload);
        editModal?.hide();
        editing.value = null;
        await fetchSources();
    } catch (err) {
        const data = err.response?.data;
        if (data?.errors) formErrors.value = data.errors;
        formError.value = data?.message || 'Не удалось сохранить источник.';
    } finally {
        saving.value = false;
    }
}

async function askDelete(source) {
    pendingDelete.value = source;
    await nextTick();
    deleteModal?.show();
}

async function confirmDelete() {
    if (!pendingDelete.value || deleting.value) return;

    deleting.value = true;

    try {
        await api.delete(`/data_source/${pendingDelete.value.id}`);
        deleteModal?.hide();
        pendingDelete.value = null;
        await fetchSources();
    } catch (err) {
        listError.value = err.response?.data?.message || 'Не удалось удалить источник.';
        deleteModal?.hide();
    } finally {
        deleting.value = false;
    }
}

async function openChatModal(source) {
    chatSource.value = source;
    chatTitle.value = '';
    chatError.value = null;
    await nextTick();
    chatModal?.show();
}

async function createChat() {
    if (!chatSource.value || creatingChat.value) return;

    creatingChat.value = true;
    chatError.value = null;

    try {
        const { data } = await api.post('/chats', {
            data_source_id: chatSource.value.id,
            title: chatTitle.value || undefined,
        });

        chatModal?.hide();
        router.push({ name: 'company.workspace', params: { workspace: data.workspace.id } });
    } catch (err) {
        chatError.value = err.response?.data?.message || 'Не удалось создать чат.';
    } finally {
        creatingChat.value = false;
    }
}

onMounted(async () => {
    await fetchSources();
    await nextTick();

    if (editModalEl.value) editModal = new Modal(editModalEl.value);
    if (deleteModalEl.value) deleteModal = new Modal(deleteModalEl.value);
    // См. ShowPage.vue: пока идёт подготовка вариантов, окно закрывать нельзя.
    if (chatModalEl.value) {
        chatModal = new Modal(chatModalEl.value, { backdrop: 'static', keyboard: false });
    }
});

onBeforeUnmount(() => {
    editModal?.dispose();
    deleteModal?.dispose();
    chatModal?.dispose();
});
</script>

<template>
    <div class="page-wrapper">
        <!-- BEGIN PAGE HEADER -->
        <div class="page-header d-print-none">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <div class="page-pretitle">Компания {{ currentUser?.company?.name }}</div>
                        <h2 class="page-title">Источники данных</h2>
                        <div class="text-secondary mt-1">
                            Подключите базу или файл — и заводите на нём столько чатов, сколько нужно.
                        </div>
                    </div>

                    <div class="col-auto ms-auto d-print-none">
                        <div class="d-flex">
                            <input
                                v-model="search"
                                type="search"
                                class="form-control d-inline-block w-9 me-3"
                                placeholder="Поиск источника…"
                                aria-label="Поиск источника"
                            />
                            <router-link v-if="canManage" class="btn btn-primary"
                                         :to="{ name: 'company.source.create' }">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round" aria-hidden="true" focusable="false" class="icon icon-2">
                                    <path d="M12 5l0 14" />
                                    <path d="M5 12l14 0" />
                                </svg>
                                Добавить источник
                            </router-link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- END PAGE HEADER -->

        <!-- BEGIN PAGE BODY -->
        <main class="page-body">
            <div class="container-xl">
                <div v-if="listError" class="alert alert-danger" role="alert">{{ listError }}</div>

                <div class="card">
                    <!-- Загрузка -->
                    <div v-if="loading" class="card-body">
                        <div class="progress progress-sm">
                            <div class="progress-bar progress-bar-indeterminate"></div>
                        </div>
                    </div>

                    <!-- Пусто -->
                    <div v-else-if="!filteredSources.length" class="card-body">
                        <div class="empty">
                            <p class="empty-title">
                                {{ search ? 'Ничего не найдено' : 'Источников пока нет' }}
                            </p>
                            <p class="empty-subtitle text-secondary">
                                {{ search
                                    ? 'Попробуйте изменить поисковый запрос.'
                                    : 'Подключите первую базу данных или загрузите файл — это первый шаг перед созданием дашбордов.' }}
                            </p>
                            <div class="empty-action" v-if="canManage && !search">
                                <router-link class="btn btn-primary" :to="{ name: 'company.source.create' }">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                         stroke-linejoin="round" aria-hidden="true" focusable="false" class="icon icon-2">
                                        <path d="M12 5l0 14" />
                                        <path d="M5 12l14 0" />
                                    </svg>
                                    Добавить источник
                                </router-link>
                            </div>
                        </div>
                    </div>

                    <!-- Таблица -->
                    <div v-else class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                            <tr>
                                <th>Источник</th>
                                <th>Тип</th>
                                <th>Чаты</th>
                                <th>Добавлен</th>
                                <th class="w-1"></th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr v-for="source in filteredSources" :key="source.id">
                                <td>
                                    <div class="d-flex py-1 align-items-center">
                                        <span class="avatar avatar-sm me-2 bg-primary-lt">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                                                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                 stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M12 6m-8 0a8 3 0 1 0 16 0a8 3 0 1 0 -16 0" />
                                                <path d="M4 6v6a8 3 0 0 0 16 0v-6" />
                                                <path d="M4 12v6a8 3 0 0 0 16 0v-6" />
                                            </svg>
                                        </span>
                                        <div class="flex-fill">
                                            <div class="font-weight-medium">
                                                <router-link
                                                    :to="{ name: 'company.source.show', params: { id: source.id } }"
                                                    class="text-reset"
                                                >
                                                    {{ source.name }}
                                                </router-link>
                                            </div>
                                            <div class="text-secondary text-truncate" style="max-width: 320px">
                                                {{ locationOf(source) }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge" :class="typeBadgeClass(source.format_key)">
                                        {{ source.format_label }}
                                    </span>
                                    <span v-if="source.version" class="text-secondary ms-2">{{ source.version }}</span>
                                </td>
                                <td>
                                    <router-link
                                        :to="{ name: 'company.source.show', params: { id: source.id } }"
                                        class="text-reset"
                                    >
                                        {{ source.chats_count ?? 0 }}
                                    </router-link>
                                </td>
                                <td class="text-secondary">
                                    {{ formatDate(source.created_at) }}
                                    <div v-if="source.creator" class="small">{{ source.creator.name }}</div>
                                </td>
                                <td>
                                    <div class="btn-list flex-nowrap justify-content-end">
                                        <button
                                            v-if="canCreateChats"
                                            class="btn btn-sm btn-primary"
                                            @click="openChatModal(source)"
                                        >
                                            Новый чат
                                        </button>
                                        <button v-if="canManage" class="btn btn-sm" @click="openEdit(source)">
                                            Изменить
                                        </button>
                                        <button
                                            v-if="canManage"
                                            class="btn btn-sm btn-ghost-danger"
                                            @click="askDelete(source)"
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
        </main>
        <!-- END PAGE BODY -->

        <!-- BEGIN MODAL: редактирование источника -->
        <div ref="editModalEl" class="modal modal-blur fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Изменение источника</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row">
                            <div class="col-lg-8">
                                <div class="mb-3">
                                    <label class="form-label required">Название</label>
                                    <input v-model="editForm.name" type="text" class="form-control"
                                           :class="{ 'is-invalid': formErrors.name }" />
                                    <div v-if="formErrors.name" class="invalid-feedback">{{ formErrors.name[0] }}</div>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="mb-3">
                                    <label class="form-label">Версия</label>
                                    <input v-model="editForm.version" type="text" class="form-control" />
                                </div>
                            </div>

                            <template v-if="editing?.connection_type === 'remote'">
                                <div class="col-lg-8">
                                    <div class="mb-3">
                                        <label class="form-label required">Хост</label>
                                        <input v-model="editForm.host" type="text" class="form-control" />
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="mb-3">
                                        <label class="form-label required">Порт</label>
                                        <input v-model="editForm.port" type="number" class="form-control" />
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="mb-3">
                                        <label class="form-label required">База данных</label>
                                        <input v-model="editForm.database" type="text" class="form-control" />
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="mb-3">
                                        <label class="form-label required">Имя пользователя</label>
                                        <input v-model="editForm.username" type="text" class="form-control" />
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="mb-3">
                                        <label class="form-label">Пароль</label>
                                        <input v-model="editForm.password" type="password" class="form-control"
                                               placeholder="Оставьте пустым, чтобы не менять"
                                               autocomplete="new-password" />
                                    </div>
                                </div>
                            </template>

                            <div v-else class="col-12">
                                <div class="alert alert-info mb-0">
                                    Это файловый источник — сам файл заменить нельзя, на нём уже
                                    построены дашборды. Загрузите новый файл отдельным источником.
                                </div>
                            </div>
                        </div>

                        <div v-if="formError" class="alert alert-danger mt-3 mb-0" role="alert">{{ formError }}</div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="button" class="btn btn-primary ms-auto" :disabled="saving" @click="submitEdit">
                            {{ saving ? 'Сохранение…' : 'Сохранить' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <!-- END MODAL -->

        <!-- BEGIN MODAL: подтверждение удаления -->
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
                        <h3>Удалить источник?</h3>
                        <div class="text-secondary">
                            «{{ pendingDelete?.name }}» будет удалён
                            <template v-if="pendingDelete?.chats_count">
                                вместе с {{ pendingDelete.chats_count }} чат(ами) и всеми их дашбордами.
                            </template>
                            <template v-else>безвозвратно.</template>
                            Действие необратимо.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <div class="w-100">
                            <div class="row">
                                <div class="col">
                                    <button class="btn w-100" data-bs-dismiss="modal">Отмена</button>
                                </div>
                                <div class="col">
                                    <button class="btn btn-danger w-100" :disabled="deleting" @click="confirmDelete">
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

        <!-- BEGIN MODAL: новый чат на источнике -->
        <div ref="chatModalEl" class="modal modal-blur fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Новый чат</h5>
                        <button v-if="!creatingChat" type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="Закрыть"></button>
                    </div>

                    <!-- См. ShowPage.vue: пока готовятся варианты дашбордов,
                         форма заменяется прогрессом и окно не закрывается. -->
                    <div v-if="creatingChat" class="modal-body text-center py-4">
                        <div class="spinner-border text-primary mb-3" role="status"></div>
                        <h3 class="mb-1">Готовим варианты дашбордов</h3>
                        <div class="text-secondary">
                            Разбираем структуру источника «{{ chatSource?.name }}» и подбираем,
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
                            <input type="text" class="form-control" :value="chatSource?.name" disabled />
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
    </div>
</template>
