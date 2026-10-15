import { createRouter, createWebHistory } from 'vue-router';
import RegisterPage from '../pages/RegisterPage.vue';
import LoginPage from '../pages/LoginPage.vue';
import HomePage from "../pages/HomePage.vue";
import AiConsentPage from "../pages/AiConsentPage.vue";
import TermsPage from "../pages/TermsPage.vue";
import PrivacyPage from "../pages/PrivacyPage.vue";
import NotFoundPage from "../pages/NotFoundPage.vue";

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
            path: '/ai-consent',
            name: 'ai-consent',
            component: AiConsentPage
        },
        {
            path: '/terms',
            name: 'terms',
            component: TermsPage
        },
        {
            path: '/privacy',
            name: 'privacy',
            component: PrivacyPage
        },
        {
            path: '/:pathMatch(.*)*',
            name: 'not-found',
            component: NotFoundPage
        }
    ],
    // Без этого переход по ссылке из подвала оставлял страницу прокрученной,
    // а якоря шапки (#alerts и т.п.) не работали.
    scrollBehavior(to, from, saved) {
        if (saved) return saved
        if (to.hash) return { el: to.hash, top: 72, behavior: 'smooth' }
        return { top: 0 }
    },
});

export default router;
