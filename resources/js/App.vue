<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, reactive, ref } from 'vue';
import Auth from './Auth.vue';
import Charts from './Charts.vue';
import FiltersPanel from './Filters.vue';
import OccurrenceTable from './OccurrenceTable.vue';
import Modal from './Modal.vue';
import PlanForm from './PlanForm.vue';
import Errors from './Errors.vue';
import PriorityBadge from './PriorityBadge.vue';
import { api, refreshCsrf, ApiError, errorMessage, money, date, today, statusLabel } from './api';
import type { User, Dashboard, FinancialPlan, Occurrence, OccurrencePage, Filters, FieldErrors } from './types';
import { chartFilters, filterParams, readFilterParams, yearLabel } from './yearFilters';

type View = 'dashboard' | 'transactions' | 'recurrences';
const user = ref<User | null>(null), starting = ref(true), busy = ref(false), actionBusy = ref(false);
const error = ref(''), errorFields = ref<FieldErrors>({}), notice = ref(''), categories = ref<string[]>([]);
const dashboard = ref<Dashboard | null>(null), result = ref<OccurrencePage | null>(null), plans = ref<FinancialPlan[]>([]);
const view = ref<View>('dashboard');
const defaults = (): Filters => ({ month: '', year: String(new Date().getFullYear()), year_from: '', year_to: '', from: '', to: '', category: '', status: '', text: '', direction: '', kind: '', priority: '', sort: 'due_date', order: 'asc', page: '1', per_page: '25' });
function readLocation(): Filters {
    const params = new URLSearchParams(location.search);
    const initial = readFilterParams(params, defaults());
    const tab = params.get('view');
    view.value = tab === 'transactions' || tab === 'recurrences' ? tab : 'dashboard';
    return initial;
}
const filters = ref<Filters>(readLocation());
function query(includePagination = true): string {
    return filterParams(filters.value, includePagination ? 'api' : 'export').toString();
}
function syncLocation(): void { const params = filterParams(filters.value, 'location'); params.set('view', view.value); history.pushState(null, '', `${location.pathname}?${params}`); }
const exportUrl = computed(() => `/api/v1/occurrences/export?${query(false)}`);
const scopeLabel = computed(() => `${filters.value.month ? `${['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'][Number(filters.value.month) - 1]} · ` : 'Todos os meses · '}${yearLabel(filters.value)}${filters.value.from || filters.value.to ? ' · intervalo personalizado' : ''}`);
let requestId = 0;
async function load(): Promise<void> {
    if (!user.value) return;
    const id = ++requestId; busy.value = true; error.value = ''; errorFields.value = {};
    dashboard.value = null; result.value = null; plans.value = [];
    try {
        if (view.value === 'recurrences') {
            const [recurring, categoryResponse] = await Promise.all([api<{data: FinancialPlan[]}>('/plans?kind=recurring'), api<{data: string[]}>('/categories')]);
            if (id === requestId && user.value) { plans.value = recurring.data; categories.value = categoryResponse.data; }
            return;
        }
        const [overview, occurrences, recurring, categoryResponse] = await Promise.all([
            api<{data: Dashboard}>(`/dashboard?${query(false)}`), api<OccurrencePage>(`/occurrences?${query()}`),
            api<{data: FinancialPlan[]}>('/plans?kind=recurring'), api<{data: string[]}>('/categories'),
        ]);
        if (id !== requestId || !user.value) return;
        dashboard.value = overview.data; result.value = occurrences; plans.value = recurring.data; categories.value = categoryResponse.data;
        if (occurrences.meta.current_page > occurrences.meta.last_page && occurrences.meta.last_page > 0) { filters.value.page = String(occurrences.meta.last_page); syncLocation(); await load(); }
    } catch (e) { if (id === requestId && user.value) { error.value = errorMessage(e); if (e instanceof ApiError) errorFields.value = e.fields; } }
    finally { if (id === requestId) busy.value = false; }
}
async function authenticated(value: User): Promise<void> { user.value = value; error.value = ''; await load(); }
function sessionExpired(): void { user.value = null; dashboard.value = null; result.value = null; plans.value = []; categories.value = []; modal.value = null; requestId++; busy.value = false; }
async function initialize(): Promise<void> {
    starting.value = true; error.value = '';
    try { await refreshCsrf(); const response = await api<{data: User}>('/user'); await authenticated(response.data); }
    catch (e) { if (!(e instanceof ApiError && e.status === 401)) error.value = errorMessage(e); }
    finally { starting.value = false; }
}
function popState(): void { filters.value = readLocation(); void load(); }
onMounted(() => { window.addEventListener('session-expired', sessionExpired); window.addEventListener('popstate', popState); void initialize(); });
onBeforeUnmount(() => { window.removeEventListener('session-expired', sessionExpired); window.removeEventListener('popstate', popState); });
function apply(next: Filters): void { filters.value = { ...next }; syncLocation(); void load(); }
function navigate(next: View): void { if (view.value === next) return; view.value = next; syncLocation(); void load(); }
function chartFilter(next: Record<string, string>): void { view.value = 'transactions'; apply(chartFilters(filters.value, next)); }
function sort(column: string): void { apply({ ...filters.value, sort: column, order: filters.value.sort === column && filters.value.order === 'asc' ? 'desc' : 'asc', page: '1' }); }
const modal = ref<'plan' | 'payment' | 'confirm' | null>(null), selectedPlan = ref<FinancialPlan>(), selectedOccurrence = ref<Occurrence>();
const modalBusy = ref(false), modalError = ref(''), modalFields = ref<FieldErrors>({});
function resetModal(): void { modalError.value = ''; modalFields.value = {}; modalBusy.value = false; }
function create(): void { resetModal(); selectedPlan.value = undefined; selectedOccurrence.value = undefined; modal.value = 'plan'; }
async function edit(item: Occurrence | FinancialPlan): Promise<void> {
    actionBusy.value = true; error.value = '';
    try {
            const isOccurrence = 'financial_plan_id' in item;
            const response = await api<{data: FinancialPlan}>(`/plans/${isOccurrence ? item.financial_plan_id : item.id}`);
            if (response.data.state === 'ended') {
                error.value = 'Planos encerrados não podem ser editados.';
                return;
            }
            resetModal();
            selectedPlan.value = response.data;
            selectedOccurrence.value = isOccurrence ? item : undefined;
            modal.value = 'plan';
        }
    catch (e) { error.value = errorMessage(e); }
    finally { actionBusy.value = false; }
}
async function saved(): Promise<void> { modal.value = null; notice.value = 'Planejamento salvo com sucesso.'; await load(); }
const payment = reactive({ paid_at: today(), paid_amount: '' });
function openPayment(item: Occurrence): void { resetModal(); selectedOccurrence.value = item; payment.paid_at = today(); payment.paid_amount = item.amount; modal.value = 'payment'; }
async function savePayment(): Promise<void> {
    if (!selectedOccurrence.value) return; modalBusy.value = true; modalError.value = ''; modalFields.value = {};
    try { await api(`/occurrences/${selectedOccurrence.value.id}/payment`, 'PUT', { paid_at: payment.paid_at, paid_amount: String(payment.paid_amount).replace(',', '.') }); modal.value = null; notice.value = 'Baixa manual registrada. Nenhuma movimentação bancária foi realizada.'; await load(); }
    catch (e) { modalError.value = errorMessage(e); if (e instanceof ApiError) modalFields.value = e.fields; }
    finally { modalBusy.value = false; }
}
const confirmation = ref<{ title: string; description: string; label: string; path: string; method: string; body?: unknown }>();
function confirmAction(action: NonNullable<typeof confirmation.value>): void { resetModal(); confirmation.value = action; modal.value = 'confirm'; }
function removeOccurrence(item: Occurrence): void { confirmAction({ title: 'Excluir esta ocorrência?', description: `“${item.description}”, com vencimento em ${date(item.due_date)}, será excluída sem remover o plano ou as outras ocorrências. Essa exclusão não será recriada pela recorrência.`, label: 'Excluir ocorrência', path: `/occurrences/${item.id}`, method: 'DELETE' }); }
function reopen(item: Occurrence): void { confirmAction({ title: 'Reabrir este lançamento?', description: `A baixa manual de “${item.description}” será removida. A situação passará a ser calculada pelo vencimento. Nenhum estorno bancário será feito.`, label: 'Remover baixa e reabrir', path: `/occurrences/${item.id}/payment`, method: 'DELETE' }); }
function lifecycle(plan: FinancialPlan, action: 'pause' | 'resume' | 'end'): void {
    const labels = { pause: 'Pausar', resume: 'Retomar', end: 'Encerrar' };
    const descriptions = {
        pause: 'A pausa remove os lançamentos futuros não liquidados, inclusive os ajustados individualmente. Lançamentos em atraso e com baixa são mantidos. Você poderá retomar a recorrência, mas as datas do período pausado não serão repostas.',
        resume: 'A recorrência volta a projetar os próximos vencimentos, respeitando as regras e os limites do plano. As datas do período pausado não serão repostas; não há geração retroativa dessas ocorrências.',
        end: 'O encerramento é definitivo e remove os lançamentos futuros não liquidados, inclusive os ajustados individualmente. Lançamentos em atraso e com baixa são mantidos. O plano encerrado não poderá ser retomado nem editado.',
    };
    confirmAction({
        title: `${labels[action]} recorrência?`,
        description: `Plano: “${plan.description}”. ${descriptions[action]}`,
        label: `${labels[action]} recorrência`,
        path: `/plans/${plan.id}/lifecycle`,
        method: 'POST',
        body: { action },
    });
}
function deletePlan(plan: FinancialPlan): void { confirmAction({ title: 'Excluir todo o planejamento?', description: `O plano “${plan.description}” e seus lançamentos serão removidos conforme as regras de preservação de baixas do servidor. Esta ação é diferente de excluir apenas uma ocorrência e não pode ser desfeita.`, label: 'Excluir planejamento', path: `/plans/${plan.id}`, method: 'DELETE' }); }
function logout(): void { confirmAction({ title: 'Sair da sua conta?', description: 'Seu planejamento continuará salvo. Você poderá entrar novamente quando quiser.', label: 'Sair da conta', path: '/logout', method: 'POST' }); }
async function executeConfirmation(): Promise<void> {
    if (!confirmation.value) return; const action = confirmation.value; modalBusy.value = true; modalError.value = ''; modalFields.value = {};
    try { await api(action.path, action.method, action.body); modal.value = null; if (action.path === '/logout') { sessionExpired(); await refreshCsrf(); } else { notice.value = 'Alteração concluída com sucesso.'; await load(); } }
    catch (e) { modalError.value = errorMessage(e); if (e instanceof ApiError) modalFields.value = e.fields; }
    finally { modalBusy.value = false; }
}
const frequencyLabel = { daily: 'Diária', weekly: 'Semanal', monthly: 'Mensal', yearly: 'Anual' };
const stateLabel = { active: 'Ativa', paused: 'Pausada', ended: 'Encerrada' };
</script>

<template>
    <div v-if="starting" class="flex min-h-screen flex-col items-center justify-center gap-4 bg-slate-50" role="status"><span class="brand-mark">◈</span><p class="text-sm text-slate-500">Preparando o DamascenoSRV Finances…</p></div>
    <template v-else-if="!user"><div v-if="error" class="fixed inset-x-4 top-4 z-50 mx-auto max-w-lg"><Errors :message="error" /><button class="btn-secondary mt-2" @click="initialize">Tentar novamente</button></div><Auth @authenticated="authenticated" /></template>
    <div v-else class="min-h-screen bg-canvas text-slate-800">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:bg-white focus:p-4">Pular para o conteúdo</a>
        <aside class="sidebar">
            <a href="/" class="flex items-center gap-3 font-semibold tracking-tight text-brand-950" aria-label="DamascenoSRV Finances — início">
                <span class="brand-mark" aria-hidden="true">◈</span>
                <span class="min-w-0"><span class="block text-lg leading-tight">DamascenoSRV</span><span class="block text-sm font-medium tracking-wide text-brand-600">Finances</span></span>
            </a>
            <p class="mt-3 text-[10px] font-semibold tracking-[0.16em] text-slate-400">PLANEJAMENTO FINANCEIRO</p>
            <p class="eyebrow mt-12 mb-4 hidden lg:block">SEU PLANEJAMENTO</p>
            <nav class="mt-6 flex gap-2 lg:mt-0 lg:flex-col" aria-label="Navegação principal"><button v-for="tab in ([{ id: 'dashboard', label: 'Visão geral', icon: '▦' }, { id: 'transactions', label: 'Lançamentos', icon: '⇄' }, { id: 'recurrences', label: 'Recorrências', icon: '↻' }] as const)" :key="tab.id" class="nav-item" :class="view === tab.id ? 'nav-active' : ''" :aria-current="view === tab.id ? 'page' : undefined" @click="navigate(tab.id)"><span class="text-xl" aria-hidden="true">{{ tab.icon }}</span><span>{{ tab.label }}</span></button></nav>
            <div class="mt-auto hidden pt-10 lg:block"><div class="rounded-2xl border border-brand-100 bg-brand-50/70 p-4"><span class="text-2xl text-brand-700" aria-hidden="true">↗</span><p class="mt-3 text-sm font-semibold text-brand-950">Um passo de cada vez.</p><p class="mt-2 text-xs leading-relaxed text-brand-900/70">Planejar hoje é abrir espaço para as escolhas de amanhã.</p></div><div class="mt-6 flex items-center gap-3 border-t border-slate-200 pt-5"><span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-900">{{ user.name.slice(0, 1).toUpperCase() }}</span><div class="min-w-0 flex-1"><p class="truncate text-sm font-medium">{{ user.name }}</p><p class="truncate text-xs text-slate-500">{{ user.email }}</p></div><button class="icon-button" aria-label="Sair da conta" @click="logout">↪</button></div></div>
        </aside>
        <div class="lg:pl-65">
            <header class="flex min-h-19 flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4 sm:px-9"><p class="text-sm text-slate-500">Seu dinheiro, <span class="font-medium text-slate-800">com mais perspectiva.</span></p><div class="flex items-center gap-4"><span class="hidden text-xs text-slate-500 sm:block">{{ date(today()) }}</span><button class="btn-quiet lg:hidden" @click="logout">Sair</button><span class="badge bg-brand-50 text-brand-800">● Planejamento pessoal</span></div></header>
            <main id="main-content" class="mx-auto flex max-w-400 flex-col gap-6 px-4 py-7 sm:px-9 sm:py-9">
                <div class="flex flex-wrap items-start justify-between gap-5"><div><p class="eyebrow">{{ view === 'dashboard' ? 'SEU PANORAMA FINANCEIRO' : view === 'transactions' ? 'CADA DETALHE IMPORTA' : 'CONTINUIDADE COM CONTROLE' }}</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">{{ view === 'dashboard' ? `Olá, ${user.name.split(' ')[0]}.` : view === 'transactions' ? 'Seus lançamentos' : 'Suas recorrências' }}</h1><p class="mt-2 text-sm text-slate-500">{{ view === 'dashboard' ? 'Um olhar claro sobre o presente e o que vem pela frente.' : view === 'transactions' ? 'Entradas, saídas e baixas manuais, em um só lugar.' : 'Gerencie planos ativos, pausados e encerrados, mesmo sem ocorrências futuras.' }}</p></div><button class="btn-primary" :disabled="actionBusy" @click="create">+ Novo planejamento</button></div>
                <div v-if="notice" role="status" class="flex items-center justify-between gap-4 rounded-xl border border-brand-200 bg-brand-50 p-4 text-sm text-brand-900"><span>{{ notice }}</span><button aria-label="Dispensar mensagem" @click="notice = ''">✕</button></div>
                <Errors :message="error" :fields="errorFields" /><button v-if="error" class="btn-secondary self-start" @click="load">Tentar carregar novamente</button>
                <FiltersPanel v-if="view !== 'recurrences'" :filters="filters" :categories="categories" :busy="busy" @apply="apply" @reset="apply({ ...defaults(), year: '' })" />
                <p v-if="actionBusy" role="status" class="text-sm text-slate-500">Carregando planejamento…</p>
                                <div v-if="busy" role="status" class="panel flex items-center gap-3 text-sm text-slate-500"><span class="loading-dot" aria-hidden="true"></span>Atualizando seus dados…</div>
                <template v-if="view !== 'recurrences' && dashboard && !busy">
                    <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-sm font-semibold">{{ scopeLabel }}</h2><span class="text-xs text-slate-500">Indicadores de todo o recorte filtrado · projeção até {{ date(dashboard.projection_end) }}</span></div>
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <article class="metric-card"><span class="metric-icon bg-brand-50 text-brand-700">↗</span><p class="metric-label">Entradas previstas</p><strong class="metric-value text-brand-800">{{ money(dashboard.totals.credit) }}</strong><p class="metric-help">Realizado: {{ money(dashboard.totals.realized_credit) }}</p></article>
                        <article class="metric-card"><span class="metric-icon bg-rose-50 text-rose-600">↘</span><p class="metric-label">Saídas previstas</p><strong class="metric-value">{{ money(dashboard.totals.debit) }}</strong><p class="metric-help">Realizado: {{ money(dashboard.totals.realized_debit) }}</p></article>
                        <article class="metric-card border-brand-200! bg-brand-950! text-white"><span class="metric-icon bg-white/10 text-brand-200">≈</span><p class="metric-label text-brand-100!">Saldo projetado</p><strong class="metric-value">{{ money(dashboard.totals.projected_balance) }}</strong><p class="metric-help text-brand-100/80!">Realizado: {{ money(dashboard.totals.realized_balance) }}</p></article>
                        <article class="metric-card"><span class="metric-icon bg-amber-50 text-amber-700">◷</span><p class="metric-label">Valor pendente</p><strong class="metric-value">{{ money(dashboard.totals.pending) }}</strong><p class="metric-help text-rose-700!">Em atraso: {{ money(dashboard.totals.overdue) }}</p></article>
                    </div>
                    <details class="text-xs text-slate-500"><summary class="w-fit cursor-pointer">Como interpretar estes valores?</summary><p class="mt-2 max-w-4xl leading-relaxed">Previstos somam os valores planejados por vencimento. Realizados usam os valores das baixas manuais. Saldo é entradas menos saídas; não representa saldo bancário. Pendente e em atraso somam o volume bruto de entradas e saídas não liquidadas. Os gráficos acumulados começam em zero no recorte filtrado, sem saldo anterior. A data da baixa não muda o mês de vencimento usado na análise.</p></details>
                </template>
                <template v-if="view === 'dashboard' && dashboard && !busy">
                    <Charts :data="dashboard" @filter="chartFilter" />
                    <section class="panel"><div class="section-heading"><div><h2>Próximos vencimentos</h2><p>Agenda retornada pelo servidor dentro do recorte filtrado</p></div><button class="btn-quiet" @click="navigate('transactions')">Ver lançamentos →</button></div><p v-if="!dashboard.upcoming.length" class="py-8 text-center text-sm text-slate-500">Nenhum vencimento próximo neste recorte.</p><div class="divide-y divide-slate-100"><div v-for="item in dashboard.upcoming" :key="item.id" class="flex flex-wrap items-center gap-3 py-4"><div class="flex size-10 items-center justify-center rounded-xl bg-slate-50 text-lg" :class="item.direction === 'credit' ? 'text-brand-700' : 'text-rose-600'">{{ item.direction === 'credit' ? '↗' : '↘' }}</div><div class="min-w-0 flex-1"><p class="truncate text-sm font-medium">{{ item.description }}</p><p class="mt-1 text-xs text-slate-500">{{ item.category }} · {{ date(item.due_date) }} · {{ statusLabel[item.status] }}</p><div v-if="item.direction === 'debit'" class="mt-2"><PriorityBadge :direction="item.direction" :priority="item.priority" /></div></div><strong class="text-sm tabular-nums">{{ money(item.amount) }}</strong><button v-if="!item.paid_at" class="btn-secondary" @click="openPayment(item)">Registrar baixa</button></div></div></section>
                </template>
                <template v-if="view === 'transactions' && !busy">
                    <div class="flex flex-wrap gap-2" aria-label="Visão por direção"><button v-for="tab in [{ value: '', label: 'Consolidado' }, { value: 'debit', label: 'Saídas · débitos' }, { value: 'credit', label: 'Entradas · créditos' }]" :key="tab.value" class="btn-secondary" :class="filters.direction === tab.value ? 'border-brand-600! bg-brand-50! text-brand-900!' : ''" :aria-pressed="filters.direction === tab.value" @click="apply({ ...filters, direction: tab.value, page: '1' })">{{ tab.label }}</button></div>
                    <section v-if="dashboard" class="panel overflow-hidden"><div class="section-heading"><div><h2>Resumo mensal</h2><p>Todos os registros filtrados, agrupados por vencimento</p></div></div><div class="overflow-x-auto"><table class="w-full min-w-170 text-left text-sm"><caption class="sr-only">Resumo mensal das entradas, saídas e saldos acumulados</caption><thead class="text-xs text-slate-500"><tr><th class="py-3">Mês</th><th>Entradas</th><th>Saídas</th><th>Saldo acumulado</th><th>Realizado acumulado</th><th>Pendente</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="month in dashboard.months" :key="month.month" class="tabular-nums"><th scope="row" class="py-3 text-left font-medium"><button class="text-brand-800 underline-offset-4 hover:underline" @click="chartFilter({ year: month.month.slice(0, 4), month: String(Number(month.month.slice(5))) })">{{ month.month.slice(5) }}/{{ month.month.slice(0, 4) }}</button></th><td class="text-brand-800">{{ money(month.credit) }}</td><td>{{ money(month.debit) }}</td><td>{{ money(month.balance) }}</td><td>{{ money(month.realized_balance) }}</td><td>{{ money(month.pending) }}</td></tr><tr v-if="!dashboard.months.length"><td colspan="6" class="py-8 text-center text-slate-500">Sem dados neste período.</td></tr></tbody></table></div></section>
                    <OccurrenceTable v-if="result" :result="result" :busy="busy || actionBusy" :export-url="exportUrl" :sort-column="filters.sort" :sort-order="filters.order" @edit="edit" @payment="openPayment" @remove="removeOccurrence" @reopen="reopen" @create="create" @page="page => apply({ ...filters, page: String(page) })" @sort="sort" />
                </template>
                <section v-if="view === 'recurrences' && !busy && !error" class="space-y-4"><p class="rounded-xl border border-sky-100 bg-sky-50 p-4 text-sm leading-relaxed text-sky-900">Esta lista mostra todos os seus planos recorrentes, sem os filtros dos lançamentos. Pausar ou encerrar remove ocorrências futuras não liquidadas, inclusive ajustes individuais; atrasadas e liquidadas são mantidas. Retomar não repõe as datas do período pausado. Encerrar é definitivo. Limite técnico: 10.000 ocorrências por plano.</p><div v-if="!plans.length" class="panel py-14! text-center"><h2 class="font-semibold">Sua rotina financeira começa aqui</h2><p class="mt-2 text-sm text-slate-500">Crie uma recorrência para acompanhar compromissos ou entradas periódicas.</p><button class="btn-primary mt-5" @click="create">+ Criar planejamento</button></div><article v-for="plan in plans" :key="plan.id" class="panel"><div class="flex flex-wrap items-start justify-between gap-5"><div><div class="flex flex-wrap items-center gap-3"><h2 class="text-lg font-semibold">{{ plan.description }}</h2><span class="badge" :class="plan.state === 'active' ? 'bg-brand-50 text-brand-800' : 'bg-slate-100 text-slate-600'">{{ stateLabel[plan.state] }}</span><PriorityBadge :direction="plan.direction" :priority="plan.priority" /></div><p class="mt-2 text-sm text-slate-500">{{ plan.category }} · {{ plan.direction === 'credit' ? 'Entrada' : 'Saída' }} · {{ frequencyLabel[plan.frequency] }}</p><p class="mt-3 text-xl font-semibold tabular-nums">{{ money(plan.amount) }} <span class="text-xs font-normal text-slate-500">por ocorrência</span></p><p class="mt-2 text-xs text-slate-500">Competência {{ date(plan.competence_date) }} · Primeiro vencimento {{ date(plan.first_due_date) }}</p><p class="mt-2 text-xs text-slate-500">{{ plan.end_date ? `Até ${date(plan.end_date)}` : 'Sem data final' }} · {{ plan.occurrence_limit ? `Limite de ${plan.occurrence_limit} ocorrências` : 'Sem limite de ocorrências' }}</p></div><div class="flex flex-wrap gap-2"><button
                                            class="btn-secondary"
                                            :disabled="actionBusy || plan.state === 'ended'"
                                            :title="plan.state === 'ended' ? 'Planos encerrados não podem ser editados' : 'Editar planejamento recorrente'"
                                            @click="edit(plan)"
                                        >
                                            Editar plano
                                        </button><button v-if="plan.state !== 'ended'" class="btn-secondary" @click="lifecycle(plan, plan.state === 'active' ? 'pause' : 'resume')">{{ plan.state === 'active' ? 'Pausar' : 'Retomar' }}</button><button v-if="plan.state !== 'ended'" class="btn-quiet text-rose-700!" @click="lifecycle(plan, 'end')">Encerrar</button><button class="btn-quiet text-rose-700!" @click="deletePlan(plan)">Excluir plano</button></div></div></article></section>
                <footer class="flex flex-wrap justify-between gap-3 border-t border-slate-200 pt-5 text-xs text-slate-400"><span>◈ DamascenoSRV Finances · Mais clareza para suas escolhas.</span><span>Planejamento e baixas manuais. Sem conexão bancária.</span></footer>
            </main>
        </div>
        <Modal v-if="modal === 'plan'" :title="selectedPlan ? 'Editar planejamento' : 'Novo planejamento'" :busy="modalBusy" @close="modal = null"><PlanForm :plan="selectedPlan" :occurrence="selectedOccurrence" :categories="categories" @saved="saved" @busy="modalBusy = $event" /><div v-if="selectedPlan" class="mt-5 border-t border-slate-200 pt-4"><button class="btn-quiet text-rose-700!" :disabled="modalBusy" @click="deletePlan(selectedPlan)">Excluir todo o planejamento…</button></div></Modal>
        <Modal
            v-if="modal === 'payment'" title="Registrar baixa manual"
            :busy="modalBusy" @close="modal = null"
        >
            <form class="space-y-5" @submit.prevent="savePayment">
                <Errors :message="modalError" :fields="modalFields" />
                <p class="text-sm leading-relaxed text-slate-500">
                    Confirme a data e o valor efetivamente {{ selectedOccurrence?.direction === 'credit' ? 'recebido' : 'pago' }}
                    de <strong class="text-slate-800">{{ selectedOccurrence?.description }}</strong>.
                    Este registro não movimenta dinheiro nem se conecta ao seu banco.
                </p>
                <fieldset :disabled="modalBusy" class="grid gap-4 sm:grid-cols-2">
                    <label class="field">
                        Data da baixa
                        <input v-model="payment.paid_at" type="date" :max="today()" required :aria-invalid="!!modalFields.paid_at" />
                    </label>
                    <label class="field">
                        Valor da baixa (R$)
                        <input
                            v-model="payment.paid_amount" type="text" pattern="[0-9]+([.,][0-9]{1,2})?"
                            placeholder="0,00" title="Informe um valor positivo, com até duas casas decimais."
                            required inputmode="decimal" :aria-invalid="!!modalFields.paid_amount"
                        />
                    </label>
                </fieldset>
                <p class="text-xs text-slate-500">
                    O sistema calcula a situação pelo vencimento e pela data informada. O valor pode ser diferente do previsto.
                    A data da baixa não pode ser posterior a hoje.
                </p>
                <button class="btn-primary w-full" :disabled="modalBusy">
                    {{ modalBusy ? 'Registrando…' : 'Confirmar baixa manual' }}
                </button>
            </form>
        </Modal>
        <Modal v-if="modal === 'confirm' && confirmation" :title="confirmation.title" :busy="modalBusy" @close="modal = null"><div class="space-y-5"><Errors :message="modalError" :fields="modalFields" /><p class="text-sm leading-relaxed text-slate-600">{{ confirmation.description }}</p><div class="flex flex-wrap justify-end gap-3"><button class="btn-secondary" :disabled="modalBusy" @click="modal = null">Cancelar</button><button class="btn-primary" :disabled="modalBusy" @click="executeConfirmation">{{ modalBusy ? 'Aguarde…' : confirmation.label }}</button></div></div></Modal>
    </div>
</template>
