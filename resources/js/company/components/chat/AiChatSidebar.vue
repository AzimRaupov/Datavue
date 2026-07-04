<script setup>
import { ref, onMounted, nextTick, onUnmounted } from "vue"
import { useRoute } from 'vue-router';
import api from '../../api.js';

const props = defineProps({
    open: {
        type: Boolean,
        default: true,
    },
    chatTitle: {
        type: String,
        default: '',
    },
    chatId: {
        type: [String, Number],
        default: null,
    },
});
const emit = defineEmits(['close']);

const route = useRoute();
const chatId = props.chatId;

const messages = ref([]);
const chatInput = ref('');
const loading = ref(false);
const chatMessagesEl = ref(null);
const error = ref(null);

/* ── Resize сайдбара ── */
const sidebarWidth = ref(parseInt(localStorage.getItem('aiChatWidth')) || 360);
const isResizing = ref(false);
const MIN_WIDTH = 280;
const MAX_WIDTH = 640;

function closeChat() {
    emit('close');
}

function startResize(e) {
    // Resizing only makes sense on desktop layout
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

onUnmounted(() => {
    window.removeEventListener('mousemove', onResize);
    window.removeEventListener('mouseup', stopResize);
});

async function getChat() {
    await fetchMessages();
}

async function fetchMessages() {
    try {
        const response = await api.get('/message', {
            params: { chat_id: chatId },
        });
        messages.value = response.data || [];
        await nextTick();
        scrollChatToBottom();
    } catch (err) {
        console.error(err);
        error.value = 'Не удалось загрузить сообщения';
    }
}

function scrollChatToBottom() {
    if (chatMessagesEl.value) {
        chatMessagesEl.value.scrollTop = chatMessagesEl.value.scrollHeight;
    }
}

async function sendMessage() {
    const text = chatInput.value.trim();
    if (!text || loading.value) return;
    loading.value = true;
    try {
        const response = await api.post('/message', {
            chat_id: chatId,
            message: text,
        });
        messages.value.push(response.data);
        chatInput.value = '';
        await nextTick();
        scrollChatToBottom();
    } catch (err) {
        console.error(err);
        error.value = 'Ошибка при отправке сообщения';
    } finally {
        loading.value = false;
    }
}

function handleChatKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
}

function autoResizeTextarea(event) {
    const textarea = event.target;
    textarea.style.height = 'auto';
    textarea.style.height = `${Math.min(textarea.scrollHeight, 200)}px`;
}

function quickAsk(text) {
    chatInput.value = text;
    sendMessage();
}

function clearChat() {
    messages.value = [];
}

onMounted(getChat);
</script>

<template>
    <aside
        class="ai-chat-sidebar"
        :class="{ 'chat-collapsed': !open }"
        :style="open ? { width: sidebarWidth + 'px' } : {}"
        id="aiChatSidebar"
        aria-label="AI Assistant"
    >
        <!-- Resize handle (desktop only, via CSS) -->
        <div class="chat-resize-handle" @mousedown="startResize"></div>

        <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom flex-shrink-0">
            <div class="avatar avatar-sm rounded-2 bg-primary text-white d-flex align-items-center justify-content-center flex-shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3l1.34 3.66l3.66 1.34l-3.66 1.34l-1.34 3.66l-1.34 -3.66l-3.66 -1.34l3.66 -1.34z"/><path d="M8 13l.7 1.87l1.87 .7l-1.87 .7l-.7 1.87l-.7 -1.87l-1.87 -.7l1.87 -.7z"/></svg>
            </div>
            <div class="flex-fill overflow-hidden">
                <div class="fw-semibold lh-1 small">AI Ассистент</div>
                <div class="text-secondary d-flex align-items-center gap-1 mt-1" style="font-size:.7rem">
                    <div class="fw-semibold text-truncate">{{ chatTitle }}</div>
                </div>
            </div>
            <div class="d-flex gap-1 ms-auto flex-shrink-0">
                <button class="btn btn-sm btn-ghost-secondary px-2" title="Закрыть" aria-label="Close chat" @click="closeChat">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <div class="d-flex gap-2 px-3 py-2 border-bottom flex-shrink-0 flex-wrap bg-body-tertiary">
            <span class="d-none d-sm-inline">
                <a href="#" class="btn btn-1 px-2 py-1 gap-1 btn-sm" @click="quickAsk('Покажи статистику продаж')"> Выручка </a>
              </span>

        </div>



        <div ref="chatMessagesEl" class="chat-messages p-3 d-flex flex-column gap-3" id="chatMessages">
            <template v-if="messages.length">
                <div v-for="message in messages" :key="message.id" class="d-flex flex-column gap-3">
                    <div v-if="message.message" class="d-flex justify-content-end">
                        <div class="card card-body bg-primary text-white shadow-sm p-3 pb-0" style="max-width: 80%;">
                            <div class="small lh-base">{{ message.message }}</div>
                            <div class="text-end text-white-50 small">{{ message.created_at ? new Date(message.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '' }}</div>
                        </div>
                    </div>

                    <div v-if="message.answer" class="d-flex justify-content-start">
                        <div class="card card-body bg-light shadow-sm p-3 pb-0" style="max-width: 80%;">
                            <div class="small lh-base text-dark">{{ message.answer }}</div>
                            <div class="text-end text-muted small">AI</div>
                        </div>
                    </div>
                </div>
            </template>
            <template v-else>
                <div id="chatWelcome" class="d-flex flex-column align-items-center justify-content-center text-center py-4 px-2 flex-grow-1">
                    <div class="avatar avatar-lg rounded-3 bg-primary text-white mb-3 d-flex align-items-center justify-content-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8a4 4 0 0 1 4 4"/><path d="M12 4a8 8 0 0 1 8 8"/><path d="M12 20a8 8 0 0 1 -8 -8"/><circle cx="12" cy="12" r="1"/></svg>
                    </div>
                    <h5 class="fw-bold mb-1">AI Ассистент</h5>
                    <p class="text-secondary small mb-3">Задайте вопрос о данных вашего дашборда — анализ метрик, отчёты, тренды.</p>
                    <div class="d-flex flex-column gap-2 w-100">
                        <button class="btn btn-outline-secondary text-start suggestion-chip d-flex align-items-center gap-2 px-3 py-2" @click="quickAsk('Проанализируй текущие показатели продаж и дай рекомендации')" style="font-size:.8rem">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0 text-muted"><path d="M3 3v18h18"/><path d="M7 16l4 -7l4 4l4 -7"/></svg>
                            Анализ продаж и рекомендации
                        </button>
                        <button class="btn btn-outline-secondary text-start suggestion-chip d-flex align-items-center gap-2 px-3 py-2" @click="quickAsk('Какие метрики показывают отрицательную динамику?')" style="font-size:.8rem">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0 text-muted"><path d="M12 5l0 14"/><path d="M18 13l-6 6"/><path d="M6 13l6 6"/></svg>
                            Проблемные метрики
                        </button>
                        <button class="btn btn-outline-secondary text-start suggestion-chip d-flex align-items-center gap-2 px-3 py-2" @click="quickAsk('Составь краткий отчёт по ключевым показателям дашборда')" style="font-size:.8rem">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0 text-muted"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2"/><path d="M9 9l1 0"/><path d="M9 13l6 0"/><path d="M9 17l6 0"/></svg>
                            Сводный отчёт по KPI
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <div class="p-3 border-top flex-shrink-0">
            <div class="input-group">
            <textarea
                class="form-control chat-textarea"
                id="chatInput"
                placeholder="Спросите о ваших данных…"
                rows="1"
                v-model="chatInput"
                @keydown="handleChatKeydown"
                @input="autoResizeTextarea"
                aria-label="Chat input"
            ></textarea>
                <button class="btn btn-primary px-3" id="chatSendBtn" type="button" @click="sendMessage" title="Отправить (Enter)" aria-label="Send" :disabled="loading">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14l11 -11"/><path d="M21 3l-6.5 18a.55 .55 0 0 1 -1 0l-3.5 -7l-7 -3.5a.55 .55 0 0 1 0 -1l18 -6.5"/></svg>
                </button>
            </div>
            <div class="text-center text-muted mt-1" style="font-size:.68rem">Enter — отправить &nbsp;·&nbsp; Shift+Enter — новая строка</div>
        </div>

    </aside>
</template>
