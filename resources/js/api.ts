import type { FieldErrors } from './types';

export class ApiError extends Error {
    constructor(public status: number, message: string, public fields: FieldErrors = {}) { super(message); }
}
let token = '';
export async function refreshCsrf(): Promise<void> {
    const response = await fetch('/api/v1/csrf', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!response.ok) { throw new ApiError(response.status, 'Não foi possível iniciar uma sessão segura. Tente novamente.'); }
    token = (await response.json()).token;
}
export async function api<T>(path: string, method = 'GET', body?: unknown): Promise<T> {
    if (method !== 'GET' && !token) { await refreshCsrf(); }
    const response = await fetch(`/api/v1${path}`, {
        method, credentials: 'same-origin', headers: { Accept: 'application/json', ...(method !== 'GET' ? { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/json' } : {}) },
        ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
    const data = response.status === 204 ? null : await response.json().catch(() => null);
    if (!response.ok) {
        if (response.status === 401) { window.dispatchEvent(new Event('session-expired')); }
        if (response.status === 419) { token = ''; }
        const messages: Record<number, string> = { 401: 'Sua sessão expirou. Entre novamente.', 403: 'Você não tem permissão para esta ação.', 404: 'Este registro não está mais disponível. Atualize a página.', 419: 'A sessão de segurança expirou. Tente novamente.', 422: 'Revise os campos indicados abaixo.', 429: 'Muitas tentativas. Aguarde um pouco e tente novamente.' };
        const fallback = response.status === 409 ? 'Esta alteração não é permitida para a situação atual do registro. Atualize os dados e tente novamente.' : 'Não foi possível concluir a solicitação. Tente novamente.';
                const detail = response.status < 500 && ![401, 419, 429].includes(response.status) && typeof data?.message === 'string' && (response.status === 409 || (response.status === 422 && !Object.keys(data?.errors ?? {}).length)) ? data.message : undefined;
                throw new ApiError(response.status, detail ?? messages[response.status] ?? fallback, data?.errors ?? {});
    }
    return data as T;
}
export function errorMessage(error: unknown): string {
    if (error instanceof TypeError) return 'Não foi possível conectar ao servidor. Verifique sua conexão e tente novamente.';
    return error instanceof Error ? error.message : 'Ocorreu um erro inesperado. Tente novamente.';
}
export { money, moneyDifference } from './money';
export const date = (value: string | null): string => value ? new Intl.DateTimeFormat('pt-BR').format(new Date(`${value.slice(0, 10)}T12:00:00`)) : '—';
export function today(): string { const now = new Date(); return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`; }
export const statusLabel = { open: 'Em aberto', overdue: 'Em atraso', paid: 'Liquidado', paid_late: 'Liquidado em atraso' };
export const kindLabel = { single: 'Único', installment: 'Parcelado', recurring: 'Recorrente' };
export const fieldLabels: Record<string, string> = { year: 'Ano', year_from: 'Ano inicial', year_to: 'Ano final', priority: 'Prioridade de pagamento', description: 'Descrição', category: 'Categoria', amount: 'Valor', amount_mode: 'Forma do valor', direction: 'Direção', kind: 'Tipo', installment_count: 'Quantidade de parcelas', frequency: 'Frequência', interest_type: 'Tipo de juros', interest_rate: 'Taxa de juros', interest_period: 'Período dos juros', competence_date: 'Competência', first_due_date: 'Primeiro vencimento', due_date: 'Vencimento', end_date: 'Data final', occurrence_limit: 'Limite de ocorrências', scope: 'Abrangência', occurrence_id: 'Ocorrência de referência', paid_at: 'Data da baixa', paid_amount: 'Valor da baixa', name: 'Nome', email: 'E-mail', password: 'Senha', password_confirmation: 'Confirmação de senha' };
