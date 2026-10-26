<script setup>
import { computed, ref } from 'vue';
import { reviewsApi } from '@/api/reviews';
import { errorMessage } from '@/api/errors';
import { useAuthStore } from '@/stores/auth';

const props = defineProps({
    type: { type: String, required: true }, // persons | books | asnads | ijazas
    id: { type: Number, required: true },
    verification: { type: String, required: true },
    publication: { type: String, required: true },
});
const emit = defineEmits(['changed']);
const auth = useAuthStore();
const notes = ref('');
const busy = ref(false);
const error = ref('');
const warnings = ref([]);

const labels = { submit: 'إرسال للمراجعة', approve: 'اعتماد', reject: 'رفض', 'send-back': 'إعادة للتعديل', publish: 'نشر', unpublish: 'إلغاء النشر' };
const needsNotes = ['reject', 'send-back'];

const actions = computed(() => {
    const out = [];
    const v = props.verification;
    if (['draft', 'needs_revision', 'rejected'].includes(v) && auth.can('reviews.submit')) out.push('submit');
    if (v === 'in_review' && auth.can('reviews.decide')) out.push('approve', 'reject', 'send-back');
    if (v === 'verified' && auth.can('reviews.decide')) out.push('send-back');
    if (v === 'verified' && props.publication !== 'published' && auth.can('publishing.publish')) out.push('publish');
    if (props.publication === 'published' && auth.can('publishing.publish')) out.push('unpublish');
    return out;
});
const askNotes = computed(() => actions.value.some((a) => needsNotes.includes(a)));

async function run(action) {
    error.value = '';
    warnings.value = [];
    if (needsNotes.includes(action) && !notes.value.trim()) {
        error.value = 'اكتب السبب أولًا: هو مطلوب عند الرفض أو الإعادة للتعديل.';
        return;
    }
    busy.value = true;
    try {
        const result = await reviewsApi.act(props.type, props.id, action, notes.value.trim());
        warnings.value = result.warnings ?? [];
        notes.value = '';
        emit('changed', result);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="review-actions">
        <p v-if="!actions.length" class="def">لا إجراء متاح لك على هذه الحالة.</p>
        <template v-else>
            <textarea v-if="askNotes" v-model="notes" class="input" rows="2" placeholder="ملاحظات / سبب القرار" aria-label="ملاحظات المراجعة"></textarea>
            <div class="row">
                <button v-for="a in actions" :key="a" type="button" class="btn small" :class="{ secondary: a !== 'approve' && a !== 'publish' && a !== 'submit' }" :disabled="busy" @click="run(a)">{{ labels[a] }}</button>
            </div>
        </template>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <ul v-if="warnings.length" class="notice" role="status">
            <li v-for="(w, i) in warnings" :key="i">{{ w.message ?? w }}</li>
        </ul>
    </div>
</template>
