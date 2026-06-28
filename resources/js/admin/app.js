import {createApp} from "vue";
import App from './components/App.vue'
import router from './router';
import '@tabler/core/dist/css/tabler.min.css'
import '@tabler/core/dist/js/tabler.min.js'
const app= createApp(App);

app.use(router);

app.mount('#app');
