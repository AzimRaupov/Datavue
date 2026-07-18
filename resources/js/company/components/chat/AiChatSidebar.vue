<script setup>
import { ref, onMounted, nextTick, onUnmounted } from "vue"
import { useRoute,useRouter } from 'vue-router';
import api from '../../api.js';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { useI18n } from 'vue-i18n'

window.Pusher = Pusher;
const { t, locale } = useI18n()

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
    dashboardId: {
        type: [String, Number],
        default: null,
    },

});
const emit = defineEmits(['close']);

const route = useRoute();
const router = useRouter();

const chatId = props.chatId;
const dashboardId = props.dashboardId
const messages = ref([]);
const chatInput = ref('');
const loading = ref(false);
const chatMessagesEl = ref(null);
const error = ref(null);

const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST || '127.0.0.1',
    wsPort: import.meta.env.VITE_REVERB_PORT || 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT || 8080,
    forceTLS: false,
    disableStats: true,
    enabledTransports: ['ws'],
});

const sidebarWidth = ref(parseInt(localStorage.getItem('aiChatWidth')) || 360);
const isResizing = ref(false);
const MIN_WIDTH = 280;
const MAX_WIDTH = 640;

const STATUS_MAP = {
    start:       { label: 'Ожидание',   badge: 'bg-secondary-lt text-secondary' },
    in_progress: { label: 'В процессе', badge: 'bg-azure-lt text-azure' },
    completed:   { label: 'Завершено',  badge: 'bg-success-lt text-success' },
    failed:      { label: 'Ошибка',     badge: 'bg-danger-lt text-danger' },
};

function statusInfo(status) {
    return STATUS_MAP[status] || { label: status || '—', badge: 'bg-secondary-lt text-secondary' };
}

function taskName(task) {
    return task?.task?.name ?? task?.name ?? '';
}

function taskStatus(task) {
    if (task?.status && typeof task.status === 'object') {
        return task.status.code ?? task.status.name ?? '';
    }
    return task?.status ?? '';
}

function sameId(a, b) {
    return String(a) === String(b);
}

function isPending(message) {
    return !message.answer && message.status !== 'failed';
}

function isFailed(message) {
    return message.status === 'failed';
}

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

async function getChat() {
    await fetchMessages();
}

async function fetchMessages() {
    try {
        const response = await api.get('/messages', {
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
    const dashboardId = props.dashboardId;

    if (!text || loading.value) return;



    loading.value = true;
    error.value = null;
    try {
        const response = await api.post('/messages', {
            chat_id: chatId,
            message: text,
            dashboard_id: dashboardId
        });
        messages.value.push({ ...response.data, tasks: [] });
        chatInput.value = '';
        await nextTick();
        const textarea = document.getElementById('chatInput');
        if (textarea) textarea.style.height = 'auto';
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

function applyTaskUpdate(payload) {
    const incomingMessage = payload?.message;
    const incomingTask = payload?.task;
    if (!incomingMessage) return;

    const msgIndex = messages.value.findIndex(m => sameId(m.id, incomingMessage.id));
    if (msgIndex === -1) return;

    const msg = messages.value[msgIndex];
    const currentTasks = Array.isArray(msg.tasks) ? msg.tasks : [];

    let newTasks = currentTasks;
    if (incomingTask) {
        const taskIndex = currentTasks.findIndex(t => sameId(t.id, incomingTask.id));
        if (taskIndex !== -1) {
            newTasks = [...currentTasks];
            newTasks[taskIndex] = { ...currentTasks[taskIndex], ...incomingTask };
        } else {
            newTasks = [...currentTasks, incomingTask];
        }
    }

    const mergedMessage = {
        ...msg,
        ...incomingMessage,
        tasks: newTasks,
    };

    messages.value.splice(msgIndex, 1, mergedMessage);

    nextTick(scrollChatToBottom);
}

onMounted(async () => {
    await getChat();

    if (chatId) {
        echo.channel(`tasks.${chatId}`)
            .listen('.MessageTasksChanged', (e) => {
                console.log('--- РЕАЛТАЙМ ИЗМЕНЕНИЕ ЗАДАЧИ ПОЙМАНО! ---', e);
                applyTaskUpdate(e);

                if (e.dashboard_id) {
                    router.push({
                        name: 'company.chat',
                        params: {
                            id: chatId,
                            dashboard: e.dashboard_id,
                        },
                    });

                }
            });
    }
});

onUnmounted(() => {
    window.removeEventListener('mousemove', onResize);
    window.removeEventListener('mouseup', stopResize);
    if (chatId) {
        echo.leaveChannel(`tasks.${chatId}`);
    }
});
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
                <TransitionGroup name="msg" tag="div" class="d-flex flex-column gap-3">
                    <div v-for="message in messages" :key="message.id" class="d-flex flex-column gap-3">
                        <div v-if="message.message" class="d-flex justify-content-end">
                            <div class="card card-body bg-primary text-white shadow-sm p-3 pb-0" style="max-width: 80%;">
                                <div class="small lh-base">{{ message.message }}</div>
                                <div class="text-end text-white-50 small">{{ message.created_at ? new Date(message.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '' }}</div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-start">
                            <div
                                class="card card-body shadow-sm p-3 pb-0 ai-bubble"
                                :class="isFailed(message) ? 'bg-danger-lt' : 'bg-light'"
                                style="max-width: 80%;"
                            >
                                <transition name="fade" mode="out-in">
                                    <div v-if="message.answer" key="answer" class="small lh-base text-dark">
                                        {{ message.answer }}
                                    </div>
                                    <div v-else-if="isFailed(message)" key="failed" class="small lh-base text-danger d-flex align-items-center gap-2 py-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                                        Не удалось получить ответ
                                    </div>
                                    <div v-else key="typing" class="typing-indicator d-flex align-items-center gap-1 py-2">
                                        <span class="typing-dot"></span>
                                        <span class="typing-dot"></span>
                                        <span class="typing-dot"></span>
                                    </div>
                                </transition>

                                <div class="text-end text-muted small" v-if="message.answer">AI</div>

                                <TransitionGroup
                                    v-if="message.tasks?.length"
                                    name="task"
                                    tag="ul"
                                    class="steps steps-vertical p-1 border-0 m-1"
                                >
                                    <li
                                        v-for="task in message.tasks"
                                        :key="task.id"
                                        class="step-item"
                                        :class="`step-status-${taskStatus(task)}`"
                                    >
                                        <div class="h4 m-0">{{ t(`tasks.${taskName(task)}`) }}</div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="task-marker" :class="`marker-${taskStatus(task)}`" aria-hidden="true">
                                                <span v-if="taskStatus(task) === 'in_progress'" class="marker-spinner"></span>
                                                <svg v-else-if="taskStatus(task) === 'completed'" class="marker-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5l10 -10"/></svg>
                                                <svg v-else-if="taskStatus(task) === 'failed'" class="marker-icon" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>
                                            </span>
                                            <transition name="badge-swap" mode="out-in">
                                                <span
                                                    :key="taskStatus(task)"
                                                    class="badge"
                                                    :class="statusInfo(taskStatus(task)).badge"
                                                >
                                                    {{ statusInfo(taskStatus(task)).label }}
                                                </span>
                                            </transition>
                                        </div>
                                    </li>
                                </TransitionGroup>
                            </div>
                        </div>
                    </div>
                </TransitionGroup>
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
            <div v-if="error" class="alert alert-danger py-1 px-2 small mb-2">{{ error }}</div>
            <div class="input-group">
            <textarea
                class="form-control chat-textarea"
                id="chatInput"
                placeholder="Спросите о ваших данных…"
                rows="1"
                v-model="chatInput"
                :disabled="loading"
                @keydown="handleChatKeydown"
                @input="autoResizeTextarea"
                aria-label="Chat input"
            ></textarea>
                <button class="btn btn-primary px-3" id="chatSendBtn" type="button" @click="sendMessage" title="Отправить (Enter)" aria-label="Send" :disabled="loading || !chatInput.trim()">
                    <span v-if="loading" class="send-spinner" aria-hidden="true"></span>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14l11 -11"/><path d="M21 3l-6.5 18a.55 .55 0 0 1 -1 0l-3.5 -7l-7 -3.5a.55 .55 0 0 1 0 -1l18 -6.5"/></svg>
                </button>
            </div>
            <div class="text-center text-muted mt-1" style="font-size:.68rem">Enter — отправить &nbsp;·&nbsp; Shift+Enter — новая строка</div>
        </div>

    </aside>
</template>

<style scoped>
.msg-enter-active {
    transition: opacity .35s ease, transform .35s ease;
}
.msg-enter-from {
    opacity: 0;
    transform: translateY(12px);
}
.msg-leave-active {
    transition: opacity .2s ease;
    position: absolute;
}
.msg-leave-to {
    opacity: 0;
}

.task-move {
    transition: transform .3s ease;
}
.task-enter-active {
    transition: opacity .35s ease, transform .35s ease;
}
.task-enter-from {
    opacity: 0;
    transform: translateX(-8px);
}
.task-leave-active {
    transition: opacity .2s ease;
    position: absolute;
}
.task-leave-to {
    opacity: 0;
}

.badge-swap-enter-active,
.badge-swap-leave-active {
    transition: opacity .25s ease, transform .25s ease;
}
.badge-swap-enter-from {
    opacity: 0;
    transform: scale(.85);
}
.badge-swap-leave-to {
    opacity: 0;
    transform: scale(.85);
}
.badge {
    transition: background-color .3s ease, color .3s ease;
}

.fade-enter-active,
.fade-leave-active {
    transition: opacity .25s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

.ai-bubble {
    transition: background-color .3s ease;
}

.typing-indicator {
    height: 20px;
}
.typing-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--tblr-secondary, #6c757d);
    display: inline-block;
    animation: typing-bounce 1.2s infinite ease-in-out;
}
.typing-dot:nth-child(2) { animation-delay: .15s; }
.typing-dot:nth-child(3) { animation-delay: .3s; }

@keyframes typing-bounce {
    0%, 60%, 100% { transform: translateY(0); opacity: .4; }
    30%           { transform: translateY(-4px); opacity: 1; }
}

.send-spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 2px solid rgba(255, 255, 255, .35);
    border-top-color: #fff;
    animation: marker-spin .7s linear infinite;
}

.task-marker {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    flex-shrink: 0;
    transition: background-color .3s ease, border-color .3s ease, transform .3s ease;
}

.marker-start {
    border: 2px solid var(--tblr-secondary, #6c757d);
    background: transparent;
}

.marker-in_progress {
    border: 2px solid transparent;
}

.marker-spinner {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    border: 2px solid rgba(32, 107, 196, .25);
    border-top-color: var(--tblr-azure, #206bc4);
    animation: marker-spin .7s linear infinite;
}

.marker-completed {
    background: var(--tblr-success, #2fb344);
    color: #fff;
    animation: marker-pop .35s ease;
}

.marker-failed {
    background: var(--tblr-danger, #d63939);
    color: #fff;
    animation: marker-shake .4s ease;
}

.marker-icon {
    width: 10px;
    height: 10px;
}

@keyframes marker-spin {
    to { transform: rotate(360deg); }
}

@keyframes marker-pop {
    0%   { transform: scale(0); opacity: 0; }
    60%  { transform: scale(1.25); opacity: 1; }
    100% { transform: scale(1); }
}

@keyframes marker-shake {
    0%, 100% { transform: translateX(0); }
    20%      { transform: translateX(-3px); }
    40%      { transform: translateX(3px); }
    60%      { transform: translateX(-2px); }
    80%      { transform: translateX(2px); }
}

.step-status-in_progress {
    animation: task-pulse 1.6s ease-in-out infinite;
}

@keyframes task-pulse {
    0%, 100% { opacity: 1; }
    50%      { opacity: .72; }
}
</style>
