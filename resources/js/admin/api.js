import axios from 'axios';
import { API_URL } from '../shared/server.js';


const api = axios.create({
    baseURL: `${API_URL}/api/admin/`,
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

export default api;
