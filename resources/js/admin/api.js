import axios from 'axios';


const api = axios.create({
    baseURL: '/api/admin/',
    headers: {
        'Accept': 'application/json',
    },
});


api.interceptors.request.use((config)=>{
        const token= localStorage.getItem('token');

        if(token){
            config.headers.Athorization=`Baerer ${token}`;
        }

        return config;

    }

);

export default api;
