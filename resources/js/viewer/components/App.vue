<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'

import Header from './Header.vue'
import Footer from './Footer.vue'

const route = useRoute()

const isAuthPage = computed(() => {
    return [
        '/login',
        '/register',
    ].includes(route.path)
})
</script>

<template>
    <Header v-if="!isAuthPage" />

    <main>
        <router-view />
    </main>

    <Footer v-if="!isAuthPage" />
</template>
