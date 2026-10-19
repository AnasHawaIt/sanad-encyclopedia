<script setup>
import { computed, ref } from 'vue';
import PersonPicker from './PersonPicker.vue';

const props = defineProps({
    node: { type: Object, required: true },
    index: { type: Number, required: true },
    total: { type: Number, required: true },
    editable: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    outsideRange: { type: Boolean, default: false },
});
const emit = defineEmits(['up', 'down', 'remove', 'link', 'unlink']);

const linking = ref(false);
const resolved = computed(() => props.node.is_linked);
const label = computed(() => props.node.person?.primary_name ?? props.node.unresolved_name ?? '');

function onPick(person) {
    linking.value = false;
    emit('link', person);
}
</script>

<template>
    <div class="node" :class="{ unresolved: !resolved, outside: outsideRange }" data-test="node">
        <div class="seq" :title="`الموضع ${node.sequence_number}`">{{ node.sequence_number }}</div>
        <div>
            <div class="name">
                <template v-if="node.person">{{ node.person.primary_name }}</template>
                <template v-else-if="node.is_linked"><span class="meta">علَم غير متاح لك</span></template>
                <template v-else>{{ node.unresolved_name }}</template>
            </div>
            <div v-if="!resolved" class="meta"><span class="badge warn">غير محسومة — لم تُربط بعلَم</span></div>
            <div v-else-if="node.person && node.unresolved_name && node.unresolved_name !== node.person.primary_name" class="meta">
                الاسم كما ورد: {{ node.unresolved_name }}
            </div>
            <div v-if="node.text_segment" class="meta">«{{ node.text_segment }}»</div>
            <div v-if="node.transmission_mode" class="meta">صيغة التحمّل: {{ node.transmission_mode.name }}</div>
            <div v-if="outsideRange" class="meta">خارج المدى المحسوب</div>
            <div v-if="editable && linking" class="row" style="margin-top: 0.5rem">
                <PersonPicker @pick="onPick" />
                <button type="button" class="btn secondary small" @click="linking = false">إلغاء</button>
            </div>
        </div>
        <div v-if="editable" class="row">
            <button type="button" class="btn secondary small" :disabled="busy || index === 0" aria-label="تقديم" @click="emit('up')">▲</button>
            <button type="button" class="btn secondary small" :disabled="busy || index === total - 1" aria-label="تأخير" @click="emit('down')">▼</button>
            <button v-if="!linking" type="button" class="btn copper small" :disabled="busy" @click="linking = true">
                {{ resolved ? 'تغيير العلَم' : 'ربط بعلَم' }}
            </button>
            <button v-if="node.is_linked" type="button" class="btn secondary small" :disabled="busy" @click="emit('unlink')">فك الربط</button>
            <button type="button" class="btn danger small" :disabled="busy" @click="emit('remove')">حذف</button>
        </div>
    </div>
</template>
