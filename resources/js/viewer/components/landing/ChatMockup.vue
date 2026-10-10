<script setup>
import { useI18n } from 'vue-i18n'
import TablerIcon from '../TablerIcon.vue'

const { t } = useI18n()

const pie = [
    { v: 46, c: 'var(--tblr-primary)' },
    { v: 28, c: 'var(--tblr-azure)' },
    { v: 18, c: 'var(--tblr-teal)' },
    { v: 8, c: 'var(--tblr-yellow)' },
]

// Круговая диаграмма через stroke-dasharray: длина окружности r=15.915 равна 100,
// поэтому доля в процентах ложится в dasharray без пересчёта.
let offset = 0
const slices = pie.map(s => {
    const slice = { ...s, dash: `${s.v} ${100 - s.v}`, offset: -offset }
    offset += s.v
    return slice
})
</script>

<template>
    <div class="dv-panel dv-chat-demo">
        <div class="dv-bubble dv-bubble-user">{{ t('home.mock.chat_user') }}</div>

        <div class="dv-bubble dv-bubble-ai">
            <div class="mb-2">{{ t('home.mock.chat_ai') }}</div>

            <div class="dv-widget">
                <div class="dv-card-title">{{ t('home.mock.chat_widget') }}</div>
                <div class="dv-pie-row">
                    <svg viewBox="0 0 36 36" class="dv-pie" aria-hidden="true">
                        <circle cx="18" cy="18" r="15.915" fill="none" stroke="var(--tblr-border-color)" stroke-width="5" />
                        <circle
                            v-for="(s, i) in slices"
                            :key="i"
                            cx="18"
                            cy="18"
                            r="15.915"
                            fill="none"
                            :stroke="s.c"
                            stroke-width="5"
                            :stroke-dasharray="s.dash"
                            :stroke-dashoffset="s.offset"
                            transform="rotate(-90 18 18)"
                        />
                    </svg>
                    <ul class="dv-legend">
                        <li v-for="(s, i) in slices" :key="i">
                            <span class="dv-legend-dot" :style="{ background: s.c }"></span>
                            {{ t(`home.mock.pie.${i}`) }}
                            <strong>{{ s.v }}%</strong>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="dv-bubble dv-bubble-user">{{ t('home.mock.chat_user2') }}</div>

        <div class="dv-chat-input">
            <span>{{ t('home.mock.placeholder') }}</span>
            <span class="dv-send"><TablerIcon name="send" class="icon-sm" /></span>
        </div>
    </div>
</template>
