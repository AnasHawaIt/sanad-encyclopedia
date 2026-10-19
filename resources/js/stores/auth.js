import { defineStore } from 'pinia';
import { http } from '@/api/http';

export const useAuthStore = defineStore('auth', {
    state: () => ({ user: null, permissions: [], loaded: false }),
    getters: {
        isAuthenticated: (state) => state.user !== null,
    },
    actions: {
        can(permission) {
            return this.permissions.includes(permission);
        },
        apply(payload) {
            this.user = payload;
            this.permissions = payload?.permissions ?? [];
        },
        async load() {
            try {
                const { data } = await http.get('/auth/me');
                this.apply(data.data);
            } catch {
                this.apply(null);
            } finally {
                this.loaded = true;
            }
        },
        async login(email, password) {
            await http.get('/sanctum/csrf-cookie', { baseURL: '/' });
            const { data } = await http.post('/auth/login', { email, password });
            this.apply(data.data);
            this.loaded = true;
        },
        async logout() {
            try {
                await http.post('/auth/logout');
            } finally {
                this.apply(null);
            }
        },
    },
});
