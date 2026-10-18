<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from "vue";
import { useI18n } from "vue-i18n";

/**
 * Выпадающий список с поиском — вместо нативного <select>, когда вариантов
 * много (таблицы и колонки источника): в длинном списке без поиска нужное
 * приходится выискивать глазами.
 *
 * Выглядит как .form-select и работает с клавиатуры: стрелки, Enter, Esc.
 * Панель вынесена в <body>: шторка настройки виджета двигается через
 * transform и прокручивается, и позиционируемый внутри неё список обрезался бы.
 *
 * options: [{ value, label, hint? }]. hint — серая подпись справа (тип колонки).
 */
const props = defineProps({
    modelValue: { type: [String, Number, null], default: "" },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: "" },
    searchPlaceholder: { type: String, default: "" },
    ariaLabel: { type: String, default: "" },
    disabled: { type: Boolean, default: false },
    small: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue", "change"]);

const { t } = useI18n();

const PANEL_MIN_WIDTH = 220;
const PANEL_MAX_HEIGHT = 300;

const open = ref(false);
const query = ref("");
const activeIndex = ref(0);

const toggle = ref(null);
const panel = ref(null);
const searchInput = ref(null);
const list = ref(null);

const position = ref({ top: 0, left: 0, width: PANEL_MIN_WIDTH, flip: false });

// Панель живёт внутри модалки Bootstrap, если селект стоит в ней: иначе
// ловушка фокуса модалки отбирает фокус у поля поиска, вынесенного в <body>.
const host = ref(null);

const selected = computed(() =>
    props.options.find((option) => String(option.value) === String(props.modelValue ?? "")) ?? null
);

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (!needle) return props.options;

    return props.options.filter((option) =>
        String(option.label).toLowerCase().includes(needle)
        || (option.hint && String(option.hint).toLowerCase().includes(needle))
    );
});

const panelStyle = computed(() => ({
    left: `${position.value.left}px`,
    width: `${position.value.width}px`,
    ...(position.value.flip
        ? { bottom: `${position.value.bottom}px` }
        : { top: `${position.value.top}px` }),
}));

function place() {
    const rect = toggle.value?.getBoundingClientRect();

    if (!rect) return;

    const below = window.innerHeight - rect.bottom;
    const flip = below < PANEL_MAX_HEIGHT && rect.top > below;
    const width = Math.max(rect.width, PANEL_MIN_WIDTH);

    position.value = {
        // Не выходим за правый край окна, когда селект стоит у края шторки.
        left: Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)),
        width,
        flip,
        top: rect.bottom + 4,
        bottom: window.innerHeight - rect.top + 4,
    };
}

function openPanel() {
    if (props.disabled || open.value) return;

    host.value = toggle.value?.closest(".modal") ?? document.body;
    query.value = "";

    const index = filtered.value.findIndex((option) => String(option.value) === String(props.modelValue ?? ""));
    activeIndex.value = Math.max(0, index);

    place();
    open.value = true;

    nextTick(() => {
        searchInput.value?.focus();
        scrollToActive();
    });
}

function closePanel({ refocus = true } = {}) {
    if (!open.value) return;

    open.value = false;

    if (refocus) nextTick(() => toggle.value?.focus());
}

function choose(option) {
    if (!option || option.disabled) return;

    emit("update:modelValue", option.value);
    emit("change", option.value);

    closePanel();
}

function scrollToActive() {
    list.value?.querySelector(".is-active")?.scrollIntoView({ block: "nearest" });
}

function move(step) {
    const total = filtered.value.length;

    if (!total) return;

    activeIndex.value = (activeIndex.value + step + total) % total;

    nextTick(scrollToActive);
}

function onSearchKeydown(event) {
    switch (event.key) {
        case "ArrowDown":
            event.preventDefault();
            move(1);
            break;
        case "ArrowUp":
            event.preventDefault();
            move(-1);
            break;
        case "Enter":
            event.preventDefault();
            choose(filtered.value[activeIndex.value]);
            break;
        case "Escape":
            // Закрываем только список: шторка и модалка по тому же Esc не закрываются.
            event.preventDefault();
            event.stopPropagation();
            closePanel();
            break;
        case "Tab":
            closePanel({ refocus: false });
            break;
    }
}

function onToggleKeydown(event) {
    if (["ArrowDown", "ArrowUp", "Enter", " "].includes(event.key)) {
        event.preventDefault();
        openPanel();
    }
}

function onOutsidePointer(event) {
    if (panel.value?.contains(event.target) || toggle.value?.contains(event.target)) return;

    closePanel({ refocus: false });
}

function onViewportChange(event) {
    // Прокрутка самого списка позицию не меняет.
    if (event?.target && panel.value?.contains(event.target)) return;

    place();
}

watch(query, () => {
    activeIndex.value = 0;
});

watch(open, (value) => {
    if (value) {
        document.addEventListener("pointerdown", onOutsidePointer, true);
        window.addEventListener("resize", onViewportChange);
        window.addEventListener("scroll", onViewportChange, true);
    } else {
        document.removeEventListener("pointerdown", onOutsidePointer, true);
        window.removeEventListener("resize", onViewportChange);
        window.removeEventListener("scroll", onViewportChange, true);
    }
});

// Селект заблокировали при открытой панели (например, сменилась таблица выше).
watch(() => props.disabled, (value) => {
    if (value) closePanel({ refocus: false });
});

onBeforeUnmount(() => {
    document.removeEventListener("pointerdown", onOutsidePointer, true);
    window.removeEventListener("resize", onViewportChange);
    window.removeEventListener("scroll", onViewportChange, true);
});
</script>

<template>
    <div class="search-select">
        <button
            ref="toggle"
            type="button"
            class="form-select search-select-toggle"
            :class="{ 'form-select-sm': small }"
            :disabled="disabled"
            :aria-label="ariaLabel || undefined"
            aria-haspopup="listbox"
            :aria-expanded="open"
            @click="open ? closePanel() : openPanel()"
            @keydown="onToggleKeydown"
        >
            <span v-if="selected" class="search-select-value">{{ selected.label }}</span>
            <span v-else class="search-select-value text-secondary">{{ placeholder }}</span>
        </button>

        <Teleport :to="host ?? 'body'" :disabled="!open">
            <div v-if="open" ref="panel" class="search-select-panel" :style="panelStyle">
                <div class="p-2 border-bottom">
                    <input
                        ref="searchInput"
                        v-model="query"
                        type="search"
                        class="form-control form-control-sm"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="searchPlaceholder || t('searchSelect.search')"
                        :aria-label="searchPlaceholder || t('searchSelect.search')"
                        @keydown="onSearchKeydown"
                    />
                </div>

                <ul ref="list" class="search-select-list" role="listbox">
                    <li
                        v-for="(option, index) in filtered"
                        :key="option.value"
                        role="option"
                        class="search-select-option"
                        :class="{
                            'is-active': index === activeIndex,
                            'is-selected': selected && String(selected.value) === String(option.value),
                            'is-disabled': option.disabled,
                        }"
                        :aria-selected="selected && String(selected.value) === String(option.value)"
                        @mousemove="activeIndex = index"
                        @click="choose(option)"
                    >
                        <span class="search-select-label">{{ option.label }}</span>
                        <span v-if="option.hint" class="search-select-hint">{{ option.hint }}</span>
                    </li>

                    <li v-if="!filtered.length" class="search-select-empty">{{ t("searchSelect.empty") }}</li>
                </ul>
            </div>
        </Teleport>
    </div>
</template>

<style>
/* Не scoped: панель вынесена из корня компонента. */
.search-select {
    position: relative;
}

.search-select-toggle {
    text-align: left;
    overflow: hidden;
    white-space: nowrap;
}

.search-select-value {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
}

.search-select-panel {
    position: fixed;
    z-index: 2000;
    background: var(--tblr-bg-surface, #fff);
    border: 1px solid var(--tblr-border-color, #e6e7e9);
    border-radius: 6px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.14);
    overflow: hidden;
}

.search-select-list {
    list-style: none;
    margin: 0;
    padding: 4px 0;
    max-height: 240px;
    overflow-y: auto;
}

.search-select-option {
    display: flex;
    align-items: baseline;
    gap: 12px;
    padding: 6px 12px;
    cursor: pointer;
}

.search-select-label {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.search-select-hint {
    flex-shrink: 0;
    font-size: 12px;
    color: var(--tblr-secondary, #667382);
}

.search-select-option.is-active {
    background: rgba(var(--tblr-primary-rgb, 32, 107, 196), 0.1);
}

.search-select-option.is-selected .search-select-label {
    font-weight: 600;
    color: var(--tblr-primary, #206bc4);
}

.search-select-option.is-disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.search-select-empty {
    padding: 8px 12px;
    color: var(--tblr-secondary, #667382);
}
</style>
