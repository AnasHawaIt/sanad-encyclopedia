<script setup>
    import { ref, onMounted } from 'vue';
    import { http } from '@/api/http';

    const state = ref('loading');

    onMounted(async () => {
        try {
            const { data } = await http.get('/health');
            state.value = data.status === 'ok' ? 'ok' : 'error';
        } catch {
            state.value = 'error';
        }
    });
</script>

<template>
    <main>
        <h1>موسوعة الأسانيد والإجازات العلمية</h1>
        <p v-if="state === 'loading'">جارٍ التحقق من الاتصال…</p>
        <p v-else-if="state === 'ok'">الاتصال بالخادم وقاعدة البيانات سليم.</p>
        <p v-else>تعذّر الاتصال بالخادم.</p>
    </main>
</template>
