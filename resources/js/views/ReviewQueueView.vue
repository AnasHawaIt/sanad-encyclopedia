<script setup>
import { onMounted, ref } from 'vue';
import { reviewsApi } from '@/api/reviews';
import { errorMessage } from '@/api/errors';
import ReviewActions from '@/components/review/ReviewActions.vue';

const types = { persons: 'الأعلام', books: 'الكتب', ijazas: 'الإجازات', asnads: 'الأسانيد' };
// يعيد الخادم الأنواع بالمفرد (person…) فنحوّلها إلى مسارات الواجهة
const plural = { person: 'persons', book: 'books', ijaza: 'ijazas', asnad: 'asnads' };

const type = ref('');
const items = ref([]);
const loading = ref(true);
const error = ref('');
const notice = ref('');

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const alias = Object.entries(plural).find(([, v]) => v === type.value)?.[0];
        items.value = await reviewsApi.queue(alias);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

function done(item, result) {
    notice.value = `تم تنفيذ الإجراء على «${item.title}».`;
    items.value = items.value.filter((i) => !(i.type === item.type && i.id === item.id));
    if (result.warnings?.length) {
        notice.value += ' ' + result.warnings.map((w) => w.message ?? w).join(' ');
    }
}

onMounted(load);
</script>

<template>
    <div class="page">
        <h1 style="color: var(--navy)">قائمة المراجعة</h1>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <p v-if="notice" class="badge" role="status">{{ notice }}</p>
        <section class="card">
            <div class="row">
                <label for="type">النوع</label>
                <select id="type" v-model="type" class="input" @change="load">
                    <option value="">الكل</option>
                    <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                </select>
            </div>
            <p v-if="loading">جارٍ التحميل…</p>
            <p v-else-if="!items.length" class="def">لا سجلات بانتظار المراجعة.</p>
            <ul v-else class="plain">
                <li v-for="item in items" :key="`${item.type}-${item.id}`" class="card" data-test="queue-item">
                    <strong>{{ item.title }}</strong>
                    <span class="badge">{{ types[plural[item.type]] }}</span>
                    <p class="def">أرسله {{ item.submitted_by?.name ?? '—' }}<template v-if="item.submit_notes"> — {{ item.submit_notes }}</template></p>
                    <RouterLink v-if="item.type === 'asnad'" :to="`/asnads/${item.id}`">فتح السند</RouterLink>
                    <ReviewActions :type="plural[item.type]" :id="item.id" verification="in_review" publication="unpublished" @changed="done(item, $event)" />
                </li>
            </ul>
        </section>
    </div>
</template>
