import { createApp } from "vue";
import App from './components/App.vue';
import router from './router';
import i18n from './i18n.js';

import '@tabler/core/dist/css/tabler.css';
import '@tabler/core/dist/css/tabler-themes.css';
import '@tabler/core/dist/css/tabler-themes.rtl.css';
import '@tabler/core/dist/css/tabler-vendors.css';
import '@tabler/core/dist/css/tabler-vendors.rtl.css';


import '@tabler/core/js/tabler.js';
import '@tabler/core/js/tabler-theme.js';


const app = createApp(App);

app.use(router);
app.use(i18n);

window.companyRouter = router;
console.log('Company router routes:', router.getRoutes());

app.mount('#app');
