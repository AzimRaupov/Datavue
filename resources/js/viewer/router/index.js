import { createRouter, createWebHistory } from 'vue-router';
import RegisterPage from '../pages/RegisterPage.vue';
import LoginPage from '../pages/LoginPage.vue';
import HomePage from "../pages/HomePage.vue";
import AboutPage from "../pages/AboutPage.vue";

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
        },
        {
            path: '/',
            name: 'home',
            component: HomePage
        },
        {
            path: '/about',
            name: 'about',
            component: AboutPage
        }
    ],
});

export default router;
