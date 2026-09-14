<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const year = new Date().getFullYear();
</script>

<template>
    <footer class="footer footer-transparent">
        <div class="container">
            <div class="py-3 text-center text-secondary">
                &copy; {{ year }} Datavue. {{ t('footer.rights') }}
            </div>
        </div>
    </footer>
</template>
