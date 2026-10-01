import { createRouter, createWebHistory } from 'vue-router';
import RegisterPage from '../pages/RegisterPage.vue';
import LoginPage from '../pages/LoginPage.vue';
import HomePage from "../pages/HomePage.vue";
import AiConsentPage from "../pages/AiConsentPage.vue";
import TermsPage from "../pages/TermsPage.vue";
import PrivacyPage from "../pages/PrivacyPage.vue";

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
        }
    ],
});

export default router;
