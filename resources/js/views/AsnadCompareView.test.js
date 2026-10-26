import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const route = { query: { ids: '1,2' } };
vi.mock('vue-router', () => ({ useRoute: () => route, useRouter: () => ({ push: vi.fn() }) }));
vi.mock('@/api/asnads', () => ({
    asnadsApi: { compare: vi.fn(), tree: vi.fn(), bookCompare: vi.fn(), bookTree: vi.fn(), list: vi.fn() },
    personsApi: {},
}));

import AsnadCompareView from '@/views/AsnadCompareView.vue';
import { asnadsApi } from '@/api/asnads';

const person = (id, name) => ({ id, primary_name: name, death_year_h: null });
const comparison = (over = {}) => ({
    scope: 'counted', partial: false, hidden_nodes: 0, truncated: false,
    definitions: { matching: 'تعريف المطابقة', common: 'تعريف المشترك', tree: 'تعريف الشجرة' },
    asnads: [
        { id: 1, title: 'السند الأول', counts: { persons_in_chain: 3 }, persons_only_here: 1, chain: [{ kind: 'person', person: person(5, 'شيخ') }, { kind: 'person', person: person(6, 'مالك') }, { kind: 'unresolved', person: null, unresolved_name: 'مجهول' }] },
        { id: 2, title: 'السند الثاني', counts: { persons_in_chain: 2 }, persons_only_here: 0, chain: [{ kind: 'person', person: person(6, 'مالك') }] },
    ],
    common_persons: [
        { person: person(6, 'مالك'), asnad_ids: [1, 2], positions: { 1: [2], 2: [1] }, mean_position: 1.5 },
        { person: person(7, 'نافع'), asnad_ids: [1, 2], positions: { 1: [3], 2: [2] }, mean_position: 2.5 },
    ],
    shared_persons: [],
    nearest_common: { person: person(6, 'مالك') },
    farthest_common: { person: person(7, 'نافع') },
    ...over,
});
const treeData = (over = {}) => ({
    scope: 'counted', partial: false, hidden_nodes: 0,
    definitions: { matching: 'م', tree: 'تعريف الشجرة' },
    asnads: [{ id: 1, title: 'السند الأول', length: 3 }, { id: 2, title: 'السند الثاني', length: 3 }],
    stats: { nodes: 4, roots: 1, leaves: 2, merged_appearances: 2 },
    nodes: [
        { id: 1, parent_id: null, depth: 0, kind: 'person', person: person(1, 'الأصل'), unresolved_name: null, asnad_ids: [1, 2], ends_asnad_ids: [] },
        { id: 2, parent_id: 1, depth: 1, kind: 'person', person: person(2, 'الوسيط'), unresolved_name: null, asnad_ids: [1, 2], ends_asnad_ids: [] },
        { id: 3, parent_id: 2, depth: 2, kind: 'person', person: person(3, 'ص'), unresolved_name: null, asnad_ids: [1], ends_asnad_ids: [1] },
        { id: 4, parent_id: 2, depth: 2, kind: 'unresolved', person: null, unresolved_name: 'سفيان', asnad_ids: [2], ends_asnad_ids: [2] },
    ],
    ...over,
});

describe('AsnadCompareView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        route.query = { ids: '1,2' };
    });

    it('shows common persons with nearest/farthest badges and per-asnad positions', async () => {
        asnadsApi.compare.mockResolvedValue(comparison());
        const w = mount(AsnadCompareView);
        await flushPromises();

        expect(asnadsApi.compare).toHaveBeenCalledWith([1, 2], 'counted');
        const rows = w.findAll('[data-test="common-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('مالك');
        expect(rows[0].text()).toContain('الأقرب إلينا');
        expect(rows[1].text()).toContain('الأبعد');
        expect(rows[0].text()).toContain('السند الأول: 2');
        expect(w.text()).toContain('تعريف المطابقة');
        expect(w.find('[data-test="partial"]').exists()).toBe(false);
    });

    it('says plainly when nothing is common and flags a partial result', async () => {
        asnadsApi.compare.mockResolvedValue(comparison({ common_persons: [], nearest_common: null, farthest_common: null, partial: true, hidden_nodes: 2 }));
        const w = mount(AsnadCompareView);
        await flushPromises();

        expect(w.text()).toContain('لا علَم مشترك');
        expect(w.get('[data-test="partial"]').text()).toContain('حدّ أدنى');
        expect(w.get('[data-test="partial"]').text()).toContain('(2)');
    });

    it('uses the book endpoint when a book is given and warns about truncation', async () => {
        route.query = { book: '9' };
        asnadsApi.bookCompare.mockResolvedValue(comparison({ truncated: true, limit: 20, book_asnads_total: 31 }));
        const w = mount(AsnadCompareView);
        await flushPromises();

        expect(asnadsApi.bookCompare).toHaveBeenCalledWith(9, 'counted');
        expect(asnadsApi.compare).not.toHaveBeenCalled();
        expect(w.text()).toContain('عُرض 20 سندًا من أصل 31');
    });

    it('reloads with the full scope when the scope is changed', async () => {
        asnadsApi.compare.mockResolvedValue(comparison());
        const w = mount(AsnadCompareView);
        await flushPromises();

        await w.get('select[aria-label="نطاق المقارنة"]').setValue('full');
        await flushPromises();

        expect(asnadsApi.compare).toHaveBeenLastCalledWith([1, 2], 'full');
    });

    it('shows the server error message when the request fails', async () => {
        asnadsApi.compare.mockRejectedValue({ response: { status: 422, data: { errors: { ids: ['يلزم سندان مرئيان على الأقل.'] } } } });
        const w = mount(AsnadCompareView);
        await flushPromises();

        expect(w.get('[role="alert"]').text()).toContain('يلزم سندان');
    });

    it('renders the tree from the flat list, collapses and expands branches, and marks unresolved nodes', async () => {
        asnadsApi.compare.mockResolvedValue(comparison());
        asnadsApi.tree.mockResolvedValue(treeData());
        const w = mount(AsnadCompareView);
        await flushPromises();

        await w.findAll('button').find((b) => b.text() === 'شجرة الأسانيد').trigger('click');
        await flushPromises();

        expect(asnadsApi.tree).toHaveBeenCalledWith([1, 2], 'counted');
        const nodes = w.findAll('[data-test="tree-node"]');
        expect(nodes).toHaveLength(4);
        expect(nodes[0].text()).toContain('الأصل');
        expect(nodes[0].text()).toContain('2 أسانيد');
        expect(w.text()).toContain('ينتهي هنا: السند الثاني');
        expect(w.text()).toContain('غير محسوم');

        await w.get('button[aria-label="طيّ"]').trigger('click');
        expect(w.findAll('[data-test="tree-node"]')).toHaveLength(1);
        await w.get('button[aria-label="فتح"]').trigger('click');
        expect(w.findAll('[data-test="tree-node"]')).toHaveLength(4);
    });

    it('does not render deep branches open by default but keeps them reachable', async () => {
        const nodes = Array.from({ length: 8 }, (_, i) => ({ id: i + 1, parent_id: i === 0 ? null : i, depth: i, kind: 'person', person: person(i + 1, `ع${i + 1}`), unresolved_name: null, asnad_ids: [1], ends_asnad_ids: i === 7 ? [1] : [] }));
        asnadsApi.compare.mockResolvedValue(comparison());
        asnadsApi.tree.mockResolvedValue(treeData({ nodes, stats: { nodes: 8, roots: 1, leaves: 1, merged_appearances: 0 } }));
        const w = mount(AsnadCompareView);
        await flushPromises();
        await w.findAll('button').find((b) => b.text() === 'شجرة الأسانيد').trigger('click');
        await flushPromises();

        expect(w.findAll('[data-test="tree-node"]')).toHaveLength(5);
        const toggles = w.findAll('button[aria-label="فتح"]');
        expect(toggles).toHaveLength(1);
        await toggles[0].trigger('click');
        expect(w.findAll('[data-test="tree-node"]').length).toBeGreaterThan(5);
    });
});
