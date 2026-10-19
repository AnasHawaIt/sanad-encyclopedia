<script setup>
import { ref } from 'vue';
import { asnadsApi } from '@/api/asnads';
import { errorMessage } from '@/api/errors';

const emit = defineEmits(['apply']);
const text = ref('');
const segments = ref([]);
const picked = ref({});
const note = ref('');
const error = ref('');
const loading = ref(false);

async function suggest() {
    error.value = '';
    loading.value = true;
    try {
        const data = await asnadsApi.suggestSplit(text.value);
        segments.value = data.segments;
        note.value = data.note ?? '';
        picked.value = {};
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

function pick(i, candidate) {
    picked.value = { ...picked.value, [i]: picked.value[i] === candidate.id ? undefined : candidate.id };
}

/** لا يُنشأ شيء إلا بهذا الزر؛ المرشح لا يُربط إلا إن اختاره الباحث صراحةً. */
function apply() {
    emit(
        'apply',
        segments.value.map((s, i) => ({
            unresolved_name: s.unresolved_name,
            text_segment: s.text_segment,
            transmission_mode_id: s.transmission_mode_id ?? null,
            person_id: picked.value[i] ?? null,
        })),
    );
    segments.value = [];
    text.value = '';
}
</script>

<template>
    <section class="card" aria-label="اقتراح تقسيم النص">
        <h2>اقتراح تقسيم نص السند</h2>
        <div class="field">
            <label for="split-text">الصق نص السند</label>
            <textarea id="split-text" v-model="text" class="textarea" dir="rtl"></textarea>
        </div>
        <button type="button" class="btn secondary" :disabled="loading || text.trim() === ''" @click="suggest">اقترح التقسيم</button>
        <p v-if="error" class="error">{{ error }}</p>
        <template v-if="segments.length">
            <p class="notice">{{ note }}</p>
            <div v-for="(s, i) in segments" :key="i" class="node" data-test="segment">
                <div class="seq">{{ s.sequence_number }}</div>
                <div>
                    <div class="name">{{ s.text_segment }}</div>
                    <div v-if="s.preceding_mode" class="meta">قبلها: {{ s.preceding_mode }}</div>
                    <div v-if="s.candidates.length" class="row">
                        <span class="meta">مرشحون (اختر إن كنت متأكدًا):</span>
                        <button
                            v-for="c in s.candidates"
                            :key="c.id"
                            type="button"
                            class="btn small"
                            :class="picked[i] === c.id ? 'copper' : 'secondary'"
                            @click="pick(i, c)"
                        >
                            {{ c.primary_name }}<span v-if="c.death_year_h"> (ت {{ c.death_year_h }}هـ)</span>
                        </button>
                    </div>
                    <div v-else class="meta">لا مرشح — تبقى الحلقة غير محسومة.</div>
                </div>
            </div>
            <button type="button" class="btn" @click="apply">إضافة هذه الحلقات إلى السند</button>
        </template>
    </section>
</template>
