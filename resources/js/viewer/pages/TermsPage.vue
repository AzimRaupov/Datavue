<template>
    <main id="content">
        <section class="py-5">
            <div class="container">
                <div class="mb-4">
                    <h1>{{ t('terms.page_title') }}</h1>
                    <p class="text-secondary">{{ t('terms.intro') }}</p>
                </div>

                <h4>{{ t('terms.point1_title') }}</h4>
                <p class="text-secondary">{{ t('terms.point1_text') }}</p>

                <h4 class="mt-4">{{ t('terms.point2_title') }}</h4>
                <p class="text-secondary">{{ t('terms.point2_text') }}</p>

                <h4 class="mt-4">{{ t('terms.point3_title') }}</h4>
                <p class="text-secondary">{{ t('terms.point3_text') }}</p>

                <h4 class="mt-4">{{ t('terms.point4_title') }}</h4>
                <p class="text-secondary">{{ t('terms.point4_text') }}</p>

                <h4 class="mt-4">{{ t('terms.point5_title') }}</h4>
                <p class="text-secondary">{{ t('terms.point5_text') }}</p>

                <hr class="my-4" />
                <p class="text-secondary mb-0">{{ t('terms.footer_note') }}</p>
            </div>
        </section>
    </main>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>
