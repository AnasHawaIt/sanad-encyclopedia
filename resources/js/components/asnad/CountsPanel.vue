<script setup>
defineProps({ counts: { type: Object, default: null } });
</script>

<template>
    <section class="card" aria-label="عدد الرجال">
        <h2>عدد الرجال</h2>
        <p v-if="!counts" class="def">لا بيانات.</p>
        <template v-else>
            <div v-if="!counts.complete" class="notice" role="status">
                <strong>النتيجة غير مكتملة:</strong>
                <ul>
                    <li v-for="issue in counts.issues" :key="issue.code">{{ issue.message }}</li>
                </ul>
            </div>
            <div class="stat">
                <span>الرجال في السند</span>
                <b data-test="persons-in-chain">{{ counts.persons_in_chain ?? '—' }}</b>
            </div>
            <p class="def">{{ counts.definitions?.persons_in_chain }}</p>
            <div class="stat">
                <span>الوسائط</span>
                <b data-test="intermediaries">{{ counts.intermediaries ?? '—' }}</b>
            </div>
            <p class="def">{{ counts.definitions?.intermediaries }}</p>
            <div class="stat">
                <span>أعلام مختلفون</span>
                <b data-test="unique-persons">{{ counts.unique_persons ?? '—' }}</b>
            </div>
            <p class="def">
                {{ counts.definitions?.unique_persons }}
                <span v-if="counts.unique_is_lower_bound" class="badge warn">حدّ أدنى بسبب حلقات غير محسومة</span>
            </p>
            <div class="stat">
                <span>حلقات غير محسومة</span>
                <b data-test="unresolved">{{ counts.unresolved_positions }}</b>
            </div>
            <p class="def">
                المدى المحسوب: من {{ counts.range?.from ?? '—' }} إلى {{ counts.range?.to ?? '—' }}
                <span class="badge">{{ counts.range?.explicit ? 'حدود محددة يدويًا' : 'السند كله' }}</span>
            </p>
        </template>
    </section>
</template>
