<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    node: { type: Object, required: true },
    childrenOf: { type: Object, required: true },
    titles: { type: Object, default: () => ({}) },
    startOpen: { type: Number, default: 4 },
});

const kids = computed(() => props.childrenOf[props.node.id] ?? []);
const open = ref(props.node.depth < props.startOpen);
const label = computed(() => {
    if (props.node.kind === 'person') return props.node.person.primary_name;
    if (props.node.kind === 'unresolved') return props.node.unresolved_name || 'اسم غير مكتوب';
    return 'علَم غير متاح لك';
});
const endsLabel = computed(() => props.node.ends_asnad_ids.map((id) => props.titles[id] || `سند #${id}`).join('، '));
</script>

<template>
    <li class="tree-node" data-test="tree-node">
        <div class="tree-row" :class="node.kind">
            <button v-if="kids.length" type="button" class="tree-toggle" :aria-expanded="open" :aria-label="open ? 'طيّ' : 'فتح'" @click="open = !open">
                {{ open ? '−' : '+' }}
            </button>
            <span v-else class="tree-toggle dot">•</span>
            <span class="tree-name">{{ label }}</span>
            <span v-if="node.kind !== 'person'" class="badge warn">{{ node.kind === 'hidden' ? 'غير متاح' : 'غير محسوم' }}</span>
            <span v-if="node.asnad_ids.length > 1" class="badge" :title="'عدد الأسانيد المارّة بهذه العقدة'">{{ node.asnad_ids.length }} أسانيد</span>
            <span v-if="endsLabel" class="meta ends">ينتهي هنا: {{ endsLabel }}</span>
        </div>
        <ul v-if="kids.length && open" class="tree-children">
            <TreeNode v-for="k in kids" :key="k.id" :node="k" :children-of="childrenOf" :titles="titles" :start-open="startOpen" />
        </ul>
    </li>
</template>
