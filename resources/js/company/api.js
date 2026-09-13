import axios from 'axios';


const api = axios.create({
    baseURL: '/api/company',
    headers: {
        'Accept': 'application/json',
    },
});


api.interceptors.request.use((config)=>{
        const token= localStorage.getItem('token');

        if (token) {
            config.headers.Authorization = `Bearer ${token}`;
        }

        return config;

    }

);

/**
 * Токен может истечь посреди работы, не только при открытии приложения.
 * В WebView без адресной строки застрять на сломанном экране особенно
 * неприятно — поэтому любой 401 от API компании сразу отправляет на вход.
 */
api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            localStorage.removeItem('token');
            localStorage.removeItem('user');
            window.location.href = '/login';
        }

        return Promise.reject(error);
    }
);

export default api;
