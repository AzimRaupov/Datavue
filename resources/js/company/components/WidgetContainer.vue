<script setup>
import { ref, computed, onMounted, watch } from "vue";
import { useI18n } from "vue-i18n";
import api from "../api.js";

import { familyOf, prepare, hasData } from "./widgets/registry.js";

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

const emit = defineEmits(["unavailable"]);

const { t } = useI18n();

const widget = computed(() => props.widget);

const contentWidget = ref(null);
const isLoading = ref(false);
const contentMeta = ref(null);

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

    const base = chosen?.options
        ?? (widget.value?.widget?.types ?? []).find(t => t.is_default)?.options
        ?? {};

    // Своя палитра виджета живёт в оформлении, а не в типе отрисовки: тип
    // общий на все виджеты семейства, а цвета выбираются штучно. Подмешиваем
    // её здесь, чтобы компоненты семейств получали цвета там же, где и
    // остальные параметры отрисовки, — см. widgets/palette.js.
    const colors = widget.value?.presentation?.colors;

    return Array.isArray(colors) && colors.length ? { ...base, colors } : base;
});

const isReady = computed(() =>
    widget.value?.status === "active" && contentWidget.value !== null
);

const contentHasData = computed(() => hasData(familyName.value, contentWidget.value));

const showWidget = computed(() => family.value && isReady.value && contentHasData.value);

// Сервер отдаёт графику данные целиком, но не больше потолка в строках.
// Если упёрлись в него — это надо сказать, иначе обрезанный график выдаёт
// себя за полный.
const isTruncated = computed(() => Boolean(contentMeta.value?.truncated));

// С какой категории начинается окно по оси. null — позиция по умолчанию:
// у времени последние периоды, у обычных категорий первые.
const axisOffset = ref(null);

const prepared = computed(() => prepare(
    familyName.value,
    contentWidget.value,
    typeOptions.value,
    { offset: axisOffset.value, otherLabel: t("widgetContainer.other_label") }
));

const widgetProps = computed(() => prepared.value.props);

const axis = computed(() => prepared.value.axis);

const notice = computed(() => {
    const info = prepared.value.notice;

    if (!info) return null;

    return t(`widgetContainer.${info.kind}_notice`, {
        shown: info.shown,
        total: info.total,
        other: t("widgetContainer.other_label"),
    });
});

function moveAxis(start) {
    if (!axis.value) return;

    axisOffset.value = Math.max(0, Math.min(start, axis.value.total - axis.value.size));
}

// Таблица листается, ищется и сортируется на сервере: набор строк может быть
// больше одной страницы. Параметры живут здесь, а не в таблице, потому что
// запрос делает контейнер.
const isTable = computed(() => familyName.value === "table");

const tableQuery = ref({ page: 1, search: "", sort_by: null, sort_dir: "asc" });
const isPageLoading = ref(false);

const remote = computed(() =>
    isTable.value && contentMeta.value?.paginated
        ? { meta: contentMeta.value, loading: isPageLoading.value }
        : null
);

// Лишние атрибуты остальным семействам не нужны: они бы попали в разметку.
const extraProps = computed(() => (remote.value ? { remote: remote.value, onQuery: onTableQuery } : {}));

function onTableQuery(query) {
    tableQuery.value = { ...tableQuery.value, ...query };

    return getWidgetContent({ silent: true });
}

/**
 * Высоты столбцов в заглушке — фиксированные, а не случайные.
 *
 * При случайных значениях столбцы прыгали на каждой перерисовке, и заглушка
 * мельтешила вместо того, чтобы спокойно показывать форму будущего графика.
 */
const PLACEHOLDER_BARS = [45, 70, 35, 85, 55, 95, 40, 65];

// Номер последнего запроса. Ответы приходят не по порядку (перелистнули
// страницу, пока грузилась прошлая), и устаревший не должен затирать свежий.
let requestSeq = 0;

function requestBody() {
    const body = { chat_id: props.chatId };

    if (!isTable.value) return body;

    const query = tableQuery.value;

    body.page = query.page;
    body.per_page = typeOptions.value.compact === true ? 12 : 5;

    if (query.search) body.search = query.search;

    if (query.sort_by) {
        body.sort_by = query.sort_by;
        body.sort_dir = query.sort_dir;
    }

    return body;
}

/**
 * silent — перезапрос страницы таблицы: прежние строки остаются на экране
 * (с затемнением), а не заменяются заглушкой, иначе таблица пересоздалась
 * бы и потеряла введённый поиск.
 */
async function getWidgetContent({ silent = false } = {}) {
    if (!widget.value?.id) return;

    // Виджет, который не удалось сгенерировать, не запрашиваем вовсе —
    // он просто не показывается, без предупреждений и заглушек.
    if (widget.value.status === "failed") {
        emit("unavailable", widget.value.id);

        return;
    }

    const seq = ++requestSeq;

    try {
        if (silent) {
            isPageLoading.value = true;
        } else {
            isLoading.value = true;
            contentWidget.value = null;
            contentMeta.value = null;
            axisOffset.value = null;
            tableQuery.value = { page: 1, search: "", sort_by: null, sort_dir: "asc" };
        }

        const response = await api.post(
            "/get-widget-content/" + widget.value.id,
            requestBody()
        );

        if (seq !== requestSeq) return;

        // Два формата ответа — по числу способов посчитать виджет.
        //
        // SQL-виджет отдаёт готовую структуру полем data: раскладку по форме
        // сделал сервер. Python-виджет печатает JSON в stdout, поэтому его
        // содержимое приходит строкой в output и разбирается здесь.
        if (response.data.data && typeof response.data.data === "object") {
            contentWidget.value = response.data.data;
            contentMeta.value = response.data.meta ?? null;

            return;
        }

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
        if (seq !== requestSeq) return;

        console.error("Ошибка загрузки данных виджета:", err);

        // Не удалось перелистнуть — остаются прежние строки, а не пропадает
        // вся таблица.
        if (silent) return;

        // Виджет не смог посчитаться — не показываем его вовсе, а не заглушку
        // или сообщение об ошибке.
        emit("unavailable", widget.value.id);
    } finally {
        if (seq === requestSeq) {
            isLoading.value = false;
            isPageLoading.value = false;
        }
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
        <template v-if="showWidget">
            <component
                :is="family.component"
                v-bind="{ ...widgetProps, ...extraProps }"
            />

            <!-- Ось длиннее окна: листаем, а не обрезаем -->
            <div v-if="axis" class="widget-pager mt-2 d-print-none">
                <div class="d-flex align-items-center gap-2">
                    <div class="btn-group btn-group-sm flex-shrink-0">
                        <button type="button" class="btn px-3 py-1 fs-3 lh-1" :disabled="axis.start === 0"
                                :aria-label="t('widgetContainer.axis_first')"
                                :title="t('widgetContainer.axis_first')"
                                @click="moveAxis(0)">«</button>
                        <button type="button" class="btn px-3 py-1 fs-3 lh-1" :disabled="axis.start === 0"
                                :aria-label="t('widgetContainer.axis_prev')"
                                :title="t('widgetContainer.axis_prev')"
                                @click="moveAxis(axis.start - axis.size)">‹</button>
                    </div>

                    <input
                        type="range"
                        class="form-range flex-fill"
                        min="0"
                        :max="axis.total - axis.size"
                        :value="axis.start"
                        :aria-label="t('widgetContainer.axis_slider')"
                        @input="moveAxis(Number($event.target.value))"
                    />

                    <div class="btn-group btn-group-sm flex-shrink-0">
                        <button type="button" class="btn px-3 py-1 fs-3 lh-1" :disabled="axis.end >= axis.total"
                                :aria-label="t('widgetContainer.axis_next')"
                                :title="t('widgetContainer.axis_next')"
                                @click="moveAxis(axis.start + axis.size)">›</button>
                        <button type="button" class="btn px-3 py-1 fs-3 lh-1" :disabled="axis.end >= axis.total"
                                :aria-label="t('widgetContainer.axis_last')"
                                :title="t('widgetContainer.axis_last')"
                                @click="moveAxis(axis.total)">»</button>
                    </div>
                </div>

                <div class="text-secondary small text-center mt-1">
                    {{ t('widgetContainer.axis_range', {
                        from: axis.start + 1,
                        to: axis.end,
                        total: axis.total,
                    }) }}
                    · {{ axis.firstLabel }} – {{ axis.lastLabel }}
                </div>
            </div>

            <div v-if="notice" class="text-secondary small mt-2">{{ notice }}</div>

            <!-- Данные урезаны до потолка строк: показываем то, что поместилось, и предупреждаем -->
            <div v-if="isTruncated" class="alert alert-warning py-2 mt-2 mb-0 small" role="status">
                {{ t('widgetContainer.truncated_notice') }}
            </div>
        </template>

        <!-- Плейсхолдеры на время генерации: форма подсказывает, что появится -->
        <template v-else-if="widget.status !== 'failed'">
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
        <p>{{ t('widgetContainer.unknown_widget_type', { type: familyName }) }}</p>
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

/* Ошибка базы бывает длинной — переносим, а не растягиваем карточку. */
.widget-error {
    white-space: pre-wrap;
    word-break: break-word;
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
