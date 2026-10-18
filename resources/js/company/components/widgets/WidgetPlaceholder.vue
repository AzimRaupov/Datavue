<script setup>
/**
 * Заглушка виджета на время загрузки.
 *
 * Повторяет форму настоящего виджета (карточки счётчиков, таблица, кольцо,
 * столбцы, линия), чтобы страница не прыгала, когда придут данные. Одна на
 * дашборд, галерею и страницу проверки анимаций: форма берётся из реестра
 * (widgets/registry.js, поле placeholder).
 */
defineProps({
    // counters | table | bars | chart | pie | radial | combo | scatter | radar | heatmap | treemap | funnel
    kind: { type: String, default: "chart" },
});

/**
 * Высоты столбцов в заглушке — фиксированные, а не случайные.
 *
 * При случайных значениях столбцы прыгали на каждой перерисовке, и заглушка
 * мельтешила вместо того, чтобы спокойно показывать форму будущего графика.
 */
const PLACEHOLDER_BARS = [45, 70, 35, 85, 55, 95, 40, 65];

/*
 * Остальные формы тоже считаются заранее и без случайности — по той же
 * причине, что и высоты столбцов: заглушка не должна меняться на перерисовке.
 */

// Круговая: сплошной круг из четырёх долей (42/25/20/13 %). Круг рисуется одной
// толстой обводкой: радиус вдвое меньше, а толщина равна диаметру половины.
const PIE_RADIUS = 40;
const PIE_LENGTH = 2 * Math.PI * PIE_RADIUS;
const PIE_SEGMENTS = (() => {
    let offset = 0;

    return [0.42, 0.25, 0.2, 0.13].map((share, index) => {
        const length = share * PIE_LENGTH;
        const segment = {
            dash: `${Math.max(length - 3, 1)} ${PIE_LENGTH}`,
            offset: -offset,
            shade: [1.3, 1, 0.8, 0.6][index],
        };

        offset += length;

        return segment;
    });
})();

// Радиальная: три кольца-прогресса разной длины на сером треке.
const RADIAL_RINGS = [66, 52, 38].map((radius, index) => {
    const length = 2 * Math.PI * radius;

    return { radius, length, dash: `${length * [0.78, 0.56, 0.34][index]} ${length}` };
});

// Точечная: [x %, y % от низа, диаметр px, оттенок].
const SCATTER_DOTS = [
    [8, 22, 10, 1], [15, 48, 14, 1.4], [22, 35, 9, 0.8], [30, 62, 16, 1.7], [36, 28, 11, 1],
    [44, 55, 12, 1.2], [52, 40, 18, 1.8], [58, 72, 10, 0.9], [66, 50, 13, 1.3], [72, 30, 9, 0.8],
    [78, 66, 15, 1.6], [85, 45, 11, 1], [90, 78, 12, 1.2], [94, 58, 9, 0.9],
];

// Радар: паутина из трёх уровней по шесть осей и многоугольник данных.
const RADAR_AXES = 6;
const RADAR_RADIUS = 80;

function radarPoint(index, radius) {
    const angle = (Math.PI * 2 * index) / RADAR_AXES - Math.PI / 2;

    return `${(100 + Math.cos(angle) * radius).toFixed(1)},${(100 + Math.sin(angle) * radius).toFixed(1)}`;
}

const RADAR_LEVELS = [1, 0.66, 0.33].map((level) =>
    Array.from({ length: RADAR_AXES }, (_, i) => radarPoint(i, RADAR_RADIUS * level)).join(" ")
);
const RADAR_SPOKES = Array.from({ length: RADAR_AXES }, (_, i) => radarPoint(i, RADAR_RADIUS));
const RADAR_DATA = [0.8, 0.55, 0.9, 0.5, 0.72, 0.62]
    .map((value, i) => radarPoint(i, RADAR_RADIUS * value))
    .join(" ");

// Тепловая карта: 12 столбцов × 5 строк, оттенок разный, но не случайный.
const HEAT_COLUMNS = 12;
const HEAT_ROWS = 5;
const HEAT_SHADES = [0.4, 0.7, 1.1, 0.6, 1.4, 0.9, 0.5];

function heatShade(index) {
    return HEAT_SHADES[(index * 5 + Math.floor(index / HEAT_COLUMNS) * 3) % HEAT_SHADES.length];
}

// Воронка: ширина этапов в процентах от первого.
const FUNNEL_STEPS = [100, 82, 64, 46, 30];
</script>

<template>
    <div v-if="kind === 'counters'" class="row g-2">
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
    <div v-else-if="kind === 'table'" class="card">
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

    <!-- Круговая: сплошной круг из долей и ряд точек-маркеров вместо легенды -->
    <div v-else-if="kind === 'pie'" class="card">
        <div class="card-body placeholder-glow text-center py-4">
            <svg class="ph-ring" viewBox="0 0 160 160" aria-hidden="true">
                <circle
                    v-for="(segment, index) in PIE_SEGMENTS"
                    :key="index"
                    class="ph-stroke"
                    cx="80" cy="80" :r="PIE_RADIUS" stroke-width="80"
                    transform="rotate(-90 80 80)"
                    :stroke-dasharray="segment.dash"
                    :stroke-dashoffset="segment.offset"
                    :style="{ '--k': segment.shade }"
                />
            </svg>
            <div class="d-flex justify-content-center gap-3 mt-3">
                <span v-for="(segment, index) in PIE_SEGMENTS" :key="index"
                      class="ph rounded-circle" :style="{ width: '10px', height: '10px', '--k': segment.shade }"></span>
            </div>
        </div>
    </div>

    <!-- Радиальная: вложенные кольца прогресса, в центре — значение -->
    <div v-else-if="kind === 'radial'" class="card">
        <div class="card-body placeholder-glow text-center py-4">
            <div class="ph-ring-wrap">
                <svg class="ph-ring" viewBox="0 0 160 160" aria-hidden="true">
                    <template v-for="ring in RADIAL_RINGS" :key="ring.radius">
                        <circle class="ph-track" cx="80" cy="80" :r="ring.radius" stroke-width="10" />
                        <circle
                            class="ph-stroke" style="--k: 1.5;"
                            cx="80" cy="80" :r="ring.radius" stroke-width="10"
                            stroke-linecap="round"
                            transform="rotate(-90 80 80)"
                            :stroke-dasharray="ring.dash"
                        />
                    </template>
                </svg>
                <span class="ph rounded-pill ph-ring-value"></span>
            </div>
        </div>
    </div>

    <!-- Столбцы: с осью значений и подписями категорий -->
    <div v-else-if="kind === 'bars'" class="card">
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

    <!-- Комбо: столбцы и поверх них линия второй метрики -->
    <div v-else-if="kind === 'combo'" class="card">
        <div class="card-body placeholder-glow">
            <div class="d-flex" style="height: 240px;">
                <div class="d-flex flex-column justify-content-between pe-2" style="width: 34px;">
                    <span v-for="n in 5" :key="n" class="placeholder bg-secondary" style="height: 7px;"></span>
                </div>
                <div class="flex-fill border-start border-bottom position-relative">
                    <div class="d-flex align-items-end gap-2 px-2 h-100">
                        <span v-for="n in 8" :key="n" class="ph rounded-top flex-fill"
                              :style="{ height: PLACEHOLDER_BARS[(n + 2) % 8] * 0.8 + '%', '--k': 0.9 }"></span>
                    </div>
                    <svg class="ph-overlay" viewBox="0 0 100 40" preserveAspectRatio="none" aria-hidden="true">
                        <polyline class="ph-line" style="--k: 1.5;"
                                  points="6,28 18,19 31,23 43,11 56,17 68,7 81,14 93,9" />
                    </svg>
                </div>
            </div>
            <div class="d-flex gap-2 mt-2" style="padding-left: 42px;">
                <span v-for="n in 8" :key="n" class="placeholder bg-secondary flex-fill" style="height: 7px;"></span>
            </div>
        </div>
    </div>

    <!-- Точечная: россыпь точек разного размера на осях -->
    <div v-else-if="kind === 'scatter'" class="card">
        <div class="card-body placeholder-glow">
            <div class="d-flex" style="height: 240px;">
                <div class="d-flex flex-column justify-content-between pe-2" style="width: 34px;">
                    <span v-for="n in 5" :key="n" class="placeholder bg-secondary" style="height: 7px;"></span>
                </div>
                <div class="flex-fill border-start border-bottom position-relative">
                    <span v-for="(dot, index) in SCATTER_DOTS" :key="index" class="ph rounded-circle position-absolute"
                          :style="{ left: dot[0] + '%', bottom: dot[1] + '%', width: dot[2] + 'px', height: dot[2] + 'px', '--k': dot[3] * 0.75 }"></span>
                </div>
            </div>
            <div class="d-flex gap-2 mt-2" style="padding-left: 42px;">
                <span v-for="n in 6" :key="n" class="placeholder bg-secondary flex-fill" style="height: 7px;"></span>
            </div>
        </div>
    </div>

    <!-- Радар: паутина осей и многоугольник данных -->
    <div v-else-if="kind === 'radar'" class="card">
        <div class="card-body placeholder-glow">
            <div class="text-center">
                <svg class="ph-radar" viewBox="0 0 200 200" aria-hidden="true">
                    <polygon v-for="(level, index) in RADAR_LEVELS" :key="index" class="ph-web" :points="level" />
                    <line v-for="(spoke, index) in RADAR_SPOKES" :key="'s' + index" class="ph-web"
                          x1="100" y1="100" :x2="spoke.split(',')[0]" :y2="spoke.split(',')[1]" />
                    <polygon class="ph-area" style="--k: 1.2;" :points="RADAR_DATA" />
                </svg>
            </div>
            <div class="d-flex justify-content-center gap-4 mt-2">
                <div v-for="n in 2" :key="n" class="d-flex align-items-center gap-2">
                    <span class="placeholder rounded-circle bg-secondary" style="width: 10px; height: 10px;"></span>
                    <span class="placeholder bg-secondary" style="width: 64px; height: 8px;"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Тепловая карта: сетка ячеек разной насыщенности -->
    <div v-else-if="kind === 'heatmap'" class="card">
        <div class="card-body placeholder-glow">
            <div class="d-flex" style="height: 240px;">
                <div class="d-flex flex-column justify-content-around pe-2" style="width: 34px;">
                    <span v-for="n in HEAT_ROWS" :key="n" class="placeholder bg-secondary" style="height: 7px;"></span>
                </div>
                <div class="ph-heat flex-fill" :style="{ '--cols': HEAT_COLUMNS, '--rows': HEAT_ROWS }">
                    <span v-for="n in HEAT_COLUMNS * HEAT_ROWS" :key="n" class="ph"
                          :style="{ '--k': heatShade(n - 1) }"></span>
                </div>
            </div>
            <div class="d-flex gap-2 mt-2" style="padding-left: 42px;">
                <span v-for="n in 6" :key="n" class="placeholder bg-secondary flex-fill" style="height: 7px;"></span>
            </div>
        </div>
    </div>

    <!-- Древовидная: вложенные блоки разной площади -->
    <div v-else-if="kind === 'treemap'" class="card">
        <div class="card-body placeholder-glow">
            <div class="ph-tree">
                <span class="ph" style="flex: 5; --k: 1.3;"></span>
                <div class="ph-tree-col" style="flex: 3;">
                    <span class="ph" style="flex: 2; --k: 1;"></span>
                    <span class="ph" style="flex: 1; --k: 0.7;"></span>
                </div>
                <div class="ph-tree-col" style="flex: 2;">
                    <span class="ph" style="flex: 3; --k: 0.9;"></span>
                    <span class="ph" style="flex: 2; --k: 1.2;"></span>
                    <span class="ph" style="flex: 2; --k: 0.6;"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Воронка: этапы по центру, каждый уже предыдущего -->
    <div v-else-if="kind === 'funnel'" class="card">
        <div class="card-body placeholder-glow">
            <div class="d-flex flex-column align-items-center gap-1" style="height: 240px;">
                <span v-for="(width, index) in FUNNEL_STEPS" :key="index" class="ph rounded flex-fill"
                      :style="{ width: width + '%', '--k': 1.4 - index * 0.2 }"></span>
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

<style scoped>
/*
 * Заглушки повторяют форму настоящего виджета: кольцо для круговых,
 * контур линии для линейных. Цвета берутся из переменных Tabler,
 * поэтому заглушка следует за темой так же, как готовый виджет.
 *
 * Фигуры сложных форм (.ph, .ph-stroke, .ph-line, .ph-area) мигают тем же
 * образом, что и .placeholder в Tabler: тот же цвет, та же глубина и период,
 * поэтому рядом с обычными полосками они пульсируют в такт. Оттенок каждой
 * фигуры задаёт --k: чем больше, тем она насыщеннее.
 */
.ph,
.ph-stroke,
.ph-line,
.ph-area {
    opacity: calc(0.2 * var(--k, 1));
    animation: ph-pulse 2s ease-in-out infinite;
}

@keyframes ph-pulse {
    50% {
        opacity: calc(0.1 * var(--k, 1));
    }
}

.ph {
    background: var(--tblr-secondary, #667382);
}

.ph-ring {
    display: block;
    width: 170px;
    height: 170px;
    margin: 0 auto;
}

.ph-ring-wrap {
    position: relative;
    display: inline-block;
}

/* Значение в центре радиальной: пилюля по центру самого малого кольца. */
.ph-ring-value {
    position: absolute;
    top: 50%;
    left: 50%;
    width: 44px;
    height: 14px;
    transform: translate(-50%, -50%);
    --k: 1.2;
}

.ph-stroke {
    fill: none;
    stroke: var(--tblr-secondary, #667382);
}

.ph-track {
    fill: none;
    stroke: var(--tblr-border-color, #e6e7e9);
    opacity: 0.6;
}

.ph-line {
    fill: none;
    stroke: var(--tblr-secondary, #667382);
    stroke-width: 3;
    vector-effect: non-scaling-stroke;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.ph-overlay {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

.ph-radar {
    width: 240px;
    max-width: 100%;
    height: auto;
}

.ph-web {
    fill: none;
    stroke: var(--tblr-border-color, #e6e7e9);
    stroke-width: 1;
}

.ph-area {
    fill: var(--tblr-secondary, #667382);
}

.ph-heat {
    display: grid;
    grid-template-columns: repeat(var(--cols), 1fr);
    grid-template-rows: repeat(var(--rows), 1fr);
    gap: 3px;
}

.ph-heat > .ph {
    border-radius: 3px;
}

.ph-tree {
    display: flex;
    gap: 3px;
    height: 240px;
}

.ph-tree-col {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
}

.ph-tree .ph {
    min-width: 0;
    border-radius: 4px;
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
