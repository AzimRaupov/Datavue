<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Modal } from 'bootstrap';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import api from '../api.js';

const router = useRouter();
const { t } = useI18n();

const sources = ref([]);
const chats = ref([]);
const dashboards = ref([]);
const usage = ref(null);
const loading = ref(true);
const listError = ref(null);

/** Разделяем разряды, чтобы 431297 читалось как 431 297. */
function formatTokens(value) {
    return new Intl.NumberFormat('ru-RU').format(value ?? 0);
}

// Предупреждаем заранее, а не по факту исчерпания: упереться в лимит
// посреди генерации дашборда неприятнее, чем увидеть жёлтую полосу.
const usageBarClass = computed(() => {
    const percent = usage.value?.percent ?? 0;
    if (percent >= 100) return 'bg-danger';
    if (percent >= 80) return 'bg-warning';
    return 'bg-primary';
});

const currentUser = JSON.parse(localStorage.getItem('user') || 'null');
const permissions = computed(() => currentUser?.permissions ?? []);
const canManageSources = computed(() => permissions.value.includes('manage data sources'));
const canViewSources = computed(() => permissions.value.includes('view data sources'));
const canCreateDashboards = computed(() => permissions.value.includes('create dashboards'));

/**
 * Создание дашборда руками — второй вход в платформу рядом с чатом.
 *
 * Источник здесь обязателен, и это не формальность: у такого дашборда нет
 * чата, а значит нет и базы, по которой виджеты могли бы что-то посчитать.
 */
const createModalEl = ref(null);
let createModal = null;
const creating = ref(false);
const createError = ref(null);
const createErrors = ref({});
const createForm = reactive({
    name: '',
    description: '',
    data_source_id: '',
});

async function openCreateModal() {
    createForm.name = '';
    createForm.description = '';
    createForm.data_source_id = sources.value.length === 1 ? sources.value[0].id : '';
    createError.value = null;
    createErrors.value = {};

    await nextTick();
    createModal?.show();
}

async function submitCreate() {
    if (creating.value) return;

    creating.value = true;
    createError.value = null;
    createErrors.value = {};

    try {
        const { data } = await api.post('/dashboards', {
            name: createForm.name,
            description: createForm.description || null,
            data_source_id: createForm.data_source_id || null,
        });

        createModal?.hide();

        // Сразу в рабочее место: пустой дашборд в списке пользы не приносит.
        router.push({
            name: 'company.workspace.dashboard',
            params: { dashboard: data.id },
            query: { mode: 'edit' },
        });
    } catch (err) {
        const body = err.response?.data;
        if (body?.errors) createErrors.value = body.errors;
        createError.value = body?.message || t('dashboardPage.modal.create_error_default');
    } finally {
        creating.value = false;
    }
}

// Показываем только свежее: полный список — на отдельных страницах.
const recentSources = computed(() => sources.value.slice(0, 4));
const recentChats = computed(() => chats.value.slice(0, 6));
const recentDashboards = computed(() => dashboards.value.slice(0, 8));

/** Статус дашборда словами — см. страницу «Дашборды». */
const dashboardStatusMap = computed(() => ({
    empty: { text: t('dashboardPage.status.empty'), cls: 'bg-secondary-lt' },
    generating_scheme: { text: t('dashboardPage.status.generating_scheme'), cls: 'bg-azure-lt' },
    generating_widgets: { text: t('dashboardPage.status.generating_widgets'), cls: 'bg-azure-lt' },
    reviewing: { text: t('dashboardPage.status.reviewing'), cls: 'bg-azure-lt' },
    completed: { text: t('dashboardPage.status.completed'), cls: 'bg-green-lt' },
    failed: { text: t('dashboardPage.status.failed'), cls: 'bg-red-lt' },
}));

function dashboardStatus(dashboard) {
    return dashboardStatusMap.value[dashboard.status] ?? { text: dashboard.status, cls: 'bg-secondary-lt' };
}

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

async function fetchAll() {
    loading.value = true;
    listError.value = null;

    try {
        // Источники может не быть права смотреть — тогда просто не запрашиваем их,
        // чтобы 403 не обрушил всю страницу.
        const requests = [api.get('/chats'), api.get('/dashboards'), api.get('/usage')];
        if (canViewSources.value) requests.unshift(api.get('/data_source'));

        const responses = await Promise.all(requests);

        if (canViewSources.value) {
            sources.value = responses.shift().data ?? [];
        }

        chats.value = responses[0].data ?? [];
        dashboards.value = responses[1].data ?? [];
        usage.value = responses[2].data ?? null;
    } catch (err) {
        listError.value = t('dashboardPage.errors.load_failed');
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    await fetchAll();
    await nextTick();

    if (createModalEl.value) createModal = new Modal(createModalEl.value);
});

onBeforeUnmount(() => {
    createModal?.dispose();
});
</script>

<template>
    <div class="page-wrapper">
        <!-- BEGIN PAGE HEADER -->
        <div class="page-header d-print-none">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <div class="page-pretitle">Data to Dashboard</div>
                        <h2 class="page-title">{{ t('dashboardPage.title') }}</h2>
                    </div>
                    <div class="col-auto ms-auto d-print-none">
                        <div class="btn-list">
                            <router-link v-if="canViewSources" class="btn" :to="{ name: 'company.sources' }">
                                {{ t('dashboardPage.all_sources') }}
                            </router-link>
                            <button v-if="canCreateDashboards" class="btn" type="button" @click="openCreateModal">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round" class="icon icon-2">
                                    <path d="M4 4h6v8h-6z" />
                                    <path d="M4 16h6v4h-6z" />
                                    <path d="M14 12h6v8h-6z" />
                                    <path d="M14 4h6v4h-6z" />
                                </svg>
                                {{ t('dashboardPage.create_dashboard') }}
                            </button>
                            <router-link v-if="canManageSources" class="btn btn-primary"
                                         :to="{ name: 'company.source.create' }">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round" class="icon icon-2">
                                    <path d="M12 5l0 14" />
                                    <path d="M5 12l14 0" />
                                </svg>
                                {{ t('dashboardPage.add_source') }}
                            </router-link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- END PAGE HEADER -->

        <main class="page-body">
            <div class="container-xl">
                <div v-if="listError" class="alert alert-danger" role="alert">{{ listError }}</div>

                <div v-if="loading" class="card">
                    <div class="card-body">
                        <div class="progress progress-sm">
                            <div class="progress-bar progress-bar-indeterminate"></div>
                        </div>
                    </div>
                </div>

                <template v-else>
                    <!-- ===================== СТАТИСТИКА ===================== -->
                    <!-- Каноничная плитка Tabler card-sm: аватар в col-auto,
                         значение через .font-weight-medium, подпись .text-secondary.
                         Раньше здесь были .subheader + .h3 — на полтора размера
                         крупнее, чем принято в шаблоне. -->
                    <div class="row row-cards mb-3">
                        <div class="col-sm-6 col-lg-4">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-auto">
                                            <span class="bg-blue-lt avatar avatar-sm">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                     class="icon">
                                                    <path d="M12 6m-8 0a8 3 0 1 0 16 0a8 3 0 1 0 -16 0" />
                                                    <path d="M4 6v6a8 3 0 0 0 16 0v-6" />
                                                    <path d="M4 12v6a8 3 0 0 0 16 0v-6" />
                                                </svg>
                                            </span>
                                        </div>
                                        <div class="col">
                                            <div class="font-weight-medium">{{ sources.length }}</div>
                                            <div class="text-secondary">{{ t('dashboardPage.stats.sources') }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-4">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-auto">
                                            <span class="bg-purple-lt avatar avatar-sm">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                     class="icon">
                                                    <path d="M8 9h8" />
                                                    <path d="M8 13h6" />
                                                    <path d="M18 4a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3h-5l-5 3v-3h-2a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3z" />
                                                </svg>
                                            </span>
                                        </div>
                                        <div class="col">
                                            <div class="font-weight-medium">{{ chats.length }}</div>
                                            <div class="text-secondary">{{ t('dashboardPage.stats.chats') }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-4">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-auto">
                                            <span class="bg-green-lt avatar avatar-sm">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                     class="icon">
                                                    <path d="M4 20l4 -9l4 5l3 -4l5 8" />
                                                </svg>
                                            </span>
                                        </div>
                                        <div class="col">
                                            <div class="font-weight-medium">{{ dashboards.length }}</div>
                                            <div class="text-secondary">{{ t('dashboardPage.stats.dashboards') }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===================== РАСХОД ИИ ===================== -->
                    <div v-if="usage" class="card card-sm mb-3">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="font-weight-medium">
                                        {{ t('dashboardPage.usage.title', { used: formatTokens(usage.used) }) }}
                                        <span v-if="usage.limit" class="text-secondary">
                                            {{ t('dashboardPage.usage.of_limit', { limit: formatTokens(usage.limit) }) }}
                                        </span>
                                        <span v-else class="text-secondary">{{ t('dashboardPage.usage.tokens') }}</span>
                                    </div>
                                    <div v-if="usage.limit" class="progress progress-sm mt-2">
                                        <div class="progress-bar" :class="usageBarClass"
                                             :style="{ width: usage.percent + '%' }"
                                             role="progressbar" :aria-valuenow="usage.percent"
                                             aria-valuemin="0" aria-valuemax="100">
                                            <span class="visually-hidden">{{ usage.percent }}%</span>
                                        </div>
                                    </div>
                                    <div v-else class="text-secondary mt-1">
                                        {{ t('dashboardPage.usage.no_limit') }}
                                    </div>
                                </div>
                                <div v-if="usage.limit" class="col-auto">
                                    <span class="badge" :class="usage.reached ? 'bg-danger-lt' : 'bg-secondary-lt'">
                                        {{ usage.percent }}%
                                    </span>
                                </div>
                            </div>

                            <div v-if="usage.reached" class="alert alert-danger mt-3 mb-0" role="alert">
                                {{ t('dashboardPage.usage.limit_reached') }}
                            </div>
                        </div>
                    </div>

                    <!-- ===================== ПУСТОЕ ПРОСТРАНСТВО ===================== -->
                    <div v-if="canViewSources && !sources.length" class="card mb-4">
                        <div class="card-body">
                            <div class="empty">
                                <p class="empty-title">{{ t('dashboardPage.empty_sources.title') }}</p>
                                <p class="empty-subtitle text-secondary">
                                    {{ t('dashboardPage.empty_sources.subtitle') }}
                                </p>
                                <div class="empty-action" v-if="canManageSources">
                                    <router-link class="btn btn-primary" :to="{ name: 'company.source.create' }">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round" class="icon icon-2">
                                            <path d="M12 5l0 14" />
                                            <path d="M5 12l14 0" />
                                        </svg>
                                        {{ t('dashboardPage.add_source') }}
                                    </router-link>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===================== ИСТОЧНИКИ ===================== -->
                    <template v-else-if="canViewSources">
                        <div class="row g-2 align-items-center mb-3">
                            <div class="col">
                                <h3 class="mb-0">{{ t('dashboardPage.sources.heading') }}</h3>
                            </div>
                            <div class="col-auto">
                                <router-link :to="{ name: 'company.sources' }" class="text-secondary">
                                    {{ t('dashboardPage.view_all') }}
                                </router-link>
                            </div>
                        </div>

                        <div class="row row-cards mb-4">
                            <div v-for="source in recentSources" :key="source.id" class="col-sm-6 col-lg-3">
                                <div class="card card-link card-link-pop h-100">
                                    <div class="card-body d-flex flex-column">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="badge" :class="typeBadgeClass(source.format_key)">
                                                {{ source.format_label }}
                                            </span>
                                            <span class="subheader text-muted">
                                                {{ formatDate(source.created_at) }}
                                            </span>
                                        </div>
                                        <h3 class="card-title mb-1 text-truncate">
                                            <router-link
                                                :to="{ name: 'company.source.show', params: { id: source.id } }"
                                                class="text-reset"
                                            >
                                                {{ source.name }}
                                            </router-link>
                                        </h3>
                                        <div class="text-secondary mt-auto">
                                            {{ t('dashboardPage.sources.chats_count', { count: source.chats_count ?? 0 }) }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- ===================== ЧАТЫ ===================== -->
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col">
                            <h3 class="mb-0">{{ t('dashboardPage.chats.heading') }}</h3>
                        </div>
                    </div>

                    <div v-if="!recentChats.length" class="card">
                        <div class="card-body">
                            <div class="empty">
                                <p class="empty-title">{{ t('dashboardPage.chats.empty_title') }}</p>
                                <p class="empty-subtitle text-secondary">
                                    {{ t('dashboardPage.chats.empty_subtitle') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-else class="row row-cards">
                        <div v-for="chat in recentChats" :key="chat.id" class="col-sm-6 col-lg-4">
                            <div class="card h-100 d-flex flex-column">
                                <div class="card-body pb-2">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="subheader text-muted">{{ formatDate(chat.created_at) }}</span>
                                        <span v-if="chat.data_source" class="badge"
                                              :class="typeBadgeClass(chat.data_source.format_key)">
                                            {{ chat.data_source.format_label }}
                                        </span>
                                    </div>
                                    <h3 class="card-title mb-1">
                                        <router-link
                                            :to="{ name: 'company.workspace.chat', params: { chat: chat.id } }"
                                            class="text-reset"
                                        >
                                            {{ chat.title || t('dashboardPage.chat_fallback_title', { id: chat.id }) }}
                                        </router-link>
                                    </h3>
                                    <div v-if="chat.data_source" class="text-secondary text-truncate">
                                        <router-link
                                            :to="{ name: 'company.source.show', params: { id: chat.data_source.id } }"
                                            class="text-reset"
                                        >
                                            {{ chat.data_source.name }}
                                        </router-link>
                                    </div>
                                </div>

                                <div v-if="chat.dashboards?.length" class="list-group list-group-flush border-top">
                                    <router-link
                                        v-for="dashboard in chat.dashboards"
                                        :key="dashboard.id"
                                        :to="{ name: 'company.workspace.dashboard', params: { dashboard: dashboard.id } }"
                                        class="list-group-item list-group-item-action d-flex align-items-center py-2 px-3"
                                    >
                                        <span class="badge bg-primary me-2" style="border-radius: 2.2px;"></span>
                                        <span class="text-truncate">{{ dashboard.name || t('dashboardPage.dashboard_fallback_name', { id: dashboard.id }) }}</span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round"
                                             class="icon ms-auto text-muted icon-xs">
                                            <path d="M9 6l6 6l-6 6" />
                                        </svg>
                                    </router-link>
                                </div>

                                <div class="card-footer bg-transparent py-2 px-3 border-top">
                                    <small class="text-muted">
                                        {{ t('dashboardPage.chats.total_dashboards', { count: chat.dashboards_count ?? 0 }) }}
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===================== ДАШБОРДЫ ===================== -->
                    <!-- Внизу обзора, потому что это результат работы: сначала
                         источник, потом чат или конструктор, и уже потом готовый
                         дашборд, к которому возвращаются каждый день. -->
                    <div class="row g-2 align-items-center mb-3 mt-4">
                        <div class="col">
                            <h3 class="mb-0">{{ t('dashboardPage.dashboards.heading') }}</h3>
                        </div>
                        <div v-if="dashboards.length" class="col-auto">
                            <router-link :to="{ name: 'company.dashboards' }" class="text-secondary">
                                {{ t('dashboardPage.view_all') }}
                            </router-link>
                        </div>
                    </div>

                    <div v-if="!recentDashboards.length" class="card">
                        <div class="card-body">
                            <div class="empty">
                                <p class="empty-title">{{ t('dashboardPage.dashboards.empty_title') }}</p>
                                <p class="empty-subtitle text-secondary">
                                    {{ t('dashboardPage.dashboards.empty_subtitle') }}
                                </p>
                                <div v-if="canCreateDashboards" class="empty-action">
                                    <button class="btn btn-primary" type="button" @click="openCreateModal">
                                        {{ t('dashboardPage.create_dashboard') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else class="row row-cards">
                        <div v-for="dashboard in recentDashboards" :key="dashboard.id"
                             class="col-sm-6 col-lg-3 col-xxl-2">
                            <div class="card card-link card-link-pop h-100">
                                <div class="card-body d-flex flex-column">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="badge" :class="dashboardStatus(dashboard).cls">
                                            {{ dashboardStatus(dashboard).text }}
                                        </span>
                                        <span class="subheader text-muted">
                                            {{ formatDate(dashboard.created_at) }}
                                        </span>
                                    </div>

                                    <h3 class="card-title mb-1">
                                        <router-link
                                            :to="{ name: 'company.workspace.dashboard', params: { dashboard: dashboard.id } }"
                                            class="text-reset"
                                        >
                                            {{ dashboard.name || t('dashboardPage.dashboard_fallback_name', { id: dashboard.id }) }}
                                        </router-link>
                                    </h3>

                                    <div class="text-secondary mt-auto">
                                        {{ t('dashboardPage.dashboards.widgets_count', { count: dashboard.widgets_count ?? 0 }) }}
                                        <template v-if="dashboard.data_source">
                                            · {{ dashboard.data_source.name }}
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </main>

        <!-- Создание дашборда руками -->
        <div ref="createModalEl" class="modal modal-blur fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <form @submit.prevent="submitCreate">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ t('dashboardPage.modal.title') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    :aria-label="t('dashboardPage.modal.close')"></button>
                        </div>

                        <div class="modal-body">
                            <div v-if="createError" class="alert alert-danger" role="alert">
                                {{ createError }}
                            </div>

                            <div class="mb-3">
                                <label class="form-label required">{{ t('dashboardPage.modal.name_label') }}</label>
                                <input v-model="createForm.name" type="text" class="form-control"
                                       :class="{ 'is-invalid': createErrors.name }"
                                       :placeholder="t('dashboardPage.modal.name_placeholder')" maxlength="255" required />
                                <div v-if="createErrors.name" class="invalid-feedback">
                                    {{ createErrors.name[0] }}
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">{{ t('dashboardPage.modal.description_label') }}</label>
                                <textarea v-model="createForm.description" class="form-control" rows="3"
                                          :placeholder="t('dashboardPage.modal.description_placeholder')"></textarea>
                            </div>

                            <div>
                                <label class="form-label required">{{ t('dashboardPage.modal.data_source_label') }}</label>
                                <select v-model="createForm.data_source_id" class="form-select"
                                        :class="{ 'is-invalid': createErrors.data_source_id }"
                                        :disabled="!sources.length" required>
                                    <option value="" disabled>{{ t('dashboardPage.modal.data_source_placeholder') }}</option>
                                    <option v-for="source in sources" :key="source.id" :value="source.id">
                                        {{ source.name }} — {{ source.format_label }}
                                    </option>
                                </select>
                                <div v-if="createErrors.data_source_id" class="invalid-feedback">
                                    {{ createErrors.data_source_id[0] }}
                                </div>
                                <!-- По источнику виджеты считают данные: без него дашборд
                                     будет пустой рамкой, поэтому поле обязательное. -->
                                <small v-if="sources.length" class="form-hint">
                                    {{ t('dashboardPage.modal.data_source_hint') }}
                                </small>
                                <small v-else class="form-hint text-danger">
                                    {{ t('dashboardPage.modal.no_sources_hint') }}
                                    <router-link v-if="canManageSources" :to="{ name: 'company.source.create' }">
                                        {{ t('dashboardPage.modal.connect_first') }}
                                    </router-link>
                                    <span v-else>{{ t('dashboardPage.modal.ask_admin') }}</span>
                                </small>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">
                                {{ t('dashboardPage.modal.cancel') }}
                            </button>
                            <button type="submit" class="btn btn-primary"
                                    :class="{ 'btn-loading': creating }"
                                    :disabled="creating || !sources.length">
                                {{ t('dashboardPage.modal.submit') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
