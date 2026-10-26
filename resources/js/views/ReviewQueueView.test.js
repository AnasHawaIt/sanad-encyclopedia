import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/api/reviews', () => ({ reviewsApi: { queue: vi.fn(), act: vi.fn() } }));

import ReviewQueueView from '@/views/ReviewQueueView.vue';
import { reviewsApi } from '@/api/reviews';
import { useAuthStore } from '@/stores/auth';

const item = (id, type = 'asnad') => ({ type, id, title: `سجل ${id}`, submitted_by: { id: 2, name: 'باحث' }, submit_notes: null });

describe('ReviewQueueView', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        useAuthStore().apply({ name: 'مراجع', permissions: ['reviews.decide'] });
    });

    it('lists waiting records from the API and filters by type', async () => {
        reviewsApi.queue.mockResolvedValue([item(1), item(2, 'person')]);
        const w = mount(ReviewQueueView, { global: { stubs: { RouterLink: true } } });
        await flushPromises();
        expect(w.findAll('[data-test=queue-item]')).toHaveLength(2);
        await w.find('select').setValue('asnads');
        expect(reviewsApi.queue).toHaveBeenLastCalledWith('asnad');
    });

    it('removes a record from the queue after approving it and shows the warnings', async () => {
        reviewsApi.queue.mockResolvedValue([item(1), item(2)]);
        reviewsApi.act.mockResolvedValue({ warnings: [{ message: 'حلقات غير محسومة' }] });
        const w = mount(ReviewQueueView, { global: { stubs: { RouterLink: true } } });
        await flushPromises();
        await w.find('[data-test=queue-item] button').trigger('click');
        await flushPromises();
        expect(reviewsApi.act).toHaveBeenCalledWith('asnads', 1, 'approve', '');
        expect(w.findAll('[data-test=queue-item]')).toHaveLength(1);
        expect(w.text()).toContain('حلقات غير محسومة');
    });

    it('shows an empty state and API errors', async () => {
        reviewsApi.queue.mockResolvedValue([]);
        let w = mount(ReviewQueueView, { global: { stubs: { RouterLink: true } } });
        await flushPromises();
        expect(w.text()).toContain('لا سجلات بانتظار المراجعة');
        reviewsApi.queue.mockRejectedValue({ response: { status: 403 } });
        w = mount(ReviewQueueView, { global: { stubs: { RouterLink: true } } });
        await flushPromises();
        expect(w.find('[role=alert]').text()).toContain('صلاحية');
    });
});
