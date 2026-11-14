import type { Filters } from './types';

export type YearMode = 'all' | 'single' | 'range';
type YearFields = Pick<Filters, 'year' | 'year_from' | 'year_to'>;

export function yearMode(filters: YearFields): YearMode {
    if (filters.year_from || filters.year_to) return 'range';
    return filters.year ? 'single' : 'all';
}

export function normalizeYearFilters<T extends YearFields>(filters: T, mode: YearMode): T {
    return {
        ...filters,
        year: mode === 'single' ? String(filters.year) : '',
        year_from: mode === 'range' ? String(filters.year_from) : '',
        year_to: mode === 'range' ? String(filters.year_to) : '',
    };
}

export function readFilterParams(params: URLSearchParams, defaults: Filters): Filters {
    const filters = { ...defaults };
    for (const key of Object.keys(filters) as (keyof Filters)[]) {
        if (params.has(key)) filters[key] = params.get(key) ?? '';
    }
    // A range URL must not inherit the initial current-year default.
    if (!params.has('year') && (params.has('year_from') || params.has('year_to'))) filters.year = '';
    return filters;
}

export function filterParams(filters: Filters, target: 'location' | 'api' | 'export' = 'api'): URLSearchParams {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(filters)) {
        if (target === 'export' && ['page', 'per_page'].includes(key)) continue;
        // An explicit empty year distinguishes all years from the initial default on reload.
        if (value || (target === 'location' && key === 'year')) params.set(key, String(value));
    }
    return params;
}

export function yearLabel(filters: YearFields): string {
    if (filters.year_from || filters.year_to) return `${filters.year_from} a ${filters.year_to}`;
    return filters.year || 'Todos os anos';
}

export function chartFilters(filters: Filters, next: Partial<Filters>): Filters {
    const merged = { ...filters, ...next, page: '1' };
    return next.year !== undefined ? normalizeYearFilters(merged, 'single') : merged;
}
