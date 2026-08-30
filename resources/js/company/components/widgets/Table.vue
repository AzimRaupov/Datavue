<template>
    <!--
      Разметка на штатных компонентах Tabler: card-table, table-vcenter,
      table-sort, pagination. Раньше здесь были инлайновые стили с
      захардкоженными цветами (#1e293b, #e2e8f0, #64748b), из-за чего виджет
      не реагировал на переключение темы и выпадал из общего вида — шрифты и
      отступы у него были свои.
    -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ t('widgets.table.title') }}</h3>
            <div class="card-actions">
                <div class="input-icon">
                    <span class="input-icon-addon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                             stroke-linejoin="round" class="icon">
                            <path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" />
                            <path d="M21 21l-6 -6" />
                        </svg>
                    </span>
                    <input v-model="searchQuery" type="text" class="form-control form-control-sm"
                           :placeholder="t('widgets.table.search_placeholder')" :aria-label="t('widgets.table.search_aria_label')" />
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table
                class="table table-vcenter card-table"
                :class="{ 'table-striped': isStriped, 'table-sm': isCompact }"
            >
                <thead>
                <tr>
                    <th v-for="(header, index) in tableData.headers" :key="index">
                        <!-- .table-sort сам рисует стрелку и её направление
                             по классам asc/desc — свои значки не нужны. -->
                        <button
                            class="table-sort"
                            :class="sortColumn === index ? (sortOrder === 'asc' ? 'asc' : 'desc') : ''"
                            @click="handleSort(index)"
                        >
                            {{ header }}
                        </button>
                    </th>
                </tr>
                </thead>
                <tbody class="table-tbody">
                <tr v-for="(row, rowIndex) in paginatedRows" :key="rowIndex">
                    <td v-for="(cell, cellIndex) in row" :key="cellIndex" class="format-number">
                        {{ cell }}
                    </td>
                </tr>
                <tr v-if="filteredRows.length === 0">
                    <td :colspan="tableData.headers.length || 1" class="text-center text-secondary py-4">
                        {{ t('widgets.table.no_results') }}
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <div class="card-footer d-flex align-items-center">
            <p class="m-0 text-secondary">
                {{ t('widgets.table.shown_prefix') }} <span class="fw-bold">{{ shownStart }}-{{ shownEnd }}</span>
                {{ t('widgets.table.shown_of') }} <span class="fw-bold">{{ filteredRows.length }}</span>
            </p>

            <ul class="pagination pagination-sm m-0 ms-auto">
                <li class="page-item" :class="{ disabled: currentPage === 1 }">
                    <button class="page-link" :disabled="currentPage === 1" @click="currentPage--">
                        {{ t('widgets.table.back') }}
                    </button>
                </li>
                <li
                    v-for="page in visiblePages"
                    :key="page"
                    class="page-item"
                    :class="{ active: currentPage === page }"
                >
                    <button class="page-link" @click="currentPage = page">{{ page }}</button>
                </li>
                <li class="page-item" :class="{ disabled: currentPage >= totalPages }">
                    <button class="page-link" :disabled="currentPage >= totalPages" @click="currentPage++">
                        {{ t('widgets.table.next') }}
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from "vue"
import { useI18n } from "vue-i18n"

const { t } = useI18n()

const props = defineProps({
    // Сюда прилетает весь объект виджета
    table: {
        type: Object,
        default: () => ({})
    },

    // options.striped — подсветка чётных строк;
    // options.compact — плотная вёрстка, строк на страницу больше.
    options: {
        type: Object,
        default: () => ({})
    },
})

const isStriped = computed(() => props.options.striped === true)
const isCompact = computed(() => props.options.compact === true)
// Отступы и размер шрифта в ячейках задаёт сам Tabler через .table-sm —
// считать их вручную больше не нужно.

// Плотный вид берут, когда строк много — показываем их больше за страницу.
const ITEMS_PER_PAGE = computed(() => (isCompact.value ? 12 : 5))
const currentPage = ref(1)
const searchQuery = ref("")
const sortColumn = ref(null)
const sortOrder = ref("asc") // 'asc' или 'desc'

// Безопасное извлечение схемы данных (в зависимости от того, обернута она бэкендом или нет)
const tableData = computed(() => {
    if (props.table && props.table.headers && props.table.rows) {
        return props.table;
    }
    return props.table?.table || { headers: [], rows: [] };
})

// Сброс страницы на 1 при изменении поискового запроса
watch(searchQuery, () => {
    currentPage.value = 1
})

// 1. Фильтрация строк по поиску
const filteredRows = computed(() => {
    const query = searchQuery.value.toLowerCase().trim()
    if (!query) return [...tableData.value.rows]

    return tableData.value.rows.filter(row =>
        row.some(cell => String(cell).toLowerCase().includes(query))
    )
})

// 2. Сортировка отфильтрованных строк
const sortedRows = computed(() => {
    const rows = [...filteredRows.value]
    if (sortColumn.value === null) return rows

    const index = sortColumn.value
    const order = sortOrder.value

    rows.sort((a, b) => {
        const aVal = a[index];
        const bVal = b[index];

        const isNumA = !isNaN(aVal) && aVal !== '' && aVal !== null;
        const isNumB = !isNaN(bVal) && bVal !== '' && bVal !== null;

        if (isNumA && isNumB) {
            return order === 'asc' ? Number(aVal) - Number(bVal) : Number(bVal) - Number(aVal);
        }

        const aText = String(aVal ?? '');
        const bText = String(bVal ?? '');

        return order === 'asc'
            ? aText.localeCompare(bText, 'ru', { numeric: true, sensitivity: 'base' })
            : bText.localeCompare(aText, 'ru', { numeric: true, sensitivity: 'base' });
    })

    return rows
})

// 3. Пагинация (срез данных для текущей страницы)
const paginatedRows = computed(() => {
    const start = (currentPage.value - 1) * ITEMS_PER_PAGE.value
    return sortedRows.value.slice(start, start + ITEMS_PER_PAGE.value)
})

// Расчет общего количества страниц
const totalPages = computed(() => {
    return Math.max(1, Math.ceil(filteredRows.value.length / ITEMS_PER_PAGE.value))
})

// Логика отображения информации о пагинации (Показано X-Y из Z)
const shownStart = computed(() => {
    return filteredRows.value.length ? (currentPage.value - 1) * ITEMS_PER_PAGE.value + 1 : 0
})

const shownEnd = computed(() => {
    return Math.min(currentPage.value * ITEMS_PER_PAGE.value, filteredRows.value.length)
})

// Массив номеров страниц для отображения кнопок (текущая +- 2 страницы)
const visiblePages = computed(() => {
    const pages = []
    const start = Math.max(1, currentPage.value - 2)
    const end = Math.min(totalPages.value, currentPage.value + 2)
    for (let i = start; i <= end; i++) {
        pages.push(i)
    }
    return pages
})

// Обработчик клика по колонке сортировки
function handleSort(index) {
    if (sortColumn.value === index) {
        sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortColumn.value = index
        sortOrder.value = 'asc'
    }
    currentPage.value = 1
}
</script>
