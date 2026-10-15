<script setup>
import { useI18n } from 'vue-i18n'
import TablerIcon from '../TablerIcon.vue'

const { t } = useI18n()
</script>

<template>
    <div class="dv-show" aria-hidden="true">
        <div class="dv-show-card dv-show-kpi">
            <div class="dv-kpi-label">{{ t('home.mock.kpi.revenue') }}</div>
            <div class="d-flex align-items-end justify-content-between gap-3">
                <div>
                    <div class="dv-kpi-value">128.4k</div>
                    <div class="dv-kpi-delta text-green">+12.4%</div>
                </div>
                <svg viewBox="0 0 80 32" class="dv-show-spark">
                    <path d="M0 26 C10 22 14 28 24 18 S40 8 50 14 S68 6 80 2" fill="none" stroke="var(--tblr-green)" stroke-width="2.5" stroke-linecap="round" />
                </svg>
            </div>
        </div>

        <div class="dv-show-card dv-show-chat">
            <span class="dv-show-ico bg-primary-lt text-primary"><TablerIcon name="sparkles" class="icon-sm" /></span>
            <span>{{ t('home.mock.user_msg') }}</span>
        </div>

        <div class="dv-show-card dv-show-alert">
            <span class="dv-show-ico bg-red-lt text-red"><TablerIcon name="bell" class="icon-sm" /></span>
            <span class="fw-semibold">{{ t('home.mock.alert_title') }}</span>
            <span class="badge bg-red-lt">{{ t('home.mock.alert_firing') }}</span>
        </div>

        <div class="dv-show-card dv-show-bars">
            <span v-for="(h, i) in [40, 64, 48, 86, 58]" :key="i" :style="{ height: h + '%' }" :class="{ 'is-accent': i === 3 }"></span>
        </div>
    </div>
</template>
