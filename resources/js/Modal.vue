<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue';
defineProps<{ title: string; busy?: boolean }>();
const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement>();
let previous: HTMLElement | null = null;
onMounted(() => { previous = document.activeElement as HTMLElement; dialog.value?.showModal(); });
onBeforeUnmount(() => { dialog.value?.close(); previous?.focus(); });
</script>
<template>
    <Teleport to="body">
        <dialog ref="dialog" class="modal" aria-labelledby="modal-title" @cancel.prevent="!busy && emit('close')" @click="event => { if (event.target === dialog && !busy) emit('close'); }">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 p-6">
                <h2 id="modal-title" class="text-xl font-semibold">{{ title }}</h2>
                <button type="button" class="icon-button" aria-label="Fechar janela" :disabled="busy" @click="emit('close')">✕</button>
            </div>
            <div class="p-6"><slot /></div>
        </dialog>
    </Teleport>
</template>
