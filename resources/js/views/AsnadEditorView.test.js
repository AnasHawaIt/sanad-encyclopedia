import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('vue-router', () => ({ useRoute: () => ({ params: { id: '7' } }), useRouter: () => ({ push: vi.fn() }) }));
vi.mock('@/api/asnads', () => ({
    asnadsApi: {
        show: vi.fn(), reorder: vi.fn(), addNode: vi.fn(), updateNode: vi.fn(), removeNode: vi.fn(), update: vi.fn(), suggestSplit: vi.fn(),
    },
    personsApi: { search: vi.fn() },
}));

import AsnadEditorView from '@/views/AsnadEditorView.vue';
import { asnadsApi, personsApi } from '@/api/asnads';
import { useAuthStore } from '@/stores/auth';

const counts = (over = {}) => ({
    total_positions: 3, range: { from: 1, to: 3, explicit: false }, persons_in_chain: 3, intermediaries: 1, unique_persons: 2,
    unique_is_lower_bound: true, unresolved_positions: 1, complete: false,
    issues: [{ code: 'unresolved_nodes', message: 'في السند حلقات غير محسومة.' }],
    definitions: { persons_in_chain: 'تعريف الرجال', intermediaries: 'تعريف الوسائط', unique_persons: 'تعريف الأعلام' },
    ...over,
});
const person = (id, name) => ({ id, primary_name: name, death_year_h: 200 });
const node = (id, seq, p = null, name = null) => ({
    id, sequence_number: seq, person_id: p?.id ?? null, person: p, is_linked: !!p, unresolved_name: name, text_segment: null,
});
const asnadData = (nodes, over = {}) => ({ id: 7, title: 'سند', chain_text_original: 'حدثنا فلان', count_from_seq: null, count_to_seq: null, nodes, counts: counts(), ...over });

async function mountEditor(permissions, data) {
    const auth = useAuthStore();
    auth.apply({ name: 'باحث', permissions });
    asnadsApi.show.mockResolvedValue(data);
    const wrapper = mount(AsnadEditorView, { global: { stubs: { RouterLink: true } } });
    await flushPromises();
    return wrapper;
}

const base = () => asnadData([node(1, 1, person(10, 'أحمد')), node(2, 2, null, 'راوٍ مجهول'), node(3, 3, person(11, 'مالك'))]);

describe('AsnadEditorView', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        vi.spyOn(window, 'confirm').mockReturnValue(true);
    });

    it('renders nodes in order, marks unresolved ones and shows counts with definitions', async () => {
        const w = await mountEditor(['asnads.view', 'asnads.update'], base());

        const rows = w.findAll('[data-test="node"]');
        expect(rows).toHaveLength(3);
        expect(rows[0].text()).toContain('أحمد');
        expect(rows[1].classes()).toContain('unresolved');
        expect(rows[1].text()).toContain('غير محسومة');
        expect(w.get('[data-test="persons-in-chain"]').text()).toBe('3');
        expect(w.get('[data-test="intermediaries"]').text()).toBe('1');
        expect(w.text()).toContain('تعريف الوسائط');
        expect(w.text()).toContain('النتيجة غير مكتملة');
        expect(w.text()).toContain('حدّ أدنى');
        expect(w.get('[data-test="original-text"]').text()).toBe('حدثنا فلان');
    });

    it('is read-only without update permission', async () => {
        const w = await mountEditor(['asnads.view'], base());

        expect(w.text()).toContain('للقراءة فقط');
        expect(w.find('button[aria-label="تقديم"]').exists()).toBe(false);
        expect(w.text()).not.toContain('إضافة حلقة');
        expect(w.text()).not.toContain('حدود العدّ');
    });

    it('sends the complete new order when a node is moved down and shows the server result', async () => {
        const w = await mountEditor(['asnads.view', 'asnads.update'], base());
        asnadsApi.reorder.mockResolvedValue(asnadData([node(2, 1, null, 'راوٍ مجهول'), node(1, 2, person(10, 'أحمد')), node(3, 3, person(11, 'مالك'))]));

        await w.findAll('button[aria-label="تأخير"]')[0].trigger('click');
        await flushPromises();

        expect(asnadsApi.reorder).toHaveBeenCalledWith(7, [2, 1, 3]);
        expect(w.findAll('[data-test="node"]')[0].text()).toContain('راوٍ مجهول');
    });

    it('disables moving the first node up and the last node down', async () => {
        const w = await mountEditor(['asnads.view', 'asnads.update'], base());

        expect(w.findAll('button[aria-label="تقديم"]')[0].attributes('disabled')).toBeDefined();
        expect(w.findAll('button[aria-label="تأخير"]')[2].attributes('disabled')).toBeDefined();
    });

    it('keeps the old order and shows the server error when reorder fails', async () => {
        const w = await mountEditor(['asnads.view', 'asnads.update'], base());
        asnadsApi.reorder.mockRejectedValue({ response: { status: 422, data: { errors: { order: ['ترتيب غير صالح.'] } } } });

        await w.findAll('button[aria-label="تأخير"]')[0].trigger('click');
        await flushPromises();

        expect(w.text()).toContain('ترتيب غير صالح.');
        expect(w.findAll('[data-test="node"]')[0].text()).toContain('أحمد');
    });

    it('links an unresolved node to a person chosen from search', async () => {
        vi.useFakeTimers();
        const w = await mountEditor(['asnads.view', 'asnads.update'], base());
        personsApi.search.mockResolvedValue([person(12, 'سفيان')]);
        asnadsApi.updateNode.mockResolvedValue(asnadData([node(1, 1, person(10, 'أحمد')), node(2, 2, person(12, 'سفيان'), 'راوٍ مجهول'), node(3, 3, person(11, 'مالك'))], { counts: counts({ unresolved_positions: 0, complete: true, issues: [], unique_is_lower_bound: false }) }));

        const row = w.findAll('[data-test="node"]')[1];
        await row.findAll('button').find((b) => b.text() === 'ربط بعلَم').trigger('click');
        await row.get('input[type="search"]').setValue('سفيان');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();
        await row.findAll('.picker-results button')[0].trigger('click');
        await flushPromises();
        vi.useRealTimers();

        expect(asnadsApi.updateNode).toHaveBeenCalledWith(7, 2, { person_id: 12 });
        expect(w.findAll('[data-test="node"]')[1].classes()).not.toContain('unresolved');
        expect(w.text()).not.toContain('النتيجة غير مكتملة');
    });

    it('does not remove a node when the confirmation is declined', async () => {
        const w = await mountEditor(['asnads.view', 'asnads.update'], base());
        window.confirm.mockReturnValue(false);

        await w.findAll('button').filter((b) => b.text() === 'حذف')[0].trigger('click');

        expect(asnadsApi.removeNode).not.toHaveBeenCalled();
    });

    it('removes a node after confirmation', async () => {
        const w = await mountEditor(['asnads.view', 'asnads.update'], base());
        asnadsApi.removeNode.mockResolvedValue(asnadData([node(1, 1, person(10, 'أحمد')), node(3, 2, person(11, 'مالك'))]));

        await w.findAll('button').filter((b) => b.text() === 'حذف')[1].trigger('click');
        await flushPromises();

        expect(asnadsApi.removeNode).toHaveBeenCalledWith(7, 2);
        expect(w.findAll('[data-test="node"]')).toHaveLength(2);
    });

    it('adds a node with a written name and position and clears the form', async () => {
        const w = await mountEditor(['asnads.view', 'asnads.update'], base());
        asnadsApi.addNode.mockResolvedValue(asnadData([node(9, 1, null, 'جديد'), ...base().nodes.map((n) => ({ ...n, sequence_number: n.sequence_number + 1 }))]));

        const add = w.findAll('button').find((b) => b.text() === 'إضافة');
        expect(add.attributes('disabled')).toBeDefined();
        await w.get('#node-name').setValue('  جديد ');
        await w.get('#node-pos').setValue('1');
        await add.trigger('click');
        await flushPromises();

        expect(asnadsApi.addNode).toHaveBeenCalledWith(7, { unresolved_name: 'جديد', position: 1 });
        expect(w.get('#node-name').element.value).toBe('');
    });

    it('saves explicit bounds as numbers and null when cleared', async () => {
        const w = await mountEditor(['asnads.view', 'asnads.update'], base());
        asnadsApi.update.mockResolvedValue(asnadData(base().nodes, { count_from_seq: 1, count_to_seq: 2, counts: counts({ range: { from: 1, to: 2, explicit: true }, persons_in_chain: 2 }) }));

        await w.get('#from').setValue('1');
        await w.get('#to').setValue('2');
        await w.findAll('button').find((b) => b.text() === 'حفظ الحدود').trigger('click');
        await flushPromises();

        expect(asnadsApi.update).toHaveBeenCalledWith(7, { count_from_seq: 1, count_to_seq: 2 });
        expect(w.text()).toContain('حدود محددة يدويًا');
        expect(w.findAll('[data-test="node"]')[2].classes()).toContain('outside');

        asnadsApi.update.mockResolvedValue(base());
        await w.findAll('button').find((b) => b.text() === 'السند كله').trigger('click');
        await flushPromises();
        expect(asnadsApi.update).toHaveBeenLastCalledWith(7, { count_from_seq: null, count_to_seq: null });
    });

    it('applies split suggestions only after the explicit click and never picks a candidate on its own', async () => {
        const w = await mountEditor(['asnads.view', 'asnads.update'], asnadData([]));
        asnadsApi.suggestSplit.mockResolvedValue({
            note: 'اقتراح فقط.',
            segments: [
                { sequence_number: 1, text_segment: 'أحمد', unresolved_name: 'أحمد', preceding_mode: 'حدثنا', transmission_mode_id: 4, candidates: [person(10, 'أحمد بن حنبل')] },
                { sequence_number: 2, text_segment: 'الزهري', unresolved_name: 'الزهري', preceding_mode: 'عن', transmission_mode_id: null, candidates: [] },
            ],
        });
        asnadsApi.addNode.mockResolvedValue(asnadData([]));

        await w.get('#split-text').setValue('حدثنا أحمد عن الزهري');
        await w.findAll('button').find((b) => b.text() === 'اقترح التقسيم').trigger('click');
        await flushPromises();
        expect(w.findAll('[data-test="segment"]')).toHaveLength(2);
        expect(asnadsApi.addNode).not.toHaveBeenCalled();

        await w.findAll('button').find((b) => b.text() === 'إضافة هذه الحلقات إلى السند').trigger('click');
        await flushPromises();

        expect(asnadsApi.addNode).toHaveBeenCalledTimes(2);
        expect(asnadsApi.addNode.mock.calls[0][1]).toEqual({ unresolved_name: 'أحمد', text_segment: 'أحمد', transmission_mode_id: 4 });
        expect(asnadsApi.addNode.mock.calls[1][1]).toEqual({ unresolved_name: 'الزهري', text_segment: 'الزهري' });
    });

    it('links a suggested candidate only when the researcher picks it', async () => {
        const w = await mountEditor(['asnads.view', 'asnads.update'], asnadData([]));
        asnadsApi.suggestSplit.mockResolvedValue({
            note: 'n',
            segments: [{ sequence_number: 1, text_segment: 'أحمد', unresolved_name: 'أحمد', preceding_mode: null, transmission_mode_id: null, candidates: [person(10, 'أحمد بن حنبل')] }],
        });
        asnadsApi.addNode.mockResolvedValue(asnadData([]));

        await w.get('#split-text').setValue('أحمد');
        await w.findAll('button').find((b) => b.text() === 'اقترح التقسيم').trigger('click');
        await flushPromises();
        await w.get('[data-test="segment"]').findAll('button').find((b) => b.text().includes('أحمد بن حنبل')).trigger('click');
        await w.findAll('button').find((b) => b.text() === 'إضافة هذه الحلقات إلى السند').trigger('click');
        await flushPromises();

        expect(asnadsApi.addNode.mock.calls[0][1].person_id).toBe(10);
    });

    it('stops applying suggestions at the first failure and reports how many were added', async () => {
        const w = await mountEditor(['asnads.view', 'asnads.update'], asnadData([]));
        asnadsApi.suggestSplit.mockResolvedValue({
            note: 'n',
            segments: ['أ', 'ب', 'ج'].map((t, i) => ({ sequence_number: i + 1, text_segment: t, unresolved_name: t, preceding_mode: null, transmission_mode_id: null, candidates: [] })),
        });
        asnadsApi.addNode.mockResolvedValueOnce(asnadData([])).mockRejectedValueOnce({ response: { status: 422, data: { errors: { x: ['فشل'] } } } });

        await w.get('#split-text').setValue('أ ب ج');
        await w.findAll('button').find((b) => b.text() === 'اقترح التقسيم').trigger('click');
        await flushPromises();
        await w.findAll('button').find((b) => b.text() === 'إضافة هذه الحلقات إلى السند').trigger('click');
        await flushPromises();

        expect(asnadsApi.addNode).toHaveBeenCalledTimes(2);
        expect(w.text()).toContain('أُضيف 1 من 3');
    });

    it('shows an error and no editor when the asnad is not available', async () => {
        const auth = useAuthStore();
        auth.apply({ permissions: ['asnads.view'] });
        asnadsApi.show.mockRejectedValue({ response: { status: 404, data: {} } });
        const w = mount(AsnadEditorView, { global: { stubs: { RouterLink: true } } });
        await flushPromises();

        expect(w.text()).toContain('غير موجود');
        expect(w.text()).not.toContain('الحلقات');
    });
});
