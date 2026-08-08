<script setup>
import { ref, onMounted, computed } from "vue";
import api from "../api.js";
import { familyOf, propsFor } from "../components/widgets/registry.js";

/**
 * Галерея виджетов.
 *
 * Каталог берётся из базы, а не из копии списка в коде: добавили семейство или
 * тип в сидере — страница показывает его сама. Рисуется тем же реестром, что и
 * дашборд, поэтому превью совпадает с тем, что увидит пользователь.
 *
 * Данные — вымышленные, но по форме те же, что генерирует python-скрипт виджета.
 */
const families = ref([]);
const isLoading = ref(true);
const error = ref("");
const showAiOnly = ref(false);

const MONTHS = ["Янв", "Фев", "Мар", "Апр", "Май", "Июн"];
const COUNTRIES = ["Россия", "Казахстан", "Узбекистан", "Таджикистан", "Киргизия"];

/**
 * Демо-данные по форме семейства. Ключ — имя семейства, значение — функция,
 * которой отдаётся имя типа: типы с собственной формой (bubble, polar-area,
 * with-progress) получают свою.
 */
const DEMO = {
    "mini-counters": (type) => ({
        counters: type === "with-progress"
            ? [
                { name: "План продаж", value: 8420, percent: 84, suffix: " ₽" },
                { name: "Новые клиенты", value: 96, percent: 120 },
                { name: "Заполненность склада", value: 312, percent: 47, suffix: " шт." },
            ]
            : [
                { name: "Выручка", value: 1284500, prefix: "", suffix: " ₽" },
                { name: "Заказов", value: 3412 },
                { name: "Клиентов", value: 842 },
                { name: "Средний чек", value: 376, suffix: " ₽" },
            ],
    }),

    "bar": () => ({
        categories: COUNTRIES,
        series: [
            { name: "Выручка", data: [420, 310, 265, 180, 120] },
            { name: "Возвраты", data: [40, 28, 22, 19, 11] },
        ],
    }),

    "line": () => ({
        labels: MONTHS,
        series: [
            { name: "Заказы", data: [120, 145, 132, 188, 176, 231] },
            { name: "Платежи", data: [98, 121, 110, 160, 152, 205] },
        ],
    }),

    "pie": () => ({
        labels: ["Прямые продажи", "Партнёры", "Онлайн", "Розница"],
        series: [42, 25, 20, 13],
    }),

    "radial": (type) => (type === "multi"
        ? { labels: ["Отдел A", "Отдел B", "Отдел C"], series: [82, 64, 108] }
        : { labels: ["Выполнение плана"], series: [72] }),

    "combo": () => ({
        categories: MONTHS,
        series: [
            { name: "Выручка, ₽", kind: "column", data: [420000, 512000, 468000, 610000, 585000, 702000] },
            { name: "Маржа, %", kind: "line", data: [18.4, 19.1, 17.8, 21.2, 20.5, 22.6] },
        ],
    }),

    "table": () => ({
        headers: ["Клиент", "Страна", "Заказов", "Сумма, ₽"],
        rows: [
            ["ООО «Восход»", "Россия", 42, 512400],
            ["ТОО «Алтын»", "Казахстан", 31, 388900],
            ["Silk Trade", "Узбекистан", 28, 301250],
            ["Памир Групп", "Таджикистан", 19, 214800],
            ["Ала-Тоо ЛТД", "Киргизия", 12, 156300],
            ["ИП Сергеев", "Россия", 9, 98400],
        ],
    }),

    "scatter": (type) => (type === "bubble"
        ? {
            series: [{
                name: "Товары",
                data: [[120, 4.2, 380], [240, 3.8, 620], [95, 4.6, 210], [310, 3.1, 810], [180, 4.4, 450]],
            }],
        }
        : {
            series: [
                { name: "Москва", data: [[120, 42], [240, 61], [95, 33], [310, 78], [180, 55]] },
                { name: "Регионы", data: [[110, 28], [200, 39], [88, 21], [280, 52], [160, 35]] },
            ],
        }),

    "radar": (type) => (type === "polar-area"
        ? {
            labels: ["Цена", "Качество", "Сервис", "Скорость", "Ассортимент"],
            series: [21, 15, 9, 18, 12],
        }
        : {
            categories: ["Цена", "Качество", "Сервис", "Скорость", "Ассортимент"],
            series: [
                { name: "Наш продукт", data: [80, 90, 70, 85, 60] },
                { name: "Конкурент", data: [65, 75, 88, 60, 82] },
            ],
        }),

    "heatmap": () => ({
        series: ["Пн", "Вт", "Ср", "Чт", "Пт"].map((day, dayIndex) => ({
            name: day,
            data: ["09:00", "12:00", "15:00", "18:00", "21:00"].map((hour, hourIndex) => ({
                x: hour,
                // Ровный «горб» к середине дня, чтобы на превью была видна структура
                y: Math.round(20 + hourIndex * 14 + dayIndex * 6 - Math.abs(hourIndex - 2) * 11),
            })),
        })),
    }),

    "treemap": () => ({
        series: [{
            data: [
                { x: "Электроника", y: 4200 }, { x: "Одежда", y: 3100 },
                { x: "Продукты", y: 2650 }, { x: "Мебель", y: 1800 },
                { x: "Спорт", y: 1200 }, { x: "Книги", y: 860 },
                { x: "Игрушки", y: 640 }, { x: "Прочее", y: 410 },
            ],
        }],
    }),

    "funnel": () => ({
        labels: ["Заявки", "Квалифицированы", "Счёт выставлен", "Оплачено", "Отгружено"],
        series: [1000, 620, 410, 280, 245],
    }),

    "map": () => ({
        series: [{ code: "RU", value: 420 }, { code: "KZ", value: 310 }, { code: "UZ", value: 265 }],
    }),
};

function demoFor(familyName, typeName) {
    const factory = DEMO[familyName];

    return factory ? factory(typeName) : null;
}

/**
 * Пропсы конкретного превью: демо-данные нужной формы + параметры типа.
 */
function previewProps(familyName, type) {
    return propsFor(familyName, demoFor(familyName, type.name), type.options ?? {});
}

const visibleFamilies = computed(() =>
    showAiOnly.value
        ? families.value.filter(f => f.is_ai_selectable)
        : families.value
);

const totals = computed(() => ({
    families: families.value.length,
    types: families.value.reduce((sum, f) => sum + f.types.length, 0),
}));

function isRenderable(familyName) {
    return Boolean(familyOf(familyName));
}

async function load() {
    try {
        isLoading.value = true;
        const { data } = await api.get("/widgets/catalog");
        families.value = data;
    } catch (err) {
        console.error(err);
        error.value = "Не удалось загрузить каталог виджетов";
    } finally {
        isLoading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="page-body">
        <div class="container-xl">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <div>
                    <h2 class="page-title mb-1">Все виджеты</h2>
                    <div class="text-secondary">
                        Каталог платформы на вымышленных данных: {{ totals.families }} семейств,
                        {{ totals.types }} вариантов отрисовки
                    </div>
                </div>

                <label class="form-check form-switch m-0">
                    <input v-model="showAiOnly" class="form-check-input" type="checkbox">
                    <span class="form-check-label">Только доступные ИИ</span>
                </label>
            </div>

            <div v-if="isLoading" class="text-secondary py-5 text-center">Загрузка каталога…</div>
            <div v-else-if="error" class="alert alert-danger">{{ error }}</div>

            <div v-else>
                <div v-for="family in visibleFamilies" :key="family.name" class="mb-5">
                    <div class="border-bottom pb-2 mb-3">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h3 class="m-0">{{ family.name }}</h3>
                            <span class="badge bg-blue-lt">{{ family.types.length }} тип(ов)</span>
                            <span v-if="!family.is_ai_selectable" class="badge bg-yellow-lt">
                                не предлагается ИИ
                            </span>
                            <span v-if="!isRenderable(family.name)" class="badge bg-red-lt">
                                нет компонента на фронте
                            </span>
                        </div>
                        <div class="text-secondary small mt-1">{{ family.description }}</div>
                    </div>

                    <div v-if="!isRenderable(family.name)" class="alert alert-warning">
                        Семейство есть в каталоге, но фронт его не рисует — превью недоступно.
                    </div>

                    <div v-else class="row g-4">
                        <div
                            v-for="type in family.types"
                            :key="type.name"
                            class="col-12 col-xl-6"
                        >
                            <div class="d-flex align-items-baseline gap-2 mb-2">
                                <strong>{{ type.title || type.name }}</strong>
                                <code class="small">{{ type.name }}</code>
                                <span v-if="type.is_default" class="badge bg-green-lt">по умолчанию</span>
                                <span v-if="type.scheme" class="badge bg-purple-lt">своя форма данных</span>
                                <span v-if="!type.is_ai_selectable" class="badge bg-yellow-lt">скрыт от ИИ</span>
                            </div>

                            <div class="text-secondary small mb-2">{{ type.description }}</div>

                            <component
                                :is="familyOf(family.name).component"
                                v-bind="previewProps(family.name, type)"
                            />
                        </div>
                    </div>
                </div>

                <div v-if="!visibleFamilies.length" class="text-secondary py-5 text-center">
                    Каталог пуст — запустите сидеры виджетов.
                </div>
            </div>
        </div>
    </div>
</template>
