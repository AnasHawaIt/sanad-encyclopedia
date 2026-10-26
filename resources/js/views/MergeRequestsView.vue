<script setup>
import { onMounted, reactive, ref } from 'vue';
import { mergesApi } from '@/api/reviews';
import { errorMessage } from '@/api/errors';
import { useAuthStore } from '@/stores/auth';
import PersonPicker from '@/components/asnad/PersonPicker.vue';

const auth = useAuthStore();
const items = ref([]);
const status = ref('');
const loading = ref(true);
const error = ref('');
const notice = ref('');
const open = ref(null); // الطلب المفتوح بتفاصيله ومعاينته
const notes = ref('');
const busy = ref(false);
const form = reactive({ primary: null, secondary: null, reason: '' });

const statusLabels = { pending: 'معلّق', approved: 'معتمد', rejected: 'مرفوض', executed: 'منفَّذ', reverted: 'تم التراجع عنه' };

async function load() {
    loading.value = true;
    error.value = '';
    try {
        items.value = (await mergesApi.list({ status: status.value || undefined })).data;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

async function show(id) {
    error.value = '';
    notes.value = '';
    try {
        open.value = await mergesApi.show(id);
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function create() {
    error.value = '';
    if (!form.primary || !form.secondary) {
        error.value = 'اختر العلَم الأساسي والعلَم المراد دمجه فيه.';
        return;
    }
    busy.value = true;
    try {
        const created = await mergesApi.create({ primary_person_id: form.primary.id, secondary_person_id: form.secondary.id, reason: form.reason });
        notice.value = 'سُجّل طلب الدمج. لم يتغير شيء في البيانات قبل الاعتماد.';
        form.primary = form.secondary = null;
        form.reason = '';
        await load();
        await show(created.id);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}

const needsReason = ['reject', 'revert'];

async function act(action) {
    error.value = '';
    if (needsReason.includes(action) && !notes.value.trim()) {
        error.value = 'اكتب السبب أولًا: هو مطلوب للرفض وللتراجع.';
        return;
    }
    if (action === 'approve' && !window.confirm('سيُنقل كل ما يشير إلى العلَم المدمَج إلى العلَم الأساسي. يمكن التراجع لاحقًا. هل تعتمد الدمج؟')) {
        return;
    }
    busy.value = true;
    try {
        const id = open.value.id;
        const n = notes.value.trim();
        if (action === 'approve') await mergesApi.approve(id, n);
        if (action === 'reject') await mergesApi.reject(id, n);
        if (action === 'revert') await mergesApi.revert(id, n);
        if (action === 'withdraw') await mergesApi.withdraw(id);
        notice.value = { approve: 'نُفِّذ الدمج.', reject: 'رُفض الطلب.', revert: 'تم التراجع عن الدمج.', withdraw: 'سُحب الطلب.' }[action];
        await load();
        action === 'withdraw' ? (open.value = null) : await show(id);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}

const name = (p, fallback) => p?.primary_name ?? fallback;
onMounted(load);
</script>

<template>
    <div class="page">
        <h1 style="color: var(--navy)">طلبات دمج الأعلام</h1>
        <p class="def">الدمج لا يحدث تلقائيًا أبدًا: يُسجَّل طلب، ثم يعتمده مراجع آخر، وللمدير التراجع عنه. العلَم المدمَج لا يُحذف.</p>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <p v-if="notice" class="badge" role="status">{{ notice }}</p>

        <section v-if="auth.can('merges.request')" class="card">
            <h2>طلب دمج جديد</h2>
            <div class="grid-2">
                <div>
                    <p>العلَم الأساسي (يبقى): <strong data-test="primary-name">{{ form.primary?.primary_name ?? '—' }}</strong></p>
                    <PersonPicker @pick="form.primary = $event" />
                </div>
                <div>
                    <p>العلَم المدمَج (يُخفى): <strong data-test="secondary-name">{{ form.secondary?.primary_name ?? '—' }}</strong></p>
                    <PersonPicker @pick="form.secondary = $event" />
                </div>
            </div>
            <div class="field">
                <label for="reason">سبب الدمج</label>
                <textarea id="reason" v-model="form.reason" class="input" rows="2"></textarea>
            </div>
            <button type="button" class="btn" :disabled="busy" @click="create">تسجيل الطلب</button>
        </section>

        <div class="grid-2">
            <section class="card">
                <div class="row">
                    <label for="status">الحالة</label>
                    <select id="status" v-model="status" class="input" @change="load">
                        <option value="">الكل</option>
                        <option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>
                <p v-if="loading">جارٍ التحميل…</p>
                <p v-else-if="!items.length" class="def">لا طلبات.</p>
                <table v-else class="list">
                    <thead><tr><th>#</th><th>الأساسي</th><th>المدمَج</th><th>الحالة</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="r in items" :key="r.id" data-test="merge-row">
                            <td>{{ r.id }}</td>
                            <td>{{ name(r.primary, 'غير متاح') }}</td>
                            <td>{{ name(r.secondary, 'غير متاح') }}</td>
                            <td>{{ r.status.label }}</td>
                            <td><button type="button" class="btn secondary small" @click="show(r.id)">تفاصيل</button></td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="open" class="card" data-test="merge-detail">
                <h2>الطلب #{{ open.id }} — {{ open.status.label }}</h2>
                <p>{{ name(open.primary, 'غير متاح') }} ← {{ name(open.secondary, 'غير متاح') }}</p>
                <p class="def">السبب: {{ open.reason }}</p>
                <p v-if="open.decision_notes" class="def">ملاحظات القرار: {{ open.decision_notes }}</p>

                <template v-if="open.preview">
                    <h3>ما الذي سيتغير</h3>
                    <ul>
                        <li v-for="o in open.preview.repoint" :key="`${o.table}.${o.column}`">{{ o.table }}.{{ o.column }}: {{ o.count }}</li>
                    </ul>
                    <p v-if="open.preview.skipped?.length" class="notice">سيُترك {{ open.preview.skipped.length }} سجل لأنه سيخالف قاعدة، وسيُذكر في النتيجة.</p>
                    <p v-if="open.preview.name_variants_to_add?.length">أسماء تُضاف للأساسي: {{ open.preview.name_variants_to_add.join('، ') }}</p>
                    <div v-if="open.preview.differences?.length">
                        <h3>اختلافات بين العلَمين</h3>
                        <ul><li v-for="d in open.preview.differences" :key="d.field">{{ d.field }}: {{ d.primary ?? '—' }} / {{ d.secondary ?? '—' }}</li></ul>
                    </div>
                </template>

                <template v-if="open.result">
                    <h3>النتيجة</h3>
                    <ul><li v-for="(n, k) in open.result.counts" :key="k">{{ k }}: {{ n }}</li></ul>
                    <p v-if="open.result.skipped?.length" class="notice">تُركت {{ open.result.skipped.length }} سجلات لتعارضها مع قواعد البيانات.</p>
                    <p v-if="open.result.revert" class="notice">أُعيد {{ open.result.revert.restored_rows }} سجل عند التراجع<template v-if="open.result.revert.conflicts?.length">، وبقي {{ open.result.revert.conflicts.length }} لأنه تغيّر بعد الدمج</template>.</p>
                </template>

                <div v-if="['pending', 'executed'].includes(open.status.value)" class="field">
                    <textarea v-model="notes" class="input" rows="2" placeholder="ملاحظات / سبب القرار" aria-label="ملاحظات"></textarea>
                </div>
                <div class="row">
                    <template v-if="open.status.value === 'pending'">
                        <button v-if="auth.can('merges.approve')" type="button" class="btn small" :disabled="busy" @click="act('approve')">اعتماد وتنفيذ</button>
                        <button v-if="auth.can('merges.approve')" type="button" class="btn secondary small" :disabled="busy" @click="act('reject')">رفض</button>
                        <button v-if="auth.can('merges.request') && open.requested_by?.id === auth.user?.id" type="button" class="btn secondary small" :disabled="busy" @click="act('withdraw')">سحب الطلب</button>
                    </template>
                    <button v-if="open.status.value === 'executed' && auth.can('merges.revert')" type="button" class="btn secondary small" :disabled="busy" @click="act('revert')">التراجع عن الدمج</button>
                </div>
            </section>
        </div>
    </div>
</template>
