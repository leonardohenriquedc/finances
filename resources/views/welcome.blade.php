<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <meta name="theme-color" content="#2563eb">
        <meta name="description" content="DamascenoSRV Finances: um site para provisionamentos financeiros e organização financeira. Planeje contas a pagar e a receber, parcelas e recorrências, sem transações bancárias.">
        <title>{{ $page === 'presentation' ? 'DamascenoSRV Finances · Provisionamentos e organização financeira' : 'DamascenoSRV Finances · Planejamento financeiro' }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    </head>
    <body>
        <div
            id="app"
            data-page="{{ $page }}"
            data-app-url="{{ route('application') }}"
            data-register-url="{{ route('application', ['auth' => 'register']) }}"
            data-initial-register="{{ request()->query('auth') === 'register' ? 'true' : 'false' }}"
        ></div>
        <noscript>Ative o JavaScript no navegador para conhecer o DamascenoSRV Finances e acessar seu planejamento financeiro.</noscript>
    </body>
</html>
