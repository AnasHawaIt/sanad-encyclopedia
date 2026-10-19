<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { errorMessage } from '@/api/errors';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const email = ref('');
const password = ref('');
const error = ref('');
const busy = ref(false);

async function submit() {
    busy.value = true;
    error.value = '';
    try {
        await auth.login(email.value, password.value);
        const target = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/asnads';
        router.push(target);
    } catch (e) {
        error.value = errorMessage(e, 'تعذّر تسجيل الدخول.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="page" style="max-width: 26rem">
        <form class="card" @submit.prevent="submit">
            <h2>تسجيل الدخول</h2>
            <div class="field"><label for="email">البريد الإلكتروني</label><input id="email" v-model="email" class="input" type="email" autocomplete="username" required /></div>
            <div class="field"><label for="password">كلمة المرور</label><input id="password" v-model="password" class="input" type="password" autocomplete="current-password" required /></div>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <button class="btn" :disabled="busy">دخول</button>
        </form>
    </div>
</template>
