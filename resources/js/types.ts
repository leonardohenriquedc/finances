export type Priority = 'when_possible' | 'soon' | 'essential';
export type Direction = 'debit' | 'credit';
export type Kind = 'single' | 'installment' | 'recurring';
export interface User { id: number; name: string; email: string }
export interface FinancialPlan {
    id: number; description: string; category: string; direction: Direction; kind: Kind; priority: Priority | null;
    amount: string; amount_mode: 'total' | 'per_installment'; installment_count: number;
    frequency: 'daily' | 'weekly' | 'monthly' | 'yearly'; interest_type: 'none' | 'simple' | 'compound';
    interest_rate: string; interest_period: 'daily' | 'monthly' | 'yearly';
    competence_date: string; first_due_date: string; end_date: string | null;
    occurrence_limit: number | null; state: 'active' | 'paused' | 'ended';
}
export interface Occurrence {
    id: number; financial_plan_id: number; sequence: number; description: string; category: string;
    direction: Direction; kind: Kind; priority: Priority | null; amount: string; competence_date: string; due_date: string;
    paid_at: string | null; paid_amount: string | null; status: 'open' | 'overdue' | 'paid' | 'paid_late';
    is_override: boolean; plan: FinancialPlan;
}
export interface Totals {
    credit: string; debit: string; projected_balance: string; realized_credit: string; realized_debit: string;
    realized_balance: string; pending: string; overdue: string;
}
export interface OccurrencePage {
    data: Occurrence[]; meta: { current_page: number; last_page: number; per_page: number; total: number }; totals: Totals;
}
export interface Month {
    month: string; credit: string; debit: string; recurring_credit: string; recurring_debit: string;
    realized: string; pending: string; balance: string; realized_balance: string;
}
export interface DashboardCategory {
    category: string;
    credit: string;
    debit: string;
    total: string;
    recurring_credit?: string;
    recurring_debit?: string;
}
export interface DashboardSettlement {
    realized: string;
    pending: string;
    recurring_realized?: string;
    recurring_pending?: string;
}
export interface Dashboard {
    totals: Totals;
    months: Month[];
    categories: DashboardCategory[];
    settlement: DashboardSettlement;
    upcoming: Occurrence[];
    projection_end: string;
}
export type Filters = Record<'month' | 'year' | 'year_from' | 'year_to' | 'from' | 'to' | 'category' | 'status' | 'text' | 'direction' | 'kind' | 'priority' | 'sort' | 'order' | 'page' | 'per_page', string>;
export type FieldErrors = Record<string, string[]>;
