<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick } from "vue";
import { Modal } from "bootstrap";
import { useRouter } from "vue-router";
import api from "../../api.js";

/**
 * Рабочие пространства компании.
 *
 * Пространство — это задача: свой источник данных, свои дашборды и свой
 * разговор с агентом. «Продажи» и «Склад» на одной и той же базе — разная
 * работа разных людей, поэтому пространство заводит человек, а не система.
 */

const router = useRouter();

const workspaces = ref([]);
const sources = ref([]);
const loading = ref(true);
const listError = ref(null);
const search = ref("");

const currentUser = JSON.parse(localStorage.getItem("user") || "null");
const permissions = computed(() => currentUser?.permissions ?? []);
const canCreate = computed(() => permissions.value.includes("create dashboards"));
const canDelete = computed(() => permissions.value.includes("delete dashboards"));
const canViewSources = computed(() => permissions.value.includes("view data sources"));

const visible = computed(() => {
    const needle = search.value.trim().toLowerCase();

    if (!needle) return workspaces.value;

    return workspaces.value.filter(
        (item) =>
            (item.name ?? "").toLowerCase().includes(needle) ||
            (item.description ?? "").toLowerCase().includes(needle) ||
            (item.data_source?.name ?? "").toLowerCase().includes(needle)
    );
});

function formatDate(value) {
    if (!value) return "—";

    return new Date(value).toLocaleDateString("ru-RU", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
    });
}

async function fetchAll() {
    loading.value = true;
    listError.value = null;

    try {
        const requests = [api.get("/workspaces")];
        if (canViewSources.value) requests.push(api.get("/data_source"));

        const [workspacesResponse, sourcesResponse] = await Promise.all(requests);

        workspaces.value = workspacesResponse.data ?? [];
        sources.value = sourcesResponse?.data ?? [];
    } catch (err) {
        listError.value = "Не удалось загрузить рабочие пространства.";
    } finally {
        loading.value = false;
    }
}

// --- Создание ---------------------------------------------------------------

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
        const { data } = await api.post("/workspaces", {
            name: createForm.name,
            description: createForm.description || null,
            data_source_id: createForm.data_source_id || null,
        });

        createModal?.hide();
        router.push({ name: "company.workspace", params: { workspace: data.id } });
    } catch (err) {
        const body = err.response?.data;
        if (body?.errors) createErrors.value = body.errors;
        createError.value = body?.message || "Не удалось создать пространство.";
    } finally {
        creating.value = false;
    }
}

// --- Удаление ---------------------------------------------------------------

const deleteModalEl = ref(null);
let deleteModal = null;
const pendingDelete = ref(null);
const deleting = ref(false);

async function askDelete(workspace) {
    pendingDelete.value = workspace;
    await nextTick();
    deleteModal?.show();
}

async function confirmDelete() {
    if (!pendingDelete.value || deleting.value) return;

    deleting.value = true;

    try {
        await api.delete(`/workspaces/${pendingDelete.value.id}`);
        workspaces.value = workspaces.value.filter((item) => item.id !== pendingDelete.value.id);
        deleteModal?.hide();
        pendingDelete.value = null;
    } catch (err) {
        listError.value = err.response?.data?.message || "Не удалось удалить пространство.";
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
                        <div class="page-pretitle">Работа</div>
                        <h2 class="page-title">Рабочие пространства</h2>
                    </div>
                    <div class="col-auto ms-auto">
                        <button v-if="canCreate" class="btn btn-primary" type="button" @click="openCreateModal">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" class="icon icon-2">
                                <path d="M12 5l0 14" />
                                <path d="M5 12l14 0" />
                            </svg>
                            Создать пространство
                        </button>
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
                    <div v-if="workspaces.length" class="mb-3">
                        <input v-model="search" type="search" class="form-control"
                               placeholder="Поиск по названию, описанию или источнику"
                               aria-label="Поиск пространства" />
                    </div>

                    <div v-if="!workspaces.length" class="card">
                        <div class="card-body">
                            <div class="empty">
                                <p class="empty-title">Пространств пока нет</p>
                                <p class="empty-subtitle text-secondary">
                                    Пространство — это задача: источник данных, дашборды по нему
                                    и разговор с агентом. Заведите первое — и собирайте внутри
                                    сколько угодно дашбордов.
                                </p>
                                <div v-if="canCreate" class="empty-action">
                                    <button class="btn btn-primary" type="button" @click="openCreateModal">
                                        Создать пространство
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="!visible.length" class="card">
                        <div class="card-body">
                            <div class="empty">
                                <p class="empty-title">Ничего не найдено</p>
                                <p class="empty-subtitle text-secondary">Попробуйте изменить запрос.</p>
                            </div>
                        </div>
                    </div>

                    <div v-else class="row row-cards">
                        <div v-for="workspace in visible" :key="workspace.id"
                             class="col-sm-6 col-lg-4 col-xxl-3">
                            <div class="card h-100 d-flex flex-column">
                                <div class="card-body pb-2">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="badge bg-blue-lt">
                                            {{ workspace.dashboards_count ?? 0 }} дашборд(ов)
                                        </span>
                                        <span class="subheader text-muted">
                                            {{ formatDate(workspace.created_at) }}
                                        </span>
                                    </div>

                                    <h3 class="card-title mb-1">
                                        <router-link
                                            :to="{ name: 'company.workspace', params: { workspace: workspace.id } }"
                                            class="text-reset"
                                        >
                                            {{ workspace.name }}
                                        </router-link>
                                    </h3>

                                    <div v-if="workspace.description" class="text-secondary mb-2">
                                        {{ workspace.description }}
                                    </div>

                                    <div v-if="workspace.data_source" class="text-secondary small">
                                        Источник: {{ workspace.data_source.name }}
                                    </div>
                                    <div v-else class="text-danger small">
                                        Источник данных удалён
                                    </div>
                                </div>

                                <div class="card-footer bg-transparent border-top d-flex gap-2 flex-wrap">
                                    <router-link
                                        class="btn btn-sm"
                                        :to="{ name: 'company.workspace', params: { workspace: workspace.id } }"
                                    >
                                        Открыть
                                    </router-link>
                                    <button v-if="canDelete" class="btn btn-sm btn-ghost-danger ms-auto"
                                            type="button" @click="askDelete(workspace)">
                                        Удалить
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
                            <h5 class="modal-title">Новое рабочее пространство</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Закрыть"></button>
                        </div>

                        <div class="modal-body">
                            <div v-if="createError" class="alert alert-danger" role="alert">{{ createError }}</div>

                            <div class="mb-3">
                                <label class="form-label required">Название</label>
                                <input v-model="createForm.name" type="text" class="form-control"
                                       :class="{ 'is-invalid': createErrors.name }"
                                       placeholder="Продажи" maxlength="255" required />
                                <div v-if="createErrors.name" class="invalid-feedback">{{ createErrors.name[0] }}</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Описание</label>
                                <textarea v-model="createForm.description" class="form-control" rows="2"
                                          placeholder="Над чем здесь работают"></textarea>
                            </div>

                            <div>
                                <label class="form-label required">Источник данных</label>
                                <select v-model="createForm.data_source_id" class="form-select"
                                        :class="{ 'is-invalid': createErrors.data_source_id }"
                                        :disabled="!sources.length" required>
                                    <option value="" disabled>Выберите источник</option>
                                    <option v-for="source in sources" :key="source.id" :value="source.id">
                                        {{ source.name }} — {{ source.format_label }}
                                    </option>
                                </select>
                                <div v-if="createErrors.data_source_id" class="invalid-feedback">
                                    {{ createErrors.data_source_id[0] }}
                                </div>
                                <small v-if="sources.length" class="form-hint">
                                    По нему будут считать все дашборды пространства.
                                </small>
                                <small v-else class="form-hint text-danger">
                                    Источников пока нет — сначала подключите источник данных.
                                </small>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">
                                Отмена
                            </button>
                            <button type="submit" class="btn btn-primary" :class="{ 'btn-loading': creating }"
                                    :disabled="creating || !sources.length">
                                Создать и открыть
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
                        <h3>Удалить пространство?</h3>
                        <div class="text-secondary">
                            «{{ pendingDelete?.name }}», все его дашборды и переписка с агентом
                            будут удалены без возможности вернуть. Источник данных останется.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <div class="w-100">
                            <div class="row">
                                <div class="col">
                                    <button class="btn w-100" data-bs-dismiss="modal">Отмена</button>
                                </div>
                                <div class="col">
                                    <button class="btn btn-danger w-100" :class="{ 'btn-loading': deleting }"
                                            :disabled="deleting" @click="confirmDelete">
                                        Удалить
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
