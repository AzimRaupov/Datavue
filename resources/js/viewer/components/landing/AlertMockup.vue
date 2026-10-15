<script setup>
import { useI18n } from 'vue-i18n'
import TablerIcon from '../TablerIcon.vue'

const { t } = useI18n()
</script>

<template>
    <div class="dv-alert-stack">
        <div class="dv-panel">
            <div class="dv-panel-head">
                <span class="dv-panel-icon bg-red-lt text-red"><TablerIcon name="bell" /></span>
                <div class="flex-fill">
                    <div class="fw-semibold">{{ t('home.mock.alert_title') }}</div>
                    <div class="text-secondary small">{{ t('home.mock.alert_interval') }}</div>
                </div>
                <span class="badge bg-red-lt">{{ t('home.mock.alert_firing') }}</span>
            </div>

            <div class="dv-modes">
                <span class="is-active"><TablerIcon name="sliders" class="icon-sm" /> {{ t('home.mock.mode_builder') }}</span>
                <span><TablerIcon name="database" class="icon-sm" /> SQL</span>
                <span><TablerIcon name="code" class="icon-sm" /> Python</span>
            </div>

            <div class="dv-rule">
                <div class="dv-rule-row">
                    <span class="dv-rule-key">{{ t('home.mock.rule_metric') }}</span>
                    <span class="dv-chip">SUM(amount)</span>
                    <span class="dv-rule-key">{{ t('home.mock.rule_by') }}</span>
                    <span class="dv-chip">{{ t('home.mock.rule_day') }}</span>
                </div>
                <div class="dv-rule-row">
                    <span class="dv-rule-key">{{ t('home.mock.rule_when') }}</span>
                    <span class="dv-chip dv-chip-warn"><TablerIcon name="trendingDown" class="icon-sm" /> &lt; 15 000</span>
                </div>
            </div>
        </div>

        <div class="dv-mail">
            <div class="dv-mail-head">
                <span class="dv-panel-icon bg-primary-lt text-primary"><TablerIcon name="mail" /></span>
                <div class="flex-fill">
                    <div class="fw-semibold">{{ t('home.mock.mail_subject') }}</div>
                    <div class="text-secondary small">Datavue → team@company.com</div>
                </div>
            </div>
            <p class="mb-2">{{ t('home.mock.mail_body') }}</p>
            <div class="dv-mail-file"><TablerIcon name="file" class="icon-sm" /> sales-report.csv</div>
        </div>
    </div>
</template>
