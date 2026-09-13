import { createRouter, createWebHistory } from 'vue-router';
import NProgress from 'nprogress';
import 'nprogress/nprogress.css';

import DashboardPage from '../pages/DashboardPage.vue';
import DashboardsIndex from '../pages/dashboards/IndexPage.vue';
import WorkspacesIndex from '../pages/workspace/IndexPage.vue';
import WorkspacePage from '../pages/workspace/WorkspacePage.vue';
import Profile from "../pages/settings/Profile.vue";
import Users from "../pages/settings/Users.vue";
import UserForm from "../pages/settings/UserForm.vue";
import AllWidgets from "../pages/AllWidgets.vue";
import SourcesIndex from "../pages/sources/IndexPage.vue";
import SourceShow from "../pages/sources/ShowPage.vue";
import SourceCreate from "../pages/sources/CreatePage.vue";
import ChatsIndex from "../pages/chats/IndexPage.vue";
import AlertForm from "../pages/alerts/AlertForm.vue";
import DirectorPage from "../pages/director/IndexPage.vue";

const routes = [
    {
        path: '/',
        name: 'company.dashboard',
        component: DashboardPage,
    },
    // Все дашборды компании: и собранные руками, и выросшие из разговора.
    {
        path: '/dashboards',
        name: 'company.dashboards',
        component: DashboardsIndex,
    },
    // Источники данных — точка входа в работу: сначала подключается источник,
    // и уже на нём заводятся пространства.
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

    // Чаты — отдельная страница: список того, что уже есть, и создание
    // нового (заводит пространство и сразу готовит варианты дашбордов —
    // тот же процесс, что раньше жил на странице источников).
    {
        path: '/chats',
        name: 'company.chats',
        component: ChatsIndex,
    },

    /*
    | Рабочие пространства.
    |
    | Пространство — задача: свой источник, свои дашборды и один разговор.
    | Страница у него одна на всё: просмотр, сборка и чат — это состояния
    | (?mode=edit), а не отдельные экраны.
    |
    | Входы по дашборду и по чату объявлены ДО '/:workspace', иначе 'd' и 'chat'
    | попадут в параметр id. Страница сама заменяет такой адрес каноническим.
    */
    {
        path: '/workspaces',
        name: 'company.workspaces',
        component: WorkspacesIndex,
    },
    {
        path: '/workspace/d/:dashboard(\\d+)',
        name: 'company.workspace.dashboard',
        component: WorkspacePage,
    },
    // Алерты пространства — отдельная страница-форма, как и заведение
    // сотрудника: условие занимает много места и заслуживает своей ссылки.
    // Объявлены ДО '/workspace/:workspace/:dashboard?', иначе 'alerts'
    // попал бы в параметр dashboard.
    {
        path: '/workspace/:workspace(\\d+)/alerts/new',
        name: 'company.alert.create',
        component: AlertForm,
    },
    {
        path: '/workspace/:workspace(\\d+)/alerts/:alert(\\d+)',
        name: 'company.alert.edit',
        component: AlertForm,
    },
    {
        path: '/workspace/chat/:chat(\\d+)',
        name: 'company.workspace.chat',
        component: WorkspacePage,
    },
    {
        path: '/workspace/:workspace(\\d+)/:dashboard(\\d+)?',
        name: 'company.workspace',
        component: WorkspacePage,
    },

    /*
    | Прежние адреса. Оставлены редиректами: на них ведут закладки, ссылки
    | из писем и старые вкладки, и приводить человека на «страница не найдена»
    | из-за перепланировки интерфейса неправильно.
    */
    {
        path: '/chat/:id(\\d+)/:dashboard(\\d+)?',
        name: 'company.chat',
        redirect: (to) => (
            to.params.dashboard
                ? { name: 'company.workspace.dashboard', params: { dashboard: to.params.dashboard } }
                : { name: 'company.workspace.chat', params: { chat: to.params.id } }
        ),
    },
    {
        path: '/project/dashboard/:dashboard(\\d+)',
        name: 'project.dashboard.show',
        redirect: (to) => ({
            name: 'company.workspace.dashboard',
            params: { dashboard: to.params.dashboard },
        }),
    },
    {
        path: '/project/dashboard/:dashboard(\\d+)/edit',
        name: 'company.dashboard.edit',
        redirect: (to) => ({
            name: 'company.workspace.dashboard',
            params: { dashboard: to.params.dashboard },
            query: { mode: 'edit' },
        }),
    },

    {
        path: '/settings/profile',
        name: 'settings.profile',
        component: Profile
    },
    {
        path: '/settings/users',
        name: 'settings.users',
        component: Users
    },
    // Заведение и правка сотрудника — отдельные адреса, а не окно поверх
    // списка: у формы доступа полтора десятка переключателей, и на неё
    // должна работать ссылка и кнопка «назад».
    {
        path: '/settings/users/new',
        name: 'settings.users.create',
        component: UserForm
    },
    {
        path: '/settings/users/:id(\\d+)/edit',
        name: 'settings.users.edit',
        component: UserForm
    },
    {
        path: '/widgets',
        name: 'company.widgets',
        component: AllWidgets
    },

    /*
    | Директор: единственный экран роли — чат с историей, как в обычных
    | ИИ-приложениях. Конструктор, источники и настройки ему не показываются
    | (см. guard ниже), генерация/перегенерация/экспорт работают через тот же
    | чат, что и у остальных ролей.
    */
    {
        path: '/director',
        name: 'director.home',
        component: DirectorPage,
    },
    {
        path: '/director/:chat(\\d+)',
        name: 'director.chat',
        component: DirectorPage,
    },
];

/**
 * Роль читаем прямо из localStorage, как и остальные компоненты (Header.vue,
 * WorkspacePage.vue) — в приложении нет отдельного стора пользователя.
 */
function currentRoles() {
    try {
        return JSON.parse(localStorage.getItem('user') || 'null')?.roles ?? [];
    } catch {
        return [];
    }
}

// У директора нет прав ни на что, кроме чатов и просмотра дашбордов —
// конструктор/источники/сотрудники ему всё равно ответят 403. Редирект здесь
// чисто ради интерфейса: чтобы старая закладка или прямой адрес не показывали
// пустую страницу с ошибкой, а сразу уводили в его единственный экран.
const DIRECTOR_ALLOWED_ROUTES = new Set(['director.home', 'director.chat', 'settings.profile']);

const router = createRouter({

    history: createWebHistory('/company'),
    routes,
});

router.beforeEach((to, from, next) => {
    NProgress.start();

    if (currentRoles().includes('director') && !DIRECTOR_ALLOWED_ROUTES.has(to.name)) {
        next({ name: 'director.home' });
        return;
    }

    next();
});

// Остановка после перехода
router.afterEach(() => {
    NProgress.done();
});

export default router;
