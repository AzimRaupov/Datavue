

<script setup>
import { ref, onMounted, nextTick } from "vue"
import DonutWidget from '../components/widgets/DonutWidget.vue'
import MultiSeriesTrend from '../components/widgets/MultiSeriesTrend.vue'
import MiniCounters from '../components/widgets/MiniCounters.vue'
import { useRoute } from 'vue-router';
import api from '../api.js';

const route = useRoute();

const chatId = route.params.id;
const messages = ref([]);
const chatInput = ref('');
const loading = ref(false);
const chatMessagesEl = ref(null);
const error = ref(null);

async function getChat() {
    await fetchMessages();
}

async function fetchMessages() {
    try {
        const response = await api.get('/message', {
            params: {
                chat_id: chatId,
            },
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
    if (!text || loading.value) {
        return;
    }
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

function toggleChat() {
    if (chatMessagesEl.value) {
        chatMessagesEl.value.classList.toggle('d-none');
    }
}


onMounted(getChat);

</script>

<template>

    <div class="dashboard-wrapper">


        <div class="dashboard-main p-1" id="dashboardMain">
           <div id="dashboard-header" class="m-3">
               <h1 class="page-title">Dashboard</h1>

           </div>

            <div class="widgets-content">

            <DonutWidget :labels="['A', 'B', 'C']" :series="[10, 20, 30]" />

            <MultiSeriesTrend  />

            <MiniCounters />
        </div>

        </div>


        <aside class="ai-chat-sidebar" id="aiChatSidebar" aria-label="AI Assistant">

            <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom flex-shrink-0">
                <div class="avatar avatar-sm rounded-2 bg-primary text-white d-flex align-items-center justify-content-center flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8a4 4 0 0 1 4 4"/><path d="M12 4a8 8 0 0 1 8 8"/><path d="M12 20a8 8 0 0 1 -8 -8"/><circle cx="12" cy="12" r="1"/></svg>
                </div>
                <div class="flex-fill overflow-hidden">
                    <div class="fw-semibold lh-1 small">AI Assistant</div>
                    <div class="text-secondary d-flex align-items-center gap-1 mt-1" style="font-size:.7rem">
                        <span class="status-online flex-shrink-0"></span>Ready to help
                    </div>
                </div>
                <div class="d-flex gap-1 ms-auto flex-shrink-0">
                    <button class="btn btn-sm btn-ghost-secondary px-2" @click="clearChat" title="Clear chat" aria-label="Clear chat">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7l16 0"/><path d="M10 11l0 6"/><path d="M14 11l0 6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/></svg>
                    </button>
                    <button class="btn btn-sm btn-ghost-secondary px-2" @click="toggleChat" title="Close" aria-label="Close chat">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            <div class="d-flex gap-2 px-3 py-2 border-bottom flex-shrink-0 flex-wrap bg-body-tertiary">
                <button class="btn btn-sm btn-outline-secondary px-2 py-1 d-flex align-items-center gap-1" @click="quickAsk('Покажи статистику продаж')" style="font-size:.72rem">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 16l4 -7l4 4l4 -7"/></svg>
                    Продажи
                </button>
                <button class="btn btn-sm btn-outline-secondary px-2 py-1 d-flex align-items-center gap-1" @click="quickAsk('Сколько активных пользователей?')" style="font-size:.72rem">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0"/><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/></svg>
                    Пользователи
                </button>
                <button class="btn btn-sm btn-outline-secondary px-2 py-1 d-flex align-items-center gap-1" @click="quickAsk('Какой текущий доход?')" style="font-size:.72rem">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16.7 8a3 3 0 0 0 -2.7 -2h-4a3 3 0 0 0 0 6h4a3 3 0 0 1 0 6h-4a3 3 0 0 1 -2.7 -2"/><path d="M12 3v3m0 12v3"/></svg>
                    Доход
                </button>

            </div>

            <div ref="chatMessagesEl" class="chat-messages p-3 d-flex flex-column gap-3" id="chatMessages">
                <template v-if="messages.length">
                    <div v-for="message in messages" :key="message.id" class="d-flex flex-column gap-3">
                        <div v-if="message.answer" class="d-flex justify-content-start">
                            <div class="card card-body bg-light shadow-sm p-3 pb-0" style="max-width: 80%;">
                                <div class="small lh-base text-dark">{{ message.answer }}</div>
                                <div class="text-end text-muted small">AI</div>
                            </div>
                        </div>
                        <div v-if="message.message" class="d-flex justify-content-end">
                            <div class="card card-body bg-primary text-white shadow-sm p-3 pb-0" style="max-width: 80%;">
                                <div class="small lh-base">{{ message.message }}</div>
                                <div class="text-end text-white-50 small">{{ message.created_at ? new Date(message.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '' }}</div>
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

    </div>
    <div class="settings">
        <a
            href="#"
            class="btn btn-floating btn-icon btn-primary"
            data-bs-toggle="offcanvas"
            data-bs-target="#offcanvas-settings"
            aria-controls="offcanvas-settings"
            aria-label="Theme Settings"
        >
            <!-- Download SVG icon from http://tabler.io/icons/icon/brush -->
            <svg
                xmlns="http://www.w3.org/2000/svg"
                width="24"
                height="24"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
                focusable="false"
                class="icon icon-1"
            >
                <path d="M3 21v-4a4 4 0 1 1 4 4h-4" />
                <path d="M21 3a16 16 0 0 0 -12.8 10.2" />
                <path d="M21 3a16 16 0 0 1 -10.2 12.8" />
                <path d="M10.6 9a9 9 0 0 1 4.4 4.4" />
            </svg>
        </a>
        <form
            class="offcanvas offcanvas-start offcanvas-narrow"
            tabindex="-1"
            id="offcanvas-settings"
            role="dialog"
            aria-modal="true"
            aria-labelledby="offcanvas-settings-title"
        >
            <div class="offcanvas-header">
                <h2 class="offcanvas-title" id="offcanvas-settings-title">Theme Settings</h2>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body d-flex flex-column">
                <div>
                    <div class="mb-4">
                        <label class="form-label">Color mode</label>
                        <p class="form-hint">Choose the color mode for your app.</p>
                        <label class="form-check">
                            <div class="form-selectgroup-item">
                                <input type="radio" name="theme" value="light" class="form-check-input" checked />
                                <div class="form-check-label">Light</div>
                            </div>
                        </label>
                        <label class="form-check">
                            <div class="form-selectgroup-item">
                                <input type="radio" name="theme" value="dark" class="form-check-input" />
                                <div class="form-check-label">Dark</div>
                            </div>
                        </label>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Color scheme</label>
                        <p class="form-hint">The perfect color mode for your app.</p>
                        <div class="row g-2">
                            <div class="col-auto">
                                <label class="form-colorinput">
                                    <input name="theme-primary" type="radio" value="blue" class="form-colorinput-input" />
                                    <span class="form-colorinput-color bg-blue"></span>
                                </label>
                            </div>
                            <div class="col-auto">
                                <label class="form-colorinput">
                                    <input name="theme-primary" type="radio" value="azure" class="form-colorinput-input" />
                                    <span class="form-colorinput-color bg-azure"></span>
                                </label>
                            </div>
                            <div class="col-auto">
                                <label class="form-colorinput">
                                    <input name="theme-primary" type="radio" value="indigo" class="form-colorinput-input" />
                                    <span class="form-colorinput-color bg-indigo"></span>
                                </label>
                            </div>
                            <div class="col-auto">
                                <label class="form-colorinput">
                                    <input name="theme-primary" type="radio" value="purple" class="form-colorinput-input" />
                                    <span class="form-colorinput-color bg-purple"></span>
                                </label>
                            </div>
                            <div class="col-auto">
                                <label class="form-colorinput">
                                    <input name="theme-primary" type="radio" value="pink" class="form-colorinput-input" />
                                    <span class="form-colorinput-color bg-pink"></span>
                                </label>
                            </div>
                            <div class="col-auto">
                                <label class="form-colorinput">
                                    <input name="theme-primary" type="radio" value="red" class="form-colorinput-input" />
                                    <span class="form-colorinput-color bg-red"></span>
                                </label>
                            </div>
                            <div class="col-auto">
                                <label class="form-colorinput">
                                    <input name="theme-primary" type="radio" value="orange" class="form-colorinput-input" />
                                    <span class="form-colorinput-color bg-orange"></span>
                                </label>
                            </div>
                            <div class="col-auto">
                                <label class="form-colorinput">
                                    <input name="theme-primary" type="radio" value="yellow" class="form-colorinput-input" />
                                    <span class="form-colorinput-color bg-yellow"></span>
                                </label>
                            </div>
                            <div class="col-auto">
                                <label class="form-colorinput">
                                    <input name="theme-primary" type="radio" value="lime" class="form-colorinput-input" />
                                    <span class="form-colorinput-color bg-lime"></span>
                                </label>
                            </div>
                            <div class="col-auto">
                                <label class="form-colorinput">
                                    <input name="theme-primary" type="radio" value="green" class="form-colorinput-input" />
                                    <span class="form-colorinput-color bg-green"></span>
                                </label>
                            </div>
                            <div class="col-auto">
                                <label class="form-colorinput">
                                    <input name="theme-primary" type="radio" value="teal" class="form-colorinput-input" />
                                    <span class="form-colorinput-color bg-teal"></span>
                                </label>
                            </div>
                            <div class="col-auto">
                                <label class="form-colorinput">
                                    <input name="theme-primary" type="radio" value="cyan" class="form-colorinput-input" />
                                    <span class="form-colorinput-color bg-cyan"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Font family</label>
                        <p class="form-hint">Choose the font family that fits your app.</p>
                        <div>
                            <label class="form-check">
                                <div class="form-selectgroup-item">
                                    <input type="radio" name="theme-font" value="sans-serif" class="form-check-input" checked />
                                    <div class="form-check-label">Sans-serif</div>
                                </div>
                            </label>
                            <label class="form-check">
                                <div class="form-selectgroup-item">
                                    <input type="radio" name="theme-font" value="serif" class="form-check-input" />
                                    <div class="form-check-label">Serif</div>
                                </div>
                            </label>
                            <label class="form-check">
                                <div class="form-selectgroup-item">
                                    <input type="radio" name="theme-font" value="monospace" class="form-check-input" />
                                    <div class="form-check-label">Monospace</div>
                                </div>
                            </label>
                            <label class="form-check">
                                <div class="form-selectgroup-item">
                                    <input type="radio" name="theme-font" value="comic" class="form-check-input" />
                                    <div class="form-check-label">Comic</div>
                                </div>
                            </label>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Theme base</label>
                        <p class="form-hint">Choose the gray shade for your app.</p>
                        <div>
                            <label class="form-check">
                                <div class="form-selectgroup-item">
                                    <input type="radio" name="theme-base" value="slate" class="form-check-input" />
                                    <div class="form-check-label">Slate</div>
                                </div>
                            </label>
                            <label class="form-check">
                                <div class="form-selectgroup-item">
                                    <input type="radio" name="theme-base" value="gray" class="form-check-input" checked />
                                    <div class="form-check-label">Gray</div>
                                </div>
                            </label>
                            <label class="form-check">
                                <div class="form-selectgroup-item">
                                    <input type="radio" name="theme-base" value="zinc" class="form-check-input" />
                                    <div class="form-check-label">Zinc</div>
                                </div>
                            </label>
                            <label class="form-check">
                                <div class="form-selectgroup-item">
                                    <input type="radio" name="theme-base" value="neutral" class="form-check-input" />
                                    <div class="form-check-label">Neutral</div>
                                </div>
                            </label>
                            <label class="form-check">
                                <div class="form-selectgroup-item">
                                    <input type="radio" name="theme-base" value="stone" class="form-check-input" />
                                    <div class="form-check-label">Stone</div>
                                </div>
                            </label>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Corner Radius</label>
                        <p class="form-hint">Choose the border radius factor for your app.</p>
                        <div>
                            <label class="form-check">
                                <div class="form-selectgroup-item">
                                    <input type="radio" name="theme-radius" value="0" class="form-check-input" />
                                    <div class="form-check-label">0</div>
                                </div>
                            </label>
                            <label class="form-check">
                                <div class="form-selectgroup-item">
                                    <input type="radio" name="theme-radius" value="0.5" class="form-check-input" />
                                    <div class="form-check-label">0.5</div>
                                </div>
                            </label>
                            <label class="form-check">
                                <div class="form-selectgroup-item">
                                    <input type="radio" name="theme-radius" value="1" class="form-check-input" checked />
                                    <div class="form-check-label">1</div>
                                </div>
                            </label>
                            <label class="form-check">
                                <div class="form-selectgroup-item">
                                    <input type="radio" name="theme-radius" value="1.5" class="form-check-input" />
                                    <div class="form-check-label">1.5</div>
                                </div>
                            </label>
                            <label class="form-check">
                                <div class="form-selectgroup-item">
                                    <input type="radio" name="theme-radius" value="2" class="form-check-input" />
                                    <div class="form-check-label">2</div>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="mt-auto space-y">
                    <button type="button" class="btn w-100" id="reset-changes">
                        <!-- Download SVG icon from http://tabler.io/icons/icon/rotate -->
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            width="24"
                            height="24"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                            focusable="false"
                            class="icon icon-1"
                        >
                            <path d="M19.95 11a8 8 0 1 0 -.5 4m.5 5v-5h-5" />
                        </svg>
                        Reset changes
                    </button>
                    <a href="#" class="btn btn-primary w-100" data-bs-dismiss="offcanvas"> Save </a>
                </div>
            </div>
        </form>
    </div>
</template>



<style>
:root {
    --chart-demo-pie-color-0: color-mix(in srgb, transparent, var(--tblr-primary) 100%);
    --chart-demo-pie-color-1: color-mix(in srgb, transparent, var(--tblr-primary) 80%);
    --chart-demo-pie-color-2: color-mix(in srgb, transparent, var(--tblr-primary) 60%);
    --chart-demo-pie-color-3: color-mix(in srgb, transparent, var(--tblr-gray-300) 100%);
}
:root {
    --chart-active-users-2-color-0: color-mix(in srgb, transparent, var(--tblr-primary) 100%);
    --chart-active-users-2-color-1: color-mix(in srgb, transparent, var(--tblr-azure) 100%);
    --chart-active-users-2-color-2: color-mix(in srgb, transparent, var(--tblr-green) 100%);
}
</style>



