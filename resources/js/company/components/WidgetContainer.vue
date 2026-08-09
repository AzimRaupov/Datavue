<script setup>
import { ref, computed, onMounted, watch } from "vue";
import api from "../api.js";

import { familyOf, propsFor, hasData } from "./widgets/registry.js";

const props = defineProps({
    widget: {
        type: Object,
        required: true
    },
    chatId: {
        type: [String, Number],
        default: null,
    },
    refreshToken: {
        type: [String, Number],
        default: 0,
    },
});

const widget = computed(() => props.widget);

const contentWidget = ref(null);
const isLoading = ref(false);

/**
 * Семейство виджета определяет, ЧЕМ рисовать, а тип — КАК.
 *
 * Карта семейств и раскладка пропсов вынесены в widgets/registry.js —
 * тем же реестром пользуется галерея виджетов, поэтому превью и дашборд
 * рисуют виджет одинаково.
 */

const familyName = computed(() => widget.value?.widget?.name ?? null);

const family = computed(() => familyOf(familyName.value));

/**
 * Параметры отрисовки выбранного типа. Если тип не выбран или пришёл
 * без options — берём тип семейства по умолчанию, чтобы виджет
 * всё равно нарисовался.
 */
const typeOptions = computed(() => {
    const chosen = widget.value?.widget_type;

    if (chosen?.options) return chosen.options;

    const fallback = (widget.value?.widget?.types ?? []).find(t => t.is_default);

    return fallback?.options ?? {};
});

const isReady = computed(() =>
    widget.value?.status === "active" && contentWidget.value !== null
);

const contentHasData = computed(() => hasData(familyName.value, contentWidget.value));

const showWidget = computed(() => family.value && isReady.value && contentHasData.value);

const widgetProps = computed(() => propsFor(familyName.value, contentWidget.value, typeOptions.value));

/**
 * Высоты столбцов в заглушке — фиксированные, а не случайные.
 *
 * При случайных значениях столбцы прыгали на каждой перерисовке, и заглушка
 * мельтешила вместо того, чтобы спокойно показывать форму будущего графика.
 */
const PLACEHOLDER_BARS = [45, 70, 35, 85, 55, 95, 40, 65];

async function getWidgetContent() {
    if (!widget.value?.id) return;

    try {
        isLoading.value = true;
        contentWidget.value = null;

        const response = await api.post(
            "/get-widget-content/" + widget.value.id,
            { chat_id: props.chatId }
        );

        const raw = response.data.output;

        let jsonString = null;

        if (Array.isArray(raw)) {
            jsonString = raw[0];
        } else if (typeof raw === "string") {
            jsonString = raw;
        }

        contentWidget.value = jsonString
            ? JSON.parse(jsonString)
            : null;

    } catch (err) {
        console.error("Ошибка загрузки данных виджета:", err);
    } finally {
        isLoading.value = false;
    }
}

watch(
    () => props.widget.updated_at,
    async (newValue, oldValue) => {
        if (newValue !== oldValue) {
            await getWidgetContent();
        }
    }
);

watch(
    () => props.refreshToken,
    async (newValue, oldValue) => {
        if (newValue !== oldValue) {
            await getWidgetContent();
        }
    }
);

onMounted(async () => {
    await getWidgetContent();
});
</script>

<template>
    <div v-if="family">
        <component
            v-if="showWidget"
            :is="family.component"
            v-bind="widgetProps"
        />

        <!-- Плейсхолдеры на время генерации: форма подсказывает, что появится -->
        <template v-else>
            <div v-if="family.placeholder === 'counters'" class="row g-2">
                <div class="col-6 col-xl-3" v-for="n in 4" :key="n">
                    <div class="card">
                        <div class="card-body placeholder-glow">
                            <span class="placeholder col-7 bg-secondary d-block mb-2"
                                  style="height: 10px;"></span>
                            <span class="placeholder col-5 bg-secondary d-block"
                                  style="height: 20px;"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Таблица: card-table, как у настоящего виджета -->
            <div v-else-if="family.placeholder === 'table'" class="card">
                <div class="card-header">
                    <span class="placeholder bg-secondary col-3"></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table placeholder-glow">
                        <thead>
                        <tr>
                            <th v-for="n in 4" :key="n">
                                <span class="placeholder col-8 bg-secondary"></span>
                            </th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="n in 6" :key="n">
                            <td v-for="c in 4" :key="c">
                                <span class="placeholder col-10 bg-secondary"></span>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Круговые: кольцо и легенда сбоку, как рисует ApexCharts -->
            <div v-else-if="family.placeholder === 'circle'" class="card">
                <div class="card-body placeholder-glow">
                    <div class="row align-items-center g-4">
                        <div class="col-auto mx-auto">
                            <div class="placeholder-donut placeholder"></div>
                        </div>
                        <div class="col">
                            <div v-for="n in 4" :key="n" class="d-flex align-items-center gap-2 mb-2">
                                <span class="placeholder rounded-circle bg-secondary flex-shrink-0"
                                      style="width: 10px; height: 10px;"></span>
                                <span class="placeholder bg-secondary" :class="`col-${[7, 5, 8, 6][n - 1]}`"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Столбцы: с осью значений и подписями категорий -->
            <div v-else-if="family.placeholder === 'bars'" class="card">
                <div class="card-body placeholder-glow">
                    <div class="d-flex" style="height: 240px;">
                        <div class="d-flex flex-column justify-content-between pe-2" style="width: 34px;">
                            <span v-for="n in 5" :key="n" class="placeholder bg-secondary"
                                  style="height: 7px;"></span>
                        </div>
                        <div class="flex-fill border-start border-bottom d-flex align-items-end gap-2 px-2 pb-0">
                            <span
                                v-for="n in 8"
                                :key="n"
                                class="placeholder bg-secondary rounded-top flex-fill"
                                :style="{ height: PLACEHOLDER_BARS[n - 1] + '%' }"
                            ></span>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-2" style="padding-left: 42px;">
                        <span v-for="n in 8" :key="n" class="placeholder bg-secondary flex-fill"
                              style="height: 7px;"></span>
                    </div>
                </div>
            </div>

            <!-- Линейные и точечные: та же ось, но контур линии -->
            <div v-else class="card">
                <div class="card-body placeholder-glow">
                    <div class="d-flex" style="height: 240px;">
                        <div class="d-flex flex-column justify-content-between pe-2" style="width: 34px;">
                            <span v-for="n in 5" :key="n" class="placeholder bg-secondary"
                                  style="height: 7px;"></span>
                        </div>
                        <div class="flex-fill border-start border-bottom position-relative">
                            <svg class="placeholder-line" viewBox="0 0 100 40" preserveAspectRatio="none"
                                 aria-hidden="true">
                                <polyline points="0,32 14,24 28,28 42,14 56,19 70,8 84,13 100,4" />
                            </svg>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-2" style="padding-left: 42px;">
                        <span v-for="n in 6" :key="n" class="placeholder bg-secondary flex-fill"
                              style="height: 7px;"></span>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div v-else class="alert alert-warning">
        <p>Неизвестный тип виджета: {{ familyName }}</p>
        <pre style="font-size: 0.75rem; color: #666;">{{ JSON.stringify(contentWidget, null, 2) }}</pre>
    </div>
</template>

<style scoped>
/*
 * Заглушки повторяют форму настоящего виджета: кольцо для круговых,
 * контур линии для линейных. Цвета берутся из переменных Tabler,
 * поэтому заглушка следует за темой так же, как готовый виджет.
 */
.placeholder-donut {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    /* Кольцо, а не круг: круговые виджеты по умолчанию рисуются донатом. */
    -webkit-mask: radial-gradient(circle, transparent 52%, #000 53%);
    mask: radial-gradient(circle, transparent 52%, #000 53%);
}

.placeholder-line {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

.placeholder-line polyline {
    fill: none;
    stroke: var(--tblr-border-color);
    stroke-width: 2;
    vector-effect: non-scaling-stroke;
    stroke-linecap: round;
    stroke-linejoin: round;
}
</style>
