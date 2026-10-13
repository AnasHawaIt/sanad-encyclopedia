import axios from 'axios';

export const http = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json' },
});
