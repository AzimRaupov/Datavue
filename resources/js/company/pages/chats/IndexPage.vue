<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick } from "vue";
import { Modal } from "bootstrap";
import { useRouter } from "vue-router";
import { useI18n } from "vue-i18n";
import api from "../../api.js";

/**
 * Чаты компании.
 *
 * Раньше новый чат заводился прямо со страницы источников — теперь это
 * отдельная страница, как у дашбордов и пространств: список того, что уже
 * есть, и кнопка «Создать» рядом с ним. Создание работает так же, как раньше
 * на источниках: чат заводится вместе с пространством, и агент сразу готовит
 * варианты дашбордов — окно создания держит прогресс, пока это не закончится.
 */

const router = useRouter();
const { t } = useI18n();

const chats = ref([]);
const sources = ref([]);
const loading = ref(true);
const listError = ref(null);
const search = ref("");

const currentUser = JSON.parse(localStorage.getItem("user") || "null");
const permissions = computed(() => currentUser?.permissions ?? []);
const canCreate = computed(() => permissions.value.includes("create chats"));
const canDelete = computed(() => permissions.value.includes("delete chats"));
const canViewSources = computed(() => permissions.value.includes("view data sources"));

const visible = computed(() => {
    const needle = search.value.trim().toLowerCase();

    if (!needle) return chats.value;

    return chats.value.filter(
        (chat) =>
            (chat.title ?? "").toLowerCase().includes(needle) ||
            (chat.data_source?.name ?? "").toLowerCase().includes(needle)
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
        const requests = [api.get("/chats")];
        if (canViewSources.value) requests.push(api.get("/data_source"));

        const [chatsResponse, sourcesResponse] = await Promise.all(requests);

        chats.value = chatsResponse.data ?? [];
        sources.value = sourcesResponse?.data ?? [];
    } catch (err) {
        listError.value =
            err.response?.status === 403
                ? t("chatsIndex.errors.no_permission")
                : t("chatsIndex.errors.load_failed");
    } finally {
        loading.value = false;
    }
}

// --- Создание -----------------------------------------------------------
//
// Тот же процесс, что раньше жил на странице источников: чат заводится
// вместе с рабочим пространством, и агент сразу готовит варианты
// дашбордов на источнике. Первый чат на источнике может занять до минуты
// (заодно строится группировка таблиц), поэтому окно во время создания
// не закрывается — оно просто показывает прогресс.

const createModalEl = ref(null);
let createModal = null;
const creating = ref(false);
const createError = ref(null);
const createErrors = ref({});
const createForm = reactive({ data_source_id: "", title: "" });

const creatingSourceName = computed(() =>
    sources.value.find((s) => s.id === createForm.data_source_id)?.name ?? ""
);

async function openCreateModal() {
    createForm.data_source_id = sources.value.length === 1 ? sources.value[0].id : "";
    createForm.title = "";
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
        const { data } = await api.post("/chats", {
            data_source_id: createForm.data_source_id,
            title: createForm.title || undefined,
        });

        createModal?.hide();
        router.push({ name: "company.workspace", params: { workspace: data.workspace.id } });
    } catch (err) {
        const body = err.response?.data;
        if (body?.errors) createErrors.value = body.errors;
        createError.value = body?.message || t("chatsIndex.errors.create_failed");
    } finally {
        creating.value = false;
    }
}

// --- Удаление -------------------------------------------------------------

const deleteModalEl = ref(null);
let deleteModal = null;
const pendingDelete = ref(null);
const deleting = ref(false);

async function askDelete(chat) {
    pendingDelete.value = chat;
    await nextTick();
    deleteModal?.show();
}

async function confirmDelete() {
    if (!pendingDelete.value || deleting.value) return;

    deleting.value = true;

    try {
        await api.delete(`/chats/${pendingDelete.value.id}`);
        chats.value = chats.value.filter((item) => item.id !== pendingDelete.value.id);
        deleteModal?.hide();
        pendingDelete.value = null;
    } catch (err) {
        listError.value = err.response?.data?.message || t("chatsIndex.errors.delete_failed");
    } finally {
        deleting.value = false;
    }
}

function openChat(chat) {
    if (chat.workspace_id) {
        router.push({ name: "company.workspace", params: { workspace: chat.workspace_id } });
        return;
    }

    // Чаты, заведённые до пространств, — на прежнем маршруте: он сам
    // подберёт или заведёт пространство для них.
    router.push({ name: "company.workspace.chat", params: { chat: chat.id } });
}

onMounted(async () => {
    await fetchAll();
    await nextTick();

    // Пока агент готовит варианты дашбордов, окно закрывать нельзя —
    // см. комментарий у submitCreate().
    if (createModalEl.value) {
        createModal = new Modal(createModalEl.value, { backdrop: "static", keyboard: false });
    }
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
                        <div class="page-pretitle">{{ t('chatsIndex.header.pretitle') }}</div>
                        <h2 class="page-title">{{ t('chatsIndex.header.title') }}</h2>
                        <div class="text-secondary mt-1">
                            {{ t('chatsIndex.header.subtitle') }}
                        </div>
                    </div>
                    <div class="col-auto ms-auto">
                        <button v-if="canCreate" class="btn btn-primary" type="button" @click="openCreateModal">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" class="icon icon-2">
                                <path d="M12 5l0 14" />
                                <path d="M5 12l14 0" />
                            </svg>
                            {{ t('chatsIndex.actions.create') }}
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
                    <div v-if="chats.length" class="mb-3">
                        <input v-model="search" type="search" class="form-control"
                               :placeholder="t('chatsIndex.search.placeholder')"
                               :aria-label="t('chatsIndex.search.aria_label')" />
                    </div>

                    <div v-if="!chats.length" class="card">
                        <div class="card-body">
                            <div class="empty">
                                <p class="empty-title">{{ t('chatsIndex.empty.title_default') }}</p>
                                <p class="empty-subtitle text-secondary">
                                    {{ t('chatsIndex.empty.subtitle_default') }}
                                </p>
                                <div v-if="canCreate" class="empty-action">
                                    <button class="btn btn-primary" type="button" @click="openCreateModal">
                                        {{ t('chatsIndex.actions.create') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="!visible.length" class="card">
                        <div class="card-body">
                            <div class="empty">
                                <p class="empty-title">{{ t('chatsIndex.empty.title_search') }}</p>
                                <p class="empty-subtitle text-secondary">{{ t('chatsIndex.empty.subtitle_search') }}</p>
                            </div>
                        </div>
                    </div>

                    <div v-else class="row row-cards">
                        <div v-for="chat in visible" :key="chat.id" class="col-sm-6 col-lg-4 col-xxl-3">
                            <div class="card h-100 d-flex flex-column">
                                <div class="card-body pb-2">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="badge bg-blue-lt">
                                            {{ t('chatsIndex.dashboards_count', { count: chat.dashboards_count ?? 0 }) }}
                                        </span>
                                        <span class="subheader text-muted">
                                            {{ formatDate(chat.created_at) }}
                                        </span>
                                    </div>

                                    <h3 class="card-title mb-1">
                                        <a href="#" class="text-reset" @click.prevent="openChat(chat)">
                                            {{ chat.title }}
                                        </a>
                                    </h3>

                                    <div v-if="chat.data_source" class="text-secondary small">
                                        {{ chat.data_source.name }}
                                    </div>
                                    <div v-else class="text-danger small">
                                        {{ t('chatsIndex.source_deleted') }}
                                    </div>
                                </div>

                                <div class="card-footer bg-transparent border-top d-flex gap-2 flex-wrap">
                                    <button class="btn btn-sm" type="button" @click="openChat(chat)">
                                        {{ t('chatsIndex.actions.open') }}
                                    </button>
                                    <button v-if="canDelete" class="btn btn-sm btn-ghost-danger ms-auto"
                                            type="button" @click="askDelete(chat)">
                                        {{ t('chatsIndex.actions.delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </main>

        <!-- BEGIN MODAL: создание чата -->
        <div ref="createModalEl" class="modal modal-blur fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ t('chatsIndex.create_modal.title') }}</h5>
                        <button v-if="!creating" type="button" class="btn-close" data-bs-dismiss="modal"
                                :aria-label="t('chatsIndex.actions.close')"></button>
                    </div>

                    <!-- Пока агент готовит варианты дашбордов, форма заменяется
                         прогрессом, и окно не закрывается (см. submitCreate). -->
                    <div v-if="creating" class="modal-body text-center py-4">
                        <div class="spinner-border text-primary mb-3" role="status"></div>
                        <h3 class="mb-1">{{ t('chatsIndex.preparing.title') }}</h3>
                        <div class="text-secondary">
                            {{ t('chatsIndex.preparing.body', { name: creatingSourceName }) }}
                        </div>
                        <div class="progress progress-sm mt-3">
                            <div class="progress-bar progress-bar-indeterminate"></div>
                        </div>
                    </div>

                    <form v-else @submit.prevent="submitCreate">
                        <div class="modal-body">
                            <div v-if="createError" class="alert alert-danger" role="alert">{{ createError }}</div>

                            <div class="mb-3">
                                <label class="form-label required">{{ t('chatsIndex.create_modal.source_label') }}</label>
                                <select v-model="createForm.data_source_id" class="form-select"
                                        :class="{ 'is-invalid': createErrors.data_source_id }"
                                        :disabled="!sources.length" required>
                                    <option value="" disabled>{{ t('chatsIndex.create_modal.source_placeholder') }}</option>
                                    <option v-for="source in sources" :key="source.id" :value="source.id">
                                        {{ source.name }} — {{ source.format_label }}
                                    </option>
                                </select>
                                <div v-if="createErrors.data_source_id" class="invalid-feedback">
                                    {{ createErrors.data_source_id[0] }}
                                </div>
                                <small v-if="sources.length" class="form-hint">
                                    {{ t('chatsIndex.create_modal.source_hint') }}
                                </small>
                                <small v-else class="form-hint text-danger">
                                    {{ t('chatsIndex.create_modal.no_sources_hint') }}
                                </small>
                            </div>

                            <div class="mb-1">
                                <label class="form-label">{{ t('chatsIndex.create_modal.title_label') }}</label>
                                <input v-model="createForm.title" type="text" class="form-control"
                                       :class="{ 'is-invalid': createErrors.title }"
                                       :placeholder="t('chatsIndex.create_modal.title_placeholder')" />
                                <div v-if="createErrors.title" class="invalid-feedback">{{ createErrors.title[0] }}</div>
                                <small class="form-hint">
                                    {{ t('chatsIndex.create_modal.title_hint') }}
                                </small>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">
                                {{ t('chatsIndex.actions.cancel') }}
                            </button>
                            <button type="submit" class="btn btn-primary ms-auto" :disabled="!sources.length || !createForm.data_source_id">
                                {{ t('chatsIndex.create_modal.submit') }}
                            </button>
                        </div>
                    </form>
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
                        <h3>{{ t('chatsIndex.delete_modal.title') }}</h3>
                        <div class="text-secondary">
                            {{ t('chatsIndex.delete_modal.body', { name: pendingDelete?.title }) }}
                        </div>
                    </div>
                    <div class="modal-footer">
                        <div class="w-100">
                            <div class="row">
                                <div class="col">
                                    <button class="btn w-100" data-bs-dismiss="modal">{{ t('chatsIndex.actions.cancel') }}</button>
                                </div>
                                <div class="col">
                                    <button class="btn btn-danger w-100" :class="{ 'btn-loading': deleting }"
                                            :disabled="deleting" @click="confirmDelete">
                                        {{ deleting ? t('chatsIndex.actions.deleting') : t('chatsIndex.actions.delete') }}
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
