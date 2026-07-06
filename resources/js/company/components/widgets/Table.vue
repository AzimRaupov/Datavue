<template>
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between py-3 px-4 bg-transparent border-bottom">
            <h3 class="card-title m-0" style="font-size: 1.1rem; font-weight: 600; color: #1e293b;">
                Таблица данных
            </h3>
            <div class="search-box" style="position: relative; max-width: 300px; width: 100%;">
                <input
                    v-model="searchQuery"
                    type="text"
                    class="table-search"
                    placeholder="Поиск..."
                    style="
                        width: 100%;
                        padding: 0.5rem 0.75rem;
                        font-size: 0.875rem;
                        border: 1px solid #e2e8f0;
                        border-radius: 6px;
                        outline: none;
                        transition: border-color 0.2s, box-shadow 0.2s;
                    "
                    @focus="$event.target.style.borderColor='#a5b4fc'; $event.target.style.boxShadow='0 0 0 3px rgba(165, 180, 252, 0.2)'"
                    @blur="$event.target.style.borderColor='#e2e8f0'; $event.target.style.boxShadow='none'"
                >
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table" style="margin-bottom: 0; width: 100%;">
                    <thead>
                    <tr>
                        <th v-for="(header, index) in tableData.headers" :key="index" style="padding: 0;">
                            <button
                                class="table-sort"
                                style="
                                        background: none;
                                        border: none;
                                        padding: 0.75rem 1rem;
                                        font-weight: 600;
                                        font-size: 0.85rem;
                                        color: #64748b;
                                        cursor: pointer;
                                        text-align: left;
                                        width: 100%;
                                        display: flex;
                                        align-items: center;
                                        justify-content: space-between;
                                    "
                                @click="handleSort(index)"
                            >
                                {{ header }}
                                <span
                                    class="sort-icon"
                                    :style="{ opacity: sortColumn === index ? '1' : '0.3', marginLeft: '0.5rem', fontSize: '0.7rem' }"
                                >
                                        {{ sortColumn === index ? (sortOrder === 'asc' ? '↑' : '↓') : '↕' }}
                                    </span>
                            </button>
                        </th>
                    </tr>
                    </thead>
                    <tbody class="table-tbody">
                    <tr v-for="(row, rowIndex) in paginatedRows" :key="rowIndex" class="table-row">
                        <td
                            v-for="(cell, cellIndex) in row"
                            :key="cellIndex"
                            class="format-number"
                            style="padding: 1rem; font-size: 0.875rem; color: #334155; border-bottom: 1px solid #f1f5f9;"
                        >
                            {{ cell }}
                        </td>
                    </tr>
                    <tr v-if="filteredRows.length === 0">
                        <td :colspan="tableData.headers.length || 1" class="text-center py-4 text-muted">
                            Ничего не найдено
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-footer d-flex align-items-center justify-content-between py-3 px-4 bg-transparent border-top" style="border-top: 1px solid #f1f5f9;">
            <div class="pagination-info" style="font-size: 0.85rem; color: #64748b;">
                Показано {{ shownStart }}-{{ shownEnd }} из {{ filteredRows.length }}
            </div>

            <div class="pagination-buttons btn-group" style="display: flex; gap: 0.25rem;">
                <button
                    v-if="currentPage > 1"
                    type="button"
                    class="btn btn-white"
                    style="padding: 6px 12px; font-size: 14px; border-radius: 6px;"
                    @click="currentPage--"
                >
                    &larr;
                </button>

                <button
                    v-for="page in visiblePages"
                    :key="page"
                    type="button"
                    :class="['btn', currentPage === page ? 'btn-primary' : 'btn-white']"
                    style="padding: 6px 12px; font-size: 14px; border-radius: 6px;"
                    @click="currentPage = page"
                >
                    {{ page }}
                </button>

                <button
                    v-if="currentPage < totalPages"
                    type="button"
                    class="btn btn-white"
                    style="padding: 6px 12px; font-size: 14px; border-radius: 6px;"
                    @click="currentPage++"
                >
                    &rarr;
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from "vue"

const props = defineProps({
    // Сюда прилетает весь объект виджета
    table: {
        type: Object,
        default: () => ({})
    },

})
console.log(props.table);
const ITEMS_PER_PAGE = 5
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
    const start = (currentPage.value - 1) * ITEMS_PER_PAGE
    return sortedRows.value.slice(start, start + ITEMS_PER_PAGE)
})

// Расчет общего количества страниц
const totalPages = computed(() => {
    return Math.max(1, Math.ceil(filteredRows.value.length / ITEMS_PER_PAGE))
})

// Логика отображения информации о пагинации (Показано X-Y из Z)
const shownStart = computed(() => {
    return filteredRows.value.length ? (currentPage.value - 1) * ITEMS_PER_PAGE + 1 : 0
})

const shownEnd = computed(() => {
    return Math.min(currentPage.value * ITEMS_PER_PAGE, filteredRows.value.length)
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
