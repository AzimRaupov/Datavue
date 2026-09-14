<template>
    <main id="content">
        <section class="py-5">
            <div class="container">
                <div class="mb-4">
                    <h1>{{ t('consent.page_title') }}</h1>
                </div>

                <h4>{{ t('consent.section1_title') }}</h4>
                <p class="text-secondary">{{ t('consent.section1_text') }}</p>

                <h4 class="mt-4">{{ t('consent.section2_title') }}</h4>
                <p class="text-secondary">{{ t('consent.section2_text') }}</p>

                <hr class="my-4" />
                <p class="text-secondary mb-0">{{ t('consent.footer_note') }}</p>
            </div>
        </section>
    </main>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>
