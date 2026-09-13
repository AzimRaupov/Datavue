<template>
    <teleport to="body">
        <div class="toast-stack">
            <transition-group name="toast-fade">
                <div
                    v-for="item in toasts"
                    :key="item.id"
                    class="toast-item"
                    :class="`toast-item--${item.type}`"
                >
                    <div class="toast-item__icon">
                        <svg v-if="item.type === 'success'" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12l5 5l10 -10" />
                        </svg>
                        <svg v-else-if="item.type === 'error'" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="9" />
                            <path d="M10 10l4 4m0 -4l-4 4" />
                        </svg>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="9" />
                            <line x1="12" y1="8" x2="12.01" y2="8" />
                            <polyline points="11 12 12 12 12 16 13 16" />
                        </svg>
                    </div>

                    <div class="toast-item__message">{{ item.message }}</div>

                    <button type="button" class="toast-item__close" @click="remove(item.id)" :aria-label="t('toast.close')">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 6l-12 12" />
                            <path d="M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </transition-group>
        </div>
    </teleport>
</template>

<script setup>
import { reactive } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const toasts = reactive([]);
let idCounter = 0;

function push(message, type = 'success', duration = 4000) {
    const id = ++idCounter;
    toasts.push({ id, message, type });

    if (duration > 0) {
        setTimeout(() => remove(id), duration);
    }
    return id;
}

function remove(id) {
    const index = toasts.findIndex((item) => item.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

function success(message, duration) {
    return push(message, 'success', duration);
}

function error(message, duration) {
    return push(message, 'error', duration);
}

function info(message, duration) {
    return push(message, 'info', duration);
}

defineExpose({ success, error, info, remove });
</script>

<style scoped>
.toast-stack {
    position: fixed;
    top: 16px;
    right: 16px;
    z-index: 10500;
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: 340px;
    max-width: calc(100vw - 32px);
    pointer-events: none;
}

/*
 * Цвета берутся из переменных Tabler, а не задаются напрямую: раньше здесь
 * стояли #fff, #182433 и #8a97a8, из-за чего в тёмной теме всплывающие
 * сообщения оставались светлыми с почти нечитаемым текстом. Через переменные
 * они следуют за настройками темы, включая выбранный основной цвет и радиус.
 */
.toast-item {
    pointer-events: auto;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 14px;
    border-radius: var(--tblr-border-radius);
    background: var(--tblr-bg-surface);
    color: var(--tblr-body-color);
    box-shadow: var(--tblr-box-shadow);
    border: var(--tblr-border-width) solid var(--tblr-border-color);
    border-left: 4px solid var(--tblr-border-color);
}

.toast-item--success { border-left-color: var(--tblr-success); }
.toast-item--success .toast-item__icon { color: var(--tblr-success); }

.toast-item--error { border-left-color: var(--tblr-danger); }
.toast-item--error .toast-item__icon { color: var(--tblr-danger); }

.toast-item--info { border-left-color: var(--tblr-info); }
.toast-item--info .toast-item__icon { color: var(--tblr-info); }

.toast-item__icon {
    flex-shrink: 0;
    margin-top: 1px;
}

.toast-item__message {
    flex: 1;
    font-size: var(--tblr-body-font-size);
    line-height: var(--tblr-body-line-height);
    color: var(--tblr-body-color);
    word-break: break-word;
}

.toast-item__close {
    flex-shrink: 0;
    background: none;
    border: none;
    color: var(--tblr-secondary);
    cursor: pointer;
    padding: 2px;
    line-height: 0;
    border-radius: var(--tblr-border-radius);
}
.toast-item__close:hover {
    color: var(--tblr-body-color);
    background: var(--tblr-bg-surface-secondary);
}

.toast-fade-enter-active,
.toast-fade-leave-active {
    transition: all 0.25s ease;
}
.toast-fade-enter-from {
    opacity: 0;
    transform: translateX(30px);
}
.toast-fade-leave-to {
    opacity: 0;
    transform: translateX(30px);
}
</style>
