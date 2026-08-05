<template>
    <div class="row g-2 row-cols-1 row-cols-md-2 row-cols-xxl-5">
        <div
            v-for="(counter, index) in props.counters.counters"
            :key="index"
            class="col"
        >
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="subheader mb-1">{{ counter.name }}</h4>

                            <div class="h3 m-0">
                                {{ counter.prefix }}{{ counter.value }}{{ counter.suffix }}
                            </div>
                        </div>

                        <div class="col-auto">
                        <span
                            class="avatar avatar-lx cursor-pointer"
                            :class="copiedIndex === index ? 'bg-success-lt text-success' : ''"
                            :title="copiedIndex === index ? 'Скопировано' : 'Копировать значение'"
                            @click="copyValue(counter, index)"
                        >
<svg
    xmlns="http://www.w3.org/2000/svg"
    width="24"
    height="24"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
    class="icon icon-1"
>
                          <path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" />
                          <path d="M9 5a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2" /></svg
>
                        </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div></template>

<script setup>
import { ref } from "vue";

const props = defineProps({
    // Меняем тип на Object, так как это прокси-объект с сервера
    counters: {
        type: Object,
        default: () => ({ counters: [] })
    },
})

const copiedIndex = ref(null);
let resetTimer = null;

async function copyValue(counter, index) {
    const textToCopy = `${counter.prefix ?? ""}${counter.value}${counter.suffix ?? ""}`;

    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(textToCopy);
        } else {
            // Фолбэк для старых браузеров / незащищённого контекста (http)
            const textarea = document.createElement("textarea");
            textarea.value = textToCopy;
            textarea.style.position = "fixed";
            textarea.style.opacity = "0";
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            document.execCommand("copy");
            document.body.removeChild(textarea);
        }

        copiedIndex.value = index;

        if (resetTimer) clearTimeout(resetTimer);
        resetTimer = setTimeout(() => {
            copiedIndex.value = null;
        }, 1500);

    } catch (err) {
        console.error("Не удалось скопировать значение:", err);
    }
}
</script>

<style scoped>
.cursor-pointer {
    cursor: pointer;
}
</style>
