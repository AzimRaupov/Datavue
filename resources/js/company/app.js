import {createApp} from "vue";
import App from './components/App.vue'
import router from './router';
import '@tabler/core/dist/css/tabler.min.css'
import '@tabler/core/dist/js/tabler.min.js'
const app= createApp(App);

app.use(router);

// Экспортируем роутер в `window` для удобства отладки в консоли браузера
window.companyRouter = router;
console.log('Company router routes:', router.getRoutes());

app.mount('#app');
