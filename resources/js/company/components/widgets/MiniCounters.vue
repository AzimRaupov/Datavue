<template>
    <div class="row">
        <div
            v-for="(counter, index) in props.counters.counters"
            :key="index"
            class="col-12 col-md-6 col-xxl-3 mb-4 mb-xxl-0"
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
                            <span class="avatar avatar-lg">
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
                                    class="icon avatar-icon icon-2"
                                >
                                    <path d="M3 8a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3l0 -8" />
                                    <path d="M3 10l18 0" />
                                    <path d="M7 15l.01 0" />
                                    <path d="M11 15l2 0" />
                                </svg>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
const props = defineProps({
    // Меняем тип на Object, так как это прокси-объект с сервера
    counters: {
        type: Object,
        default: () => ({ counters: [] })
    },
})
</script>
