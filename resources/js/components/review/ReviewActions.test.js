import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/api/reviews', () => ({ reviewsApi: { act: vi.fn() } }));

import ReviewActions from '@/components/review/ReviewActions.vue';
import { reviewsApi } from '@/api/reviews';
import { useAuthStore } from '@/stores/auth';

function make(permissions, verification, publication = 'unpublished') {
    useAuthStore().apply({ name: 'م', permissions });
    return mount(ReviewActions, { props: { type: 'asnads', id: 7, verification, publication } });
}
const texts = (w) => w.findAll('button').map((b) => b.text());

describe('ReviewActions', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('offers only the actions the state and permissions allow', () => {
        expect(texts(make(['reviews.submit'], 'draft'))).toEqual(['إرسال للمراجعة']);
        expect(texts(make(['reviews.submit'], 'in_review'))).toEqual([]);
        expect(texts(make(['reviews.decide'], 'in_review'))).toEqual(['اعتماد', 'رفض', 'إعادة للتعديل']);
        expect(texts(make(['reviews.decide', 'publishing.publish'], 'verified'))).toEqual(['إعادة للتعديل', 'نشر']);
        expect(texts(make(['publishing.publish'], 'verified', 'published'))).toEqual(['إلغاء النشر']);
        expect(texts(make([], 'draft'))).toEqual([]);
    });

    it('does not call the API for reject without a reason', async () => {
        const w = make(['reviews.decide'], 'in_review');
        await w.findAll('button')[1].trigger('click');
        expect(reviewsApi.act).not.toHaveBeenCalled();
        expect(w.find('[role=alert]').exists()).toBe(true);
    });

    it('sends the reason, emits the result and shows warnings', async () => {
        reviewsApi.act.mockResolvedValue({ warnings: [{ message: 'حلقات غير محسومة' }], verification_status: { value: 'rejected' } });
        const w = make(['reviews.decide'], 'in_review');
        await w.find('textarea').setValue('ناقص');
        await w.findAll('button')[1].trigger('click');
        await flushPromises();
        expect(reviewsApi.act).toHaveBeenCalledWith('asnads', 7, 'reject', 'ناقص');
        expect(w.emitted('changed')).toHaveLength(1);
        expect(w.text()).toContain('حلقات غير محسومة');
    });

    it('shows the server error and does not emit', async () => {
        reviewsApi.act.mockRejectedValue({ response: { status: 422, data: { errors: { action: ['لا يمكنك اعتماد سجل أرسلته بنفسك.'] } } } });
        const w = make(['reviews.decide'], 'in_review');
        await w.findAll('button')[0].trigger('click');
        await flushPromises();
        expect(w.text()).toContain('لا يمكنك اعتماد سجل أرسلته بنفسك.');
        expect(w.emitted('changed')).toBeUndefined();
    });
});
