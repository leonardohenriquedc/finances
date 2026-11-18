import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

let server;
let App;
const originalFetch = globalThis.fetch;
const originalLocation = globalThis.location;

before(async () => {
    server = await createServer({
        configFile: false,
        cacheDir: 'node_modules/.vite-plan-tests',
        plugins: [{
            name: 'initialize-app-for-ssr-tests',
            enforce: 'pre',
            transform(source, id) {
                if (!id.endsWith('/resources/js/App.vue')) return;
                // SSR does not run onMounted; use the same initialization before rendering.
                return source.replace('<script setup lang="ts">', '<script setup lang="ts">\nimport { onServerPrefetch } from "vue";\nonServerPrefetch(initialize);');
            },
        }, vue()],
        server: { middlewareMode: true, hmr: false, watch: null },
        appType: 'custom',
    });
    ({ default: App } = await server.ssrLoadModule('/resources/js/App.vue'));
});
after(async () => {
    globalThis.fetch = originalFetch;
    if (originalLocation === undefined) delete globalThis.location;
    else globalThis.location = originalLocation;
    await server?.close();
});

async function renderPlans(view, plans) {
    const requests = [];
    globalThis.location = { search: `?view=${view}&year=2020&text=ignorado` };
    globalThis.fetch = async path => {
        requests.push(path);
        const responses = {
            '/api/v1/csrf': { token: 'test-token' },
            '/api/v1/user': { data: { id: 1, name: 'Maria', email: 'maria@example.test' } },
            '/api/v1/categories': { data: ['Casa'] },
            [`/api/v1/plans?kind=${view === 'installments' ? 'installment' : 'recurring'}`]: { data: plans },
        };
        assert.ok(path in responses, `Unexpected request: ${path}`);
        return new Response(JSON.stringify(responses[path]), { headers: { 'Content-Type': 'application/json' } });
    };
    return { html: await renderToString(createSSRApp(App)), requests };
}

const plan = {
    id: 10, description: 'Compra de móveis', category: 'Casa', direction: 'debit',
    kind: 'installment', priority: 'essential', state: 'active', amount: '1200.00',
    amount_mode: 'total', installment_count: 12, frequency: 'monthly',
    competence_date: '2026-11-01', first_due_date: '2026-12-01',
    end_date: null, occurrence_limit: null,
};

test('installments load independently of transaction filters and render plan details without recurrence actions', async () => {
    const { html, requests } = await renderPlans('installments', [plan]);
    assert.ok(requests.includes('/api/v1/plans?kind=installment'));
    assert.match(html, /aria-current="page"[^>]*>.*?Parcelamentos/s);
    assert.match(html, /Seus parcelamentos/);
    assert.match(html, /Compra de móveis/);
    assert.match(html, /12 parcelas/);
    assert.match(html, /valor total base/);
    assert.match(html, /Editar plano/);
    assert.match(html, /Excluir plano/);
    assert.doesNotMatch(html, />Pausar<|>Retomar<|>Encerrar<|Sem limite de ocorrências|Entradas previstas/);
});

test('installment cards distinguish per-installment values and singular counts', async () => {
    const { html } = await renderPlans('installments', [{ ...plan, amount_mode: 'per_installment', installment_count: 1 }]);
    assert.match(html, /por parcela · valor base/);
    assert.match(html, /1 parcela</);
});

test('empty installments show their own guidance and a create action', async () => {
    const { html } = await renderPlans('installments', []);
    assert.match(html, /Seus parcelamentos começam aqui/);
    assert.match(html, /Crie um parcelamento/);
    assert.match(html, /Criar planejamento/);
});

test('recurrences retain their list and lifecycle actions', async () => {
    const { html, requests } = await renderPlans('recurrences', [{ ...plan, kind: 'recurring' }]);
    assert.ok(requests.includes('/api/v1/plans?kind=recurring'));
    assert.match(html, /Suas recorrências/);
    assert.match(html, /por ocorrência/);
    assert.match(html, />Pausar</);
    assert.match(html, />Encerrar</);
    assert.doesNotMatch(html, /12 parcelas/);
});
