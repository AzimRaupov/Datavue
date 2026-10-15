<script setup>
import { useI18n } from 'vue-i18n'
import TablerIcon from '../TablerIcon.vue'

const { t } = useI18n()

const palette = ['chart', 'table', 'dashboard', 'mapPin']
</script>

<template>
    <div class="dv-panel dv-builder">
        <div class="dv-builder-bar">
            <span class="dv-modes dv-modes-inline">
                <span><TablerIcon name="eye" class="icon-sm" /> {{ t('home.mock.mode_view') }}</span>
                <span class="is-active"><TablerIcon name="sliders" class="icon-sm" /> {{ t('home.mock.mode_edit') }}</span>
            </span>
            <span class="badge bg-primary-lt">{{ t('home.mock.drag_hint') }}</span>
        </div>

        <div class="dv-builder-body">
            <div class="dv-palette">
                <div class="dv-palette-title">{{ t('home.mock.widgets') }}</div>
                <div v-for="icon in palette" :key="icon" class="dv-palette-item">
                    <TablerIcon :name="icon" class="icon-sm" />
                    <span class="dv-skel" style="width: 56%"></span>
                </div>
            </div>

            <div class="dv-grid">
                <div class="dv-grid-cell dv-grid-kpi"><span class="dv-skel" style="width: 50%"></span><b></b></div>
                <div class="dv-grid-cell dv-grid-kpi"><span class="dv-skel" style="width: 62%"></span><b></b></div>
                <div class="dv-grid-cell dv-grid-wide is-selected">
                    <span class="dv-skel" style="width: 34%"></span>
                    <svg viewBox="0 0 200 60" preserveAspectRatio="none" aria-hidden="true">
                        <path d="M0 48 C20 40 30 52 55 36 S95 18 115 28 S160 44 200 10" fill="none" stroke="var(--tblr-primary)" stroke-width="2.5" stroke-linecap="round" />
                    </svg>
                    <i class="dv-handle"></i>
                </div>
                <div class="dv-grid-cell dv-grid-drop">{{ t('home.mock.drop_here') }}</div>
            </div>
        </div>
    </div>
</template>
