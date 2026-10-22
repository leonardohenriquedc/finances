<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <meta name="theme-color" content="#2563eb">
        <meta name="description" content="Planeje suas finanças, acompanhe entradas, saídas, parcelas e recorrências com mais clareza.">
        <title>DamascenoSRV Finances · Planejamento financeiro</title>

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    </head>
    <body>
        <div id="app"></div>
        <noscript>Ative o JavaScript no navegador para acessar seu planejamento financeiro.</noscript>
    </body>
</html>
