<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
    <main id="content" class="container py-6 text-center">
        <div class="display-1 fw-bold text-primary">404</div>
        <p class="h2 mb-4">{{ t('notfound.title') }}</p>
        <router-link to="/" class="btn btn-primary">{{ t('notfound.back') }}</router-link>
    </main>
</template>
