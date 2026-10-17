<script setup>
import { useI18n } from "vue-i18n";
import { useDashboardExport } from "../../composables/useDashboardExport.js";

/**
 * Выгрузка дашборда в PDF/Word.
 *
 *  - по умолчанию — кнопка с выпадающим списком форматов (десктоп);
 *  - items — только пункты списка, чтобы встроить в чужое меню (телефон).
 */
const props = defineProps({
    dashboardId: {
        type: [String, Number],
        default: null,
    },
    items: {
        type: Boolean,
        default: false,
    },
});

const { t } = useI18n();
const { busy, start } = useDashboardExport(() => props.dashboardId);
</script>

<template>
    <template v-if="items">
        <button class="dropdown-item" type="button" :disabled="!!busy" @click="start('pdf')">
            {{ t("dashboardExport.pdf") }}
        </button>
        <button class="dropdown-item" type="button" :disabled="!!busy" @click="start('docx')">
            {{ t("dashboardExport.docx") }}
        </button>
    </template>

    <div v-else-if="dashboardId" class="dropdown">
        <button
            class="btn btn-icon"
            type="button"
            data-bs-toggle="dropdown"
            aria-expanded="false"
            :disabled="!!busy"
            :title="t('dashboardExport.button')"
            :aria-label="t('dashboardExport.button')"
        >
            <span v-if="busy" class="spinner-border spinner-border-sm" role="status"></span>
            <svg v-else xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon">
                <path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" />
                <path d="M7 11l5 5l5 -5" />
                <path d="M12 4l0 12" />
            </svg>
        </button>

        <div class="dropdown-menu dropdown-menu-end">
            <button class="dropdown-item" type="button" @click="start('pdf')">
                {{ t("dashboardExport.pdf") }}
            </button>
            <button class="dropdown-item" type="button" @click="start('docx')">
                {{ t("dashboardExport.docx") }}
            </button>
        </div>
    </div>
</template>
