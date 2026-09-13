<script setup>
import { ref } from "vue"
import { useI18n } from 'vue-i18n'
import ChatConversation from './ChatConversation.vue';

const { t } = useI18n()

const props = defineProps({
    open: {
        type: Boolean,
        default: true,
    },
    chatId: {
        type: [String, Number],
        default: null,
    },
    dashboardId: {
        type: [String, Number],
        default: null,
    },
    suggestions: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['close', 'dashboard']);

const sidebarWidth = ref(parseInt(localStorage.getItem('aiChatWidth')) || 360);
const isResizing = ref(false);
const MIN_WIDTH = 280;
const MAX_WIDTH = 640;

function closeChat() {
    emit('close');
}

function startResize(e) {
    if (window.innerWidth < 992) return;
    isResizing.value = true;
    document.body.style.userSelect = 'none';
    document.body.style.cursor = 'col-resize';
    window.addEventListener('mousemove', onResize);
    window.addEventListener('mouseup', stopResize);
    e.preventDefault();
}

function onResize(e) {
    if (!isResizing.value) return;
    const newWidth = window.innerWidth - e.clientX;
    sidebarWidth.value = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, newWidth));
}

function stopResize() {
    if (!isResizing.value) return;
    isResizing.value = false;
    document.body.style.userSelect = '';
    document.body.style.cursor = '';
    localStorage.setItem('aiChatWidth', sidebarWidth.value);
    window.removeEventListener('mousemove', onResize);
    window.removeEventListener('mouseup', stopResize);
}
</script>
<template>
    <aside
        class="ai-chat-sidebar"
        :class="{ 'chat-collapsed': !open }"
        :style="open ? { width: sidebarWidth + 'px' } : {}"
        id="aiChatSidebar"
        aria-label="AI Assistant"
    >
        <div class="chat-resize-handle" @mousedown="startResize"></div>

        <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom flex-shrink-0">
            <div class="avatar avatar-sm rounded-2 bg-primary text-white d-flex align-items-center justify-content-center flex-shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3l1.34 3.66l3.66 1.34l-3.66 1.34l-1.34 3.66l-1.34 -3.66l-3.66 -1.34l3.66 -1.34z"/><path d="M8 13l.7 1.87l1.87 .7l-1.87 .7l-.7 1.87l-.7 -1.87l-1.87 -.7l1.87 -.7z"/></svg>
            </div>
            <div class="fw-bold flex-fill overflow-hidden text-truncate">{{ t('aiChat.header.title') }}</div>
            <div class="d-flex gap-1 ms-auto flex-shrink-0">
                <button class="btn btn-sm btn-ghost-secondary px-2" :title="t('aiChat.buttons.close')" aria-label="Close chat" @click="closeChat">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <ChatConversation
            :chat-id="chatId"
            :dashboard-id="dashboardId"
            :suggestions="suggestions"
            class="flex-fill"
            style="min-height: 0;"
            @dashboard="emit('dashboard', $event)"
        />
    </aside>
</template>
