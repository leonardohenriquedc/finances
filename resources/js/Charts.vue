<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Dashboard, DashboardCategory, Month } from './types';
import { money, moneyDifference } from './api';
const props = defineProps<{ data: Dashboard }>();
const emit = defineEmits<{ filter: [filters: Record<string, string>] }>();
const tooltip = ref('');
const maxFlow = computed(() => Math.max(1, ...props.data.months.flatMap(m => [Number(m.credit), Number(m.debit)])));
const maxCategory = computed(() => Math.max(1, ...props.data.categories.map(c => Number(c.total))));
const maxBalance = computed(() => Math.max(1, ...props.data.months.flatMap(m => [Math.abs(Number(m.balance)), Math.abs(Number(m.realized_balance))])));
const spansYears = computed(() => new Set(props.data.months.map(month => month.month.slice(0, 4))).size > 1);

function monthLabel(value: string): string {
    const month = new Intl.DateTimeFormat('pt-BR', { month: 'short' })
        .format(new Date(`${value}-15T12:00:00`));
    return spansYears.value ? `${month.replace('.', '')}/${value.slice(2, 4)}` : month;
}

function monthFilter(month: string): void {
    emit('filter', { year: month.slice(0, 4), month: String(Number(month.slice(5, 7))) });
}

function balanceTitle(month: Month): string {
    const recurringNet = moneyDifference(month.recurring_credit, month.recurring_debit);
    return `${month.month}: saldo projetado acumulado ${money(month.balance)}; saldo realizado acumulado ${money(month.realized_balance)}. Contribuição líquida recorrente somente deste mês: ${money(recurringNet)} (entradas ${money(month.recurring_credit)} menos saídas ${money(month.recurring_debit)}). Abrir mês.`;
}

function flowTitle(month: Month): string {
    return `${month.month}: entradas ${money(month.credit)} (recorrentes ${money(month.recurring_credit)}); saídas ${money(month.debit)} (recorrentes ${money(month.recurring_debit)}). Abrir lançamentos.`;
}

function categoryRecurring(category: DashboardCategory): string {
    if (category.recurring_credit === undefined || category.recurring_debit === undefined) {
        return 'Detalhamento recorrente indisponível.';
    }
    return `Recorrentes: entradas ${money(category.recurring_credit)} · saídas ${money(category.recurring_debit)}.`;
}

function categoryTitle(category: DashboardCategory): string {
    return `${category.category}: entradas ${money(category.credit)}; saídas ${money(category.debit)}. ${categoryRecurring(category)} Filtrar categoria.`;
}

function settlementTitle(status: 'realized' | 'pending'): string {
    const settlement = props.data.settlement;
    const recurring = status === 'realized' ? settlement.recurring_realized : settlement.recurring_pending;
    const label = status === 'realized' ? 'Realizado' : 'Pendente';
    const scope = status === 'realized' ? 'todas as baixas, no prazo ou em atraso' : 'todos os lançamentos em aberto ou em atraso';
    const detail = recurring === undefined ? 'Detalhamento recorrente indisponível.' : `Deste valor, ${money(recurring)} são recorrentes.`;
    return `${label}: ${money(settlement[status])}. ${detail} Ver ${scope}.`;
}
const balanceY = (value: string) => 110 - Number(value) / maxBalance.value * 85;
const balanceX = (index: number) => 35 + index * (530 / Math.max(1, props.data.months.length - 1));
const line = (key: 'balance' | 'realized_balance') => props.data.months.map((m, i) => `${balanceX(i)},${balanceY(m[key])}`).join(' ');
const settlementTotal = computed(() => Number(props.data.settlement.realized) + Number(props.data.settlement.pending));
const realizedPercent = computed(() => settlementTotal.value ? Number(props.data.settlement.realized) / settlementTotal.value * 100 : 0);
</script>
<template>
    <div class="grid gap-5 xl:grid-cols-2">
        <section class="panel min-w-0">
            <div class="section-heading"><div><h2>Entradas e saídas</h2><p>Valores previstos por mês de vencimento</p></div><span class="eyebrow">FLUXO</span></div>
            <div v-if="!data.months.length" class="chart-empty">Sem movimentos para este período.</div>
            <div v-else class="overflow-x-auto">
                <svg viewBox="0 0 600 250" class="min-w-100 w-full" role="group" aria-label="Gráfico de entradas e saídas. Use Tab e Enter para abrir um mês.">
                    <line v-for="y in [40, 90, 140, 190]" :key="y" x1="25" x2="590" :y1="y" :y2="y" stroke="#e2e8f0" stroke-dasharray="3 5" />
                    <g v-for="(m, i) in data.months" :key="m.month" tabindex="0" role="button" :aria-label="flowTitle(m)" class="chart-target" @mouseenter="tooltip = flowTitle(m)" @mouseleave="tooltip = ''" @focus="tooltip = flowTitle(m)" @blur="tooltip = ''" @click="monthFilter(m.month)" @keydown.enter.prevent="monthFilter(m.month)" @keydown.space.prevent="monthFilter(m.month)">
                        <title>{{ flowTitle(m) }}</title>
                        <rect :x="28 + i * 560 / data.months.length" y="20" :width="550 / data.months.length" height="200" fill="transparent" />
                        <rect :x="32 + i * 560 / data.months.length" :y="200 - Number(m.credit) / maxFlow * 160" :width="Math.max(3, 220 / data.months.length)" :height="Number(m.credit) / maxFlow * 160" rx="3" fill="var(--color-brand-400)" />
                        <rect :x="32 + i * 560 / data.months.length" :y="200 - Number(m.recurring_credit) / maxFlow * 160" :width="Math.max(3, 220 / data.months.length)" :height="Number(m.recurring_credit) / maxFlow * 160" rx="2" fill="var(--color-brand-700)" />
                        <rect :x="35 + (i * 560 + 240) / data.months.length" :y="200 - Number(m.debit) / maxFlow * 160" :width="Math.max(3, 220 / data.months.length)" :height="Number(m.debit) / maxFlow * 160" rx="3" fill="#fda4af" />
                        <rect :x="35 + (i * 560 + 240) / data.months.length" :y="200 - Number(m.recurring_debit) / maxFlow * 160" :width="Math.max(3, 220 / data.months.length)" :height="Number(m.recurring_debit) / maxFlow * 160" rx="2" fill="#be123c" />
                        <text :x="40 + (i * 560 + 150) / data.months.length" y="230" text-anchor="middle" fill="#64748b" font-size="11">{{ monthLabel(m.month) }}</text>
                    </g>
                </svg>
            </div>
            <div class="flex flex-wrap gap-x-4 gap-y-2 text-xs text-slate-600"><span>● <span class="text-brand-700">Entradas</span></span><span>● <span class="text-rose-700">Saídas</span></span><span>Tom escuro = parcela recorrente do valor</span></div>
        </section>
        <section class="panel min-w-0">
            <div class="section-heading"><div><h2>Evolução do saldo</h2><p>Acumulado no recorte, iniciando em zero</p></div><span class="eyebrow">PROJEÇÃO</span></div>
            <div v-if="!data.months.length" class="chart-empty">Sem dados para projetar.</div>
            <div v-else class="overflow-x-auto">
                <svg viewBox="0 0 600 250" class="min-w-100 w-full" role="group" aria-label="Saldo projetado e realizado acumulados. Selecione um ponto para abrir o mês.">
                    <line x1="25" x2="585" y1="110" y2="110" stroke="#cbd5e1" stroke-dasharray="4 4" /><text x="7" y="114" font-size="10" fill="#64748b">0</text>
                    <polyline :points="line('balance')" fill="none" stroke="var(--color-brand-600)" stroke-width="3" /><polyline :points="line('realized_balance')" fill="none" stroke="#6366f1" stroke-width="2" stroke-dasharray="5 4" />
                    <g v-for="(m, i) in data.months" :key="m.month" tabindex="0" role="button" class="chart-target" :aria-label="balanceTitle(m)" @mouseenter="tooltip = balanceTitle(m)" @mouseleave="tooltip = ''" @focus="tooltip = balanceTitle(m)" @blur="tooltip = ''" @click="monthFilter(m.month)" @keydown.enter.prevent="monthFilter(m.month)" @keydown.space.prevent="monthFilter(m.month)">
                        <title>{{ balanceTitle(m) }}</title>
                        <circle :cx="balanceX(i)" :cy="balanceY(m.balance)" r="7" fill="var(--color-brand-600)" stroke="white" stroke-width="2" /><circle :cx="balanceX(i)" :cy="balanceY(m.realized_balance)" r="4" fill="#6366f1" />
                        <text :x="balanceX(i)" y="232" text-anchor="middle" font-size="11" fill="#64748b">{{ monthLabel(m.month) }}</text>
                    </g>
                </svg>
            </div>
            <div class="flex gap-5 text-xs"><span class="text-brand-700">━ Saldo projetado</span><span class="text-indigo-700">┄ Saldo realizado</span></div>
        </section>
        <section class="panel">
            <div class="section-heading"><div><h2>Distribuição por categoria</h2><p>Entradas + saídas, sem compensação</p></div><span class="eyebrow">CATEGORIAS</span></div>
            <div v-if="!data.categories.length" class="chart-empty">Suas categorias aparecerão aqui.</div>
            <div v-else class="max-h-72 space-y-4 overflow-y-auto pr-2">
                <button v-for="c in data.categories" :key="c.category" class="group block w-full rounded-lg text-left" :title="categoryTitle(c)" :aria-label="categoryTitle(c)" @focus="tooltip = categoryTitle(c)" @blur="tooltip = ''" @click="emit('filter', { category: c.category })">
                    <span class="flex justify-between gap-3 text-sm">
                                            <span class="truncate font-medium">{{ c.category }}</span>
                                            <span class="shrink-0 tabular-nums text-slate-600">{{ money(c.total) }}</span>
                                        </span>
                                        <span class="mt-1 block text-xs text-slate-500">{{ categoryRecurring(c) }}</span>
                    <svg viewBox="0 0 500 14" class="mt-2 w-full" aria-hidden="true"><rect width="500" height="14" rx="7" fill="#f1f5f9" /><rect :width="Number(c.credit) / maxCategory * 500" height="14" rx="7" fill="var(--color-brand-400)" /><rect :x="Number(c.credit) / maxCategory * 500" :width="Number(c.debit) / maxCategory * 500" height="14" rx="7" fill="#fda4af" /></svg>
                </button>
            </div>
        </section>
        <section class="panel">
            <div class="section-heading"><div><h2>Realizado e pendente</h2><p>Volume bruto de entradas e saídas</p></div><span class="eyebrow">BAIXAS MANUAIS</span></div>
            <div v-if="!settlementTotal" class="chart-empty">Ainda não há valores neste recorte.</div>
            <div v-else class="flex flex-wrap items-center justify-center gap-8 py-3">
                <svg viewBox="0 0 160 160" class="size-44" role="group" aria-label="Proporção de valores realizados e pendentes. Use os segmentos ou botões ao lado para filtrar.">
                    <title>Realizado: {{ money(data.settlement.realized) }}. Pendente: {{ money(data.settlement.pending) }}.</title>
                    <circle
                                            cx="80" cy="80" r="62" fill="none" stroke="#e2e8f0" stroke-width="19"
                                            role="button" tabindex="0" class="chart-target" :aria-label="settlementTitle('pending')"
                                            @focus="tooltip = settlementTitle('pending')" @blur="tooltip = ''"
                                            @click="emit('filter', { status: 'pending' })"
                                            @keydown.enter.prevent="emit('filter', { status: 'pending' })"
                                            @keydown.space.prevent="emit('filter', { status: 'pending' })"
                                        >
                                            <title>{{ settlementTitle('pending') }}</title>
                                        </circle>
                    <circle
                                            cx="80" cy="80" r="62" fill="none" stroke="var(--color-brand-600)" stroke-width="19" pathLength="100"
                                            :stroke-dasharray="`${realizedPercent} ${100 - realizedPercent}`" transform="rotate(-90 80 80)"
                                            role="button" tabindex="0" class="chart-target" :aria-label="settlementTitle('realized')"
                                            @focus="tooltip = settlementTitle('realized')" @blur="tooltip = ''"
                                            @click="emit('filter', { status: 'realized' })"
                                            @keydown.enter.prevent="emit('filter', { status: 'realized' })"
                                            @keydown.space.prevent="emit('filter', { status: 'realized' })"
                                        >
                                            <title>{{ settlementTitle('realized') }}</title>
                                        </circle>
                    <text x="80" y="79" text-anchor="middle" font-size="25" font-weight="600" fill="#0f172a">{{ Math.round(realizedPercent) }}%</text><text x="80" y="100" text-anchor="middle" font-size="11" fill="#64748b">realizado</text>
                </svg>
                <div class="flex flex-col gap-3">
                                    <button
                                        v-for="status in (['realized', 'pending'] as const)" :key="status"
                                        class="rounded-lg p-2 text-left hover:bg-brand-50"
                                        :title="settlementTitle(status)" :aria-label="settlementTitle(status)"
                                        @click="emit('filter', { status })"
                                    >
                                        <span class="text-sm text-slate-500">{{ status === 'realized' ? '● Realizado · todas as baixas' : '○ Pendente · inclui atrasos' }}</span>
                                        <strong class="block text-xl">{{ money(data.settlement[status]) }}</strong>
                                        <span v-if="data.settlement[status === 'realized' ? 'recurring_realized' : 'recurring_pending'] !== undefined" class="mt-1 block text-xs text-slate-500">
                                            Recorrentes: {{ money(data.settlement[status === 'realized' ? 'recurring_realized' : 'recurring_pending']) }}
                                        </span>
                                        <span v-else class="mt-1 block text-xs text-slate-500">Detalhamento recorrente indisponível</span>
                                        <small class="mt-1 block text-brand-800">{{ status === 'realized' ? 'Ver todas as baixas →' : 'Ver todos os pendentes →' }}</small>
                                    </button>
                                </div>
            </div>
        </section>
    </div>
    <p class="text-xs leading-relaxed text-slate-500">Os valores recorrentes já estão incluídos nos totais de todos os gráficos; não devem ser somados novamente. No saldo, o detalhamento recorrente é a contribuição líquida do mês, não um saldo acumulado.</p>
        <p class="min-h-5 text-xs text-slate-500" aria-live="polite">{{ tooltip || 'Explore os gráficos: clique ou use Tab + Enter para detalhar os lançamentos.' }}</p>
</template>
