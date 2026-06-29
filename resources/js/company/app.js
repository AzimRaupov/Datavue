import { createApp } from "vue";
import App from './components/App.vue';
import router from './router';
import i18n from './i18n.js';

import '@tabler/core/dist/css/tabler.css';
import '@tabler/core/dist/js/tabler.js';

const app = createApp(App);

app.use(router);
app.use(i18n);

window.companyRouter = router;
console.log('Company router routes:', router.getRoutes());

app.mount('#app');
