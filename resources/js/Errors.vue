<script setup lang="ts">
import type { FieldErrors } from './types';
import { fieldLabels } from './api';
defineProps<{ message?: string; fields?: FieldErrors }>();
function localizedMessage(message: string): string {
    if (/credentials.*(match|incorrect)|password is incorrect/i.test(message)) return 'E-mail ou senha incorretos.';
    if (/already been taken/i.test(message)) return 'Este valor já está em uso.';
    if (/confirmation.*match|does not match.*confirmation/i.test(message)) return 'A confirmação deve ser igual à senha.';
    if (/required/i.test(message)) return 'Este campo é obrigatório.';
    if (/valid email/i.test(message)) return 'Informe um endereço de e-mail válido.';
    const minimum = message.match(/at least (\d+) characters/i);
    if (minimum) return `Use pelo menos ${minimum[1]} caracteres.`;
    const maximum = message.match(/(?:greater than|more than) (\d+) characters/i);
    if (maximum) return `Use no máximo ${maximum[1]} caracteres.`;
    if (/must be an integer/i.test(message)) return 'Informe um número inteiro válido.';
    if (/must be a number|must be numeric/i.test(message)) return 'Informe um valor numérico válido.';
    if (/selected .* invalid/i.test(message)) return 'Selecione uma opção válida.';
    if (/not a valid date|must be a valid date/i.test(message)) return 'Informe uma data válida.';
    return message;
}
</script>
<template>
    <div v-if="message" role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
        <p class="font-semibold">{{ message }}</p>
        <ul v-if="fields && Object.keys(fields).length" class="mt-2 list-disc space-y-1 pl-5">
            <li v-for="(messages, field) in fields" :key="field"><strong>{{ fieldLabels[field] ?? field }}:</strong> {{ messages.map(localizedMessage).join(' ') }}</li>
        </ul>
    </div>
</template>
