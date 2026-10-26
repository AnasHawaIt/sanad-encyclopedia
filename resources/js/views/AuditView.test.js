import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/api/reviews', () => ({ auditApi: { list: vi.fn(), events: vi.fn() } }));

import AuditView from '@/views/AuditView.vue';
import { auditApi } from '@/api/reviews';

describe('AuditView', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        auditApi.events.mockResolvedValue(['persons.created', 'merges.executed']);
        auditApi.list.mockResolvedValue({ data: [{ id: 1, event: 'merges.executed', user: { name: 'مراجع' }, subject_type: 'person', subject_id: 4, created_at: '2026-10-20T10:30:00+00:00' }], meta: { current_page: 1, last_page: 2, total: 30 } });
    });

    it('shows rows and the event list from the API', async () => {
        const w = mount(AuditView);
        await flushPromises();
        expect(w.findAll('[data-test=audit-row]')).toHaveLength(1);
        expect(w.text()).toContain('مراجع');
        expect(w.findAll('option').map((o) => o.text())).toContain('persons.created');
    });

    it('applies filters and paginates', async () => {
        const w = mount(AuditView);
        await flushPromises();
        await w.find('select').setValue('merges.executed');
        await w.find('form').trigger('submit');
        await flushPromises();
        expect(auditApi.list).toHaveBeenLastCalledWith({ page: 1, per_page: 25, event: 'merges.executed' });
        await w.findAll('button').find((b) => b.text() === 'التالي').trigger('click');
        await flushPromises();
        expect(auditApi.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }));
    });

    it('shows an error when the API fails', async () => {
        auditApi.list.mockRejectedValue({ response: { status: 403 } });
        const w = mount(AuditView);
        await flushPromises();
        expect(w.find('[role=alert]').exists()).toBe(true);
    });
});
