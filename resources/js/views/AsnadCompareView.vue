<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { asnadsApi } from '@/api/asnads';
import { errorMessage } from '@/api/errors';
import TreeNode from '@/components/asnad/TreeNode.vue';

const route = useRoute();
const tab = ref('compare');
const scope = ref('counted');
const loading = ref(true);
const error = ref('');
const comparison = ref(null);
const tree = ref(null);

const bookId = computed(() => (route.query.book ? Number(route.query.book) : null));
const ids = computed(() => String(route.query.ids ?? '').split(',').map(Number).filter((n) => Number.isInteger(n) && n > 0));

const titles = computed(() => {
    const source = [...(comparison.value?.asnads ?? []), ...(tree.value?.asnads ?? [])];
    return Object.fromEntries(source.map((a) => [a.id, a.title || `سند #${a.id}`]));
});

/** الشجرة تصل مسطّحة (الأب قبل أبنائه)؛ نبني خريطة الأبناء مرة واحدة. */
const childrenOf = computed(() => {
    const map = {};
    const roots = [];
    for (const n of tree.value?.nodes ?? []) {
        if (n.parent_id === null) {
            roots.push(n);
        } else {
            (map[n.parent_id] ??= []).push(n);
        }
    }
    return { map, roots };
});

async function load() {
    loading.value = true;
    error.value = '';
    try {
        if (tab.value === 'compare') {
            comparison.value = bookId.value ? await asnadsApi.bookCompare(bookId.value, scope.value) : await asnadsApi.compare(ids.value, scope.value);
        } else {
            tree.value = bookId.value ? await asnadsApi.bookTree(bookId.value, scope.value) : await asnadsApi.tree(ids.value, scope.value);
        }
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

watch([tab, scope], load);
onMounted(load);

const meta = computed(() => (tab.value === 'compare' ? comparison.value : tree.value));
</script>

<template>
    <div class="page">
        <h1 style="color: var(--navy)">مقارنة الأسانيد</h1>
        <div class="row" style="margin-bottom: 1rem">
            <button type="button" class="btn" :class="{ secondary: tab !== 'compare' }" @click="tab = 'compare'">المقارنة</button>
            <button type="button" class="btn" :class="{ secondary: tab !== 'tree' }" @click="tab = 'tree'">شجرة الأسانيد</button>
            <label class="row" style="margin-inline-start: auto">
                النطاق:
                <select v-model="scope" class="select" aria-label="نطاق المقارنة">
                    <option value="counted">داخل حدّي العدّ</option>
                    <option value="full">السند كاملًا</option>
                </select>
            </label>
        </div>

        <p v-if="loading">جارٍ التحميل…</p>
        <p v-else-if="error" class="error" role="alert">{{ error }}</p>
        <template v-else-if="meta">
            <p v-if="meta.truncated" class="notice">عُرض {{ meta.limit }} سندًا من أصل {{ meta.book_asnads_total }}.</p>
            <p v-if="meta.partial" class="notice" data-test="partial">
                النتيجة حدّ أدنى للتطابق: فيها حلقات غير محسومة{{ meta.hidden_nodes ? ` أو أعلام غير متاحة لك (${meta.hidden_nodes})` : '' }}، ولا تُطابَق مع غيرها.
            </p>
            <p class="def">{{ meta.definitions?.matching }}</p>

            <template v-if="tab === 'compare'">
                <section class="card" data-test="common">
                    <h2>الأعلام المشتركة بين كل الأسانيد</h2>
                    <p class="def">{{ meta.definitions?.common }}</p>
                    <p v-if="!meta.common_persons.length" class="def">لا علَم مشترك بين الأسانيد كلها.</p>
                    <table v-else class="list">
                        <thead><tr><th>العلَم</th><th>متوسط الموضع</th><th>المواضع في كل سند</th></tr></thead>
                        <tbody>
                            <tr v-for="c in meta.common_persons" :key="c.person.id" data-test="common-row">
                                <td>
                                    {{ c.person.primary_name }}
                                    <span v-if="c.person.id === meta.nearest_common?.person.id" class="badge">الأقرب إلينا</span>
                                    <span v-if="c.person.id === meta.farthest_common?.person.id && meta.common_persons.length > 1" class="badge">الأبعد</span>
                                </td>
                                <td>{{ c.mean_position }}</td>
                                <td>
                                    <span v-for="(pos, aid) in c.positions" :key="aid" class="meta" style="margin-inline-end: 0.75rem">{{ titles[aid] }}: {{ pos.join('، ') }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
                <section v-if="meta.shared_persons.length" class="card">
                    <h2>أعلام مشتركة بين بعض الأسانيد</h2>
                    <ul>
                        <li v-for="s in meta.shared_persons" :key="s.person.id" data-test="shared-row">
                            {{ s.person.primary_name }} — في {{ s.asnad_ids.map((i) => titles[i]).join('، ') }}
                        </li>
                    </ul>
                </section>
                <section class="card">
                    <h2>الأسانيد المقارَنة</h2>
                    <div v-for="a in meta.asnads" :key="a.id" style="margin-bottom: 0.75rem">
                        <strong>{{ a.title || `سند #${a.id}` }}</strong>
                        <span class="meta"> — الرجال: {{ a.counts.persons_in_chain ?? '—' }}، ينفرد بـ {{ a.persons_only_here }} علَم</span>
                        <div class="row">
                            <template v-for="(e, i) in a.chain" :key="i">
                                <span class="badge" :class="{ warn: e.kind !== 'person' }">{{ e.person?.primary_name ?? e.unresolved_name ?? 'غير متاح' }}</span>
                                <span v-if="i < a.chain.length - 1">←</span>
                            </template>
                        </div>
                    </div>
                </section>
            </template>

            <section v-else class="card" data-test="tree">
                <h2>شجرة الأسانيد (من الأقدم إلى الأقرب إلينا)</h2>
                <p class="def">{{ meta.definitions?.tree }}</p>
                <p class="meta">{{ meta.stats.nodes }} عقدة، {{ meta.stats.roots }} جذر، {{ meta.stats.leaves }} نهاية.</p>
                <p v-if="!meta.nodes.length" class="def">لا عناصر داخل النطاق المختار.</p>
                <ul v-else class="tree-root">
                    <TreeNode v-for="r in childrenOf.roots" :key="r.id" :node="r" :children-of="childrenOf.map" :titles="titles" />
                </ul>
            </section>
        </template>
    </div>
</template>
