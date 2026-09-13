<script setup>
import { ref, computed, watch, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import api from "../../api.js";
import ChatConversation from "../chat/ChatConversation.vue";
import DirectorDashboardViewer from "./DirectorDashboardViewer.vue";

const { t } = useI18n();

/**
 * Полноэкранная (не выезжающая) панель одного разговора — главный экран
 * директора. В отличие от AiChatSidebar в рабочем пространстве, здесь дашборд
 * не показывается сам собой рядом: агент сообщает о готовом дашборде
 * карточкой в сообщении, и открыть его — отдельное действие (см. ChatConversation
 * showDashboardCard/@open-dashboard), чтобы не отвлекать от переписки.
 */
const props = defineProps({
    chatId: {
        type: [String, Number],
        required: true,
    },
});

defineEmits(["toggle-sidebar"]);

const loading = ref(true);
const error = ref(null);

const chat = ref(null);
const dataSource = ref(null);
const dashboards = ref([]);
const currentDashboardId = ref(null);

const viewerOpen = ref(false);
const viewerDashboardId = ref(null);

const hasDashboard = computed(() => dashboards.value.length > 0);

async function load() {
    loading.value = true;
    error.value = null;

    try {
        const { data } = await api.get(`/workspaces/by-chat/${props.chatId}`);

        chat.value = data.chat;
        dataSource.value = data.data_source;
        dashboards.value = data.dashboards ?? [];
        currentDashboardId.value = data.current_dashboard_id ?? null;
    } catch (err) {
        error.value =
            err.response?.status === 403
                ? t("director.chat_panel.errors.no_access")
                : t("director.chat_panel.errors.load_failed");
    } finally {
        loading.value = false;
    }
}

/**
 * Дашборд построен/перестроен агентом. Не открываем панель сама —
 * пользователь делает это осознанно кликом по карточке в сообщении
 * (open-dashboard); здесь только обновляем список версий и заголовок
 * кнопки в шапке.
 */
async function onDashboardReady() {
    try {
        const { data } = await api.get(`/workspaces/by-chat/${props.chatId}`);
        dashboards.value = data.dashboards ?? [];
        currentDashboardId.value = data.current_dashboard_id ?? null;
    } catch {
        // Заголовок/кнопка просто не обновятся до следующего действия —
        // сам чат при этом продолжает работать.
    }
}

function openViewer(dashboardId) {
    viewerDashboardId.value = dashboardId ?? currentDashboardId.value;
    viewerOpen.value = true;
}

watch(() => props.chatId, load, { immediate: true });
</script>

<template>
    <div class="director-chat-panel d-flex flex-column h-100">
        <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom flex-shrink-0">
            <button
                class="btn btn-icon d-lg-none flex-shrink-0"
                type="button"
                :aria-label="t('director.topbar.toggle_sidebar')"
                @click="$emit('toggle-sidebar')"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6l16 0" /><path d="M4 12l16 0" /><path d="M4 18l16 0" /></svg>
            </button>

            <div class="flex-fill overflow-hidden">
                <div class="fw-bold text-truncate">{{ chat?.title || t('director.chat_panel.untitled') }}</div>
                <div v-if="dataSource" class="text-secondary small text-truncate">{{ dataSource.name }}</div>
            </div>

            <button
                v-if="hasDashboard"
                class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 flex-shrink-0"
                type="button"
                @click="openViewer(currentDashboardId)"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h6v8h-6z" /><path d="M4 16h6v4h-6z" /><path d="M14 12h6v8h-6z" /><path d="M14 4h6v4h-6z" /></svg>
                <span class="d-none d-sm-inline">{{ t('director.chat_panel.open_dashboard') }}</span>
            </button>
        </div>

        <div v-if="loading" class="p-3">
            <div class="progress progress-sm">
                <div class="progress-bar progress-bar-indeterminate"></div>
            </div>
        </div>

        <div v-else-if="error" class="p-3">
            <div class="alert alert-danger mb-0">{{ error }}</div>
        </div>

        <ChatConversation
            v-else
            class="flex-fill"
            style="min-height: 0;"
            :chat-id="chatId"
            :dashboard-id="currentDashboardId"
            :suggestions="chat?.suggestions ?? []"
            show-dashboard-card
            @dashboard="onDashboardReady"
            @open-dashboard="openViewer"
        />

        <DirectorDashboardViewer
            v-if="viewerOpen"
            :dashboards="dashboards"
            :initial-dashboard-id="viewerDashboardId"
            @close="viewerOpen = false"
        />
    </div>
</template>
