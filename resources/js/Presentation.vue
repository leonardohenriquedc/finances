<script setup lang="ts">
defineProps<{ appUrl: string; registerUrl: string }>();

const features: ReadonlyArray<{ title: string; description: string; icon: string }> = [
    { title: 'Entradas e saídas no mesmo lugar', description: 'Organize seus provisionamentos de crédito e débito para entender o que você espera receber e pagar.', icon: 'M7 4v16m-4-4 4 4 4-4M17 20V4m-4 4 4-4 4 4' },
    { title: 'Um plano para cada compromisso', description: 'Cadastre lançamentos únicos, parcelados ou recorrentes, conforme a frequência de cada entrada ou despesa.', icon: 'M8 2v4m8-4v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2m2 10h3m4 0h3m-10 4h3' },
    { title: 'Prioridades bem definidas', description: 'Classifique suas contas a pagar em três níveis de prioridade e mantenha em vista o que precisa de mais atenção.', icon: 'M5 20V4m0 1h14l-3 5 3 5H5' },
    { title: 'Previsto e realizado, com clareza', description: 'Registre as baixas manualmente para acompanhar os valores realizados e os compromissos ainda pendentes.', icon: 'M9 5H5v16h14V5h-4M9 3h6v4H9zM8 14l3 3 5-6' },
    { title: 'Perspectiva para os próximos meses', description: 'Consulte o dashboard e use filtros por ano e intervalos de anos para analisar seu planejamento no período que importa.', icon: 'M4 3v18h17M8 16v-4m5 4V8m5 8V5' },
    { title: 'Seu histórico faz parte do plano', description: 'Preserve o histórico dos registros realizados e exporte os lançamentos em CSV para consultar também fora do sistema.', icon: 'M12 3v12m-4-4 4 4 4-4M4 15v6h16v-6M4 7V3h4m8 0h4v4' },
];

const steps: ReadonlyArray<{ number: string; title: string; description: string }> = [
    { number: '01', title: 'Coloque seus planos no papel. Ou melhor, aqui.', description: 'Cadastre as entradas e saídas previstas, escolha a frequência e defina as prioridades das suas contas a pagar.' },
    { number: '02', title: 'Olhe além do mês atual.', description: 'Explore os períodos e o dashboard para visualizar como seus compromissos se distribuem ao longo do tempo.' },
    { number: '03', title: 'Acompanhe o que aconteceu.', description: 'Informe manualmente os pagamentos e recebimentos realizados e mantenha seu planejamento atualizado.' },
];
</script>

<template>
    <div class="min-h-screen bg-white text-slate-900" lang="pt-BR">
        <a href="#conteudo" class="sr-only z-50 rounded-lg bg-white px-5 py-3 text-brand-800 focus:not-sr-only focus:fixed focus:top-4 focus:left-4">Pular para o conteúdo</a>

        <header class="border-b border-slate-200/80 bg-white">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-8 gap-y-5 px-6 py-5 lg:px-8">
                <a href="#" class="inline-flex items-center gap-3 font-semibold tracking-tight" aria-label="DamascenoSRV Finances — início">
                    <span class="brand-mark" aria-hidden="true">◈</span>
                    <span><span class="block text-lg leading-tight">DamascenoSRV</span><span class="block text-xs font-medium tracking-widest text-brand-700">Finances</span></span>
                </a>
                <nav aria-label="Navegação principal" class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm font-medium text-slate-600 lg:ml-auto">
                    <a href="#recursos" class="inline-flex min-h-11 items-center hover:text-brand-700">Recursos</a>
                    <a href="#como-funciona" class="inline-flex min-h-11 items-center hover:text-brand-700">Como funciona</a>
                    <a href="#objetivo" class="inline-flex min-h-11 items-center hover:text-brand-700">Nosso objetivo</a>
                </nav>
                <div class="flex flex-wrap items-center gap-3">
                    <a :href="appUrl" class="btn-quiet">Entrar</a>
                    <a :href="registerUrl" class="btn-primary">Criar conta <span aria-hidden="true">↗</span></a>
                </div>
            </div>
        </header>

        <main id="conteudo" tabindex="-1">
            <section aria-labelledby="hero-title" class="relative isolate overflow-hidden bg-brand-950 text-white">
                <div aria-hidden="true" class="pointer-events-none absolute -top-64 -right-64 size-200 rounded-full border border-brand-400/15"></div>
                <div aria-hidden="true" class="pointer-events-none absolute -right-32 -bottom-96 size-200 rounded-full border border-brand-400/15"></div>
                <div class="relative mx-auto grid max-w-7xl items-center gap-14 px-6 py-20 sm:py-24 lg:grid-cols-[1.15fr_1fr] lg:gap-16 lg:px-8 lg:py-28">
                    <div class="flex flex-col items-start gap-7">
                        <p class="flex items-center gap-3 text-xs font-semibold tracking-widest text-brand-200"><span aria-hidden="true" class="h-px w-8 bg-brand-400"></span>CLAREZA PARA O QUE VEM</p>
                        <h1 id="hero-title" class="max-w-2xl text-4xl leading-tight font-semibold tracking-tight sm:text-5xl lg:text-6xl">Organize suas finanças hoje. <span class="text-brand-300">Planeje seu amanhã.</span></h1>
                        <p class="max-w-lg text-base leading-relaxed text-brand-100 sm:text-lg">Um espaço para seus provisionamentos financeiros e sua organização financeira. Reúna entradas, despesas e compromissos futuros para decidir com mais perspectiva.</p>
                        <div class="flex flex-wrap gap-3">
                            <a :href="registerUrl" class="btn-primary min-h-12 px-6">Começar meu planejamento <span aria-hidden="true">→</span></a>
                            <a :href="appUrl" class="inline-flex min-h-12 items-center justify-center rounded-lg border border-brand-300/40 px-6 py-3 text-sm font-medium text-white hover:bg-brand-900">Já tenho uma conta</a>
                        </div>
                        <p class="text-sm leading-relaxed text-brand-200">Mais perspectiva. Menos surpresas.<br />Sem conexão bancária ou movimentação de dinheiro.</p>
                    </div>

                    <figure class="min-w-0 rounded-3xl border border-brand-300/25 bg-brand-900/40 p-5 sm:p-8">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-300/20 pb-5">
                            <p class="text-sm font-medium text-white">Do presente aos seus próximos passos</p>
                            <span class="rounded-full border border-brand-300/30 px-3 py-1 text-xs text-brand-200">Visão ilustrativa</span>
                        </div>
                        <ol class="relative mt-7 flex flex-col gap-6 border-l border-brand-400/40 pl-6">
                            <li class="relative flex flex-col gap-3">
                                <span aria-hidden="true" class="absolute top-1 -left-7.5 size-3 rounded-full bg-brand-300 ring-4 ring-brand-900"></span>
                                <p class="text-xs font-semibold tracking-widest text-brand-200">HOJE</p>
                                <div class="rounded-xl border border-brand-200/30 bg-white p-4 text-slate-900 shadow-sm sm:p-5">
                                    <p class="font-semibold">Organizar o que já está previsto</p>
                                    <p class="mt-2 text-sm leading-relaxed text-slate-600">Entradas e saídas, cada uma com seu lugar.</p>
                                    <div class="mt-4 flex flex-wrap gap-2"><span class="badge bg-brand-50 text-brand-800">Créditos</span><span class="badge bg-slate-100 text-slate-700">Débitos</span></div>
                                </div>
                            </li>
                            <li class="relative flex flex-col gap-3">
                                <span aria-hidden="true" class="absolute top-1 -left-7.5 size-3 rounded-full border-2 border-brand-300 bg-brand-950"></span>
                                <p class="text-xs font-semibold tracking-widest text-brand-200">PRÓXIMOS MESES</p>
                                <div class="rounded-xl border border-brand-300/25 bg-brand-950/50 p-4 sm:p-5">
                                    <p class="font-semibold">Enxergar o que vem pela frente</p>
                                    <p class="mt-2 text-sm leading-relaxed text-brand-100">Parcelas, recorrências e prioridades no seu planejamento.</p>
                                    <div aria-hidden="true" class="mt-5 grid grid-cols-4 gap-2">
                                        <span class="h-2 rounded-full bg-brand-400"></span><span class="h-2 rounded-full bg-brand-400/70"></span><span class="h-2 rounded-full bg-brand-400/45"></span><span class="h-2 rounded-full bg-brand-400/25"></span>
                                    </div>
                                </div>
                            </li>
                        </ol>
                        <figcaption class="mt-6 text-xs leading-relaxed text-brand-200">Ilustração conceitual do planejamento. Não representa uma conta, valores ou dados financeiros reais.</figcaption>
                    </figure>
                </div>
            </section>

            <section aria-label="Objetivos do planejamento" class="border-b border-slate-200 bg-brand-50/60">
                <ul class="mx-auto grid max-w-7xl gap-6 px-6 py-8 text-sm font-medium text-slate-700 sm:grid-cols-3 lg:px-8">
                    <li class="flex items-center gap-3"><span aria-hidden="true" class="text-brand-600">↗</span>Antecipe seus compromissos</li>
                    <li class="flex items-center gap-3"><span aria-hidden="true" class="text-brand-600">◈</span>Defina o que é prioridade</li>
                    <li class="flex items-center gap-3"><span aria-hidden="true" class="text-brand-600">◎</span>Acompanhe previsto e realizado</li>
                </ul>
            </section>

            <section id="recursos" aria-labelledby="features-title" class="mx-auto max-w-7xl scroll-mt-8 px-6 py-20 sm:py-24 lg:px-8">
                <div class="flex max-w-2xl flex-col gap-4">
                    <p class="eyebrow text-brand-700!">RECURSOS PARA SUA ROTINA</p>
                    <h2 id="features-title" class="text-3xl leading-tight font-semibold tracking-tight sm:text-4xl">Seu planejamento merece<br class="hidden sm:block" /> mais do que anotações soltas.</h2>
                    <p class="text-base leading-relaxed text-slate-600">Do compromisso pontual à despesa que se repete: organize os detalhes sem perder a visão do todo.</p>
                </div>
                <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <article v-for="feature in features" :key="feature.title" class="panel flex flex-col items-start gap-4">
                        <span class="flex size-11 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                            <svg aria-hidden="true" focusable="false" class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path :d="feature.icon" /></svg>
                        </span>
                        <h3 class="text-lg leading-snug font-semibold tracking-tight">{{ feature.title }}</h3>
                        <p class="text-sm leading-relaxed text-slate-600">{{ feature.description }}</p>
                    </article>
                </div>
            </section>

            <section id="como-funciona" aria-labelledby="steps-title" class="scroll-mt-8 border-y border-slate-200/80 bg-canvas">
                <div class="mx-auto max-w-7xl px-6 py-20 sm:py-24 lg:px-8">
                    <div class="flex max-w-2xl flex-col gap-4">
                        <p class="eyebrow text-brand-700!">COMO FUNCIONA</p>
                        <h2 id="steps-title" class="text-3xl leading-tight font-semibold tracking-tight sm:text-4xl">Um plano que acompanha você.</h2>
                        <p class="text-base leading-relaxed text-slate-600">Três passos para transformar compromissos em uma visão mais clara das suas finanças.</p>
                    </div>
                    <ol class="mt-12 grid gap-10 md:grid-cols-3 md:gap-8">
                        <li v-for="step in steps" :key="step.number" class="flex flex-col items-start gap-5 border-t border-slate-200 pt-6">
                            <span aria-hidden="true" class="text-3xl font-semibold tracking-tight text-brand-600">{{ step.number }}</span>
                            <h3 class="max-w-xs text-xl leading-snug font-semibold tracking-tight">{{ step.title }}</h3>
                            <p class="text-sm leading-relaxed text-slate-600">{{ step.description }}</p>
                        </li>
                    </ol>
                </div>
            </section>

            <section id="objetivo" aria-labelledby="purpose-title" class="mx-auto grid max-w-7xl scroll-mt-8 gap-10 px-6 py-20 sm:py-24 lg:grid-cols-2 lg:items-center lg:gap-20 lg:px-8">
                <div class="flex flex-col items-start gap-5">
                    <p class="eyebrow text-brand-700!">NOSSO OBJETIVO</p>
                    <h2 id="purpose-title" class="text-3xl leading-tight font-semibold tracking-tight sm:text-4xl">Menos improviso.<br />Mais intenção nas suas decisões.</h2>
                    <p class="text-base leading-relaxed text-slate-600">O DamascenoSRV Finances é um site para provisionamentos financeiros e organização financeira. A proposta é ajudar você a enxergar seus compromissos e planejar os próximos meses com mais contexto.</p>
                    <p class="text-base leading-relaxed text-slate-600">Você registra o que espera receber ou pagar, acompanha as prioridades e informa o que foi realizado. O planejamento fica em um só lugar; as decisões continuam com você.</p>
                </div>
                <aside aria-labelledby="planning-title" class="rounded-2xl border border-brand-100 bg-brand-50 p-6 sm:p-8">
                    <span aria-hidden="true" class="flex size-11 items-center justify-center rounded-xl bg-white text-brand-700">
                        <svg focusable="false" class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><circle cx="12" cy="12" r="9" /><path d="M12 11v6m0-10v1" /></svg>
                    </span>
                    <h3 id="planning-title" class="mt-5 text-xl font-semibold tracking-tight text-brand-950">Planejamento, não transações.</h3>
                    <p class="mt-4 text-sm leading-relaxed text-slate-700">As baixas de pagamentos e recebimentos são manuais e informativas. Registrar uma baixa não movimenta dinheiro nem efetua um pagamento.</p>
                    <p class="mt-4 text-sm leading-relaxed text-slate-700">O sistema não conecta contas bancárias, não importa movimentações bancárias automaticamente e não substitui seu banco.</p>
                </aside>
            </section>

            <section aria-labelledby="start-title" class="px-6 pb-20 sm:pb-24 lg:px-8">
                <div class="mx-auto flex max-w-7xl flex-col items-start gap-8 rounded-3xl bg-brand-950 px-6 py-10 text-white sm:px-10 sm:py-12 lg:flex-row lg:items-center lg:justify-between lg:px-12">
                    <div class="flex max-w-xl flex-col gap-4">
                        <p class="text-xs font-semibold tracking-widest text-brand-300">SEU PRÓXIMO PASSO</p>
                        <h2 id="start-title" class="text-3xl leading-tight font-semibold tracking-tight sm:text-4xl">Dê um lugar aos seus planos.</h2>
                        <p class="text-base leading-relaxed text-brand-100">Comece a organizar hoje os compromissos que fazem parte do seu amanhã.</p>
                    </div>
                    <div class="flex shrink-0 flex-col items-start gap-4">
                        <a :href="registerUrl" class="btn-primary min-h-12 px-6">Criar minha conta <span aria-hidden="true">→</span></a>
                        <a :href="appUrl" class="inline-flex min-h-11 items-center text-sm text-brand-100 underline underline-offset-4 hover:text-white">Já tem uma conta? Entre aqui</a>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-slate-200 bg-canvas">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-6 px-6 py-8 lg:px-8">
                <a href="#" class="inline-flex items-center gap-3" aria-label="DamascenoSRV Finances — voltar ao início">
                    <span class="brand-mark" aria-hidden="true">◈</span>
                    <span class="text-sm font-semibold">DamascenoSRV <span class="text-brand-700">Finances</span></span>
                </a>
                <p class="max-w-sm text-xs leading-relaxed text-slate-600">Organização no presente. Perspectiva para o futuro.<br />Um espaço de planejamento, sem transações bancárias.</p>
            </div>
        </footer>
    </div>
</template>
