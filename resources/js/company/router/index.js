import { createRouter, createWebHistory } from 'vue-router';
import NProgress from 'nprogress';
import 'nprogress/nprogress.css';

import DashboardPage from '../pages/DashboardPage.vue';
import ProjectDashboardShow from '../pages/project/dashboard/ShowPage.vue';
import ChatPage from '../pages/ChatPage.vue';
import Profile from "../pages/settings/Profile.vue";
import Users from "../pages/settings/Users.vue";
import AllWidgets from "../pages/AllWidgets.vue";
import SourcesIndex from "../pages/sources/IndexPage.vue";
import SourceShow from "../pages/sources/ShowPage.vue";
import SourceCreate from "../pages/sources/CreatePage.vue";
const routes = [
    {
        path: '/',
        name: 'company.dashboard',
        component: DashboardPage,
    },
    // Источники данных — точка входа в работу: сначала подключается источник,
    // и уже на нём заводятся чаты.
    {
        path: '/sources',
        name: 'company.sources',
        component: SourcesIndex,
    },
    // Подключение источника — отдельная страница-мастер, а не модалка:
    // шагов три, и на среднем идёт долгая работа на бэкенде.
    // Объявлен ДО '/sources/:id', иначе 'create' попадёт в параметр id.
    {
        path: '/sources/create',
        name: 'company.source.create',
        component: SourceCreate,
    },
    {
        path: '/sources/:id',
        name: 'company.source.show',
        component: SourceShow,
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
