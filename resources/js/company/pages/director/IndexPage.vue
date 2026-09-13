<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useI18n } from "vue-i18n";
import axios from "axios";
import { Modal } from "bootstrap";
import api from "../../api.js";
import { disconnectEcho } from "../../echo.js";
import DirectorChatPanel from "../../components/director/DirectorChatPanel.vue";

/**
 * Единственный экран роли «Директор» — чат с историей, как в обычных
 * ИИ-приложениях: слева список разговоров и кнопка «Новый чат», справа —
 * открытый диалог. Никакого конструктора, источников или настроек: всё,
 * что директору нужно от платформы, делается через переписку с агентом
 * (DirectorChatPanel → ChatConversation), а результат — через
 * DirectorDashboardViewer.
 */

const logo = '/logos/logo.png';

const route = useRoute();
const router = useRouter();
const { t, locale } = useI18n();

if (localStorage.getItem("lang")) {
    locale.value = localStorage.getItem("lang");
}

const currentUser = JSON.parse(localStorage.getItem("user") || "null");
const permissions = computed(() => currentUser?.permissions ?? []);
const canCreate = computed(() => permissions.value.includes("create chats"));
const canEditChat = computed(() => permissions.value.includes("edit chats"));
const canDeleteChat = computed(() => permissions.value.includes("delete chats"));

const chats = ref([]);
const sources = ref([]);
const loading = ref(true);
const listError = ref(null);

const activeChatId = computed(() => (route.params.chat ? Number(route.params.chat) : null));

const sidebarOpen = ref(false);

async function fetchAll() {
    loading.value = true;
    listError.value = null;

    try {
        const [chatsRes, sourcesRes] = await Promise.all([
            api.get("/chats"),
            api.get("/data_source"),
        ]);

        chats.value = chatsRes.data ?? [];
        sources.value = sourcesRes.data ?? [];
    } catch (err) {
        listError.value = err.response?.data?.message || t("director.errors.load_failed");
    } finally {
        loading.value = false;
    }
}

function selectChat(chat) {
    sidebarOpen.value = false;
    showNewChatForm.value = false;
    router.push({ name: "director.chat", params: { chat: chat.id } });
}

// --- Новый чат ---------------------------------------------------------

const showNewChatForm = ref(false);
const newChatForm = reactive({ data_source_id: "", title: "" });
const creatingChat = ref(false);
const createError = ref(null);

function openNewChatForm() {
    newChatForm.data_source_id = sources.value.length === 1 ? sources.value[0].id : "";
    newChatForm.title = "";
    createError.value = null;
    showNewChatForm.value = true;
    sidebarOpen.value = false;

    if (route.params.chat) {
        router.push({ name: "director.home" });
    }
}

async function submitNewChat() {
    if (creatingChat.value || !newChatForm.data_source_id) return;

    creatingChat.value = true;
    createError.value = null;

    try {
        const { data } = await api.post("/chats", {
            data_source_id: newChatForm.data_source_id,
            title: newChatForm.title || undefined,
        });

        chats.value.unshift({ ...data.chat, dashboards_count: 0 });
        showNewChatForm.value = false;
        router.push({ name: "director.chat", params: { chat: data.chat.id } });
    } catch (err) {
        createError.value = err.response?.data?.message || t("director.new_chat.errors.create_failed");
    } finally {
        creatingChat.value = false;
    }
}

watch(activeChatId, (id) => {
    if (id) showNewChatForm.value = false;
});

// --- Переименование -----------------------------------------------------

const renamingId = ref(null);
const renameValue = ref("");
const renameInput = ref(null);

async function startRename(chat) {
    renamingId.value = chat.id;
    renameValue.value = chat.title;

    await nextTick();

    // ref внутри v-for всегда собирается Vue в массив, даже когда условие
    // (v-if="renamingId === chat.id") оставляет в нём один элемент.
    const input = Array.isArray(renameInput.value) ? renameInput.value[0] : renameInput.value;
    input?.focus();
    input?.select();
}

function cancelRename() {
    renamingId.value = null;
}

async function confirmRename(chat) {
    if (renamingId.value !== chat.id) return;

    const title = renameValue.value.trim();
    renamingId.value = null;

    if (!title || title === chat.title) return;

    try {
        const { data } = await api.patch(`/chats/${chat.id}`, { title });
        chat.title = data.title ?? title;
    } catch (err) {
        listError.value = err.response?.data?.message || t("director.errors.rename_failed");
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

        const wasActive = pendingDelete.value.id === activeChatId.value;
        chats.value = chats.value.filter((item) => item.id !== pendingDelete.value.id);
        deleteModal?.hide();
        pendingDelete.value = null;

        if (wasActive) router.push({ name: "director.home" });
    } catch (err) {
        listError.value = err.response?.data?.message || t("director.errors.delete_failed");
    } finally {
        deleting.value = false;
    }
}

// --- Пользовательское меню (шапка своя, без общего Header) -----------------

const changeLanguage = (lang) => {
    locale.value = lang;
    localStorage.setItem("lang", lang);
};

const loggingOut = ref(false);

async function logout() {
    if (loggingOut.value) return;
    loggingOut.value = true;

    try {
        await axios.post("/api/logout", {}, {
            headers: {
                Authorization: `Bearer ${localStorage.getItem("token")}`,
                Accept: "application/json",
            },
        });
    } catch {
        // намеренно тихо — см. Header.vue: выйти локально нужно в любом случае
    } finally {
        disconnectEcho();
        localStorage.removeItem("token");
        localStorage.removeItem("user");
        window.location.href = "/login";
    }
}

onMounted(async () => {
    await fetchAll();
    await nextTick();

    if (deleteModalEl.value) deleteModal = new Modal(deleteModalEl.value);

    // Заход без выбранного чата: если хоть один уже есть — просто ждём выбора,
    // если это первый визит вообще — сразу ведём к созданию.
    if (!activeChatId.value && !chats.value.length) {
        openNewChatForm();
    }
});

onBeforeUnmount(() => {
    deleteModal?.dispose();
});
</script>

<template>
    <div class="director-shell d-flex">
        <div class="director-backdrop d-lg-none" :class="{ 'd-none': !sidebarOpen }" @click="sidebarOpen = false"></div>

        <aside class="director-sidebar d-flex flex-column" :class="{ 'sidebar-open': sidebarOpen }">
            <!-- Бренд — как в остальном приложении (Header.vue), но здесь без
                 навигации рядом: у директора кроме чата ничего нет. Логотип
                 обязательно через класс .navbar-brand-image, а не HTML-атрибут
                 height — у Tabler своё правило img{height:auto}, которое
                 атрибут перетирает (см. tabler-overrides.css). -->
            <div class="navbar-brand navbar-brand-autodark p-3 pb-2 flex-shrink-0">
                <img :src="logo" alt="Datavue" class="navbar-brand-image" />
            </div>

            <!-- Новый чат — полноширинная кнопка над списком, а не втиснута
                 в шапку рядом с логотипом: по смыслу она относится к списку
                 разговоров, а не к бренду. -->
            <div class="px-3 pb-3 flex-shrink-0">
                <button
                    class="btn btn-primary w-100 d-inline-flex align-items-center justify-content-center gap-2"
                    type="button"
                    :disabled="!canCreate"
                    @click="openNewChatForm"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon"><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                    {{ t('director.sidebar.new_chat') }}
                </button>
            </div>

            <div class="flex-fill overflow-auto px-2">
                <div v-if="loading" class="p-3">
                    <div class="progress progress-sm">
                        <div class="progress-bar progress-bar-indeterminate"></div>
                    </div>
                </div>

                <div v-else-if="!chats.length" class="p-3 text-secondary small">
                    {{ t('director.sidebar.empty') }}
                </div>

                <!-- list-group-transparent — без фона и разделителей между
                     пунктами, активный/наведённый пункт выделяется мягкой
                     плашкой (нативная стилизация Tabler, без своих правил). -->
                <div v-else class="list-group list-group-transparent list-group-hoverable">
                    <div
                        v-for="chat in chats"
                        :key="chat.id"
                        class="list-group-item d-flex align-items-center gap-2 rounded-2 mb-1"
                        :class="{ active: chat.id === activeChatId }"
                    >
                        <input
                            v-if="renamingId === chat.id"
                            ref="renameInput"
                            v-model="renameValue"
                            type="text"
                            class="form-control form-control-sm"
                            @keydown.enter="confirmRename(chat)"
                            @keydown.esc="cancelRename"
                            @blur="confirmRename(chat)"
                        />
                        <template v-else>
                            <span class="avatar avatar-sm rounded-2 bg-primary-lt flex-shrink-0" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 9h8" /><path d="M8 13h6" /><path d="M9 18h-3a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3h10a3 3 0 0 1 3 3v3.5" /><path d="M15 19l2 2l4 -4" /></svg>
                            </span>

                            <a
                                href="#"
                                class="flex-fill text-reset text-decoration-none overflow-hidden"
                                @click.prevent="selectChat(chat)"
                            >
                                <div class="text-truncate">{{ chat.title }}</div>
                                <div class="text-secondary small text-truncate">
                                    {{ chat.data_source?.name ?? t('director.sidebar.source_deleted') }}
                                </div>
                            </a>

                            <div v-if="canEditChat || canDeleteChat" class="dropdown list-group-item-actions flex-shrink-0">
                                <button
                                    class="btn btn-sm btn-ghost-secondary px-1"
                                    type="button"
                                    data-bs-toggle="dropdown"
                                    :aria-label="t('director.sidebar.chat_actions_aria')"
                                    aria-expanded="false"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M12 18m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /></svg>
                                </button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <button v-if="canEditChat" class="dropdown-item" type="button" @click="startRename(chat)">
                                        {{ t('director.sidebar.rename') }}
                                    </button>
                                    <button v-if="canDeleteChat" class="dropdown-item text-danger" type="button" @click="askDelete(chat)">
                                        {{ t('director.sidebar.delete') }}
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <div class="dropdown border-top p-3 flex-shrink-0">
                <a
                    href="#"
                    class="d-flex align-items-center gap-2 text-reset text-decoration-none"
                    data-bs-toggle="dropdown"
                    :aria-label="t('director.user_menu.open')"
                    aria-expanded="false"
                >
                    <span class="avatar avatar-sm rounded-2 bg-primary-lt">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0"/><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/></svg>
                    </span>
                    <span class="flex-fill overflow-hidden">
                        <span class="d-block text-truncate small fw-bold">{{ currentUser?.name }}</span>
                        <span class="d-block text-truncate text-secondary" style="font-size: .75rem;">{{ currentUser?.company?.name }}</span>
                    </span>
                </a>
                <div class="dropdown-menu">
                    <router-link class="dropdown-item" :to="{ name: 'settings.profile' }">
                        {{ t('header.profile') }}
                    </router-link>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="#" @click.prevent="changeLanguage('ru')">Русский</a>
                    <a class="dropdown-item" href="#" @click.prevent="changeLanguage('tj')">Тоҷики</a>
                    <a class="dropdown-item" href="#" @click.prevent="changeLanguage('en')">English</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="#" @click.prevent="logout">
                        {{ loggingOut ? '…' : t('header.logout') }}
                    </a>
                </div>
            </div>
        </aside>

        <main class="director-main flex-fill d-flex flex-column">
            <!-- Открытый чат несёт свой собственный переключатель истории
                 в шапке (DirectorChatPanel) — здесь он нужен только для
                 остальных состояний экрана, чтобы на телефоне история не
                 оказалась вообще недостижимой. -->
            <div v-if="!activeChatId" class="d-flex d-lg-none align-items-center gap-2 p-2 border-bottom flex-shrink-0">
                <button class="btn btn-icon" type="button" :aria-label="t('director.topbar.toggle_sidebar')" @click="sidebarOpen = true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6l16 0" /><path d="M4 12l16 0" /><path d="M4 18l16 0" /></svg>
                </button>
                <div class="fw-bold text-truncate">{{ t('director.app_name') }}</div>
            </div>

            <div v-if="listError" class="alert alert-danger m-3" role="alert">{{ listError }}</div>

            <DirectorChatPanel
                v-if="activeChatId"
                :key="activeChatId"
                :chat-id="activeChatId"
                @toggle-sidebar="sidebarOpen = true"
            />

            <div v-else-if="showNewChatForm" class="m-auto p-4" style="max-width: 440px; width: 100%;">
                <h3 class="mb-1">{{ t('director.new_chat.title') }}</h3>
                <p class="text-secondary mb-3">{{ t('director.new_chat.subtitle') }}</p>

                <div v-if="createError" class="alert alert-danger">{{ createError }}</div>

                <form @submit.prevent="submitNewChat">
                    <div class="mb-3">
                        <label class="form-label required">{{ t('director.new_chat.source_label') }}</label>
                        <select v-model="newChatForm.data_source_id" class="form-select" :disabled="!sources.length" required>
                            <option value="" disabled>{{ t('director.new_chat.source_placeholder') }}</option>
                            <option v-for="source in sources" :key="source.id" :value="source.id">
                                {{ source.name }} — {{ source.format_label }}
                            </option>
                        </select>
                        <small v-if="!sources.length" class="form-hint text-danger">
                            {{ t('director.new_chat.no_sources_hint') }}
                        </small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">{{ t('director.new_chat.title_label') }}</label>
                        <input v-model="newChatForm.title" type="text" class="form-control"
                               :placeholder="t('director.new_chat.title_placeholder')" />
                    </div>

                    <button type="submit" class="btn btn-primary w-100" :class="{ 'btn-loading': creatingChat }"
                            :disabled="creatingChat || !sources.length || !newChatForm.data_source_id">
                        {{ t('director.new_chat.submit') }}
                    </button>
                </form>
            </div>

            <div v-else class="m-auto text-center p-4">
                <p class="text-secondary mb-3">{{ t('director.empty.prompt') }}</p>
                <button v-if="canCreate" class="btn btn-primary" type="button" @click="openNewChatForm">
                    {{ t('director.sidebar.new_chat') }}
                </button>
            </div>
        </main>

        <!-- УДАЛЕНИЕ ЧАТА -->
        <div ref="deleteModalEl" class="modal modal-blur fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-status bg-danger"></div>
                    <div class="modal-body text-center py-4">
                        <h3>{{ t('director.delete_modal.title') }}</h3>
                        <div class="text-secondary">
                            {{ t('director.delete_modal.body', { name: pendingDelete?.title }) }}
                        </div>
                    </div>
                    <div class="modal-footer">
                        <div class="w-100">
                            <div class="row">
                                <div class="col">
                                    <button class="btn w-100" data-bs-dismiss="modal">{{ t('director.delete_modal.cancel') }}</button>
                                </div>
                                <div class="col">
                                    <button class="btn btn-danger w-100" :class="{ 'btn-loading': deleting }"
                                            :disabled="deleting" @click="confirmDelete">
                                        {{ t('director.delete_modal.confirm') }}
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

<style scoped>
.director-shell {
    height: 100vh;
    overflow: hidden;
}

.director-sidebar {
    /* 15rem — тот же токен, что использует .navbar-vertical в самом Tabler. */
    width: 15rem;
    flex-shrink: 0;
    height: 100%;
    border-right: var(--tblr-border-width) var(--tblr-border-style) var(--tblr-border-color);
    background: var(--tblr-bg-surface);
}

.director-main {
    min-width: 0;
    height: 100%;
    overflow: hidden;
}

/* На телефоне нет :hover — меню «переименовать/удалить» иначе не открыть
   иначе как случайным долгим тапом. Раскрытие по наведению (Tabler
   .list-group-hoverable) оставляем только там, где есть мышь. */
@media (max-width: 991.98px) {
    .director-sidebar .list-group-item-actions {
        opacity: 1;
    }
}

@media (max-width: 991.98px) {
    .director-sidebar {
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        width: 85%;
        max-width: 300px;
        z-index: 1046;
        transform: translateX(-100%);
        transition: transform .25s ease;
        box-shadow: 4px 0 24px rgba(0, 0, 0, .15);
    }

    .director-sidebar.sidebar-open {
        transform: translateX(0);
    }
}

.director-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1045;
    background: rgba(0, 0, 0, .4);
}

.director-backdrop:not(.d-none) {
    display: block;
}
</style>
