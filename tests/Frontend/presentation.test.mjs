import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

let server;
let html;
let Auth;
before(async () => {
    server = await createServer({
        configFile: false,
        cacheDir: 'node_modules/.vite-presentation-tests',
        plugins: [vue()],
        server: { middlewareMode: true, hmr: false, watch: null },
        appType: 'custom',
    });
    const { default: Presentation } = await server.ssrLoadModule('/resources/js/Presentation.vue');
    ({ default: Auth } = await server.ssrLoadModule('/resources/js/Auth.vue'));
    html = await renderToString(createSSRApp(Presentation, {
        appUrl: '/sistema-financeiro',
        registerUrl: '/sistema-financeiro?auth=register',
    }));
});
after(async () => { await server?.close(); });

test('presentation renders the brand, purpose and one accessible main heading', () => {
    assert.match(html, /DamascenoSRV/);
    assert.match(html, /Finances/);
    assert.match(html, /provisionamentos financeiros/i);
    assert.match(html, /organização financeira/i);
    assert.equal((html.match(/<h1\b/g) ?? []).length, 1);
    assert.match(html, /<main\b/);
    assert.match(html, /href="#conteudo"/);
});

test('presentation connects login and account creation to the routes supplied by Laravel', () => {
    assert.match(html, /href="\/sistema-financeiro"/);
    assert.match(html, /href="\/sistema-financeiro\?auth=register"/);
    assert.doesNotMatch(html, /href="\/app/);
    for (const section of ['recursos', 'como-funciona', 'objetivo']) {
        assert.match(html, new RegExp(`href="#${section}"`));
        assert.match(html, new RegExp(`id="${section}"`));
    }
});

test('application access opens login by default', async () => {
    const login = await renderToString(createSSRApp(Auth));
    assert.match(login, /Que bom ter você aqui/);
    assert.doesNotMatch(login, /Confirme sua senha/);
});

test('account creation opens registration directly instead of login', async () => {
    const registration = await renderToString(createSSRApp(Auth, { initialRegister: true }));
    assert.match(registration, /Comece seu planejamento/);
    assert.match(registration, /Confirme sua senha/);
    assert.match(registration, /autocomplete="new-password"/);
});

test('presentation explains manual planning and does not present fictitious financial balances', () => {
    assert.match(html, /manua/i);
    assert.match(html, /transações/i);
    assert.match(html, /bancária/i);
    assert.doesNotMatch(html, /R\$\s*\d/);
});
