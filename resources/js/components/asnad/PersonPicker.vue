<script setup>
import { ref, onBeforeUnmount } from 'vue';
import { personsApi } from '@/api/asnads';
import { errorMessage } from '@/api/errors';

const emit = defineEmits(['pick']);
const query = ref('');
const results = ref([]);
const searched = ref(false);
const loading = ref(false);
const error = ref('');
let timer = null;
let ticket = 0;

function onInput() {
    clearTimeout(timer);
    error.value = '';
    if (query.value.trim() === '') {
        results.value = [];
        searched.value = false;
        return;
    }
    timer = setTimeout(run, 250);
}

async function run() {
    const mine = ++ticket;
    loading.value = true;
    try {
        const data = await personsApi.search(query.value.trim());
        if (mine === ticket) {
            results.value = data;
            searched.value = true;
        }
    } catch (e) {
        if (mine === ticket) {
            error.value = errorMessage(e);
        }
    } finally {
        if (mine === ticket) {
            loading.value = false;
        }
    }
}

function pick(person) {
    emit('pick', person);
    query.value = '';
    results.value = [];
    searched.value = false;
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="person-picker">
        <input v-model="query" class="input" type="search" placeholder="ابحث عن علَم بالاسم…" aria-label="بحث عن علَم" @input="onInput" />
        <p v-if="loading" class="def">جارٍ البحث…</p>
        <p v-if="error" class="error">{{ error }}</p>
        <ul v-if="results.length" class="picker-results">
            <li v-for="p in results" :key="p.id">
                <button type="button" class="btn secondary small" @click="pick(p)">
                    {{ p.primary_name }}
                    <span v-if="p.death_year_h" class="meta">(ت {{ p.death_year_h }}هـ)</span>
                </button>
            </li>
        </ul>
        <p v-else-if="searched && !loading" class="def">لا نتائج. يمكنك إبقاء الحلقة باسمها المكتوب دون ربط.</p>
    </div>
</template>

<style scoped>
.picker-results { list-style: none; padding: 0; margin: .5rem 0 0; display: flex; flex-wrap: wrap; gap: .35rem; }
</style>
