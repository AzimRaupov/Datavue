import { createRouter, createWebHistory } from 'vue-router';
import BookPage from '../pages/BookPage.vue';
import CategoryPage from '../pages/CategoryPage.vue';

const router = createRouter({
    history: createWebHistory('/admin'),
    routes: [
        {
            path: '/books',
            name: 'books',
            component: BookPage,
        },
        {
            path: '/categories',
            name: 'categories',
            component: CategoryPage
        }
    ],
});

export default router;
