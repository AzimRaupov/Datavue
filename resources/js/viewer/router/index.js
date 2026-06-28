import { createRouter, createWebHistory } from 'vue-router';
import RegisterPage from '../pages/RegisterPage.vue';
import LoginPage from '../pages/LoginPage.vue';
const router = createRouter({
    history: createWebHistory('/'),
    routes: [
        {
            path: '/register',
            name: 'register',
            component: RegisterPage,
        },
        {
            path: '/login',
            name: 'login',
            component: LoginPage,
        }
    ],
});

export default router;
