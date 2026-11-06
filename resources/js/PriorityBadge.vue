<script setup lang="ts">
import { computed } from 'vue';
import type { Direction, Priority } from './types';
import { paymentPriority, priorityLabels } from './priority';

const props = defineProps<{ direction: Direction; priority: Priority | null }>();
const effectivePriority = computed(() => paymentPriority(props.direction, props.priority));
const colors: Record<Priority, string> = {
    when_possible: 'bg-slate-100 text-slate-700',
    soon: 'bg-amber-50 text-amber-900',
    essential: 'bg-rose-50 text-rose-800',
};
</script>
<template>
    <span v-if="effectivePriority" class="badge" :class="colors[effectivePriority]">
        <span class="sr-only">Prioridade de pagamento: </span>{{ priorityLabels[effectivePriority] }}
    </span>
</template>
