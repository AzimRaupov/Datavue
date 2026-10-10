<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import TablerIcon from './TablerIcon.vue'

/*
 * Юридические страницы (условия, конфиденциальность, согласие на ИИ) — один
 * шаблон на три страницы. Пункты свёрнуты в аккордеон: на экране только
 * заголовки, а полный текст открывается по клику.
 */
const props = defineProps({
    ns: { type: String, required: true },
    icon: { type: String, required: true },
    prefix: { type: String, default: 'point' },
    count: { type: Number, default: 5 },
})

const { t, te } = useI18n()

const items = computed(() =>
    Array.from({ length: props.count }, (_, i) => ({
        id: `${props.ns}-${i + 1}`,
        title: t(`${props.ns}.${props.prefix}${i + 1}_title`),
        text: t(`${props.ns}.${props.prefix}${i + 1}_text`),
    }))
)

const tabs = [
    { to: '/terms', ns: 'terms', label: 'header.terms' },
    { to: '/privacy', ns: 'privacy', label: 'header.privacy' },
    { to: '/ai-consent', ns: 'consent', label: 'footer.ai_consent' },
]
</script>

<template>
    <main id="content" class="dv-legal">
        <div class="container container-narrow">
            <div class="dv-legal-head">
                <span class="dv-legal-icon bg-primary-lt text-primary"><TablerIcon :name="icon" /></span>
                <h1>{{ t(`${ns}.page_title`) }}</h1>
                <p v-if="te(`${ns}.intro`)" class="text-secondary mt-3 mb-0">{{ t(`${ns}.intro`) }}</p>

                <div class="dv-legal-tabs">
                    <router-link v-for="tab in tabs" :key="tab.ns" :to="tab.to" class="dv-pill" :class="{ 'is-active': tab.ns === ns }">
                        {{ t(tab.label) }}
                    </router-link>
                </div>
            </div>

            <div :id="`${ns}-accordion`" class="accordion pb-5">
                <div v-for="item in items" :key="item.id" class="accordion-item">
                    <h2 class="accordion-header">
                        <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            :data-bs-target="`#${item.id}`"
                            aria-expanded="false"
                            :aria-controls="item.id"
                        >
                            {{ item.title }}
                        </button>
                    </h2>
                    <div :id="item.id" class="accordion-collapse collapse" :data-bs-parent="`#${ns}-accordion`">
                        <div class="accordion-body text-secondary">{{ item.text }}</div>
                    </div>
                </div>

                <p class="dv-legal-note">{{ t(`${ns}.footer_note`) }}</p>
            </div>
        </div>
    </main>
</template>
