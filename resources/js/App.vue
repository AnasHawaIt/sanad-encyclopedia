<script setup>
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();
const router = useRouter();

async function logout() {
    await auth.logout();
    router.push({ name: 'login' });
}
</script>

<template>
    <header class="topbar">
        <strong>موسوعة الأسانيد والإجازات العلمية</strong>
        <RouterLink v-if="auth.isAuthenticated" to="/asnads">الأسانيد</RouterLink>
        <RouterLink v-if="auth.can('reviews.decide')" to="/reviews">المراجعة</RouterLink>
        <RouterLink v-if="auth.can('merges.view')" to="/merges">الدمج</RouterLink>
        <RouterLink v-if="auth.can('audit.view')" to="/audit">التدقيق</RouterLink>
        <span class="spacer"></span>
        <template v-if="auth.isAuthenticated">
            <span>{{ auth.user?.name }}</span>
            <button type="button" class="btn secondary small" style="color: var(--ivory); border-color: var(--ivory)" @click="logout">خروج</button>
        </template>
    </header>
    <RouterView />
</template>
