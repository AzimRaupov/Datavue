<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Иконка провайдера источника данных.
 *
 * Ключ приходит с бэкенда (data_source_types.icon), поэтому добавление нового
 * провайдера не требует правок в мастере — достаточно завести здесь иконку.
 * Все контуры взяты из набора Tabler Icons и рисуются в его стиле:
 * 24×24, stroke currentColor, без заливки.
 */
const props = defineProps({
    name: { type: String, default: 'database' },
    size: { type: [Number, String], default: 24 },
});

const { t } = useI18n();

// Подпись для нативной всплывающей подсказки при наведении (элемент <title> в SVG).
const label = computed(() => {
    switch (props.name) {
        case 'file-spreadsheet':
            return t('providerIcon.file_spreadsheet');
        case 'file-database':
            return t('providerIcon.file_database');
        case 'table':
            return t('providerIcon.table');
        default:
            return t('providerIcon.database');
    }
});
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
        class="icon"
    >
        <title>{{ label }}</title>

        <!-- tabler: file-spreadsheet -->
        <template v-if="name === 'file-spreadsheet'">
            <path d="M14 3v4a1 1 0 0 0 1 1h4" />
            <path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" />
            <path d="M8 11h8v7h-8z" />
            <path d="M8 15h8" />
            <path d="M12 11v7" />
        </template>

        <!-- tabler: file-database -->
        <template v-else-if="name === 'file-database'">
            <path d="M12 12.75m-4 0a4 1.75 0 1 0 8 0a4 1.75 0 1 0 -8 0" />
            <path d="M8 12.5v3.75c0 .966 1.79 1.75 4 1.75s4 -.784 4 -1.75v-3.75" />
            <path d="M14 3v4a1 1 0 0 0 1 1h4" />
            <path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" />
        </template>

        <!-- tabler: table -->
        <template v-else-if="name === 'table'">
            <path d="M3 5a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-14z" />
            <path d="M3 10h18" />
            <path d="M10 3v18" />
        </template>

        <!-- tabler: database (по умолчанию) -->
        <template v-else>
            <path d="M12 6m-8 0a8 3 0 1 0 16 0a8 3 0 1 0 -16 0" />
            <path d="M4 6v6a8 3 0 0 0 16 0v-6" />
            <path d="M4 12v6a8 3 0 0 0 16 0v-6" />
        </template>
    </svg>
</template>
