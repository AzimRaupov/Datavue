<script setup>
import { useI18n } from 'vue-i18n'
import TablerIcon from '../TablerIcon.vue'

const { t } = useI18n()

const kpis = [
    { key: 'revenue', value: '128.4k', delta: '+12.4%', up: true },
    { key: 'orders', value: '1 284', delta: '+5.1%', up: true },
    { key: 'avg', value: '99.9', delta: '-1.8%', up: false },
    { key: 'customers', value: '342', delta: '+9.3%', up: true },
]

const bars = [62, 78, 54, 91, 70, 86, 48]
</script>

<template>
    <div class="dv-window">
        <div class="dv-window-bar">
            <span class="dv-dot"></span><span class="dv-dot"></span><span class="dv-dot"></span>
            <span class="dv-window-url"><TablerIcon name="lock" class="icon-sm text-green" /> datavue.startcoding.tj</span>
        </div>

        <div class="dv-window-body">
            <div class="dv-dash">
                <div class="dv-dash-head">
                    <div>
                        <div class="dv-dash-title">{{ t('home.mock.dashboard_title') }}</div>
                        <div class="dv-dash-sub">{{ t('home.mock.dashboard_sub') }}</div>
                    </div>
                    <span class="badge bg-green-lt">{{ t('home.mock.live') }}</span>
                </div>

                <div class="dv-kpis">
                    <div v-for="kpi in kpis" :key="kpi.key" class="dv-kpi">
                        <div class="dv-kpi-label">{{ t(`home.mock.kpi.${kpi.key}`) }}</div>
                        <div class="dv-kpi-value">{{ kpi.value }}</div>
                        <div class="dv-kpi-delta" :class="kpi.up ? 'text-green' : 'text-red'">{{ kpi.delta }}</div>
                    </div>
                </div>

                <div class="dv-charts">
                    <div class="dv-card dv-card-wide">
                        <div class="dv-card-title">{{ t('home.mock.trend') }}</div>
                        <svg viewBox="0 0 400 140" preserveAspectRatio="none" class="dv-line" aria-hidden="true">
                            <defs>
                                <linearGradient id="dv-area" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0" stop-color="var(--tblr-primary)" stop-opacity=".28" />
                                    <stop offset="1" stop-color="var(--tblr-primary)" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                            <g stroke="var(--tblr-border-color)" stroke-dasharray="3 4">
                                <line x1="0" y1="35" x2="400" y2="35" />
                                <line x1="0" y1="70" x2="400" y2="70" />
                                <line x1="0" y1="105" x2="400" y2="105" />
                            </g>
                            <path d="M0 108 C30 100 50 112 80 92 S130 70 160 78 S210 40 240 50 S290 82 320 52 S370 20 400 28 L400 140 L0 140 Z" fill="url(#dv-area)" />
                            <path d="M0 108 C30 100 50 112 80 92 S130 70 160 78 S210 40 240 50 S290 82 320 52 S370 20 400 28" fill="none" stroke="var(--tblr-primary)" stroke-width="2.5" stroke-linecap="round" />
                            <circle cx="400" cy="28" r="4" fill="var(--tblr-primary)" />
                        </svg>
                    </div>

                    <div class="dv-card">
                        <div class="dv-card-title">{{ t('home.mock.regions') }}</div>
                        <div class="dv-bars">
                            <span v-for="(h, i) in bars" :key="i" :style="{ height: h + '%' }" :class="{ 'is-accent': i === 3 }"></span>
                        </div>
                    </div>
                </div>
            </div>

            <aside class="dv-chat">
                <div class="dv-chat-head">
                    <span class="dv-chat-ava"><TablerIcon name="sparkles" class="icon-sm" /></span>
                    <div>
                        <div class="dv-chat-name">{{ t('home.mock.assistant') }}</div>
                        <div class="dv-chat-state">{{ t('home.mock.assistant_state') }}</div>
                    </div>
                </div>
                <div class="dv-bubble dv-bubble-user">{{ t('home.mock.user_msg') }}</div>
                <div class="dv-bubble dv-bubble-ai">
                    <div class="dv-steps">
                        <span><TablerIcon name="check" class="icon-sm text-green" /> {{ t('home.mock.step1') }}</span>
                        <span><TablerIcon name="check" class="icon-sm text-green" /> {{ t('home.mock.step2') }}</span>
                        <span><TablerIcon name="check" class="icon-sm text-green" /> {{ t('home.mock.step3') }}</span>
                    </div>
                </div>
                <div class="dv-chat-input">
                    <span>{{ t('home.mock.placeholder') }}</span>
                    <span class="dv-send"><TablerIcon name="send" class="icon-sm" /></span>
                </div>
            </aside>
        </div>
    </div>
</template>
