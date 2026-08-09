<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api.js';

const router = useRouter();

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

// Показываем только свежее: полный список — на отдельных страницах.
const recentSources = computed(() => sources.value.slice(0, 4));
const recentChats = computed(() => chats.value.slice(0, 6));

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
        listError.value = 'Не удалось загрузить рабочее пространство.';
    } finally {
        loading.value = false;
    }
}

onMounted(fetchAll);
</script>

<template>
    <div class="page-wrapper">
        <!-- BEGIN PAGE HEADER -->
        <div class="page-header d-print-none">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <div class="page-pretitle">Data to Dashboard</div>
                        <h2 class="page-title">Обзор рабочего пространства</h2>
                    </div>
                    <div class="col-auto ms-auto d-print-none">
                        <div class="btn-list">
                            <router-link v-if="canViewSources" class="btn" :to="{ name: 'company.sources' }">
                                Все источники
                            </router-link>
                            <router-link v-if="canManageSources" class="btn btn-primary"
                                         :to="{ name: 'company.source.create' }">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round" class="icon icon-2">
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
                                            <div class="text-secondary">Источников данных</div>
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
                                            <div class="text-secondary">Чатов</div>
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
                                            <div class="text-secondary">Дашбордов</div>
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
                                        Расход ИИ за месяц: {{ formatTokens(usage.used) }}
                                        <span v-if="usage.limit" class="text-secondary">
                                            из {{ formatTokens(usage.limit) }} токенов
                                        </span>
                                        <span v-else class="text-secondary">токенов</span>
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
                                        Лимит не задан — расход не ограничен.
                                    </div>
                                </div>
                                <div v-if="usage.limit" class="col-auto">
                                    <span class="badge" :class="usage.reached ? 'bg-danger-lt' : 'bg-secondary-lt'">
                                        {{ usage.percent }}%
                                    </span>
                                </div>
                            </div>

                            <div v-if="usage.reached" class="alert alert-danger mt-3 mb-0" role="alert">
                                Лимит исчерпан — новые запросы к ИИ отклоняются до начала месяца.
                            </div>
                        </div>
                    </div>

                    <!-- ===================== ПУСТОЕ ПРОСТРАНСТВО ===================== -->
                    <div v-if="canViewSources && !sources.length" class="card mb-4">
                        <div class="card-body">
                            <div class="empty">
                                <p class="empty-title">Начните с источника данных</p>
                                <p class="empty-subtitle text-secondary">
                                    Подключите базу данных или загрузите файл. После этого на источнике
                                    можно будет создать чат и описать словами нужный дашборд.
                                </p>
                                <div class="empty-action" v-if="canManageSources">
                                    <router-link class="btn btn-primary" :to="{ name: 'company.source.create' }">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round" class="icon icon-2">
                                            <path d="M12 5l0 14" />
                                            <path d="M5 12l14 0" />
                                        </svg>
                                        Добавить источник
                                    </router-link>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===================== ИСТОЧНИКИ ===================== -->
                    <template v-else-if="canViewSources">
                        <div class="row g-2 align-items-center mb-3">
                            <div class="col">
                                <h3 class="mb-0">Источники данных</h3>
                            </div>
                            <div class="col-auto">
                                <router-link :to="{ name: 'company.sources' }" class="text-secondary">
                                    Смотреть все →
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
                                            {{ source.chats_count ?? 0 }} чат(ов)
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- ===================== ЧАТЫ ===================== -->
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col">
                            <h3 class="mb-0">Последние чаты</h3>
                        </div>
                    </div>

                    <div v-if="!recentChats.length" class="card">
                        <div class="card-body">
                            <div class="empty">
                                <p class="empty-title">Чатов пока нет</p>
                                <p class="empty-subtitle text-secondary">
                                    Откройте источник данных и создайте на нём первый чат.
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
                                            :to="{ name: 'company.chat', params: { id: chat.id } }"
                                            class="text-reset"
                                        >
                                            {{ chat.title || `Чат #${chat.id}` }}
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
                                        :to="{ name: 'company.chat', params: { id: chat.id, dashboard: dashboard.id } }"
                                        class="list-group-item list-group-item-action d-flex align-items-center py-2 px-3"
                                    >
                                        <span class="badge bg-primary me-2" style="border-radius: 2.2px;"></span>
                                        <span class="text-truncate">{{ dashboard.name || `Дашборд #${dashboard.id}` }}</span>
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
                                        Всего дашбордов: {{ chat.dashboards_count ?? 0 }}
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </main>
    </div>
</template>
