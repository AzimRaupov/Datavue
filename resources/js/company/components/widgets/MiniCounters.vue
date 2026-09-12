<template>
    <!-- Плитки: крупные карточки, вид по умолчанию для верхнего блока -->
    <div v-if="layout === 'cards'" class="row g-2">
        <div v-for="(counter, index) in items" :key="index" :class="columnClass">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="subheader mb-1">{{ counter.name }}</h4>
                            <div class="h3 m-0" :style="valueStyle(index)">{{ formatValue(counter) }}</div>
                        </div>

                        <div class="col-auto">
                            <span
                                class="avatar avatar-lx cursor-pointer"
                                :class="copiedIndex === index ? 'bg-success-lt text-success' : ''"
                                :title="copiedIndex === index ? t('widgets.minicounters.copied') : t('widgets.minicounters.copy_value')"
                                @click="copyValue(counter, index)"
                            >
                                <CopyIcon />
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- С полосой выполнения: под каждым числом прогресс к плану -->
    <div v-else-if="layout === 'progress'" class="row g-2">
        <div v-for="(counter, index) in items" :key="index" :class="columnClass">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <h4 class="subheader mb-1">{{ counter.name }}</h4>

                        <span
                            class="cursor-pointer text-secondary"
                            :class="copiedIndex === index ? 'text-success' : ''"
                            :title="copiedIndex === index ? t('widgets.minicounters.copied') : t('widgets.minicounters.copy_value')"
                            @click="copyValue(counter, index)"
                        >
                            <CopyIcon />
                        </span>
                    </div>

                    <div class="d-flex align-items-baseline justify-content-between">
                        <div class="h3 m-0" :style="valueStyle(index)">{{ formatValue(counter) }}</div>
                        <div class="text-secondary small">{{ percentOf(counter) }}%</div>
                    </div>

                    <div class="progress progress-sm mt-2">
                        <div
                            class="progress-bar"
                            :class="ownColorAt(index) ? '' : (percentOf(counter) >= 100 ? 'bg-success' : 'bg-primary')"
                            :style="barStyle(counter, index)"
                        ></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Компактная строка: когда счётчики вспомогательные -->
    <div v-else class="card">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap gap-4">
                <div
                    v-for="(counter, index) in items"
                    :key="index"
                    class="cursor-pointer"
                    :title="copiedIndex === index ? t('widgets.minicounters.copied') : t('widgets.minicounters.copy_value')"
                    @click="copyValue(counter, index)"
                >
                    <div class="subheader">{{ counter.name }}</div>
                    <div
                        class="h4 m-0"
                        :class="copiedIndex === index ? 'text-success' : ''"
                        :style="copiedIndex === index ? {} : valueStyle(index)"
                    >
                        {{ formatValue(counter) }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, h } from "vue"
import { useI18n } from "vue-i18n"

const { t } = useI18n()

const props = defineProps({
    // Прокси-объект с сервера: { counters: [...] }
    counters: {
        type: Object,
        default: () => ({ counters: [] })
    },

    // options.layout — "cards" | "inline" | "progress"
    options: {
        type: Object,
        default: () => ({})
    },
})

const items = computed(() => props.counters?.counters ?? [])

/**
 * Цвет плитки — тот, что автор выбрал для ряда с этим номером.
 *
 * Счётчик — такой же ряд, как столбец или линия, просто нарисованный числом,
 * поэтому и палитра, и порядок ячеек у него общие с графиками (см. palette.js).
 * Красим само число, а не всю карточку: заливка целиком превратила бы сводку
 * в набор ярких прямоугольников и убила бы читаемость цифр, ради которых
 * виджет и существует.
 *
 * В отличие от графиков, незаполненную ячейку НЕ добираем из общей палитры.
 * У графика ряд обязан быть каким-то цветом, и стандартный там — цвет палитры;
 * у счётчика стандартный — цвет текста. Добери мы палитру, как в colorsFor(),
 * и выбор цвета для третьей плитки перекрасил бы первые две в синий и оранжевый,
 * о чём никто не просил.
 */
function ownColorAt(index) {
    const chosen = props.options?.colors

    if (!Array.isArray(chosen)) return null

    const color = chosen[index]

    return typeof color === "string" && color.trim() !== "" ? color.trim() : null
}

function valueStyle(index) {
    const color = ownColorAt(index)

    return color ? { color } : {}
}

function barStyle(counter, index) {
    const width = Math.min(percentOf(counter), 100) + "%"
    const color = ownColorAt(index)

    return color ? { width, backgroundColor: color } : { width }
}

const layout = computed(() => {
    const value = props.options.layout

    return ["cards", "inline", "progress"].includes(value) ? value : "cards"
})

/**
 * Ширина колонки под количество счётчиков.
 *
 * Раньше стояли фиксированные row-cols-xxl-5 / row-cols-xxl-4: при двух
 * счётчиках они жались влево, оставляя справа пустоту в три четверти ширины.
 *
 * Теперь до четырёх штук они делят ряд поровну и занимают его целиком,
 * а начиная с пяти — переносятся по четыре в ряд (больше в ряд не ставим:
 * числа становятся нечитаемо мелкими).
 */
const columnClass = computed(() => {
    const count = items.value.length

    if (count <= 1) return "col-12"
    if (count === 2) return "col-12 col-md-6"
    if (count === 3) return "col-12 col-md-4"

    return "col-12 col-sm-6 col-xl-3"
})

// Иконка копирования одна на все три раскладки — рендер-функцией,
// чтобы не дублировать svg в шаблоне трижды.
const CopyIcon = () =>
    h(
        "svg",
        {
            xmlns: "http://www.w3.org/2000/svg",
            width: 20,
            height: 20,
            viewBox: "0 0 24 24",
            fill: "none",
            stroke: "currentColor",
            "stroke-width": 2,
            "stroke-linecap": "round",
            "stroke-linejoin": "round",
            "aria-hidden": "true",
            class: "icon icon-1",
        },
        [
            h("path", { d: "M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" }),
            h("path", { d: "M9 5a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2" }),
        ]
    )

function formatValue(counter) {
    return `${counter.prefix ?? ""}${counter.value}${counter.suffix ?? ""}`
}

function percentOf(counter) {
    const percent = Number(counter.percent)

    return Number.isFinite(percent) ? Math.max(Math.round(percent), 0) : 0
}

const copiedIndex = ref(null)
let resetTimer = null

async function copyValue(counter, index) {
    const textToCopy = formatValue(counter)

    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(textToCopy)
        } else {
            // Фолбэк для старых браузеров / незащищённого контекста (http)
            const textarea = document.createElement("textarea")
            textarea.value = textToCopy
            textarea.style.position = "fixed"
            textarea.style.opacity = "0"
            document.body.appendChild(textarea)
            textarea.focus()
            textarea.select()
            document.execCommand("copy")
            document.body.removeChild(textarea)
        }

        copiedIndex.value = index

        if (resetTimer) clearTimeout(resetTimer)
        resetTimer = setTimeout(() => {
            copiedIndex.value = null
        }, 1500)

    } catch (err) {
        console.error("Не удалось скопировать значение:", err)
    }
}
</script>

<style scoped>
.cursor-pointer {
    cursor: pointer;
}
</style>
