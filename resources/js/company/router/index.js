import { createRouter, createWebHistory } from 'vue-router';
import NProgress from 'nprogress';
import 'nprogress/nprogress.css';

import DashboardPage from '../pages/DashboardPage.vue';
import ProjectDashboardShow from '../pages/project/dashboard/ShowPage.vue';
import ChatPage from '../pages/ChatPage.vue';
import Profile from "../pages/settings/Profile.vue";
import Users from "../pages/settings/Users.vue";
import AllWidgets from "../pages/AllWidgets.vue";
const routes = [
    {
        path: '/',
        name: 'company.dashboard',
        component: DashboardPage,
    },
    {
        path: '/chat/:id/:dashboard?',
        name: 'company.chat',
        component: ChatPage,
    },
    {
        path:'/settings/profile',
        name: 'settings.profile',
        component: Profile
    },
    {
        path:'/settings/users',
        name: 'settings.users',
        component: Users
    },
    {
        path:'/project/dashboard/:dashboard',
        name: 'project.dashboard.show',
        component: ProjectDashboardShow
    },
    {
        path: '/widgets',
        name: 'company.widgets',
        component: AllWidgets
    }

];

const router = createRouter({

    history: createWebHistory('/company'),
    routes,
});

router.beforeEach((to, from, next) => {
    NProgress.start();
    next();
});

// Остановка после перехода
router.afterEach(() => {
    NProgress.done();
});

export default router;
