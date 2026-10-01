<template>
    <main id="content">
        <section class="py-5">
            <div class="container">
                <div class="mb-4">
                    <h1>{{ t('privacy.page_title') }}</h1>
                    <p class="text-secondary">{{ t('privacy.intro') }}</p>
                </div>

                <h4>{{ t('privacy.point1_title') }}</h4>
                <p class="text-secondary">{{ t('privacy.point1_text') }}</p>

                <h4 class="mt-4">{{ t('privacy.point2_title') }}</h4>
                <p class="text-secondary">{{ t('privacy.point2_text') }}</p>

                <h4 class="mt-4">{{ t('privacy.point3_title') }}</h4>
                <p class="text-secondary">{{ t('privacy.point3_text') }}</p>

                <h4 class="mt-4">{{ t('privacy.point4_title') }}</h4>
                <p class="text-secondary">{{ t('privacy.point4_text') }}</p>

                <h4 class="mt-4">{{ t('privacy.point5_title') }}</h4>
                <p class="text-secondary">{{ t('privacy.point5_text') }}</p>

                <hr class="my-4" />
                <p class="text-secondary mb-0">{{ t('privacy.footer_note') }}</p>
            </div>
        </section>
    </main>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>
