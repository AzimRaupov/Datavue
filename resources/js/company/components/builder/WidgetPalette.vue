<script setup>
import { ref, computed, onMounted } from "vue";
import api from "../../api.js";

/**
 * Палитра семейств виджетов для конструктора.
 *
 * Каталог берётся из базы тем же запросом, что и галерея, — добавили семейство
 * в сидере, оно появилось здесь само. Параметр ai_only не передаём намеренно:
 * человеку доступны и те виджеты, которые пока скрыты от модели.
 */

defineProps({
    // Внутри выдвижной панели у каталога уже есть рамка и заголовок —
    // вторые такие же только съедают место.
    embedded: { type: Boolean, default: false },
});

const emit = defineEmits(["add"]);

const families = ref([]);
const isLoading = ref(true);
const error = ref(null);
const search = ref("");

const visible = computed(() => {
    const needle = search.value.trim().toLowerCase();

    if (!needle) return families.value;

    return families.value.filter(
        (family) =>
            (family.name ?? "").toLowerCase().includes(needle) ||
            (family.description ?? "").toLowerCase().includes(needle)
    );
});

function defaultTypeId(family) {
    const types = family.types ?? [];

    return (types.find((type) => type.is_default) ?? types[0])?.id ?? null;
}

function add(family) {
    emit("add", {
        widget_id: family.id,
        widget_type_id: defaultTypeId(family),
        family,
    });
}

onMounted(async () => {
    try {
        const { data } = await api.get("/widgets/catalog");
        families.value = data ?? [];
    } catch (err) {
        error.value = "Не удалось загрузить каталог виджетов.";
    } finally {
        isLoading.value = false;
    }
});
</script>

<template>
    <div :class="embedded ? '' : 'card'">
        <div v-if="!embedded" class="card-header">
            <h3 class="card-title">Виджеты</h3>
        </div>

        <div class="card-body p-2 border-bottom" :class="{ 'px-0': embedded }">
            <input
                v-model="search"
                type="search"
                class="form-control form-control-sm"
                placeholder="Поиск по каталогу"
                aria-label="Поиск виджета"
            />
        </div>

        <div v-if="isLoading" class="card-body">
            <div class="progress progress-sm">
                <div class="progress-bar progress-bar-indeterminate"></div>
            </div>
        </div>

        <div v-else-if="error" class="card-body">
            <div class="alert alert-danger mb-0">{{ error }}</div>
        </div>

        <div v-else class="list-group list-group-flush builder-palette"
             :class="{ 'builder-palette--embedded': embedded }">
            <button
                v-for="family in visible"
                :key="family.id"
                type="button"
                class="list-group-item list-group-item-action text-start"
                @click="add(family)"
            >
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-bold text-truncate">{{ family.name }}</span>
                    <span class="badge bg-secondary-lt ms-auto">{{ (family.types ?? []).length }}</span>
                </div>
                <div class="text-secondary small builder-palette__description">
                    {{ family.description }}
                </div>
            </button>

            <div v-if="!visible.length" class="list-group-item text-secondary">
                Ничего не найдено.
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Каталог длиннее экрана, но прокручиваться должен он, а не вся страница. */
.builder-palette {
    max-height: 60vh;
    overflow-y: auto;
}

/* В выдвижной панели прокрутка своя — ограничивать высоту второй раз незачем. */
.builder-palette--embedded {
    max-height: none;
    overflow-y: visible;
}

/* Описание семейства — подсказка, а не текст для чтения: две строки хватает,
   чтобы отличить bar от combo, и список остаётся обозримым. */
.builder-palette__description {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
