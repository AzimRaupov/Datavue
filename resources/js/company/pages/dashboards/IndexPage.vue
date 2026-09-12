<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick } from "vue";
import { Modal } from "bootstrap";
import { useRouter } from "vue-router";
import { useI18n } from "vue-i18n";
import api from "../../api.js";

/**
 * Все дашборды компании.
 *
 * До этой страницы дашборды можно было найти только через чат, из которого они
 * выросли: на обзоре показывался их счётчик, а сами они прятались внутри
 * карточек чатов. Собранный руками дашборд чата не имеет вовсе — и найти его
 * было бы негде.
 */

const router = useRouter();
const { t } = useI18n();

const dashboards = ref([]);
const sources = ref([]);
const loading = ref(true);
const listError = ref(null);
const search = ref("");
const originFilter = ref("all");

const currentUser = JSON.parse(localStorage.getItem("user") || "null");
const permissions = computed(() => currentUser?.permissions ?? []);
const canCreate = computed(() => permissions.value.includes("create dashboards"));
const canEdit = computed(() => permissions.value.includes("edit dashboards"));
const canDelete = computed(() => permissions.value.includes("delete dashboards"));
const canViewSources = computed(() => permissions.value.includes("view data sources"));

/**
 * Статус дашборда словами. Пока идёт генерация, дашборд открыть можно, но
 * виджеты в нём ещё появляются — об этом лучше сказать заранее.
 */
const STATUS_CLASS = {
    empty: "bg-secondary-lt",
    generating_scheme: "bg-azure-lt",
    generating_widgets: "bg-azure-lt",
    reviewing: "bg-azure-lt",
    completed: "bg-green-lt",
    failed: "bg-red-lt",
};

function statusOf(dashboard) {
    const cls = STATUS_CLASS[dashboard.status] ?? "bg-secondary-lt";
    const text = STATUS_CLASS[dashboard.status]
        ? t(`dashboardsIndex.status.${dashboard.status}`)
        : dashboard.status;

    return { text, cls };
}

const visible = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return dashboards.value.filter((dashboard) => {
        if (originFilter.value !== "all" && (dashboard.origin ?? "ai") !== originFilter.value) {
            return false;
        }

        if (!needle) return true;

        return (
            (dashboard.name ?? "").toLowerCase().includes(needle) ||
            (dashboard.description ?? "").toLowerCase().includes(needle) ||
            (dashboard.data_source?.name ?? "").toLowerCase().includes(needle)
        );
    });
});

function formatDate(value) {
    if (!value) return "—";

    return new Date(value).toLocaleDateString("ru-RU", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
    });
}

function titleOf(dashboard) {
    return dashboard.name || t("dashboardsIndex.untitled_dashboard", { id: dashboard.id });
}

async function fetchAll() {
    loading.value = true;
    listError.value = null;

    try {
        const requests = [api.get("/dashboards")];
        if (canViewSources.value) requests.push(api.get("/data_source"));

        const [dashboardsResponse, sourcesResponse] = await Promise.all(requests);

        dashboards.value = dashboardsResponse.data ?? [];
        sources.value = sourcesResponse?.data ?? [];
    } catch (err) {
        listError.value = t("dashboardsIndex.load_error");
    } finally {
        loading.value = false;
    }
}

// --- Создание (та же форма, что и на обзоре) --------------------------------

const createModalEl = ref(null);
let createModal = null;
const creating = ref(false);
const createError = ref(null);
const createErrors = ref({});
const createForm = reactive({ name: "", description: "", data_source_id: "" });

async function openCreateModal() {
    createForm.name = "";
    createForm.description = "";
    createForm.data_source_id = sources.value.length === 1 ? sources.value[0].id : "";
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
        const { data } = await api.post("/dashboards", {
            name: createForm.name,
            description: createForm.description || null,
            data_source_id: createForm.data_source_id || null,
        });

        createModal?.hide();
        router.push({ name: "company.workspace.dashboard", params: { dashboard: data.id }, query: { mode: "edit" } });
    } catch (err) {
        const body = err.response?.data;
        if (body?.errors) createErrors.value = body.errors;
        createError.value = body?.message || t("dashboardsIndex.create_error");
    } finally {
        creating.value = false;
    }
}

// --- Удаление ---------------------------------------------------------------

const deleteModalEl = ref(null);
let deleteModal = null;
const pendingDelete = ref(null);
const deleting = ref(false);

async function askDelete(dashboard) {
    pendingDelete.value = dashboard;
    await nextTick();
    deleteModal?.show();
}

async function confirmDelete() {
    if (!pendingDelete.value || deleting.value) return;

    deleting.value = true;

    try {
        await api.delete(`/dashboards/${pendingDelete.value.id}`);
        dashboards.value = dashboards.value.filter((item) => item.id !== pendingDelete.value.id);
        deleteModal?.hide();
        pendingDelete.value = null;
    } catch (err) {
        listError.value = err.response?.data?.message || t("dashboardsIndex.delete_error");
    } finally {
        deleting.value = false;
    }
}

onMounted(async () => {
    await fetchAll();
    await nextTick();

    if (createModalEl.value) createModal = new Modal(createModalEl.value);
    if (deleteModalEl.value) deleteModal = new Modal(deleteModalEl.value);
});

onBeforeUnmount(() => {
    createModal?.dispose();
    deleteModal?.dispose();
});
</script>

<template>
    <div class="page-wrapper">
        <div class="page-header d-print-none">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <div class="page-pretitle">{{ t('dashboardsIndex.workspace') }}</div>
                        <h2 class="page-title">{{ t('dashboardsIndex.title') }}</h2>
                    </div>
                    <div class="col-auto ms-auto">
                        <div class="btn-list">
                            <button v-if="canCreate" class="btn btn-primary" type="button" @click="openCreateModal">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round" class="icon icon-2">
                                    <path d="M12 5l0 14" />
                                    <path d="M5 12l14 0" />
                                </svg>
                                {{ t('dashboardsIndex.create_dashboard') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

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
                    <!-- Фильтры показываем, только когда есть что фильтровать -->
                    <div v-if="dashboards.length" class="row g-2 align-items-center mb-3">
                        <div class="col-12 col-md">
                            <input v-model="search" type="search" class="form-control"
                                   :placeholder="t('dashboardsIndex.search_placeholder')"
                                   :aria-label="t('dashboardsIndex.search_aria_label')" />
                        </div>
                        <div class="col-12 col-md-auto">
                            <select v-model="originFilter" class="form-select" :aria-label="t('dashboardsIndex.origin_filter_aria_label')">
                                <option value="all">{{ t('dashboardsIndex.origin_all') }}</option>
                                <option value="manual">{{ t('dashboardsIndex.origin_manual') }}</option>
                                <option value="ai">{{ t('dashboardsIndex.origin_ai') }}</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="!dashboards.length" class="card">
                        <div class="card-body">
                            <div class="empty">
                                <p class="empty-title">{{ t('dashboardsIndex.empty_title') }}</p>
                                <p class="empty-subtitle text-secondary">
                                    {{ t('dashboardsIndex.empty_subtitle') }}
                                </p>
                                <div v-if="canCreate" class="empty-action">
                                    <button class="btn btn-primary" type="button" @click="openCreateModal">
                                        {{ t('dashboardsIndex.create_dashboard') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="!visible.length" class="card">
                        <div class="card-body">
                            <div class="empty">
                                <p class="empty-title">{{ t('dashboardsIndex.not_found_title') }}</p>
                                <p class="empty-subtitle text-secondary">
                                    {{ t('dashboardsIndex.not_found_subtitle') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-else class="row row-cards">
                        <div v-for="dashboard in visible" :key="dashboard.id"
                             class="col-sm-6 col-lg-4 col-xxl-3">
                            <div class="card h-100 d-flex flex-column">
                                <div class="card-body pb-2">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="badge" :class="statusOf(dashboard).cls">
                                            {{ statusOf(dashboard).text }}
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
                                            {{ titleOf(dashboard) }}
                                        </router-link>
                                    </h3>

                                    <div v-if="dashboard.description" class="text-secondary mb-2">
                                        {{ dashboard.description }}
                                    </div>

                                    <div class="text-secondary small">
                                        {{ t('dashboardsIndex.widgets_count', { count: dashboard.widgets_count ?? 0 }) }}
                                        <template v-if="dashboard.data_source">
                                            · {{ dashboard.data_source.name }}
                                        </template>
                                    </div>

                                    <!-- Дашборд из чата — не тупик: к разговору,
                                         в котором он появился, надо уметь вернуться. -->
                                    <div v-if="dashboard.chat_id" class="text-secondary small mt-1">
                                        <router-link
                                            :to="{ name: 'company.workspace.dashboard', params: { dashboard: dashboard.id } }"
                                            class="text-reset"
                                        >
                                            {{ t('dashboardsIndex.from_chat', { title: dashboard.chat?.title || `#${dashboard.chat_id}` }) }}
                                        </router-link>
                                    </div>
                                </div>

                                <div class="card-footer bg-transparent border-top d-flex gap-2 flex-wrap">
                                    <router-link
                                        class="btn btn-sm"
                                        :to="{ name: 'company.workspace.dashboard', params: { dashboard: dashboard.id } }"
                                    >
                                        {{ t('dashboardsIndex.open') }}
                                    </router-link>
                                    <router-link
                                        v-if="canEdit"
                                        class="btn btn-sm"
                                        :to="{ name: 'company.workspace.dashboard', params: { dashboard: dashboard.id }, query: { mode: 'edit' } }"
                                    >
                                        {{ t('dashboardsIndex.edit') }}
                                    </router-link>
                                    <button v-if="canDelete" class="btn btn-sm btn-ghost-danger ms-auto"
                                            type="button" @click="askDelete(dashboard)">
                                        {{ t('dashboardsIndex.delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </main>

        <!-- Создание -->
        <div ref="createModalEl" class="modal modal-blur fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <form @submit.prevent="submitCreate">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ t('dashboardsIndex.new_dashboard') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" :aria-label="t('dashboardsIndex.close')"></button>
                        </div>

                        <div class="modal-body">
                            <div v-if="createError" class="alert alert-danger" role="alert">{{ createError }}</div>

                            <div class="mb-3">
                                <label class="form-label required">{{ t('dashboardsIndex.name_label') }}</label>
                                <input v-model="createForm.name" type="text" class="form-control"
                                       :class="{ 'is-invalid': createErrors.name }"
                                       :placeholder="t('dashboardsIndex.name_placeholder')" maxlength="255" required />
                                <div v-if="createErrors.name" class="invalid-feedback">{{ createErrors.name[0] }}</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">{{ t('dashboardsIndex.description_label') }}</label>
                                <textarea v-model="createForm.description" class="form-control" rows="3"
                                          :placeholder="t('dashboardsIndex.description_placeholder')"></textarea>
                            </div>

                            <div>
                                <label class="form-label required">{{ t('dashboardsIndex.data_source_label') }}</label>
                                <select v-model="createForm.data_source_id" class="form-select"
                                        :class="{ 'is-invalid': createErrors.data_source_id }"
                                        :disabled="!sources.length" required>
                                    <option value="" disabled>{{ t('dashboardsIndex.data_source_placeholder') }}</option>
                                    <option v-for="source in sources" :key="source.id" :value="source.id">
                                        {{ source.name }} — {{ source.format_label }}
                                    </option>
                                </select>
                                <div v-if="createErrors.data_source_id" class="invalid-feedback">
                                    {{ createErrors.data_source_id[0] }}
                                </div>
                                <small v-if="sources.length" class="form-hint">
                                    {{ t('dashboardsIndex.data_source_hint') }}
                                </small>
                                <small v-else class="form-hint text-danger">
                                    {{ t('dashboardsIndex.no_sources_hint') }}
                                </small>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">
                                {{ t('dashboardsIndex.cancel') }}
                            </button>
                            <button type="submit" class="btn btn-primary" :class="{ 'btn-loading': creating }"
                                    :disabled="creating || !sources.length">
                                {{ t('dashboardsIndex.create_and_open') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Удаление -->
        <div ref="deleteModalEl" class="modal modal-blur fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-status bg-danger"></div>
                    <div class="modal-body text-center py-4">
                        <h3>{{ t('dashboardsIndex.delete_confirm_title') }}</h3>
                        <div class="text-secondary">
                            {{ t('dashboardsIndex.delete_confirm_text', { name: pendingDelete ? titleOf(pendingDelete) : "" }) }}
                        </div>
                    </div>
                    <div class="modal-footer">
                        <div class="w-100">
                            <div class="row">
                                <div class="col">
                                    <button class="btn w-100" data-bs-dismiss="modal">{{ t('dashboardsIndex.cancel') }}</button>
                                </div>
                                <div class="col">
                                    <button class="btn btn-danger w-100" :class="{ 'btn-loading': deleting }"
                                            :disabled="deleting" @click="confirmDelete">
                                        {{ t('dashboardsIndex.delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
