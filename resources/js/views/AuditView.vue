<script setup>
import { onMounted, reactive, ref } from 'vue';
import { auditApi } from '@/api/reviews';
import { errorMessage } from '@/api/errors';

const filters = reactive({ event: '', from: '', to: '' });
const events = ref([]);
const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(true);
const error = ref('');

async function load(page = 1) {
    loading.value = true;
    error.value = '';
    try {
        const params = { page, per_page: 25 };
        for (const k of ['event', 'from', 'to']) if (filters[k]) params[k] = filters[k];
        const res = await auditApi.list(params);
        rows.value = res.data;
        meta.value = res.meta ?? meta.value;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    try {
        events.value = await auditApi.events();
    } catch (e) {
        error.value = errorMessage(e);
    }
    await load();
});
</script>

<template>
    <div class="page">
        <h1 style="color: var(--navy)">سجل التدقيق</h1>
        <p class="def">للقراءة فقط: لا يمكن تعديل السجل ولا حذفه من هنا.</p>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <section class="card">
            <form class="row" @submit.prevent="load(1)">
                <select v-model="filters.event" class="input" aria-label="الحدث">
                    <option value="">كل الأحداث</option>
                    <option v-for="e in events" :key="e" :value="e">{{ e }}</option>
                </select>
                <input v-model="filters.from" class="input" type="date" aria-label="من تاريخ" />
                <input v-model="filters.to" class="input" type="date" aria-label="إلى تاريخ" />
                <button class="btn secondary">تصفية</button>
            </form>
            <p v-if="loading">جارٍ التحميل…</p>
            <p v-else-if="!rows.length" class="def">لا أحداث.</p>
            <table v-else class="list">
                <thead><tr><th>الوقت</th><th>الحدث</th><th>المستخدم</th><th>السجل</th></tr></thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.id" data-test="audit-row">
                        <td>{{ r.created_at?.slice(0, 16).replace('T', ' ') }}</td>
                        <td>{{ r.event }}</td>
                        <td>{{ r.user?.name ?? '—' }}</td>
                        <td>{{ r.subject_type ?? '' }} {{ r.subject_id ?? '' }}</td>
                    </tr>
                </tbody>
            </table>
            <div class="row">
                <button type="button" class="btn secondary small" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">السابق</button>
                <span>{{ meta.current_page }} / {{ meta.last_page }}</span>
                <button type="button" class="btn secondary small" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">التالي</button>
            </div>
        </section>
    </div>
</template>
