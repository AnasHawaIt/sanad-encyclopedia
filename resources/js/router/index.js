import { createRouter, createWebHistory } from 'vue-router';
import HomeView from '@/views/HomeView.vue';
import LoginView from '@/views/LoginView.vue';
import AsnadListView from '@/views/AsnadListView.vue';
import AsnadCompareView from '@/views/AsnadCompareView.vue';
import AsnadEditorView from '@/views/AsnadEditorView.vue';
import ReviewQueueView from '@/views/ReviewQueueView.vue';
import MergeRequestsView from '@/views/MergeRequestsView.vue';
import AuditView from '@/views/AuditView.vue';
import { useAuthStore } from '@/stores/auth';

export const routes = [
    { path: '/', name: 'home', component: HomeView },
    { path: '/login', name: 'login', component: LoginView, meta: { guest: true } },
    { path: '/asnads', name: 'asnads', component: AsnadListView, meta: { auth: true, permission: 'asnads.view' } },
    { path: '/asnads/compare', name: 'asnad-compare', component: AsnadCompareView, meta: { auth: true, permission: 'asnads.view' } },
    { path: '/asnads/:id(\\d+)', name: 'asnad-editor', component: AsnadEditorView, meta: { auth: true, permission: 'asnads.view' } },
    { path: '/reviews', name: 'review-queue', component: ReviewQueueView, meta: { auth: true, permission: 'reviews.decide' } },
    { path: '/merges', name: 'merges', component: MergeRequestsView, meta: { auth: true, permission: 'merges.view' } },
    { path: '/audit', name: 'audit', component: AuditView, meta: { auth: true, permission: 'audit.view' } },
];

export function guard(auth) {
    return async (to) => {
        if (!auth.loaded) {
            await auth.load();
        }
        if (to.meta.auth && !auth.isAuthenticated) {
            return { name: 'login', query: { redirect: to.fullPath } };
        }
        if (to.meta.guest && auth.isAuthenticated) {
            return { name: 'asnads' };
        }
        if (to.meta.permission && !auth.can(to.meta.permission)) {
            return { name: 'home' };
        }
        return true;
    };
}

const router = createRouter({ history: createWebHistory(), routes });
router.beforeEach((to) => guard(useAuthStore())(to));

export default router;
