import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/api/http', () => ({ http: { get: vi.fn(), post: vi.fn() } }));
import { http } from '@/api/http';
import { useAuthStore } from '@/stores/auth';
import { guard } from '@/router';

describe('auth store and route guard', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('login fetches the csrf cookie first and keeps permissions', async () => {
        http.get.mockResolvedValue({});
        http.post.mockResolvedValue({ data: { data: { name: 'أ', permissions: ['asnads.view'] } } });
        const auth = useAuthStore();

        await auth.login('a@b.c', 'x');

        expect(http.get).toHaveBeenCalledWith('/sanctum/csrf-cookie', { baseURL: '/' });
        expect(auth.isAuthenticated).toBe(true);
        expect(auth.can('asnads.view')).toBe(true);
        expect(auth.can('asnads.update')).toBe(false);
    });

    it('a failed login leaves the user logged out', async () => {
        http.get.mockResolvedValue({});
        http.post.mockRejectedValue({ response: { status: 422 } });
        const auth = useAuthStore();

        await expect(auth.login('a@b.c', 'bad')).rejects.toBeDefined();
        expect(auth.isAuthenticated).toBe(false);
    });

    it('load treats 401 as a guest', async () => {
        http.get.mockRejectedValue({ response: { status: 401 } });
        const auth = useAuthStore();

        await auth.load();

        expect(auth.isAuthenticated).toBe(false);
        expect(auth.loaded).toBe(true);
    });

    it('logout clears the state even if the request fails', async () => {
        const auth = useAuthStore();
        auth.apply({ permissions: ['asnads.view'] });
        http.post.mockRejectedValue(new Error('x'));

        await auth.logout().catch(() => {});

        expect(auth.isAuthenticated).toBe(false);
        expect(auth.permissions).toEqual([]);
    });

    it('guard sends guests to login with the redirect, and blocks missing permissions', async () => {
        const auth = useAuthStore();
        auth.loaded = true;
        const go = guard(auth);
        const target = { meta: { auth: true, permission: 'asnads.view' }, fullPath: '/asnads/3' };

        expect(await go(target)).toEqual({ name: 'login', query: { redirect: '/asnads/3' } });

        auth.apply({ permissions: [] });
        expect(await go(target)).toEqual({ name: 'home' });

        auth.apply({ permissions: ['asnads.view'] });
        expect(await go(target)).toBe(true);
        expect(await go({ meta: { guest: true }, fullPath: '/login' })).toEqual({ name: 'asnads' });
    });
});
