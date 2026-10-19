<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { asnadsApi } from '@/api/asnads';
import { errorMessage } from '@/api/errors';
import { useAuthStore } from '@/stores/auth';

const router = useRouter();
const auth = useAuthStore();
const items = ref([]);
const loading = ref(true);
const error = ref('');
const q = ref('');
const creating = reactive({ title: '', busy: false });

async function load() {
    loading.value = true;
    error.value = '';
    try {
        items.value = (await asnadsApi.list({ q: q.value || undefined })).data;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

async function create() {
    creating.busy = true;
    error.value = '';
    try {
        const created = await asnadsApi.create({ title: creating.title || null });
        router.push({ name: 'asnad-editor', params: { id: created.id } });
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        creating.busy = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="page">
        <h1 style="color: var(--navy)">الأسانيد</h1>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <section v-if="auth.can('asnads.create')" class="card">
            <h2>سند جديد</h2>
            <form class="row" @submit.prevent="create">
                <input v-model="creating.title" class="input" placeholder="عنوان السند (اختياري)" style="flex: 1" aria-label="عنوان السند" />
                <button class="btn" :disabled="creating.busy">إنشاء وفتح المحرر</button>
            </form>
        </section>
        <section class="card">
            <form class="row" @submit.prevent="load">
                <input v-model="q" class="input" type="search" placeholder="بحث في العنوان ونص السند" style="flex: 1" aria-label="بحث" />
                <button class="btn secondary">بحث</button>
            </form>
            <p v-if="loading">جارٍ التحميل…</p>
            <p v-else-if="!items.length" class="def">لا أسانيد.</p>
            <table v-else class="list">
                <thead><tr><th>العنوان</th><th>الرجال</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="a in items" :key="a.id">
                        <td>{{ a.title || `سند #${a.id}` }}</td>
                        <td>{{ a.counts?.persons_in_chain ?? '—' }}</td>
                        <td><span class="badge">{{ a.verification_status?.label }}</span></td>
                        <td><RouterLink :to="{ name: 'asnad-editor', params: { id: a.id } }">فتح</RouterLink></td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
