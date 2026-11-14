import { createApp } from 'vue';

const element = document.querySelector<HTMLElement>('#app');

async function mountPage(root: HTMLElement): Promise<void> {
    if (root.dataset.page === 'presentation') {
        const { default: Presentation } = await import('./Presentation.vue');
        createApp(Presentation, {
            appUrl: root.dataset.appUrl,
            registerUrl: root.dataset.registerUrl,
        }).mount(root);
        return;
    }

    const { default: App } = await import('./App.vue');
    createApp(App, { initialRegister: root.dataset.initialRegister === 'true' }).mount(root);
}

if (element) {
    mountPage(element).catch(() => {
        element.textContent = 'Não foi possível carregar a página. Atualize o navegador para tentar novamente.';
    });
}
