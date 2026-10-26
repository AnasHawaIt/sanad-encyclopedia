import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/api/exports', async (orig) => ({ ...(await orig()), exportsApi: { download: vi.fn() } }));

import ExportMenu from '@/components/ExportMenu.vue';
import { exportsApi } from '@/api/exports';
import { filenameOf } from '@/api/exports';
import { useAuthStore } from '@/stores/auth';

function make(permissions, props = {}) {
    useAuthStore().apply({ name: 'م', permissions });
    return mount(ExportMenu, { props: { kind: 'asnads', ...props } });
}

describe('ExportMenu', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('is hidden without the export permission', () => {
        expect(make(['asnads.view']).find('[data-test=export-menu]').exists()).toBe(false);
    });

    it('downloads with the chosen format and the given params and reports the count', async () => {
        exportsApi.download.mockResolvedValue({ count: 3, total: 3, truncated: false });
        const w = make(['exports.create'], { params: { ids: [1, 2, 3] } });
        await w.findAll('button')[1].trigger('click');
        await flushPromises();
        expect(exportsApi.download).toHaveBeenCalledWith('asnads', { ids: [1, 2, 3], format: 'json' });
        expect(w.text()).toContain('صُدِّر 3 سجل');
    });

    it('says clearly when the export was cut at the limit', async () => {
        exportsApi.download.mockResolvedValue({ count: 500, total: 900, truncated: true });
        const w = make(['exports.create']);
        await w.findAll('button')[0].trigger('click');
        await flushPromises();
        expect(w.text()).toContain('500 من 900');
    });

    it('shows the server error', async () => {
        exportsApi.download.mockRejectedValue({ response: { status: 403 } });
        const w = make(['exports.create']);
        await w.findAll('button')[0].trigger('click');
        await flushPromises();
        expect(w.find('[role=alert]').text()).toContain('صلاحية');
    });
});

describe('filenameOf', () => {
    it('reads the quoted name or falls back', () => {
        expect(filenameOf('attachment; filename="sanad-asnads-1.csv"', 'x')).toBe('sanad-asnads-1.csv');
        expect(filenameOf(null, 'x')).toBe('x');
    });
});
