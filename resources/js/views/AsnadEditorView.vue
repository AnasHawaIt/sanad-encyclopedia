<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { asnadsApi } from '@/api/asnads';
import { errorMessage } from '@/api/errors';
import { useAuthStore } from '@/stores/auth';
import CountsPanel from '@/components/asnad/CountsPanel.vue';
import NodeRow from '@/components/asnad/NodeRow.vue';
import PersonPicker from '@/components/asnad/PersonPicker.vue';
import ReviewActions from '@/components/review/ReviewActions.vue';
import SplitSuggestions from '@/components/asnad/SplitSuggestions.vue';

const route = useRoute();
const auth = useAuthStore();
const id = computed(() => route.params.id);

const asnad = ref(null);
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const bounds = reactive({ from: '', to: '' });
const form = reactive({ title: '', person: null, name: '', position: '' });

const editable = computed(() => auth.can('asnads.update'));
const nodes = computed(() => asnad.value?.nodes ?? []);
const counts = computed(() => asnad.value?.counts ?? null);

function inRange(node) {
    const range = counts.value?.range;
    if (!range || range.from == null || range.to == null) {
        return true;
    }
    return node.sequence_number >= range.from && node.sequence_number <= range.to;
}

function adopt(data) {
    asnad.value = data;
    form.title = data.title ?? '';
    bounds.from = data.count_from_seq ?? '';
    bounds.to = data.count_to_seq ?? '';
}

async function reviewed(result) {
    notice.value = `الحالة الآن: ${result.verification_status.label} / ${result.publication_status.label}.`;
    if (result.warnings?.length) {
        notice.value += ' ' + result.warnings.map((w) => w.message ?? w).join(' ');
    }
    try {
        adopt(await asnadsApi.show(asnad.value.id));
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function run(action, success = '') {
    if (busy.value) {
        return false;
    }
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const result = await action();
        if (result) {
            adopt(result);
        }
        notice.value = success;
        return true;
    } catch (e) {
        error.value = errorMessage(e);
        return false;
    } finally {
        busy.value = false;
    }
}

onMounted(async () => {
    try {
        adopt(await asnadsApi.show(id.value));
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
});

/** الترتيب يُرسل كاملًا (تبديل داخل معاملة واحدة في الخادم) لا كسلسلة تحريكات متفرقة. */
function swap(index, delta) {
    const order = nodes.value.map((n) => n.id);
    const target = index + delta;
    if (target < 0 || target >= order.length) {
        return;
    }
    [order[index], order[target]] = [order[target], order[index]];
    run(() => asnadsApi.reorder(asnad.value.id, order), 'تم تغيير الترتيب.');
}

function link(node, person) {
    run(() => asnadsApi.updateNode(asnad.value.id, node.id, { person_id: person.id }), 'تم ربط الحلقة بالعلَم.');
}

function unlink(node) {
    run(() => asnadsApi.updateNode(asnad.value.id, node.id, { person_id: null }), 'تم فك الربط.');
}

function remove(node) {
    if (!window.confirm('حذف هذه الحلقة من السند؟')) {
        return;
    }
    run(() => asnadsApi.removeNode(asnad.value.id, node.id), 'تم حذف الحلقة.');
}

async function addNode() {
    const payload = {};
    if (form.person) {
        payload.person_id = form.person.id;
    }
    if (form.name.trim() !== '') {
        payload.unresolved_name = form.name.trim();
    }
    if (form.position !== '' && form.position !== null) {
        payload.position = Number(form.position);
    }
    const ok = await run(() => asnadsApi.addNode(asnad.value.id, payload), 'تمت إضافة الحلقة.');
    if (ok) {
        form.person = null;
        form.name = '';
        form.position = '';
    }
}

function saveBounds() {
    const num = (v) => (v === '' || v === null ? null : Number(v));
    run(() => asnadsApi.update(asnad.value.id, { count_from_seq: num(bounds.from), count_to_seq: num(bounds.to) }), 'تم حفظ حدود العدّ.');
}

function clearBounds() {
    bounds.from = '';
    bounds.to = '';
    saveBounds();
}

function saveTitle() {
    run(() => asnadsApi.update(asnad.value.id, { title: form.title }), 'تم حفظ العنوان.');
}

/** تُضاف الحلقات المقترحة واحدةً بعد أخرى؛ عند أول خطأ يتوقف الإضافة ويُذكر ما تمّ. */
async function applySuggestions(items) {
    if (busy.value) {
        return;
    }
    busy.value = true;
    error.value = '';
    notice.value = '';
    let added = 0;
    try {
        for (const item of items) {
            const payload = Object.fromEntries(Object.entries(item).filter(([, v]) => v !== null && v !== ''));
            adopt(await asnadsApi.addNode(asnad.value.id, payload));
            added++;
        }
        notice.value = `أُضيفت ${added} حلقة. راجعها وأحسم غير المحسومة.`;
    } catch (e) {
        error.value = `${errorMessage(e)} (أُضيف ${added} من ${items.length} قبل التوقف.)`;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="page">
        <p v-if="loading">جارٍ التحميل…</p>
        <p v-else-if="!asnad" class="error">{{ error }}</p>
        <template v-else>
            <h1 style="color: var(--navy)">محرر السند</h1>
            <p v-if="!editable" class="notice">عرض للقراءة فقط: لا تملك صلاحية تعديل الأسانيد.</p>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <p v-if="notice" class="badge" role="status">{{ notice }}</p>

            <section class="card" data-test="review-bar">
                <h2>المراجعة والنشر</h2>
                <p>
                    التحقق: <span class="badge">{{ asnad.verification_status?.label }}</span>
                    النشر: <span class="badge">{{ asnad.publication_status?.label }}</span>
                </p>
                <ReviewActions
                    v-if="asnad.verification_status && asnad.publication_status"
                    type="asnads" :id="asnad.id"
                    :verification="asnad.verification_status.value" :publication="asnad.publication_status.value"
                    @changed="reviewed"
                />
            </section>

            <div class="grid-2">
                <div>
                    <section class="card">
                        <h2>بيانات السند</h2>
                        <div class="field">
                            <label for="title">العنوان</label>
                            <div class="row">
                                <input id="title" v-model="form.title" class="input" :disabled="!editable" style="flex: 1" />
                                <button v-if="editable" type="button" class="btn secondary" :disabled="busy" @click="saveTitle">حفظ</button>
                            </div>
                        </div>
                        <div v-if="asnad.chain_text_original" class="field">
                            <label>النص الأصلي (لا يُعدَّل بعد حفظه)</label>
                            <div class="input" dir="rtl" data-test="original-text">{{ asnad.chain_text_original }}</div>
                        </div>
                    </section>

                    <section class="card">
                        <h2>الحلقات (الأقرب إلينا أولًا)</h2>
                        <p v-if="!nodes.length" class="def">لا حلقات بعد.</p>
                        <NodeRow
                            v-for="(node, i) in nodes"
                            :key="node.id"
                            :node="node"
                            :index="i"
                            :total="nodes.length"
                            :editable="editable"
                            :busy="busy"
                            :outside-range="!inRange(node)"
                            @up="swap(i, -1)"
                            @down="swap(i, 1)"
                            @link="(p) => link(node, p)"
                            @unlink="unlink(node)"
                            @remove="remove(node)"
                        />
                    </section>

                    <section v-if="editable" class="card">
                        <h2>إضافة حلقة</h2>
                        <div class="field">
                            <label>علَم (اختياري)</label>
                            <p v-if="form.person" class="row">
                                <span class="badge">{{ form.person.primary_name }}</span>
                                <button type="button" class="btn secondary small" @click="form.person = null">إزالة</button>
                            </p>
                            <PersonPicker v-else @pick="(p) => (form.person = p)" />
                        </div>
                        <div class="field">
                            <label for="node-name">الاسم كما ورد (مطلوب إن لم تختر علَمًا)</label>
                            <input id="node-name" v-model="form.name" class="input" />
                        </div>
                        <div class="field">
                            <label for="node-pos">الموضع (اتركه فارغًا للإضافة في آخر السند)</label>
                            <input id="node-pos" v-model="form.position" class="input" type="number" min="1" :max="nodes.length + 1" />
                        </div>
                        <button type="button" class="btn" :disabled="busy || (!form.person && form.name.trim() === '')" @click="addNode">إضافة</button>
                    </section>

                    <SplitSuggestions v-if="editable" @apply="applySuggestions" />
                </div>

                <aside>
                    <CountsPanel :counts="counts" />
                    <section v-if="editable" class="card">
                        <h2>حدود العدّ</h2>
                        <p class="def">اتركها فارغة لعدّ السند كله. الحدود مواضع لا أشخاص.</p>
                        <div class="row">
                            <div class="field"><label for="from">من</label><input id="from" v-model="bounds.from" class="input" type="number" min="1" style="width: 5rem" /></div>
                            <div class="field"><label for="to">إلى</label><input id="to" v-model="bounds.to" class="input" type="number" min="1" style="width: 5rem" /></div>
                        </div>
                        <div class="row">
                            <button type="button" class="btn" :disabled="busy" @click="saveBounds">حفظ الحدود</button>
                            <button type="button" class="btn secondary" :disabled="busy" @click="clearBounds">السند كله</button>
                        </div>
                    </section>
                </aside>
            </div>
        </template>
    </div>
</template>
