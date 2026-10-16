import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import HomeView from '@/views/HomeView.vue';
import { http } from '@/api/http';

vi.mock('@/api/http', () => ({ http: { get: vi.fn() } }));

describe('HomeView', () => {
    it('shows success when the health endpoint returns ok', async () => {
        http.get.mockResolvedValue({ data: { status: 'ok' } });
        const wrapper = mount(HomeView);
        await flushPromises();

        expect(wrapper.text()).toContain('سليم');
    });

    it('does not show success when the request fails', async () => {
        http.get.mockRejectedValue(new Error('network'));
        const wrapper = mount(HomeView);
        await flushPromises();

        expect(wrapper.text()).not.toContain('سليم');
        expect(wrapper.text()).not.toContain('جارٍ');
    });
});
