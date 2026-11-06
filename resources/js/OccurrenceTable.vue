<script setup lang="ts">
import PriorityBadge from './PriorityBadge.vue';
import type { Occurrence, OccurrencePage } from './types';
import { money, date, statusLabel, kindLabel } from './api';
const props = defineProps<{ result: OccurrencePage; busy: boolean; exportUrl: string; sortColumn: string; sortOrder: string }>();
function ariaSort(column: string): 'ascending' | 'descending' | 'none' { return props.sortColumn === column ? props.sortOrder === 'asc' ? 'ascending' : 'descending' : 'none'; }
const emit = defineEmits<{ edit: [item: Occurrence]; payment: [item: Occurrence]; remove: [item: Occurrence]; reopen: [item: Occurrence]; page: [page: number]; create: []; sort: [column: string] }>();
</script>
<template>
    <section class="panel overflow-hidden p-0!" :aria-busy="busy">
        <div class="flex flex-wrap items-center justify-between gap-3 p-5"><div><h2 class="text-lg font-semibold">Seus lançamentos</h2><p class="mt-1 text-xs text-slate-500">{{ result.meta.total }} registros no recorte · valores por vencimento</p></div><a :href="exportUrl" class="btn-secondary" download>↓ Exportar CSV</a></div>
        <div v-if="!result.data.length" class="flex flex-col items-center gap-4 px-6 py-16 text-center"><span class="flex size-14 items-center justify-center rounded-2xl bg-brand-50 text-2xl text-brand-700" aria-hidden="true">≡</span><h3 class="font-semibold">Nenhum lançamento por aqui</h3><p class="max-w-sm text-sm text-slate-500">Ajuste os filtros ou crie seu primeiro planejamento para começar a enxergar suas finanças.</p><button class="btn-primary" @click="emit('create')">+ Novo planejamento</button></div>
        <div v-else class="overflow-x-auto"><table class="w-full min-w-245 text-left text-sm"><caption class="sr-only">Entradas e saídas filtradas. Totais consideram todos os registros, não apenas esta página.</caption><thead class="border-y border-slate-200 bg-slate-50 text-xs text-slate-500"><tr><th scope="col" class="px-5 py-3" :aria-sort="ariaSort('description')"><button @click="emit('sort', 'description')">Descrição ↕</button></th><th scope="col" class="px-4 py-3" :aria-sort="ariaSort('category')"><button @click="emit('sort', 'category')">Categoria ↕</button></th><th scope="col" class="px-4 py-3" :aria-sort="ariaSort('due_date')"><button @click="emit('sort', 'due_date')">Vencimento ↕</button></th><th scope="col" class="px-4 py-3" :aria-sort="ariaSort('amount')"><button @click="emit('sort', 'amount')">Valor ↕</button></th><th scope="col" class="px-4 py-3" :aria-sort="ariaSort('status')"><button @click="emit('sort', 'status')">Situação ↕</button></th><th scope="col" class="px-4 py-3" :aria-sort="ariaSort('priority')"><button @click="emit('sort', 'priority')">Prioridade ↕</button></th><th scope="col" class="px-4 py-3">Baixa manual</th><th scope="col" class="px-5 py-3 text-right">Ações</th></tr></thead>
            <tbody class="divide-y divide-slate-100"><tr v-for="item in result.data" :key="item.id" class="hover:bg-slate-50/70"><td class="px-5 py-4"><div class="flex items-center gap-3"><span class="flex size-9 shrink-0 items-center justify-center rounded-xl text-lg" :class="item.direction === 'credit' ? 'bg-brand-50 text-brand-700' : 'bg-rose-50 text-rose-600'" :aria-label="item.direction === 'credit' ? 'Entrada' : 'Saída'">{{ item.direction === 'credit' ? '↗' : '↘' }}</span><div><p class="max-w-64 truncate font-medium" :title="item.description">{{ item.description }}</p><p class="mt-1 text-xs text-slate-500">{{ kindLabel[item.kind] }}<template v-if="item.kind !== 'single'"> · #{{ item.sequence }}</template><span v-if="item.is_override"> · Ajustado</span></p><p class="mt-1 text-xs text-slate-400">Competência {{ date(item.competence_date) }}</p></div></div></td><td class="px-4 py-4"><span class="rounded-md bg-slate-100 px-2 py-1 text-xs">{{ item.category }}</span></td><td class="whitespace-nowrap px-4 py-4 tabular-nums">{{ date(item.due_date) }}</td><td class="whitespace-nowrap px-4 py-4 font-semibold tabular-nums" :class="item.direction === 'credit' ? 'text-brand-700' : 'text-slate-800'">{{ item.direction === 'credit' ? '+' : '−' }} {{ money(item.amount) }}</td><td class="px-4 py-4"><span class="badge" :class="item.status === 'overdue' ? 'bg-rose-50 text-rose-700' : item.paid_at ? 'bg-brand-50 text-brand-800' : 'bg-amber-50 text-amber-800'">{{ statusLabel[item.status] }}</span></td><td class="px-4 py-4"><PriorityBadge :direction="item.direction" :priority="item.priority" /></td><td class="px-4 py-4 text-xs text-slate-500"><template v-if="item.paid_at"><span class="block whitespace-nowrap">{{ date(item.paid_at) }}</span><span class="mt-1 block font-medium tabular-nums">{{ money(item.paid_amount) }}</span></template><span v-else>Não registrada</span></td><td class="px-5 py-4"><div class="flex justify-end gap-2"><button
                                            class="table-action"
                                            :disabled="busy || item.plan.state === 'ended'"
                                            :title="item.plan.state === 'ended' ? 'Planos encerrados não podem ser editados' : undefined"
                                            :aria-label="`Editar ${item.description}`"
                                            @click="emit('edit', item)"
                                        >
                                            Editar
                                        </button><button v-if="!item.paid_at" class="table-action text-brand-800!" :disabled="busy" @click="emit('payment', item)">Baixar</button><button v-else class="table-action" :disabled="busy" @click="emit('reopen', item)">Reabrir</button><button v-if="!item.paid_at" class="table-action text-rose-700!" :disabled="busy" @click="emit('remove', item)" :aria-label="`Excluir ocorrência ${item.description}`">Excluir</button></div></td></tr></tbody>
            </table>
        </div>
        <div class="grid gap-3 border-t border-slate-200 bg-slate-50/60 p-5 text-sm sm:grid-cols-3">
            <p>Entradas do recorte <strong class="block text-brand-800 tabular-nums">{{ money(result.totals.credit) }}</strong></p>
            <p>Saídas do recorte <strong class="block text-rose-700 tabular-nums">{{ money(result.totals.debit) }}</strong></p>
            <p>Saldo previsto <strong class="block tabular-nums">{{ money(result.totals.projected_balance) }}</strong></p>
            <p class="text-xs text-slate-500 sm:col-span-3">Totais de todos os registros filtrados, não apenas desta página.</p>
        </div>
        <nav class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-5" aria-label="Paginação dos lançamentos">
            <p class="text-xs text-slate-500">
                Página {{ result.meta.current_page }} de {{ result.meta.last_page }} · {{ result.meta.total }} registros
            </p>
            <div class="flex gap-2">
                <button class="btn-secondary" :disabled="busy || result.meta.current_page <= 1" @click="emit('page', result.meta.current_page - 1)">
                    ← Anterior
                </button>
                <button class="btn-secondary" :disabled="busy || result.meta.current_page >= result.meta.last_page" @click="emit('page', result.meta.current_page + 1)">
                    Próxima →
                </button>
            </div>
        </nav>
    </section>
</template>