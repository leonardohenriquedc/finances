<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import type { Filters } from './types';
import { priorityLabels } from './priority';
import { normalizeYearFilters, yearMode } from './yearFilters';
import type { YearMode } from './yearFilters';
const props = defineProps<{ filters: Filters; categories: string[]; busy: boolean }>();
const emit = defineEmits<{ apply: [filters: Filters]; reset: [] }>();
const draft = reactive({ ...props.filters });
const annualPeriod = ref<YearMode>(yearMode(props.filters));
watch(() => props.filters, value => {
    Object.assign(draft, value);
    annualPeriod.value = yearMode(value);
}, { deep: true });
watch(annualPeriod, mode => Object.assign(draft, normalizeYearFilters(draft, mode)));
const months = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
</script>
<template>
    <form class="panel space-y-4" aria-label="Filtros combinados dos lançamentos e indicadores" @submit.prevent="emit('apply', { ...normalizeYearFilters(draft, annualPeriod), page: '1' })">
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-sm font-semibold">Personalize sua visão</h2><span class="text-xs text-slate-500">Todos os filtros se combinam pelo vencimento</span></div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
            <label class="field">Buscar descrição<input v-model="draft.text" type="search" placeholder="O que você procura?" /></label>
            <label class="field">Período anual<select v-model="annualPeriod"><option value="all">Todos os anos</option><option value="single">Ano específico</option><option value="range">Intervalo de anos</option></select></label>
            <label v-if="annualPeriod === 'single'" class="field">Ano<input v-model="draft.year" type="number" min="1900" max="9999" step="1" required /></label>
            <template v-if="annualPeriod === 'range'">
                <label class="field">Ano inicial<input v-model="draft.year_from" type="number" min="1900" :max="draft.year_to || 9999" step="1" required aria-describedby="year-range-help" /></label>
                <label class="field">Ano final<input v-model="draft.year_to" type="number" :min="draft.year_from || 1900" max="9999" step="1" required aria-describedby="year-range-help" /></label>
            </template>
            <label class="field">Mês<select v-model="draft.month"><option value="">Todos os meses</option><option v-for="(month, index) in months" :key="month" :value="String(index + 1)">{{ month }}</option></select></label>
            <label class="field">Categoria<input v-model="draft.category" list="filter-categories" placeholder="Todas as categorias" /><datalist id="filter-categories"><option v-for="category in categories" :key="category" :value="category" /></datalist></label>
            <label class="field">
                            Situação
                            <select v-model="draft.status">
                                <option value="">Todas as situações</option>
                                <optgroup label="Grupos de situações">
                                    <option value="realized">Realizado · todas as baixas</option>
                                    <option value="pending">Pendente · em aberto e em atraso</option>
                                </optgroup>
                                <optgroup label="Situações individuais">
                                    <option value="open">Em aberto</option>
                                    <option value="overdue">Em atraso</option>
                                    <option value="paid">Liquidado no prazo</option>
                                    <option value="paid_late">Liquidado em atraso</option>
                                </optgroup>
                            </select>
                        </label>
        </div>
        <p v-if="annualPeriod === 'range'" id="year-range-help" class="text-xs text-slate-500">O intervalo inclui ambos os anos: de 1º de janeiro do ano inicial a 31 de dezembro do ano final. Os demais filtros continuam se combinando com esse período.</p>
        <details><summary class="w-fit cursor-pointer text-xs font-medium text-brand-800">Mais filtros e ordenação</summary><div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="field">Vencimento de<input v-model="draft.from" type="date" /></label><label class="field">Vencimento até<input v-model="draft.to" type="date" :min="draft.from || undefined" /></label>
            <label class="field">Direção<select v-model="draft.direction"><option value="">Entradas e saídas</option><option value="credit">Somente entradas</option><option value="debit">Somente saídas</option></select></label>
            <label class="field">Prioridade de pagamento<select v-model="draft.priority"><option value="">Todas as prioridades</option><option v-for="(label, value) in priorityLabels" :key="value" :value="value">{{ label }}</option></select></label>
                        <label class="field">Tipo<select v-model="draft.kind"><option value="">Todos os tipos</option><option value="single">Único</option><option value="installment">Parcelado</option><option value="recurring">Recorrente</option></select></label>
            <label class="field">Ordenar por<select v-model="draft.sort"><option value="due_date">Vencimento</option><option value="amount">Valor</option><option value="description">Descrição</option><option value="category">Categoria</option><option value="status">Situação</option><option value="priority">Prioridade de pagamento</option></select></label><label class="field">Ordem<select v-model="draft.order"><option value="asc">Crescente</option><option value="desc">Decrescente</option></select></label><label class="field">Por página<select v-model="draft.per_page"><option value="10">10 registros</option><option value="25">25 registros</option><option value="50">50 registros</option><option value="100">100 registros</option></select></label>
        </div><p class="mt-3 text-xs text-slate-500">A prioridade organiza o pagamento das saídas, sem alterar vencimento ou situação. Na ordenação, crescente vai de Quando Puder a Extrema Necessidade; decrescente inverte essa ordem. Entradas ficam ao final em ambas.</p></details>
        <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4"><button class="btn-primary" :disabled="busy">{{ busy ? 'Atualizando…' : 'Aplicar filtros' }}</button><button type="button" class="btn-quiet" :disabled="busy" @click="emit('reset')">Limpar filtros</button><p class="ml-auto text-xs text-slate-500">Filtros salvos no endereço desta página.</p></div>
    </form>
</template>
