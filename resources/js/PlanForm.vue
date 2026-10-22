<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import type { FinancialPlan, Occurrence, FieldErrors } from './types';
import { api, ApiError, errorMessage, today, date } from './api';
import Errors from './Errors.vue';
const props = defineProps<{ plan?: FinancialPlan; occurrence?: Occurrence; categories: string[] }>();
const emit = defineEmits<{ saved: []; busy: [value: boolean] }>();
const scope = ref<'one' | 'future' | 'all'>(props.occurrence && !props.occurrence.paid_at ? 'one' : 'all');
const form = reactive({
    description: '',
    category: '',
    direction: 'debit',
    kind: 'single',
    amount: '',
    amount_mode: 'total',
    installment_count: 1,
    frequency: 'monthly',
    interest_type: 'none',
    interest_rate: '0',
    interest_period: 'monthly',
    competence_date: today(),
    first_due_date: today(),
    end_date: null as string | null,
    occurrence_limit: null as number | null,
    ...props.plan,
});
if (props.occurrence && scope.value === 'one') { Object.assign(form, { description: props.occurrence.description, category: props.occurrence.category, amount: props.occurrence.amount, competence_date: props.occurrence.competence_date, first_due_date: props.occurrence.due_date }); }
watch(scope, value => { if (props.plan) Object.assign(form, props.plan); if (value === 'one' && props.occurrence) Object.assign(form, { description: props.occurrence.description, category: props.occurrence.category, amount: props.occurrence.amount, competence_date: props.occurrence.competence_date, first_due_date: props.occurrence.due_date }); });
watch(() => form.kind, kind => {
    if (kind === 'recurring') {
        form.amount_mode = 'per_installment';
    }
    if (kind === 'single') {
        form.amount_mode = 'total';
        form.installment_count = 1;
    }
    if (kind !== 'recurring') {
        form.end_date = null;
        form.occurrence_limit = null;
    }
    if (kind !== 'installment') {
        form.interest_type = 'none';
        form.interest_rate = '0';
    }
}, { immediate: true });
const busy = ref(false), error = ref(''), fields = ref<FieldErrors>({});
async function save(): Promise<void> {
    if (props.plan?.state === 'ended') {
        error.value = 'Planos encerrados não podem ser editados.';
        return;
    }
    busy.value = true;
    emit('busy', true);
    error.value = '';
    fields.value = {};
    try {
        if (props.occurrence && scope.value === 'one') {
            await api(`/occurrences/${props.occurrence.id}`, 'PATCH', {
                description: form.description,
                category: form.category,
                amount: String(form.amount).replace(',', '.'),
                competence_date: form.competence_date,
                due_date: form.first_due_date,
            });
        } else {
            const { id, state, ...payload } = form;
            await api(props.plan ? `/plans/${props.plan.id}` : '/plans', props.plan ? 'PUT' : 'POST', {
                ...payload,
                amount: String(form.amount).replace(',', '.'),
                interest_rate: form.kind === 'installment' && form.interest_type !== 'none'
                    ? String(form.interest_rate).replace(',', '.') : '0',
                interest_type: form.kind === 'installment' ? form.interest_type : 'none',
                amount_mode: form.kind === 'single' ? 'total'
                    : form.kind === 'recurring' ? 'per_installment' : form.amount_mode,
                installment_count: form.kind === 'single' ? 1 : form.installment_count,
                end_date: form.kind === 'recurring' ? form.end_date || null : null,
                occurrence_limit: form.kind === 'recurring' ? form.occurrence_limit || null : null,
                ...(props.plan ? {
                    scope: scope.value,
                    ...(scope.value === 'future' ? { occurrence_id: props.occurrence?.id } : {}),
                } : {}),
            });
        }
        emit('saved');
    } catch (e) { error.value = errorMessage(e); if (e instanceof ApiError) fields.value = e.fields; }
    finally { busy.value = false; emit('busy', false); }
}
</script>
<template>
    <form class="space-y-5" @submit.prevent="save">
        <Errors :message="error" :fields="fields" />
        <label v-if="plan" class="field">
            Aplicar alterações a
            <select v-model="scope" :disabled="busy || plan.state === 'ended'">
                <option v-if="occurrence && !occurrence.paid_at" value="one">Somente esta ocorrência (preserva o plano)</option>
                <option v-if="occurrence" value="future">A partir desta ocorrência (preserva baixas)</option>
                <option value="all">Todo o plano (preserva baixas)</option>
            </select>
        </label>
        <p v-if="occurrence?.paid_at" class="text-xs leading-relaxed text-slate-500">
            Esta ocorrência já foi liquidada. Você pode editar somente o plano inteiro ou a partir desta ocorrência;
            os lançamentos com baixa são preservados.
        </p>
        <p v-if="scope === 'future' && plan" class="rounded-xl bg-sky-50 p-4 text-xs leading-relaxed text-sky-900">
            A ocorrência selecionada define a partir de onde as alterações se aplicam.
            As datas de competência e primeiro vencimento continuam sendo as datas-base do plano original
            (primeiro vencimento: {{ date(plan.first_due_date) }}), não as datas desta ocorrência.
            Ajuste essas referências do plano, sem tratá-las como um novo início na ocorrência selecionada.
        </p>
        <fieldset :disabled="busy || plan?.state === 'ended'" class="grid gap-4 sm:grid-cols-2">
            <label class="field sm:col-span-2">Descrição<input v-model="form.description" required maxlength="255" autofocus :aria-invalid="!!fields.description" /></label>
            <label class="field">Categoria<input v-model="form.category" list="plan-categories" required maxlength="255" :aria-invalid="!!fields.category" /><datalist id="plan-categories"><option v-for="category in categories" :key="category" :value="category" /></datalist></label>
            <label v-if="scope !== 'one' || !plan" class="field">Direção<select v-model="form.direction"><option value="debit">Saída · débito</option><option value="credit">Entrada · crédito</option></select></label>
            <label v-if="scope !== 'one' || !plan" class="field">Tipo de plano<select v-model="form.kind"><option value="single">Lançamento único</option><option value="installment">Parcelamento</option><option value="recurring">Recorrência</option></select></label>
            <label class="field">{{ scope === 'one' && plan ? 'Valor desta ocorrência (R$)' : 'Valor informado (R$)' }}<input v-model="form.amount" type="text" pattern="[0-9]+([.,][0-9]{1,2})?" placeholder="0,00" title="Informe um valor positivo, com até duas casas decimais." required inputmode="decimal" :aria-invalid="!!fields.amount" /></label>
            <template v-if="scope !== 'one' || !plan">
                <label class="field">
                    Este valor representa
                    <select v-model="form.amount_mode" :disabled="form.kind !== 'installment'">
                        <option v-if="form.kind !== 'recurring'" value="total">Valor total do plano</option>
                        <option v-if="form.kind !== 'single'" value="per_installment">
                            {{ form.kind === 'recurring' ? 'Valor de cada ocorrência' : 'Valor de cada parcela' }}
                        </option>
                    </select>
                </label>
                <label v-if="form.kind === 'installment'" class="field">Quantidade de parcelas<input v-model.number="form.installment_count" type="number" min="1" max="360" step="1" required /></label>
                <label v-if="form.kind !== 'single'" class="field">Frequência dos vencimentos<select v-model="form.frequency"><option value="daily">Diária</option><option value="weekly">Semanal</option><option value="monthly">Mensal</option><option value="yearly">Anual</option></select></label>
            </template>
            <label class="field">Data de competência<input v-model="form.competence_date" type="date" required /></label>
            <label class="field">{{ scope === 'one' && plan ? 'Vencimento' : 'Primeiro vencimento' }}<input v-model="form.first_due_date" type="date" required /></label>
            <template v-if="(scope !== 'one' || !plan) && form.kind === 'installment'">
                <label class="field">Juros<select v-model="form.interest_type"><option value="none">Sem juros</option><option value="simple">Juros simples</option><option value="compound">Juros compostos</option></select></label>
                <template v-if="form.interest_type !== 'none'"><label class="field">Taxa de juros (%)<input v-model="form.interest_rate" type="text" pattern="[0-9]+([.,][0-9]{1,4})?" inputmode="decimal" title="Informe uma taxa não negativa, com até quatro casas decimais." required /></label><label class="field">Período da taxa (independente da frequência)<select v-model="form.interest_period"><option value="daily">Ao dia</option><option value="monthly">Ao mês</option><option value="yearly">Ao ano</option></select></label></template>
            </template>
            <template v-if="(scope !== 'one' || !plan) && form.kind === 'recurring'">
                <label class="field">Data final (opcional)<input v-model="form.end_date" type="date" :min="form.first_due_date" /></label><label class="field">Limite de ocorrências (opcional)<input v-model.number="form.occurrence_limit" type="number" min="1" max="10000" step="1" placeholder="Sem limite configurado" /></label>
            </template>
        </fieldset>
        <div class="rounded-xl bg-slate-50 p-4 text-xs leading-relaxed text-slate-600"><p v-if="form.kind === 'recurring'">Limite técnico: até 10.000 ocorrências por recorrência, mesmo sem um limite menor configurado.</p><p v-if="form.kind === 'installment'">Limite técnico: até 360 parcelas por plano.</p><p>Competência é a data de referência do plano. Os filtros e projeções consideram o vencimento.</p><p v-if="form.kind === 'installment'">Juros calculados desde a competência por dias corridos: mês de 30 dias e ano de 365 dias. A taxa e a frequência dos vencimentos são independentes.</p><p v-if="form.kind !== 'single'">Vencimentos nos dias 29, 30 ou 31 se ajustam ao último dia do mês, mantendo o dia original como referência para os meses seguintes.</p></div>
        <button class="btn-primary w-full" :disabled="busy || plan?.state === 'ended'">{{ busy ? 'Salvando…' : plan ? 'Salvar alterações' : 'Criar planejamento' }}</button>
    </form>
</template>
