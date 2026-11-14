<script setup lang="ts">
import { reactive, ref } from 'vue';
import { api, refreshCsrf, ApiError, errorMessage } from './api';
import type { User, FieldErrors } from './types';
import Errors from './Errors.vue';
const emit = defineEmits<{ authenticated: [user: User] }>();
const props = defineProps<{ initialRegister?: boolean }>();
const register = ref(props.initialRegister ?? false), busy = ref(false), error = ref(''), fields = ref<FieldErrors>({});
const form = reactive({ name: '', email: '', password: '', password_confirmation: '' });
async function submit() {
    busy.value = true; error.value = ''; fields.value = {};
    try { await refreshCsrf(); const result = await api<{data: User}>(register.value ? '/register' : '/login', 'POST', register.value ? form : { email: form.email, password: form.password }); form.password = ''; form.password_confirmation = ''; await refreshCsrf(); emit('authenticated', result.data); }
    catch (e) { error.value = errorMessage(e); if (e instanceof ApiError) fields.value = e.fields; }
    finally { busy.value = false; }
}
</script>
<template>
    <main class="grid min-h-screen lg:grid-cols-2">
        <section class="relative flex flex-col justify-between overflow-hidden bg-linear-to-br from-brand-950 via-brand-900 to-brand-700 px-7 py-9 text-white sm:px-16 lg:py-14">
            <div class="absolute -right-40 top-20 size-140 rounded-full border border-brand-700/40" aria-hidden="true"></div><div class="absolute -right-16 top-44 size-92 rounded-full border border-brand-700/40" aria-hidden="true"></div>
            <a href="/" class="relative flex items-center gap-3 font-semibold tracking-tight" aria-label="DamascenoSRV Finances — início"><span class="brand-mark" aria-hidden="true">◈</span><span><span class="block text-2xl leading-tight sm:text-3xl">DamascenoSRV</span><span class="block text-base font-medium tracking-wide text-brand-200">Finances</span></span></a>
            <div class="relative max-w-lg py-16 lg:py-24"><span class="eyebrow text-brand-300!">CLAREZA PARA O QUE VEM</span><h1 class="mt-5 text-4xl leading-tight font-medium tracking-tight sm:text-5xl">Seu amanhã começa<br />com um bom plano.</h1><p class="mt-6 max-w-sm text-base leading-relaxed text-brand-100/80">Organize suas entradas e saídas, acompanhe parcelas e enxergue o futuro das suas finanças em um só lugar.</p><div class="mt-9 flex flex-wrap gap-3 text-xs text-brand-100"><span class="rounded-full border border-brand-700 px-3 py-2">Planejamento consciente</span><span class="rounded-full border border-brand-700 px-3 py-2">Sem conexão bancária</span></div></div>
            <p class="relative text-xs text-brand-200/70">Mais perspectiva. Menos surpresas.</p>
        </section>
        <section class="flex items-center justify-center bg-canvas px-6 py-12">
            <div class="w-full max-w-sm"><p class="eyebrow">SEU ESPAÇO FINANCEIRO</p><h2 class="mt-3 text-3xl font-semibold tracking-tight">{{ register ? 'Comece seu planejamento' : 'Que bom ter você aqui' }}</h2><p class="mt-3 text-sm text-slate-500">{{ register ? 'Crie sua conta para organizar o que vem pela frente.' : 'Entre para acompanhar seus planos e próximos passos.' }}</p>
                <form class="mt-8 space-y-5" @submit.prevent="submit"><Errors :message="error" :fields="fields" /><fieldset :disabled="busy" class="space-y-4"><label v-if="register" class="field">Seu nome<input v-model="form.name" required autocomplete="name" :aria-invalid="!!fields.name" /></label><label class="field">E-mail<input v-model="form.email" type="email" required autocomplete="email" placeholder="voce@exemplo.com" :aria-invalid="!!fields.email" /></label><label class="field">Senha<input v-model="form.password" type="password" required :minlength="register ? 8 : undefined" :autocomplete="register ? 'new-password' : 'current-password'" :aria-invalid="!!fields.password" /></label><label v-if="register" class="field">Confirme sua senha<input v-model="form.password_confirmation" type="password" required minlength="8" autocomplete="new-password" :aria-invalid="!!fields.password_confirmation" /></label></fieldset><button class="btn-primary w-full" :disabled="busy">{{ busy ? 'Aguarde…' : register ? 'Criar minha conta →' : 'Entrar na minha conta →' }}</button></form>
                <p class="mt-7 text-center text-sm text-slate-500">{{ register ? 'Já tem uma conta?' : 'Ainda não tem uma conta?' }} <button class="font-semibold text-brand-800 underline-offset-4 hover:underline" :disabled="busy" @click="register = !register; error = ''; fields = {}; form.password = ''; form.password_confirmation = ''">{{ register ? 'Entrar' : 'Criar conta' }}</button></p><p class="mt-10 text-xs leading-relaxed text-slate-500">Este é um espaço de planejamento. Os valores realizados são registrados manualmente, sem transações ou integração bancária.</p>
            </div>
        </section>
    </main>
</template>
