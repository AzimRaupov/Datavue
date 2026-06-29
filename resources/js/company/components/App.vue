<script setup>
import Header from "./Header.vue";
</script>

<template>
    <div class="page">
        <Header />

        <div class="page-wrapper">

            <main id="content" class="page-body">
                <div class="container-lg">
                    <router-view />
                </div>
            </main>
        </div>
    </div>
</template>
