
<script setup>
import { ref, onMounted } from "vue"
import ApexCharts from "apexcharts"
import DonutWidget from '../components/widgets/DonutWidget.vue'
import MultiSeriesTrend from '../components/widgets/MultiSeriesTrend.vue'
import MiniCounters from '../components/widgets/MiniCounters.vue'


</script>

<template>

    <div class="dashboard-wrapper">


        <div class="dashboard-main p-1" id="dashboardMain">
            <DonutWidget :labels="['A', 'B', 'C']" :series="[10, 20, 30]" />

            <MultiSeriesTrend  />

           <MiniCounters />
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
                    <button class="btn btn-sm btn-ghost-secondary px-2" onclick="clearChat()" title="Clear chat" aria-label="Clear chat">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7l16 0"/><path d="M10 11l0 6"/><path d="M14 11l0 6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/></svg>
                    </button>
                    <button class="btn btn-sm btn-ghost-secondary px-2" onclick="toggleChat()" title="Close" aria-label="Close chat">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            <div class="d-flex gap-2 px-3 py-2 border-bottom flex-shrink-0 flex-wrap bg-body-tertiary">
                <button class="btn btn-sm btn-outline-secondary px-2 py-1 d-flex align-items-center gap-1" onclick="quickAsk('Покажи статистику продаж')" style="font-size:.72rem">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 16l4 -7l4 4l4 -7"/></svg>
                    Продажи
                </button>
                <button class="btn btn-sm btn-outline-secondary px-2 py-1 d-flex align-items-center gap-1" onclick="quickAsk('Сколько активных пользователей?')" style="font-size:.72rem">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0"/><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/></svg>
                    Пользователи
                </button>
                <button class="btn btn-sm btn-outline-secondary px-2 py-1 d-flex align-items-center gap-1" onclick="quickAsk('Какой текущий доход?')" style="font-size:.72rem">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16.7 8a3 3 0 0 0 -2.7 -2h-4a3 3 0 0 0 0 6h4a3 3 0 0 1 0 6h-4a3 3 0 0 1 -2.7 -2"/><path d="M12 3v3m0 12v3"/></svg>
                    Доход
                </button>

            </div>

            <div class="chat-messages p-3 d-flex flex-column gap-3" id="chatMessages">
                <div id="chatWelcome" class="d-flex flex-column align-items-center justify-content-center text-center py-4 px-2 flex-grow-1">
                    <div class="avatar avatar-lg rounded-3 bg-primary text-white mb-3 d-flex align-items-center justify-content-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8a4 4 0 0 1 4 4"/><path d="M12 4a8 8 0 0 1 8 8"/><path d="M12 20a8 8 0 0 1 -8 -8"/><circle cx="12" cy="12" r="1"/></svg>
                    </div>
                    <h5 class="fw-bold mb-1">AI Ассистент</h5>
                    <p class="text-secondary small mb-3">Задайте вопрос о данных вашего дашборда — анализ метрик, отчёты, тренды.</p>
                    <div class="d-flex flex-column gap-2 w-100">
                        <button class="btn btn-outline-secondary text-start suggestion-chip d-flex align-items-center gap-2 px-3 py-2" onclick="quickAsk('Проанализируй текущие показатели продаж и дай рекомендации')" style="font-size:.8rem">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0 text-muted"><path d="M3 3v18h18"/><path d="M7 16l4 -7l4 4l4 -7"/></svg>
                            Анализ продаж и рекомендации
                        </button>
                        <button class="btn btn-outline-secondary text-start suggestion-chip d-flex align-items-center gap-2 px-3 py-2" onclick="quickAsk('Какие метрики показывают отрицательную динамику?')" style="font-size:.8rem">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0 text-muted"><path d="M12 5l0 14"/><path d="M18 13l-6 6"/><path d="M6 13l6 6"/></svg>
                            Проблемные метрики
                        </button>
                        <button class="btn btn-outline-secondary text-start suggestion-chip d-flex align-items-center gap-2 px-3 py-2" onclick="quickAsk('Составь краткий отчёт по ключевым показателям дашборда')" style="font-size:.8rem">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="flex-shrink-0 text-muted"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2"/><path d="M9 9l1 0"/><path d="M9 13l6 0"/><path d="M9 17l6 0"/></svg>
                            Сводный отчёт по KPI
                        </button>
                    </div>
                </div>
            </div>

            <div class="p-3 border-top flex-shrink-0">
                <div class="input-group">
                <textarea
                    class="form-control chat-textarea"
                    id="chatInput"
                    placeholder="Спросите о ваших данных…"
                    rows="1"
                    onkeydown="handleChatKeydown(event)"
                    oninput="autoResizeTextarea(this)"
                    aria-label="Chat input"
                ></textarea>
                    <button class="btn btn-primary px-3" id="chatSendBtn" onclick="sendMessage()" title="Отправить (Enter)" aria-label="Send">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14l11 -11"/><path d="M21 3l-6.5 18a.55 .55 0 0 1 -1 0l-3.5 -7l-7 -3.5a.55 .55 0 0 1 0 -1l18 -6.5"/></svg>
                    </button>
                </div>
                <div class="text-center text-muted mt-1" style="font-size:.68rem">Enter — отправить &nbsp;·&nbsp; Shift+Enter — новая строка</div>
            </div>

        </aside>

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



