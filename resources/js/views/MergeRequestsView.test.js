import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/api/reviews', () => ({
    mergesApi: { list: vi.fn(), show: vi.fn(), create: vi.fn(), approve: vi.fn(), reject: vi.fn(), withdraw: vi.fn(), revert: vi.fn() },
}));
vi.mock('@/api/asnads', () => ({ personsApi: { search: vi.fn().mockResolvedValue([]) } }));

import MergeRequestsView from '@/views/MergeRequestsView.vue';
import { mergesApi } from '@/api/reviews';
import { useAuthStore } from '@/stores/auth';

const row = (over = {}) => ({
    id: 3, status: { value: 'pending', label: 'معلّق' }, reason: 'نفس الشخص', primary: { primary_name: 'أ' }, secondary: { primary_name: 'ب' },
    requested_by: { id: 1 }, ...over,
});

async function mountView(permissions, detail = row(), userId = 1) {
    useAuthStore().apply({ id: userId, name: 'م', permissions });
    mergesApi.list.mockResolvedValue({ data: [row()] });
    mergesApi.show.mockResolvedValue(detail);
    const w = mount(MergeRequestsView, { global: { stubs: { RouterLink: true } } });
    await flushPromises();
    return w;
}
const open = async (w) => { await w.find('[data-test=merge-row] button').trigger('click'); await flushPromises(); };
const buttons = (w) => w.find('[data-test=merge-detail]').findAll('button').map((b) => b.text());

describe('MergeRequestsView', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        vi.spyOn(window, 'confirm').mockReturnValue(true);
    });

    it('lists requests from the API and shows the preview of a pending one', async () => {
        const w = await mountView(['merges.view', 'merges.approve'], row({ preview: { repoint: [{ table: 'asnad_nodes', column: 'person_id', count: 4 }], skipped: [], name_variants_to_add: ['البخاري'], differences: [] } }));
        expect(w.findAll('[data-test=merge-row]')).toHaveLength(1);
        await open(w);
        expect(w.text()).toContain('asnad_nodes.person_id: 4');
        expect(w.text()).toContain('البخاري');
        expect(buttons(w)).toEqual(['اعتماد وتنفيذ', 'رفض']);
    });

    it('approving asks for confirmation and calls the API; declining the confirmation does nothing', async () => {
        mergesApi.approve.mockResolvedValue(row({ status: { value: 'executed', label: 'منفَّذ' } }));
        const w = await mountView(['merges.view', 'merges.approve']);
        await open(w);
        window.confirm.mockReturnValueOnce(false);
        await w.find('[data-test=merge-detail]').findAll('button')[0].trigger('click');
        expect(mergesApi.approve).not.toHaveBeenCalled();
        await w.find('[data-test=merge-detail]').findAll('button')[0].trigger('click');
        await flushPromises();
        expect(mergesApi.approve).toHaveBeenCalledWith(3, '');
    });

    it('reject and revert need a reason before any request', async () => {
        const w = await mountView(['merges.view', 'merges.approve']);
        await open(w);
        await w.find('[data-test=merge-detail]').findAll('button')[1].trigger('click');
        expect(mergesApi.reject).not.toHaveBeenCalled();
        expect(w.find('[role=alert]').exists()).toBe(true);
    });

    it('only the requester sees withdraw, and revert only with its permission on executed requests', async () => {
        let w = await mountView(['merges.view', 'merges.request'], row(), 1);
        await open(w);
        expect(buttons(w)).toEqual(['سحب الطلب']);
        w = await mountView(['merges.view', 'merges.request'], row(), 2);
        await open(w);
        expect(buttons(w)).toEqual([]);
        w = await mountView(['merges.view', 'merges.revert'], row({ status: { value: 'executed', label: 'منفَّذ' }, result: { counts: { 'asnad_nodes.person_id': 4 }, skipped: [] } }));
        await open(w);
        expect(buttons(w)).toEqual(['التراجع عن الدمج']);
        expect(w.text()).toContain('asnad_nodes.person_id: 4');
    });

    it('refuses to create a request without both persons', async () => {
        const w = await mountView(['merges.view', 'merges.request']);
        w.vm.$.setupState.form.reason = 'x';
        await w.findAll('button').find((b) => b.text() === 'تسجيل الطلب').trigger('click');
        expect(w.find('[role=alert]').text()).toContain('اختر');
        expect(mergesApi.create).not.toHaveBeenCalled();
    });
});
