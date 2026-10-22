# DamascenoSRV Finances — provisionamento financeiro

Sistema de planejamento de contas a pagar e a receber. Permite projetar débitos e créditos únicos, parcelados ou recorrentes, acompanhar vencimentos e registrar baixas **manuais e informativas**. Não executa transferências, cobranças, pagamentos nem qualquer transação bancária.

## Stack e requisitos

- PHP 8.3+, Composer 2, Laravel 13.
- Vue 3, TypeScript 5.9, Vite 8, Tailwind CSS 4.
- Node.js 22.12+ e npm. Node 22.23 foi utilizado no desenvolvimento.
- SQLite para desenvolvimento/testes; migrations e consultas compatíveis com MySQL 8+ e MariaDB 10.6+.
- Extensões PHP usuais do Laravel, incluindo `pdo_sqlite` ou `pdo_mysql` conforme o banco.

## Instalação e execução

```sh
composer install
cp .env.example .env
php artisan key:generate
npm ci
```

Configure `.env` antes das migrations. Para SQLite, mantenha `DB_CONNECTION=sqlite` e crie o arquivo se ainda não existir:

```sh
touch database/database.sqlite
php artisan migrate
npm run build
composer run dev
```

Abra o endereço exibido pelo servidor Laravel, não a porta do Vite. Cadastre sua conta na interface; não existe usuário/senha padrão e não é necessário executar seeders. O banco da aplicação não é populado com exemplos financeiros.

Como alternativa a `composer run dev`, execute `php artisan serve` e `npm run dev` em terminais separados. Após uma alteração do frontend, mantenha o Vite em execução ou gere os assets com `npm run build`.

### MySQL / MariaDB

Crie previamente um banco vazio e um usuário com acesso a ele. Exemplo de configuração, substituindo os valores pelas credenciais do seu ambiente:

```dotenv
APP_NAME="DamascenoSRV Finances"
APP_URL=http://localhost:8000
APP_TIMEZONE=America/Sao_Paulo
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=provision_finances
DB_USERNAME=seu_usuario
DB_PASSWORD=sua_senha
FINANCE_PROJECTION_MONTHS=12
```

O driver `mysql` também atende MariaDB; a configuração padrão do Laravel disponibiliza ainda a conexão `mariadb`. Em seguida:

```sh
php artisan config:clear
php artisan migrate
npm run build
```

A interface e as mensagens de validação são em português do Brasil. A data corrente usa `APP_TIMEZONE`, com padrão `America/Sao_Paulo`.

### Geração automática de recorrências

Na criação/edição, a projeção é gerada imediatamente. Para estender a janela com a passagem do tempo, configure o scheduler do Laravel. O comando é idempotente e está agendado diariamente, com prevenção de sobreposição:

```sh
php artisan finance:generate-occurrences
```

Em desenvolvimento, execute em outro terminal:

```sh
php artisan schedule:work
```

Em produção, adicione ao cron uma execução por minuto de `php artisan schedule:run`, usando os caminhos absolutos de PHP e do `artisan` no seu servidor. Sem o scheduler, a janela existente não avança automaticamente; a situação vencida continua correta por ser calculada na leitura.

A janela é `hoje + FINANCE_PROJECTION_MONTHS`, inclusiva, padrão 12 meses e limitada a 1–60 meses. Uma recorrência que começa depois da janela permanece cadastrada, sem gerar ocorrências até entrar no horizonte. O scheduler deve estar em execução mesmo quando ninguém acessa a aplicação.

## Modelo e regras financeiras

`FinancialPlan` guarda a regra do planejamento. `Occurrence` guarda cada parcela/ocorrência e seus valores previstos, competência, vencimento, baixa e ajustes individuais. Valores são enviados na API como **strings decimais**, por exemplo `"1234.56"`.

### Único, parcelado e recorrente

- **Único:** uma ocorrência. Não aplica juros.
- **Parcelado:** obrigação finita de 1–360 parcelas. O formulário exige distinguir **valor total do principal** de **valor de cada parcela**. A frequência dos vencimentos é independente do período da taxa de juros.
- **Recorrente:** compromissos ou receitas repetidos, como salário, aluguel, energia e assinaturas. O valor é sempre o de **cada ocorrência**, sem juros de parcelamento. Frequências diária, semanal, mensal e anual; término inclusivo e limite de ocorrências opcionais. Quando ambos são informados, vale o primeiro limite atingido.

O total de principal é dividido em centavos inteiros, distribuindo um centavo adicional nas primeiras parcelas até consumir o resto. Exemplo: R$ 100,00 em três parcelas resulta em R$ 33,34 + R$ 33,33 + R$ 33,33 antes de juros.

### Fórmulas de juros

Para cada parcela:

- `P`: principal da parcela, após a divisão quando o valor informado é o total.
- `r`: taxa percentual informada / 100.
- `d`: dias corridos entre a competência **do plano** e o vencimento da parcela, com mínimo zero.
- `b`: 1 para taxa diária, 30 para mensal e 365 para anual.
- `t = d / b`.
- **Simples:** `A = P × (1 + r × t)`.
- **Compostos:** `A = P × (1 + r)^t`.

O vencimento da primeira parcela pode gerar juros: o tempo parte da competência, não do número da parcela. Mês de 30 dias e ano de 365 dias são convenções financeiras; os vencimentos continuam usando o calendário real. Exemplo: P=100, taxa mensal=10%, competência 01/01 e vencimento 31/01 dão t=1 e A=110 nos dois regimes. Para 60 dias: simples=120 e composto=121.

É uma projeção estimativa, **não uma tabela Price/SAC** nem amortização bancária. Usa Brick Math já disponível no Laravel: valores e somas decimais sem `float`; juros simples racionais; expoentes compostos fracionários calculados por aproximação decimal (32 casas de trabalho, fator diário com 24 casas). Arredondamento monetário `half-up` apenas para centavos de cada parcela. Valores individuais até R$ 9.999.999.999.999,99, taxas até 9.999,9999%; projeções que excedem o limite são recusadas atomicamente.

MySQL/MariaDB usam `DECIMAL(15,2)` para dinheiro e `DECIMAL(8,4)` para taxas. SQLite usa texto decimal canônico para evitar a conversão implícita de NUMERIC em ponto flutuante. Os agregados são calculados com precisão decimal em lotes no servidor, não com `SUM()` do SQLite. A ordenação monetária no SQLite usa centavos inteiros.

### Meses curtos e anos bissextos

Cada data é calculada a partir da data-base original, nunca da ocorrência anterior. Nos dias 29, 30 e 31, o vencimento é limitado ao último dia do mês de destino. Assim, uma recorrência iniciada em 31/01 passa por 28/02 (ou 29/02) e volta a 31/03. Uma recorrência anual em 29/02 vence em 28/02 nos anos comuns e volta a 29/02 nos bissextos. A competência recorrente segue sua própria data-base com a mesma frequência.

### Baixas e situações

- **Em aberto (`open`):** sem baixa e vencimento hoje ou depois.
- **Vencida (`overdue`):** sem baixa e vencimento antes de hoje.
- **Paga (`paid`):** baixa até o vencimento.
- **Paga em atraso (`paid_late`):** baixa após o vencimento.

As situações são derivadas das datas em todas as leituras, filtros e ordenações; não dependem de um job para ficarem vencidas. O usuário registra data e valor realizado, que pode diferir do previsto; a baixa não altera o principal projetado. Datas futuras de baixa são recusadas. Um registro pago em atraso conta integralmente como realizado. A baixa representa liquidação informativa integral, não um sistema de pagamentos parciais: não há saldo residual automático quando o valor realizado difere do previsto. É possível reabrir explicitamente a ocorrência, removendo a baixa.

### Edições, exclusões e ciclo de vida

- **Somente esta ocorrência:** ajusta descrição, categoria, valor, competência e vencimento de uma ocorrência não realizada. Fica marcada como exceção e sobrevive ao recálculo do plano.
- **Esta e as próximas:** aplica novos termos a partir da sequência selecionada, inclusive. Datas continuam relativas às datas-base do plano, não à data da ocorrência selecionada; o formulário explicita isso. Sequências anteriores, baixas, exceções e exclusões são preservadas.
- **Todo o plano:** recalcula somente ocorrências não realizadas e sem exceções. As baixas e seus valores permanecem intactos. Consequentemente, o total histórico pode diferir de um novo valor total informado.
- Ocorrências pagas não podem ser alteradas/excluídas sem reabertura explícita. A edição de um plano com baixas não pode mudar seu tipo.
- A exclusão individual usa uma marca persistente (`is_deleted`), impedindo que a próxima geração recrie o registro.
- **Pausar:** remove projeções não realizadas com vencimento hoje ou depois, inclusive ajustes individuais futuros; preserva baixas e vencidas. Não altera o calendário original.
- **Retomar:** gera a partir da data da retomada, sem repor datas suprimidas durante a pausa. Essas datas ainda consomem posições do eventual limite de ocorrências original.
- **Encerrar:** interrompe definitivamente a geração e remove futuras não realizadas; preserva baixas e vencidas. Não pode ser retomada ou editada.
- **Excluir plano:** remove fisicamente se não houver baixas; se houver, arquiva como encerrado, mantém as baixas e remove os demais registros. A confirmação distingue esta ação da exclusão individual.

Geração, edição, baixa e exclusão são transacionais. O bloqueio da linha do plano serializa alterações concorrentes; a restrição única `(financial_plan_id, sequence)` é a última proteção contra duplicatas.

## Dashboard, filtros e exportação

- Entradas/saídas previstas: soma dos valores **planejados**, inclusive os já baixados.
- Saldo projetado: créditos previstos menos débitos previstos.
- Saldo realizado: créditos realizados menos débitos realizados, incluindo baixas em atraso.
- Pendentes/vencidos: volume bruto de entradas **mais** saídas ainda não baixadas.
- Gráficos: entradas versus saídas, evolução acumulada, categorias e realizado versus pendente. Identificam as contribuições recorrentes, possuem legendas/dicas e permitem abrir os registros por clique ou teclado.
- Próximos vencimentos: até dez não realizados entre hoje e os próximos 30 dias, respeitando todos os filtros.

Todos os indicadores e gráficos respeitam o mesmo recorte da tabela. O agrupamento é pelo **vencimento**, não pela competência nem pela data da baixa. O acumulado começa em zero no recorte e não representa um saldo bancário ou saldo anterior. Somente meses com registros aparecem nas séries.

Filtros combináveis de mês, ano, intervalo inclusivo, categoria, situação, texto, direção e tipo. Mês/ano e intervalo se **intersectam**. `pending` e `realized` são filtros agrupados adicionais usados nos gráficos: aberto+vencido e pago+pago em atraso, respectivamente. Ordenação e paginação são feitas no servidor; os totais abrangem todos os resultados filtrados, não apenas a página. Filtros persistem na URL, inclusive ao voltar/avançar no navegador.

CSV exporta **todos** os resultados filtrados e ordenados, ignorando a página, em UTF-8 com BOM, vírgula como separador e decimais com ponto. Prefixos de fórmula em textos são neutralizados para reduzir o risco de CSV injection.

## API JSON versionada

Base: `/api/v1`. A API é **same-origin**, autenticada pelo guard de sessão `web`, com proteção CSRF habilitada. A localização das rotas em `routes/web.php` é deliberada para compartilhar cookies, sessão e middleware; as respostas de negócio são JSON, não Blade. O único Blade carrega a aplicação Vue.

1. `GET /csrf` inicia a sessão e devolve `{ "token": "..." }`.
2. Envie o token no cabeçalho `X-CSRF-TOKEN` e mantenha cookies nas alterações.
3. `POST /register` ou `POST /login`; atualize o token após autenticar.
4. Envie `Accept: application/json`. Não grave senhas/tokens no localStorage.

| Método | Rota | Finalidade |
|---|---|---|
| GET | `/user` | Usuário autenticado |
| POST | `/logout` | Encerra a sessão |
| GET / POST | `/plans` | Lista / cria planos |
| GET / PUT / DELETE | `/plans/{id}` | Consulta / edita / exclui plano |
| POST | `/plans/{id}/lifecycle` | `action`: `pause`, `resume`, `end` |
| GET | `/occurrences` | Resultados paginados e totais filtrados |
| PATCH / DELETE | `/occurrences/{id}` | Ajuste individual / exclusão |
| PUT / DELETE | `/occurrences/{id}/payment` | Baixa manual / reabertura |
| GET | `/occurrences/export` | CSV filtrado |
| GET | `/categories` | Categorias do usuário |
| GET | `/dashboard` | Indicadores, séries e próximos vencimentos |

`PUT /plans/{id}` aceita edição parcial e os campos `scope: all|future`, com `occurrence_id` obrigatório para `future`. A edição individual utiliza `PATCH /occurrences/{id}`. Filtros de listagem: `month`, `year`, `from`, `to`, `category`, `status`, `text`, `direction`, `kind`, `sort`, `order`, `page`, `per_page` (1–100). Ordenações permitidas: `due_date`, `amount`, `description`, `category`, `status`; ordem `asc|desc`.

Consultas, gráficos, categorias, exportações e mutações são isolados por usuário. Identificadores de outro usuário retornam 404. Login/cadastro têm limitação por conta+IP e por IP. Erros de validação retornam 422; ausência de autenticação, 401. Não há chaves de serviços externos ou integração financeira.

## Validação

```sh
php artisan test --compact
npm run typecheck
npm run test:frontend
npm run build
vendor/bin/pint --format agent
```

Validação local: **174 testes PHP passaram (966 asserções)**, **10 testes de formatação monetária do frontend passaram**, checagem TypeScript e build de produção passaram. Os testes de frontend usam o runner nativo do Node, sem novas bibliotecas de testes.

Os testes PHP usam SQLite em memória, conforme `phpunit.xml`; nunca aponte testes destrutivos para o banco da aplicação. Cobrem cálculos, arredondamento, recorrências, calendário, preservação histórica, idempotência, API, filtros, totais, CSV, autenticação, CSRF, rate limiting e isolamento por usuário. Para validar especificamente MySQL/MariaDB, prepare **um banco exclusivo de testes**, configure o ambiente de testes e remova as substituições SQLite de `phpunit.xml` em uma configuração separada antes de executar a suíte.

## Análise do projeto de referência e decisões

Referência analisada: `leonardohenriquedc/provision_finances_system`, commit `6c264f2d3707c239814cecddfc952c4dbb2327cf` — README, controllers, serviços de provisionamento/parcelas e testes de parcelas. A finalidade estimativa e o isolamento por usuário foram preservados, não a implementação problemática.

Principais problemas identificados no original:

1. Frequência de vencimentos dependia do período de juros; criação e edição calculavam datas de formas diferentes.
2. Edição apagava e recriava parcelas, descartando status.
3. `LATE_PAYMENT` era aceito na atualização, mas não era contabilizado corretamente como realizado nos agregados.
4. Valor realizado se confundia com previsto, não havia recorrência independente e parte dos gráficos não respeitava os filtros da listagem.

Nesta implementação, regras e ocorrências são separadas; dinheiro é decimal; baixas têm seus próprios campos; status são derivados; filtros são compartilhados e a interface é Vue com TypeScript. Não foram adicionadas bibliotecas de gráficos ou serviços externos; SVGs acessíveis evitam dependências extras. TypeScript e `vue-tsc` são as novas ferramentas de desenvolvimento do frontend.

## Limitações conhecidas e operação

- Teto vitalício de segurança de **10.000 sequências por recorrência**, inclusive quando nenhum limite é informado. Uma diária alcança esse teto em cerca de 27,4 anos. O plano permanece ativo ao esgotar as sequências; não há alerta automático de esgotamento. Parcelamentos têm até 360 parcelas. Não há geração infinita de linhas.
- Juros compostos fracionários são uma aproximação decimal, não precisão simbólica exata. Não modela impostos, IOF, amortizações, feriados ou dias úteis.
- Não há importador automático dos dados do repositório anterior. Esta é uma implementação sobre a aplicação local; não rode estas migrations como atualização direta de um banco legado sem mapear e migrar os dados previamente.
- Não há trilha completa de auditoria, recuperação de senha, verificação de e-mail, suporte multimoeda ou conciliação bancária.
- Agregados usam leitura em lotes e aritmética decimal; para volumes muito grandes, convém medir e otimizar sem perder a precisão e o isolamento. Lista de planos recorrentes não é paginada.
- Testes de formatação monetária usam Node; testes automatizados de frontend em navegador real não foram adicionados. Build e checagem TypeScript não substituem uma revisão visual/acessibilidade com tecnologias assistivas.
- Compatibilidade MySQL/MariaDB foi mantida no esquema e nas consultas, mas a execução da suíte nessa base exige credenciais de um banco de testes. A validação automatizada local foi realizada com SQLite.
- Em produção, sirva `public/`, configure HTTPS, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, faça backups e mantenha scheduler e dependências atualizados. Os arquivos `.env` nunca devem ser publicados.
