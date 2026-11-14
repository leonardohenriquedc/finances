import test from 'node:test';
import assert from 'node:assert/strict';
import { chartFilters, filterParams, normalizeYearFilters, readFilterParams, yearLabel, yearMode } from '../../resources/js/yearFilters.ts';

const defaults = () => ({
    year: '2026', year_from: '', year_to: '', month: '', from: '', to: '',
    category: '', status: '', text: '', direction: '', kind: '', priority: '',
    sort: 'due_date', order: 'asc', page: '1', per_page: '25',
});
const range = () => ({ ...defaults(), year: '', year_from: '2026', year_to: '2027' });

test('keeps the initial current year and backward-compatible single-year links', () => {
    assert.equal(readFilterParams(new URLSearchParams(), defaults()).year, '2026');
    const filters = readFilterParams(new URLSearchParams('year=2024&month=3'), defaults());
    assert.equal(filters.year, '2024');
    assert.equal(filters.month, '3');
    assert.equal(yearMode(filters), 'single');
});

test('range links without year clear the default, including incomplete ranges for API validation', () => {
    for (const query of ['year_from=2026&year_to=2027', 'year_from=2026', 'year_to=2027']) {
        const filters = readFilterParams(new URLSearchParams(query), defaults());
        assert.equal(filters.year, '');
        assert.equal(yearMode(filters), 'range');
    }
});

test('does not silently rewrite explicit conflicting URL filters before API validation', () => {
    const filters = readFilterParams(new URLSearchParams('year=2025&year_from=2026&year_to=2027'), defaults());
    assert.equal(filters.year, '2025');
    assert.equal(filters.year_from, '2026');
    assert.equal(filters.year_to, '2027');
});

test('normalizes single, range and all modes without changing intersecting filters or mutating input', () => {
    const original = { ...range(), year: '2025', month: '2', from: '2026-02-01', to: '2027-02-28', category: 'Casa' };
    for (const mode of ['all', 'single', 'range']) {
        const filters = normalizeYearFilters(original, mode);
        assert.equal(filters.year, mode === 'single' ? '2025' : '');
        assert.equal(filters.year_from, mode === 'range' ? '2026' : '');
        assert.equal(filters.year_to, mode === 'range' ? '2027' : '');
        for (const key of ['month', 'from', 'to', 'category']) assert.equal(filters[key], original[key]);
    }
    assert.equal(original.year, '2025');
    assert.equal(original.year_from, '2026');
});

test('switching between single and range clears incompatible values and all clears all three', () => {
    const single = normalizeYearFilters(range(), 'single');
    assert.equal(single.year_from, '');
    assert.equal(single.year_to, '');
    const interval = normalizeYearFilters(defaults(), 'range');
    assert.equal(interval.year, '');
    assert.equal(interval.year_from, '');
    assert.equal(interval.year_to, '');
    assert.equal(yearMode(normalizeYearFilters(range(), 'all')), 'all');
});

test('normalizes numeric input values to query strings', () => {
    assert.equal(normalizeYearFilters({ ...defaults(), year: 2027 }, 'single').year, '2027');
    const filters = normalizeYearFilters({ ...range(), year_from: 1900, year_to: 9999 }, 'range');
    assert.equal(filters.year_from, '1900');
    assert.equal(filters.year_to, '9999');
});

test('location round trips preserve range and all years through reload and history navigation', () => {
    for (const original of [range(), defaults(), normalizeYearFilters(range(), 'all')]) {
        const params = filterParams(original, 'location');
        params.set('view', 'transactions');
        assert.deepEqual(readFilterParams(new URLSearchParams(params.toString()), defaults()), original);
    }
    assert.equal(filterParams(normalizeYearFilters(range(), 'all'), 'location').get('year'), '');
});

test('API, pagination and export retain the inclusive range and every intersecting filter', () => {
    const filters = { ...range(), month: '4', from: '2026-04-15', to: '2027-04-20', category: 'Casa', status: 'pending', direction: 'debit', priority: 'essential', kind: 'recurring', text: 'Aluguel', sort: 'amount', order: 'desc', page: '3', per_page: '10' };
    for (const target of ['location', 'api', 'export']) {
        const params = filterParams(filters, target);
        for (const [key, value] of Object.entries(filters)) {
            if (key === 'year') {
                assert.equal(params.has(key), target === 'location');
            } else if (target === 'export' && ['page', 'per_page'].includes(key)) {
                assert.equal(params.has(key), false);
            } else {
                assert.equal(params.get(key), value);
            }
        }
    }
});

test('labels all years, a single year, a range and an inclusive same-year range', () => {
    assert.equal(yearLabel(normalizeYearFilters(defaults(), 'all')), 'Todos os anos');
    assert.equal(yearLabel(defaults()), '2026');
    assert.equal(yearLabel(range()), '2026 a 2027');
    assert.equal(yearLabel({ ...range(), year_to: '2026' }), '2026 a 2026');
});

test('monthly chart drilldown clears both range fields before applying year and month', () => {
    const original = { ...range(), category: 'Casa', status: 'pending', from: '2026-01-15', to: '2027-12-15', page: '4' };
    const filters = chartFilters(original, { year: '2027', month: '6' });
    assert.deepEqual(filters, { ...original, year: '2027', year_from: '', year_to: '', month: '6', page: '1' });
    assert.equal(original.year_from, '2026');
});

test('category and status drilldowns preserve the annual range', () => {
    for (const next of [{ category: 'Casa' }, { status: 'realized' }, { status: 'pending' }]) {
        assert.deepEqual(chartFilters({ ...range(), page: '3' }, next), { ...range(), ...next, page: '1' });
    }
});
