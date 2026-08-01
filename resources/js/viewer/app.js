import { createApp } from 'vue';
import i18n from './i18n.js';

import App from './components/App.vue';
import router from './router';

import '@tabler/core/dist/css/tabler.css';
import '@tabler/core/dist/css/tabler-themes.css';
import '@tabler/core/dist/css/tabler-themes.rtl.css';
import '@tabler/core/dist/css/tabler-vendors.css';
import '@tabler/core/dist/css/tabler-vendors.rtl.css';


import '@tabler/core/js/tabler.js';
import '@tabler/core/js/tabler-theme.js';


import '@tabler/core/dist/css/tabler-marketing.css'
const app = createApp(App);

app.use(router);
app.use(i18n);

window.viewerRouter = router;

console.log('Viewer router routes:', router.getRoutes());

app.mount('#app');
