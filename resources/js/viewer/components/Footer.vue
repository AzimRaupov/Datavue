<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const year = new Date().getFullYear();
</script>

<template>
    <footer class="dv-footer">
        <div class="container">
            <div class="row align-items-center g-3">
                <div class="col-lg-4 text-center text-lg-start">
                    <router-link to="/" aria-label="Datavue">
                        <img :src="'/logos/logo.png'" width="110" alt="Datavue" />
                    </router-link>
                </div>
                <div class="col-lg-4">
                    <ul class="list-inline list-inline-dots mb-0 text-center">
                        <li class="list-inline-item"><router-link to="/terms">{{ t('header.terms') }}</router-link></li>
                        <li class="list-inline-item"><router-link to="/privacy">{{ t('header.privacy') }}</router-link></li>
                        <li class="list-inline-item"><router-link to="/ai-consent">{{ t('footer.ai_consent') }}</router-link></li>
                    </ul>
                </div>
                <div class="col-lg-4 text-center text-lg-end text-secondary">
                    &copy; {{ year }} Datavue
                </div>
            </div>
        </div>
    </footer>
</template>
