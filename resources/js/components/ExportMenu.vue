<script setup>
import { ref } from 'vue';
import { exportsApi } from '@/api/exports';
import { errorMessage } from '@/api/errors';
import { useAuthStore } from '@/stores/auth';

const props = defineProps({
    kind: { type: String, required: true }, // asnads | persons | ijazas
    params: { type: Object, default: () => ({}) },
    formats: { type: Array, default: () => [['csv', 'CSV (Excel)'], ['json', 'JSON']] },
});
const auth = useAuthStore();
const busy = ref(false);
const error = ref('');
const notice = ref('');

async function run(format) {
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const result = await exportsApi.download(props.kind, { ...props.params, format });
        notice.value = result.truncated
            ? `صُدِّر ${result.count} من ${result.total} فقط: وصلنا إلى الحد الأقصى، فضيّق التصفية لتصدير الباقي.`
            : `صُدِّر ${result.count} سجل.`;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div v-if="auth.can('exports.create')" class="export-menu" data-test="export-menu">
        <span class="meta">تصدير:</span>
        <button v-for="[value, label] in formats" :key="value" type="button" class="btn secondary small" :disabled="busy" @click="run(value)">{{ label }}</button>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <p v-if="notice" class="badge" role="status">{{ notice }}</p>
    </div>
</template>
