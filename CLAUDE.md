# CLAUDE.md — module-zbx-plantonistas

Contexto, memória e instruções de trabalho deste projeto. Portável: serve como
knowledge de projeto no Claude.ai e como contexto de agente ao trabalhar neste
repositório. Atualizado em 2026-08-31.

---

## 1. Contexto

### O que é

Módulo frontend único para Zabbix 7.0 LTS que unifica dois módulos antigos:

- `module-zbx-escala-plantao` v3.0.1 (id `plantao`, ns `Modules\Plantao`) — escala mensal, telefones, histórico
- `module-zbx-repasse-plantao` v2.5.0 (id `turnos-noc-report`, ns `Modules\TurnosNocReport`) — relatório de repasse NOC, turnos por equipe

Resultado: **Plantonistas** — id `plantonistas`, namespace `Modules\Plantonistas`,
repo `leaoereno/module-zbx-plantonistas`. Rebranding completo (decisão do Rafael):
todas as actions com prefixo `plantonistas.` e todas as tabelas com prefixo
`module_plantonistas_`. De-para completo está no README.md.

**Números atuais** (v5.4.0): 23 actions no `manifest.json`, 9 tabelas no
`Schema.php`, 7 telas no menu. Este parágrafo já esteve defasado dizendo
"18 actions, 8 tabelas" — conferir com `manifest.json`/`Schema.php` antes de
citar, não repetir daqui.

### Infraestrutura

| Item | Valor |
|---|---|
| Produção | `monitoracao.claroempresas.com.br` — frontends `lnxdczbxfront01` e `lnxdczbxfront02` atrás de F5 BIG-IP |
| Banco produção | MariaDB em `172.18.190.21`, database `zabbix`, user `zabbix` (sem privilégio PROCESS — mysqldump precisa de `--no-tablespaces`) |
| SO produção | RHEL/CentOS: usuário web `apache`, serviço `php-fpm.service` (sem sufixo de versão) |
| Homolog | `lnxdczbxhmg01` (Docker + Nginx) |
| Lab | `lab-zbx` — 192.168.0.151 (AlmaLinux, Apache, PHP 8.4, MariaDB) — **testar aqui primeiro** |
| Homelab | 192.168.0.150 (Docker; SMTP relay boky/postfix na porta 25) |
| Módulos no servidor | `/usr/share/zabbix/modules/<nome>/` |

Restrição crítica do F5: **bloqueia arquivos `.js` estáticos** — todo JS das views
é inline. Exceção herdada: `assets/js/chart.min.js` (gráficos do Repasse) é
estático; se gráfico não renderizar atrás do F5, é isso.

### Arquitetura do módulo

Menu **Plantão** (após Reports), 7 itens: Visão Geral, Escala, Histórico,
Telefones, Repasse Plantão, Repasses (abertos/fechados), Gerenciar Turnos
(este só role type >= 2).

Duas famílias de código coexistem de propósito (não unificar sem demanda):

- **Família escala** (`Plantao*.php`, `Phones*.php`): camada de DB nativa do
  Zabbix (`\DBselect`/`\DBfetch`/`\DBexecute`), views com `ob_start()` +
  `CHtmlPage`, CSS inline tema escuro, prefixos CSS `plt-`, `ov-`, `phn-`.
- **Família repasse** (`Turnos*.php`): mysqli direto via `getDb()` no trait
  `TurnosReportBase` (lê `$GLOBALS['DB']`), assets em `assets/` (css `rp-*`,
  FontAwesome local, Chart.js), tema claro com detecção de dark theme via JS.

Classes PHP e nomes de arquivos de asset mantiveram os nomes antigos
(`PlantaoList`, `TurnosShiftsView`, `turnos.report.css`) — só namespace,
actions, tabelas e paths mudaram. Manifest mapeia action→classe livremente.

`Module.php::init()` roda a cada request: migração idempotente de schema
(RENAME das tabelas antigas → novas, cria faltantes, ajusta colunas/índices)
e monta o menu. **Não existem hooks onInstall/onUninstall no Zabbix** — tudo
vive no init(), e o módulo **nunca dropa tabela com dados** (única exceção:
tabela nova VAZIA no caminho do RENAME de uma antiga com dados).

Actions AJAX (`*.save`, `*.delete`, `notes.*`, `usershift.save` do repasse)
usam `layout.javascript` + `view: null` e fazem `echo json_encode(...); die()`.
Actions de redirect da escala (`plantonistas.save/delete/import/export`,
`phones.save/export`) não têm view no manifest — respondem redirect/CSV.
`plantonistas.report.notes.get` é action morta (sem caller) mantida por segurança.

Cron: `scripts/cron_presence_tracker.php` popula `module_plantonistas_user_sessions`
a cada 5 min, lendo tudo direto do banco (env `DB_*`, obrigatórias em CLI).
Não usa mais a API do Zabbix nem token — ver "Cron de presença sem API".
Crontab aponta pro caminho do módulo — mudou na unificação.

### Modelo de permissões por perfil

Checado via `getUserType()`/`CWebUser::getType()` (família escala) ou
`role.type` lido manualmente por `getUserRoleType()` (família repasse, que
usa mysqli direto e não tem acesso ao `CWebUser` nativo do mesmo jeito).
Constantes Zabbix: `USER_TYPE_ZABBIX_USER=1`, `USER_TYPE_ZABBIX_ADMIN=2`,
`USER_TYPE_SUPER_ADMIN=3`. Menu **Plantão** aparece a partir de type ≥ 1
(Guest não vê nada), mas **User (1) e Admin (2) enxergam os mesmos três
itens: Visão Geral, Repasse Plantão e Repasses (abertos/fechados)**. Escala,
Histórico, Telefones e Gerenciar Turnos são **exclusivos de Super Admin (3)**
desde 2026-09-14 (ver abaixo); entre 2026-08-17 e essa data eram Admin (2)+
(commit `9959722`).

| Tela | User (1) | Admin (2) | Super Admin (3) |
|---|---|---|---|
| Visão Geral | Vê só os próprios grupos | idem User | Todos os grupos |
| Escala / Histórico | **Sem acesso** (menu não aparece; `checkPermissions()` recusa) | **Sem acesso** (idem) | Todos os grupos |
| Telefones | **Sem acesso** (idem) | **Sem acesso** (idem) | Todos os usuários habilitados do sistema |
| Repasse Plantão (relatório) | Eventos seguem `rights` do Zabbix; MTTA só o próprio; Notas/Presença só do(s) próprio(s) grupo(s) | MTTA de todos; Notas/Presença do(s) próprio(s) grupo(s) | Sem filtro nenhum |
| Diário de Bordo (escrever) | Pode escrever nota | idem | idem |
| Gerenciar Turnos | **Sem acesso** (idem) | **Sem acesso** (idem) | Todas as equipes com ≥1 membro |

O código que restringia Admin (2) por grupo nessas telas continua no lugar
(`listManageableGroups()`, filtro de `usrgrp` em `PlantaoList`/`PhonesList`):
virou caminho morto, não foi removido. Se um dia alguma delas voltar para
Admin (2)+, o comportamento por grupo volta junto — mas hoje só quem entra é
Super Admin, que cai sempre no ramo "todos os grupos".

**Restrição de User (1) a Visão Geral + Repasse — commit `9959722`
(2026-08-17).** Antes disso o User via o menu Plantão inteiro e abria todas
as telas. Duas camadas foram necessárias, e a segunda é a que importa:
`Module.php:53-55` monta o submenu condicional ao tipo (Escala e Telefones
com `setAliases()` das actions filhas, pra o item continuar marcado durante
save/delete/export/import), **mas esconder o item do menu não basta — a
action continua acessível pela URL**. Por isso o `checkPermissions()` de
`PlantaoList/Save/Delete/History/Export/Import` e de
`PhonesList/Save/Export/Import` passou de `USER_TYPE_ZABBIX_USER` para
`USER_TYPE_ZABBIX_ADMIN`. Os AJAX de turnos (`shifts.save`, `shifts.delete`,
`usershift.save`) validavam só `!isGuest()` — qualquer usuário autenticado
gravava turno por POST direto — e agora exigem Admin também.

**Escala, Histórico, Telefones e Gerenciar Turnos passaram a Super Admin (3)
— 2026-09-14.** Na prática quase todo o NOC é Admin (2) no Zabbix, então o
corte em `USER_TYPE_ZABBIX_ADMIN` não separava ninguém: as quatro telas
administrativas estavam visíveis para o turno inteiro. As mesmas duas camadas
do commit `9959722` subiram um degrau — o gate do menu em `Module.php`
(`$is_admin` virou `$is_super`) e, o que de fato fecha, o `checkPermissions()`
de `PlantaoList/Save/Delete/History/Export/Import`,
`PhonesList/Save/Export/Import`, `TurnosShiftsView` e dos três AJAX de turno
(`shifts.save`, `shifts.delete`, `usershift.save`). Junto foram as duas flags
que desenham atalho para tela fechada: `can_manage` (botão "Gerenciar Escala"
na Visão Geral) e `can_manage_shifts` (atalho "Gerenciar Turnos" no cabeçalho
do Repasse) — deixar o botão aparecendo levaria o Admin a um "Acesso negado".

**Não mexeu no Repasse.** `restrictMttaByRole()` (Admin vê o MTTA de todos,
User só o próprio) e o refechamento de turno em `TurnosReportClose` continuam
em Admin (2)+: são regras de dentro do Repasse, tela que segue aberta para
todo mundo. E o telefone do plantonista de hoje continua a um hover na Visão
Geral, que é User (1)+ — perder a tela Telefones não tira de ninguém o número
para ligar às 3h.

Pontos fora do padrão "grupo = visibilidade":
- **Telefones** exigia grupo **e** role idênticos até 2026-08-19 — era bug,
  ver "Telefones: o filtro por papel era bug". Hoje a leitura é só por grupo;
  o papel só restringe a **escrita**.
- **Diário de Bordo / Presença** (família repasse) segmentam por
  `users_groups` compartilhado — segmentação própria do módulo, independente
  da tabela `rights` do Zabbix.
- **Eventos/alertas** do Repasse (KPIs, Top Hosts, MTTA) seguem `rights` do
  Zabbix quando configurada; sem `rights` por host, todo mundo vê tudo.

---

## 2. Memória (estado e decisões)

### Decisões tomadas e porquês

- **Rebranding total** (actions + tabelas) foi escolha explícita do Rafael,
  ciente de que quebra URLs salvas e exige migração — a alternativa
  conservadora (manter nomes) foi oferecida e recusada.
- **Migração por RENAME TABLE** (atômico, só metadados): dados de produção
  preservados sem cópia. Automática no init(), com `sql/migrate-from-old-modules.<banco>.sql`
  (manual, opcional) e `sql/rollback-to-old-modules.<banco>.sql` (reverso).
- `scripts/]` do repo antigo era lixo acidental (cópia truncada do cron) — excluído.
- `install.sh` ganhou guard: pergunta se o banco é novo antes de rodar schema.sql
  (rodar schema em banco com dados antigos criaria tabelas novas vazias).

### Causa raiz do bug "grupos não listam usuários" (Gerenciar Turnos)

O schema v2.5 do repasse **nunca rodou em produção**: `custom_shifts` e
`custom_user_shift` não existem lá. `listShiftsByGroup()` e `listUsersByGroup()`
(LEFT JOIN em `custom_user_shift`) falhavam e o catch devolvia `[]` em silêncio —
a tela mostrava "Nenhum usuário Zabbix neste grupo" para tudo.
**Confirmado em produção em 2026-08-14** (query no front02 contra 172.18.190.21):
existem 6 tabelas (`module_plantao_phones/schedule/history`, `custom_shift_notes`,
`custom_shift_reports`, `custom_user_sessions`); faltam as 2 do v2.5.
O v4 resolve: init() cria as duas que faltam e renomeia as 6 existentes.
Falhas de consulta agora saem no log com prefixo `[plantonistas]`, e a view de
turnos distingue "equipe sem analistas" de "consulta falhou".

### Correções de UX da tela Gerenciar Turnos (feitas no repasse, incluídas no v4)

Botões travam durante request AJAX (anti duplo clique); salvar (azul) e remover
(vermelho `--sev-disaster`) visualmente distintos; linha editada e não salva
marcada em amarelo (`rp-row-dirty`); salvar/criar/remover turno atualiza DOM
inline (sem `location.reload()`); equipes em abas (`rp-tabs`); turnos legados
(24h/Manhã/Tarde/Noite) exibidos como chips informativos.

### Turnos dinâmicos na tela Escala / Escalar Técnico (2026-08-14)

`module_plantonistas_schedule` ganhou a coluna `shift_id` (default 0 =
grupo sem turnos, comportamento idêntico ao de antes) e a unique key virou
`(usrgrpid, schedule_date, shift_id)`: 1 titular por grupo/dia sem turno,
1 titular por grupo/dia/turno em grupos com turnos cadastrados em
"Gerenciar Turnos". Migração idempotente em `Module::migrateColumns()`
(ADD COLUMN + troca de unique key, ADD antes do DROP pra nunca ficar sem
constraint), replicada em `sql/schema.mysql.sql` pra instalação nova.

- **Escala**: se o grupo tem turnos ativos, "Escalar Técnico" renderiza 1
  seletor por turno (rótulo = nome do turno) no lugar do par Técnico/Reserva
  único. Turno deixado em branco no formulário não altera o que já está
  salvo (dá pra editar 1 turno por vez sem reescalar os outros). Decisão do
  Rafael: **sem reserva por turno** — grupos com turnos só têm titular.
- Calendário (Escala) e cards (Visão Geral) empilham 1 entrada por turno
  preenchido, com o nome do turno como rótulo; Visão Geral distingue "Com
  cobertura" / "Cobertura parcial" (só parte dos turnos preenchida) / "Sem
  cobertura".
- `module_plantonistas_history` ganhou `shift_id`/`shift_name` (snapshot do
  nome no momento da alteração — sobrevive a rename/remoção do turno depois);
  tela Histórico ganhou coluna Turno. `PlantaoDelete` também loga o turno.
- **CSV import/export propositalmente NÃO mexidos** (decisão do Rafael) —
  continuam gravando/lendo só em modo legado (shift_id=0), mesmo em grupos
  com turnos. Consequência aceita: importar CSV num grupo com turnos cria
  uma entrada "sem turno" ao lado das entradas por turno (aparece no
  calendário sem rótulo, não é perdida); exportar um grupo com turnos só
  reflete a última entrada do dia lida do banco, não soma os turnos.

### Bug dos campos de turno "grandes demais" na Escala (2026-08-14)

`.plt-select-row` (largura ~300px) foi desenhada pra dentro de `.plt-form-row`,
que é flex **row** — ali `flex:0 1 300px` vira largura. O bloco novo por turno
(`.plt-shift-field`) é flex **column**; reaproveitar `.plt-select-row` dentro
dele faz o MESMO `300px` virar **altura** (flex-basis segue o eixo principal
do pai), e cada campo de turno inchava pra ~300px de altura. Fix: regra
escopada `.plt-shift-field .plt-select-row { flex:0 0 auto; width:300px; }`
restaura largura fixa sem depender do eixo do flex pai.

Investigado também o relato de que campos de técnico apareceriam em grupo
**sem** turno cadastrado: `PlantaoList::doAction()` já filtra `$shifts` por
`usrgrpid` e a view faz `if (!$has_shifts)` (par Técnico/Reserva único) /
`else` (1 campo por turno) — mutuamente exclusivos, sem caminho no código pra
os dois aparecerem juntos ou pro campo por turno aparecer sem turno
cadastrado. Não reproduzido por leitura de código; efeito provavelmente do
bug de altura acima (campo de ~300px lido como "não deveria estar aí").
Reabrir com print se persistir depois do fix de tamanho.

### Estado do deploy em produção (2026-08-14)

Feito: repo GitHub criado; clone no **front02**; tabelas de produção verificadas.
Não confirmado/pendente: backup dump concluído; clone + chown + restart php-fpm
no **front01**; chown/restart no front02; Scan directory; desabilitar os 2
módulos antigos; habilitar Plantonistas; atualizar crontab do presence tracker;
conferir roles (Modules → Plantonistas); validar as 7 telas; remover pastas
antigas após estabilizar. Runbook completo no README.md.

Atualização 2026-08-17: front01 rodando o código novo, tela de Repasse
validada (Presença voltou a listar, 16 analistas) e cron do presence tracker
recriado ali — ver "Cron de presença estava desabilitado". Continua pendente
conferir o `/etc/cron.d/` do **front02** (se o cron antigo estiver ativo lá,
desabilitar: o tracker deve rodar em um nó só).

Nota: o fix de UX também ficou não-commitado na working tree do repo antigo
`module-zbx-repasse-plantao` local — irrelevante após a unificação, mas o
histórico está no zip `module-zbx-repasse-plantao-commit-1dadacd.zip` se precisar.

### Filtro de usuário ativo/bloqueado (2026-08-14, **corrigido em 2026-08-17**)

⚠️ A versão de 2026-08-14 registrava aqui que "usuário aparece se tiver pelo
menos 1 grupo com `users_status=0`" era "a mesma regra que o Zabbix usa".
**Isso estava errado** — a regra era o inverso da do Zabbix. Ver a seção
"Regra de usuário desabilitado estava invertida" abaixo. O critério vigente
está descrito lá; `PlantaoImport` (CSV) continua sem filtro, mesma decisão de
não mexer no import/export.

Decisão consciente que **continua valendo**: escala/telefones/turnos **não**
filtram pelo bloqueio de login (`users.attempt_failed`/`attempt_clock`) — é
estado transitório de segurança (conta trava minutos após senhas erradas),
sem relação com elegibilidade pra escala. A única tela que filtra bloqueio é
a busca de menção (`@`), por pedido explícito do Rafael.

### Datas e horas em padrão brasileiro (auditoria 2026-08-14)

Auditoria em todo o módulo (`date()` PHP + `Date`/`toLocaleString` JS). Maior
parte já estava em `d/m/Y` (herdado da família escala). Gaps corrigidos,
todos na família repasse:
- `TurnosReportBase::queryNotes()`/`queryPresence()`: `created_at`/
  `first_seen`/`last_seen` vinham crus do banco (`Y-m-d H:i:s`) — agora
  `DATE_FORMAT(..., '%d/%m/%Y %H:%i')`. Ordenação usa coluna auxiliar
  (`created_sort`/`first_seen_sort`) com o valor original — ordenar pela
  string já formatada dá resultado cronologicamente errado.
- `plantonistas.report.view.php`: timestamp otimista via JS (nota inserida
  antes do reload) reconstruído em `dd/mm/aaaa HH:MM`.

Datas usadas só como chave interna (nunca exibidas — heatmap do calendário,
`$today_str` da Escala) continuam em `Y-m-d` de propósito; não é gap pt-BR.

### Diário de Bordo — sem expiração automática (2026-08-14)

Confirmado por grep no repo inteiro: não existe `DELETE`/TTL/cron de limpeza
para `module_plantonistas_shift_notes`. As únicas limpezas automáticas do
módulo são `module_plantonistas_user_sessions` (cron, 7 dias) e os vínculos
`module_plantonistas_user_shift` (quando o turno é removido). Diário de Bordo
é histórico permanente — cresce sem limite. Ver Backlog.

### Cores dos botões de navegação do Repasse (2026-08-14)

"Gerenciar Turnos" e "Voltar ao Relatório" usam a mesma classe `.rp-nh-btn` —
1 fix resolveu os 2 lugares reportados + o botão "Gerar PDF" (mesma classe).
Fundo trocado de branco 15% translúcido (quase invisível sobre o header
escuro) para `var(--rp-blue)` sólido (mesma cor de `.rp-btn-primary`), com
borda sutil e sombra. Já respeita dark theme (`--rp-blue` já tem override).
Botão de reload (ícone só) não foi mexido — tinha estilo inline próprio.

**Regressão do mesmo dia**: o fundo sólido ficou certo, mas o texto continuou
azul (só ficava legível no `:hover`). Causa: `.rp-nh-btn` é aplicada em `<a>`,
e o tema nativo do Zabbix define cor de link com especificidade maior que uma
classe simples — o `color:#fff` da classe perdia a disputa de cascata em
repouso (no `:hover` não havia regra nativa concorrente, por isso só ali
ficava legível). Mesmo problema que `.plt-nav a.btn.btn-alt` já tinha
resolvido com `!important` (`views/plantonistas.list.php`). Fix: `!important`
em `background`/`color` de `.rp-nh-btn` e `.rp-nh-btn:hover`.

### Editor rico + menções no Diário de Bordo (2026-08-14)

Decisões tomadas com o Rafael (via perguntas de escopo) antes de implementar:
editor leve sem dependência nova (contenteditable + JS inline, sem vendorizar
biblioteca); notificação de menção como banner simples com link pra nota (sem
central de notificações); item escolhido vira link/chip limpo na nota (sem
manter texto de gatilho literal); os 3 tipos de menção entregues juntos, não
em etapas.

**Gatilhos trocados no mesmo dia**: sintaxe original era `[hostgroup]`/`[host]`/
`[user]` — o Rafael achou ruim (colchetes atrapalham digitação natural) e
pediu a troca por `@` (usuário), `_h` (host) e `_hg` (grupo de hosts), mais
próximo do que já se usa em Slack/Teams. `_h` é prefixo de `_hg` — resolvido
por ordem de alternância no regex (`_hg` testada antes de `_h`; ver comentário
em `plantonistas.report.view.php`), sem debounce. Ambíguo só no caso
`_hg<texto colado sem espaço>` (ex.: `_hgateway`): resolve como hostgroup com
busca "ateway", não host com busca "gateway" — edge case aceito, mesmo
espírito do edge case de sanitização abaixo. Só o regex de detecção no JS
mudou; o backend continua recebendo `type=hostgroup|host|user` sem alteração
nenhuma.

**Schema novo**: `module_plantonistas_shift_notes.notes_format` (`text` |
`html`, default `text` — distingue nota antiga de nota nova na exibição) e
tabela `module_plantonistas_mentions` (note_id, mentioned_userid, created_by,
is_read, created_at, read_at). Ambos migrados idempotente em
`Module::migrateColumns()`/`tableDdl()`, replicados em `sql/schema.mysql.sql`.

**Fluxo**: digitar `[hostgroup]`/`[host]`/`[user]` no editor abre um dropdown
(nova action `plantonistas.report.mentions.search`, GET com `type`+`q`) que
busca com a MESMA regra de permissão já usada no resto do módulo — não
inventei um modelo novo: hostgroup/host respeitam `rights` do Zabbix (igual
a `host_filter` de `resolveUserContext()`); user respeita grupo compartilhado
(igual a Notas/Presença) e exclui usuário com todos os grupos desabilitados
(igual ao filtro de usuário ativo). Selecionar um item substitui o texto
`[tipo]busca` por um `<a>` (hostgroup/host, linkando pra Monitoramento >
Problemas filtrado) ou `<span data-mention-userid>` (user, sem link).

**Sanitização**: `TurnosReportBase::sanitizeNoteHtml()`/`sanitizeNode()` —
allowlist de tags (`b,strong,i,em,u,ul,ol,li,br,p,div,a,span`) e atributos
por tag via `DOMDocument`, nunca regex. O servidor NUNCA confia no HTML do
cliente, mesmo vindo do próprio editor — dá pra postar direto no endpoint
sem passar pela UI. Testado com bypass aninhado (`<iframe><span onclick>`),
`javascript:`/`data:` em `href`, `<script>`: todos neutralizados (suite em
`test_sanitizer.php`, não versionada — rodar de novo se mexer nessa função).
Ponto de atenção arquitetural: a sanitização roda em **profundidade primeiro**
(sanitiza o filho antes de decidir promover/descartar a tag pai) — inverter
essa ordem reabre o bypass aninhado.

Menção extraída do HTML sanitizado (`<span data-mention-userid>`) grava 1
linha em `module_plantonistas_mentions` por userid válido (ativo + visível
ao autor — mesma regra de grupo compartilhado; menção a si mesmo é ignorada).
Menção inválida é descartada em silêncio, não derruba o save da nota.

`plantonistas.report.view.php` mostra o banner de menções pendentes (contador
no header + lista expansível), com "Ver nota" (marca como lida via
`sendBeacon`, não bloqueia a navegação) e "X" pra dispensar sem abrir.

**Notas antigas continuam funcionando** — `notes_format='text'` (default)
renderiza pelo caminho antigo (`nl2br(htmlspecialchars(...))`); só nota nova
(sempre `'html'`) usa o HTML sanitizado direto. `TurnosNotesGet.php`
(action morta, sem caller) não foi tocado.

Edge case conhecido e aceito: colar/digitar um `<` literal que NÃO veio de um
`contenteditable` real (ex.: POST manual direto no endpoint) pode truncar o
texto depois do `<` — o parser HTML do DOMDocument é mais permissivo que um
navegador nisso. Não afeta o uso normal (o navegador sempre serializa `<`
digitado como `&lt;` no `innerHTML`), só input adversarial/manual.

### Coluna Turno na Presença de Analistas (2026-08-17)

Tabela "Presença de Analistas" (Repasse) ganhou a coluna **Turno**, entre
Username e Primeira Atividade: nome + horário do turno vinculado ao usuário
em Gerenciar Turnos, no mesmo formato do seletor de turno do relatório
(`Diurno (07:00–19:00)`). Sem vínculo → "Sem turno" em itálico cinza
(classe `.rp-muted`, criada agora, com `var(--rp-text-muted)`).
`TurnosReportPdf` não renderiza essa tabela — não precisou de ajuste.

**Regressão do mesmo dia, e a lição que ficou**: a primeira versão buscou o
turno por `LEFT JOIN` dentro de `queryPresence()` e a tabela de presença
inteira voltou vazia, exibindo "Nenhum dado de presença. Execute o cron" —
mensagem enganosa, porque o cron não era o problema. Causa: `getDb()` liga
`MYSQLI_REPORT_STRICT`, então `prepare()` de query que referencia tabela
ausente **lança** `mysqli_sql_exception`, e as tabelas de turno podem não
existir no ambiente (schema v2.5 nunca rodou em produção — só o `init()` do
v4 as cria). Agravante: o `prepare()` estava **fora** do try, que só cobria
o `execute()` — a falha não era nem logada.

Regra que vale pro módulo inteiro: **informação acessória não entra por JOIN
na query do dado principal**. O turno virou query própria
(`attachUserShift()`, `IN` com userids já convertidos pra int) que anexa as
colunas às linhas de presença; se ela falhar, loga `[plantonistas]` e a
coluna mostra "Sem turno" — a presença continua na tela. `prepare()`/
`bind_param()` de `queryPresence()` passaram pra dentro do try com
`error_log`. Turno inativo (`active=0`) é exibido de propósito: o vínculo
existe, e esconder seria pior que mostrar um turno desativado.

Maioria dos analistas aparece como "Sem turno" porque o vínculo é um clique
por analista em Gerenciar Turnos (ver Backlog: salvar em massa).

### Cron de presença estava desabilitado desde a unificação (2026-08-17)

`/etc/cron.d/` do **front01** tinha só `zbx-repasse-plantao.disabled` — nome
com ponto é ignorado pelo cron.d, e ainda apontava pro script do módulo
antigo (que escreve em `custom_user_sessions`, tabela renomeada pelo v4).
Era a causa real da tabela vazia (a regressão do JOIN acima mascarou o
diagnóstico por um tempo).

Recriado como `/etc/cron.d/plantonistas-presence` derivando do arquivo antigo
por `sed` de `module-zbx-repasse-plantao` → `module-zbx-plantonistas`, o que
preserva credenciais sem redigitar e mantém o truque do `; sleep 150; php ...`
(roda a cada 2,5 min em vez de 5). As env `DB_*` no arquivo de cron são
**obrigatórias**: em CLI não existe `$GLOBALS['DB']`, e sem elas o script cai
no default `localhost`. Tracker deve rodar em **um** frontend só (grava no
banco compartilhado; nos dois duplica escrita).

Na época, `ZABBIX_URL`/`ZABBIX_API_TOKEN` também eram obrigatórias — a de
produção apontava pro endpoint `nessus.claroempresas.com.br:22443/services/
zabbix/api/v1/api_jsonrpc.php`, não pra URL do frontend. As duas deixaram de
existir na v5 (seção abaixo); podem sair do `/etc/cron.d/` e o token pode ser
revogado no Zabbix.

### Cron de presença sem API do Zabbix (2026-08-19, issue #4)

O script autenticava por `Authorization: Bearer <token>` com
`CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST` em `false` — credencial viajando por
canal não verificado. E a API **nunca foi necessária**: a presença de verdade
sempre veio de leitura direta da tabela `sessions`; a API só buscava
`username`/`name`/`surname`/`usrgrps`, que estão em `users`/`usrgrp`/
`users_groups`, no mesmo banco em que o script já estava conectado.

Agora são três consultas locais, e a ordem foi **invertida de propósito**:
primeiro as sessões ativas, depois os dados só dos userids encontrados. Antes
puxava a base inteira de usuários da API para indexar em memória e descartar
quase tudo.

- **Usuário habilitado** usa a mesma cláusula do resto do módulo (nenhum grupo
  com `users_status=1` + `roleid` preenchido), não o `filter gui_access` que a
  chamada de API usava. Sessão de usuário desabilitado é ignorada, como antes.
- **`noc_context` virou query própria**, não JOIN — regra do módulo: se o dado
  acessório falhar, a presença continua sendo gravada (WARN no log, contexto
  nulo). O descarte de grupo de sistema passou a ser por flag
  (`users_status`/`gui_access`), que funciona em instalação traduzida; a lista
  de nomes em inglês ficou só como reserva para o caso do grupo `Guests`.
- Falha ao gravar **uma** linha não derruba mais a execução inteira (`continue`
  com WARN), mesma decisão já tomada para menção inválida no Diário de Bordo.

**O `CREATE TABLE IF NOT EXISTS` do cron foi removido.** Ele divergia do DDL
oficial (`id INT` contra `BIGINT UNSIGNED`, `name VARCHAR(255)` contra `128`,
sem a coluna `ip`, índices `idx_userid`/`idx_lastaccess` contra `idx_cus_*`).
Onde o cron rodou antes do primeiro request com o módulo habilitado,
`existingTables()` via a tabela como pronta e **nunca a corrigia** — e o
`ALTER ... ADD COLUMN noc_context ... AFTER ip` do `migrateColumns()` falhava,
porque `ip` não existia. Provisionamento agora vive só no `Module.php`; sem a
tabela, o cron encerra dizendo para habilitar o módulo uma vez.

`migrateColumns()` conserta o que o cron antigo criou: adiciona `ip` **antes**
do bloco de `noc_context`, promove `id INT` → `BIGINT UNSIGNED`, `userid` para
unsigned, e cria os índices `idx_cus_*` que faltam. Duas decisões conscientes:
os índices antigos do cron **não** são dropados (redundantes, mas dropar índice
em tabela grande trava escrita) e `name VARCHAR(255)` **não** é reduzido para
128 (encurtar coluna trunca dado em silêncio; 255 acomoda 128).

Para detectar `id INT` foi preciso trazer `COLUMN_TYPE` no SELECT de
`INFORMATION_SCHEMA.COLUMNS` — `CHARACTER_MAXIMUM_LENGTH` é NULL em coluna
numérica e não distingue INT de BIGINT.

**Três defeitos antigos corrigidos na mesma passada** (achados na revisão):

- **Fuso do PHP × fuso do banco.** `session_start`/`lastaccess` sempre foram
  gerados por `date()` em `America/Sao_Paulo`, mas o `DATE(session_start) =
  CURDATE()` e o `DATE_SUB(NOW(), INTERVAL 7 DAY)` eram avaliados no fuso do
  MariaDB, que roda em outro host. Com o banco em UTC, das 21h em diante o
  `CURDATE()` já é amanhã: a linha gravada às 21h05 nunca casava e o script
  inseria uma sessão nova **a cada ciclo**, duplicando presença justamente no
  turno da noite. Agora os dois valores são calculados em PHP e vão como
  parâmetro. É o mesmo bug de fuso do heatmap do Repasse, em outro lugar —
  vale procurar por `CURDATE()`/`NOW()` antes de assumir que não há mais.
- **Nome em branco para conta de serviço.** `buildFullName()` devolvia `''`
  quando `name` e `surname` estavam os dois vazios, e a view imprime o campo
  cru. Ganhou o `$username` como reserva, igual ao `formatUserLabel()` do
  trait (o comentário já dizia que espelhava; agora espelha de verdade).
- **Nome longo descartava a linha.** `users.name` e `users.surname` são
  VARCHAR(100) cada; o concatenado chega a 201, contra o VARCHAR(128) da
  tabela de presença. Com `sql_mode` STRICT o INSERT lançava "Data too long"
  e aquele analista simplesmente não era registrado. Agora trunca em 128, como
  o `noc_context` já fazia em 50.

### Nome duplicado no rastreador de presença (2026-08-17)

Analistas apareciam como "Rafael Rafael Leao Ereno" / "Erica Erica Felix de
Oliveira": vários cadastros do Zabbix têm o nome completo no campo `surname`
e só o primeiro nome em `name`, e o cron concatenava os dois às cegas.
Novo `buildFullName()` em `cron_presence_tracker.php` devolve só o campo mais
completo quando um já contém o outro **nas bordas** — contenção no meio não
conta ("Ana" + "Mariana Costa" continua concatenando; exigir o espaço como
limite de palavra é o que evita esse falso positivo). Cobre também o caso
inverso (`name` já com o sobrenome) e comparação sem distinguir maiúscula.

O `UPDATE` da sessão existente passou a incluir `name` no SET (antes o nome
só era gravado no INSERT) — sem isso a sessão aberta hoje carregaria o nome
duplicado até a limpeza de 7 dias. Efeito colateral bom: renomeação no
cadastro do Zabbix agora se reflete na próxima execução do cron.

Correção é só de gravação: **nada reescreve as linhas já no banco**, elas se
corrigem sozinhas na próxima passada do cron (UPDATE) ou expiram em 7 dias.

### Dropdown de menções: itens em branco, corte e polimento (2026-08-17)

Três problemas na lista que abre ao digitar `@` no Diário de Bordo:

**1. Itens em branco no topo.** O label vinha de
`CONCAT(name,' ',surname)` cru — conta que só tem `username` (serviço,
integração) produzia label `' '`, e o `ORDER BY label` jogava justamente
esses para o começo da lista. Agora o SQL devolve `username`/`name`/`surname`
separados, o label é montado por `formatUserLabel()` (novo helper no trait,
mesma regra de bordas do `buildFullName()` do cron) com o **username como
reserva**, e o `ORDER BY` usa o nome efetivo. A duplicação da lógica entre
trait e cron é proposital: o cron roda em CLI sem carregar o módulo.
`formatUserLabel()` também passou a ser usado em `current_fullname`
(`TurnosReportView`, aparece no "Analista:" e no rodapé), no
`TurnosReportPdf` e no `TurnosNotesSave` — este último é o mais importante
porque `analyst_name` é **persistido**: ali o nome duplicado ficaria gravado
para sempre, sem cron que corrija depois.

**2. Lista cortada, "sem barra de rolagem".** O dropdown era
`position: absolute` dentro de `.rp-editor-wrap`, e `.rp-card` tem
`overflow: hidden` (necessário pro border-radius das tabelas) — tudo que
passava da borda do card era recortado, inclusive a scrollbar, dando a
impressão de que a lista tinha só os primeiros itens. Virou
`position: fixed` com coordenadas de viewport calculadas no JS, que **não**
sofre clipping de ancestral. Consequências que o fixed traz e estão
tratadas: `positionDropdown()` é chamado em `scroll` (com `capture: true`,
pra pegar scroll de container interno do Zabbix, não só o da janela) e em
`resize`, senão a lista ficaria parada enquanto o texto rola; inverte pra
cima (`.rp-flip-up`) quando não cabe embaixo; e prende nas bordas laterais.

**3. Polimento visual/UX.** Ícone por tipo (`fa-user`/`fa-server`/
`fa-layer-group`), cabeçalho sticky com o tipo e a contagem, username como
segunda linha do item (desambigua homônimo), animação de entrada de 0.14s
com `prefers-reduced-motion` respeitado, scrollbar fina, e navegação por
teclado: ↑/↓ circulares, Enter/Tab inserem, Esc fecha, item ativo espelhando
o hover (`.rp-mention-active`) com `scrollIntoView({block:'nearest'})`.
Enter sem item destacado continua fazendo quebra de linha no editor.

### Regra de usuário desabilitado estava invertida (2026-08-17)

Pesquisa na doc do Zabbix 7.0 + código do frontend, motivada pelo pedido de
não listar usuário desativado na busca de menção. O que se descobriu:

**O Zabbix resolve o status com `MAX(usrgrp.users_status)`** — pertencer a
**qualquer** grupo desabilitado já desabilita a pessoa
(`CUser::getAccess()` faz `MAX(g.users_status)`; `addUserGroupFields()` barra
o login se qualquer grupo vier desabilitado; a coluna Status da lista de
usuários mostra "Disabled"). A doc não é explícita sobre grupo misto ("Status
… depending on the one set for the whole user group"), o código é.

O módulo fazia o **oposto**: `EXISTS (grupo com users_status=0)`, isto é,
"tem pelo menos 1 grupo ativo → aparece". Consequência: analista em grupo
misto (membro de "NOC" **e** de um grupo tipo "Desligados") continuava
aparecendo e sendo escalável no módulo enquanto o Zabbix já o mostrava como
Disabled. Corrigido nos 5 pontos por decisão do Rafael (a alternativa de
corrigir só a menção foi oferecida e recusada):
`TurnosReportBase::enabledUserClause()` novo, usado pela busca de menção e
por `listUsersByGroup()`, e a mesma cláusula escrita à mão em `PlantaoList`
(seletor de técnico), `PhonesList` e `PhonesExport`.

A cláusula também exige `roleid` preenchido — a lista do Zabbix mostra
"Disabled" para usuário sem role. Usuário **sem nenhum grupo** conta como
habilitado, igual ao Zabbix (que inicializa `users_status = 0` e só mostra um
ícone de aviso "User does not have user groups").

**Bloqueado por tentativas de login** (`notBlockedUserClause()`, só na busca
de menção): `attempt_failed >= config.login_attempts`, mesmo critério da
coluna "Login: Blocked" da lista de usuários — que não olha tempo nenhum.
Vale saber a assimetria: o bloqueio *efetivo* de login expira sozinho depois
de `config.login_block` (default 30s), mas `attempt_failed` só zera quando a
pessoa loga ou um Super Admin usa Unblock. Então alguém pode aparecer
"Blocked" no Zabbix (e sumir da busca de menção) já podendo logar. É o
comportamento que casa com o que se vê na tela do Zabbix, e foi a escolha
consciente. `config.login_block` é string com sufixo (`30s`, `5m`) e
precisaria de conversão em PHP — motivo extra pra não usar a janela de tempo.

### Busca de menção por nome completo (2026-08-17)

O `@` só achava pelo campo isolado (`username`/`name`/`surname`), então
buscar "Rafael Leao" não achava `name='Rafael'` + `surname='Leao Ereno'` —
nome que atravessa os dois campos nunca casa num LIKE campo por campo. E o
regex do gatilho parava no primeiro espaço, ou seja: era **impossível**
digitar nome completo. Ninguém procura colega por login (`z148534`), procura
por "Rafael Leão Ereno".

Agora a query quebra o texto digitado em **termos** e exige que cada termo
apareça em algum de `username`, `name`, `surname` ou no nome completo
concatenado. Sendo condições AND independentes, a ordem digitada não importa
("ereno rafael" acha) e dá pra misturar nome + login. Teto de 5 termos.

O gatilho no JS aceita até 5 palavras (era 1). Escolher um teto baixo é pior
que um alto: a lista fecharia na cara de quem digitou o nome inteiro
("Marcos de Queiroz Pastrolin Junior" tem 5 palavras). O que evita o dropdown
perseguir uma frase é outra coisa: busca **com espaço** e zero resultado
fecha a lista, sinal de que virou texto comum. Sem espaço, mantém "Nenhum
resultado" — ali ainda é uma busca em andamento.

### 2ª auditoria de datas/horas pt-BR (2026-08-17)

Revarredura do módulo inteiro (`date()`/`DATE_FORMAT`/`->format()` no PHP,
`Date`/`toISOString`/`toLocale` no JS, e todo `<?= ... ?>` de campo de data
nas views). A auditoria de 2026-08-14 tinha coberto o que vinha do banco; o
que faltava era a data que **transita pela URL** e era ecoada crua na tela:

- Cabeçalho do Repasse (`rp-nh-sub`) e o "**Data:**" do formulário do Diário
  de Bordo imprimiam `$date` direto — `2026-08-17` na cara do usuário.
- `TurnosReportPdf`: mesmo gap no subtítulo e no `<title>`.

Helpers novos, um por camada (a view não usa o trait): `rp_dateBr()` em
`plantonistas.report.view.php` e `TurnosReportBase::formatDateBr()`.

No `<title>` do PDF a data vai com **hífen** (`17-08-2026`), não barra: o
`<title>` é o nome de arquivo que o navegador sugere ao salvar/imprimir em
PDF, e barra não é caractere válido em nome de arquivo. Continua dia-mês-ano.

**Bug de fuso achado de brinde (heatmap do Repasse)**: a chave dos 30 dias
saía de `d.toISOString().slice(0,10)`, que converte pra **UTC** — em UTC-3,
das 21h em diante devolvia a data de amanhã, e o calendário inteiro
(contagem, célula de "hoje", link do relatório) deslizava um dia. Ou seja:
quebrava justamente durante o turno da noite. Agora a chave é montada com
`getFullYear/getMonth/getDate` (componentes locais). Verificado com TZ
America/Sao_Paulo às 9h/18h/20h/21h/22h/23h.

**Y-m-d que é protocolo e NÃO deve ser "corrigido"** (comentado no código pra
não cair na próxima auditoria): `value` do `input type="date"` (a spec do HTML
exige ISO — mudar quebra o filtro), `from`/`to` da URL do `problem.view`
nativo, `date=` dos links do módulo, a const JS `NOTE_DATE` (volta pro
backend no save da nota), as chaves internas (`$today_str`, chave do heatmap)
e o timestamp dos logs do cron.

Conferido e já correto: Histórico (`d/m/Y` e `d/m/Y H:i`), Visão Geral
(`d/m/Y` + dia da semana em pt-BR), Escala (nomes de mês em pt-BR), Gerenciar
Turnos (`HH:MM` 24h), notas e presença (`DATE_FORMAT` pt-BR com coluna
auxiliar de ordenação), e o CSV da Escala — que exporta `d/m/Y` com `;` e BOM
UTF-8 (Excel pt-BR) e cujo import aceita `d/m/Y`, `d-m-Y`, `Y-m-d` e serial
do Excel, com `d/m/Y` testado ANTES de qualquer outro formato (nunca
interpreta 03/04 como 4 de março).

### "Não é possível atualizar a função do usuário" ao habilitar o módulo (2026-08-17)

Salvar um papel (Usuários → Papéis de utilizador) com o módulo marcado
falhava com `Duplicate entry '4182' for key 'role_rule.PRIMARY'` em
`CRole::updateRules() → DB::insertBatch()`.

**Não é bug do módulo** — o Plantonistas não escreve em `role_rule` (só o
README tem um DELETE opcional de regras órfãs). É a tabela `ids` do Zabbix
fora de sincronia: `role_rule.role_ruleid` **não** é auto-increment, o
Zabbix reserva IDs via `DB::reserveIds()`, que lê `ids.nextid` e usa
`nextid + 1` em diante. Se alguém insere linhas em `role_rule` por SQL
direto (o velho `MAX(id)+1`) sem atualizar `ids`, o contador fica atrasado e
todo INSERT novo colide.

Números reais de produção em 2026-08-17: `ids.nextid = 4181` contra
`MAX(role_ruleid) = 4418` — 237 IDs de atraso. As linhas 4182–4194 eram do
módulo **`fcorr`** (`fcorr.list`, `fcorr.save`, … nos roleid 3 e 8),
inseridas na mão. O Plantonistas só foi o próximo a tentar usar a faixa.

Diagnóstico e correção (no banco `172.18.190.21`, não nos frontends — `ids`
é compartilhada):

```sql
SELECT MAX(role_ruleid) FROM role_rule;
SELECT * FROM ids WHERE table_name = 'role_rule';

UPDATE ids SET nextid = (SELECT MAX(role_ruleid) FROM role_rule)
 WHERE table_name = 'role_rule' AND field_name = 'role_ruleid';
```

`nextid` guarda o **último** ID usado, então igualar ao `MAX` é o valor
certo. Se a linha em `ids` não existir, o Zabbix a recria a partir do
`MAX` — aí a colisão teria outra origem.

Auditoria das outras tabelas (o hábito de inserir `role_rule` na mão
provavelmente atingiu mais coisa). São ~124 linhas em `ids`, então roda-se tudo
de uma vez com prepared statement, direto no cliente `mysql`:

```sql
SET SESSION group_concat_max_len = 1000000;

SET @sql = (SELECT CONCAT(
  'SELECT * FROM (',
  GROUP_CONCAT(CONCAT('SELECT ''',i.table_name,''' AS tbl,',i.nextid,
                      ' AS nextid,MAX(',i.field_name,') AS max_real FROM ',i.table_name)
               SEPARATOR ' UNION ALL '),
  ') t WHERE max_real > nextid ORDER BY max_real - nextid DESC'
) FROM ids i
  JOIN information_schema.columns c
    ON c.table_schema = DATABASE()
   AND c.table_name  = i.table_name
   AND c.column_name = i.field_name);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
```

**O JOIN com `information_schema` não é enfeite**: a tabela `ids` de produção
carrega linhas órfãs de versões antigas do Zabbix — `applications`,
`application_discovery`, `application_prototype`, `application_template`,
`items_applications`, `item_application_prototype`, `screens`,
`screens_items`, `screen_user`, `screen_usrgrp`, `valuemaps` (applications e
screens saíram do schema no 5.4). Sem o filtro, o `PREPARE` morre inteiro com
`Table 'zabbix.application_discovery' doesn't exist`. Essas linhas são
inofensivas (nenhum código do 7.0 as consulta) — resíduo de upgrade. Listá-las:
mesmo JOIN, com `LEFT JOIN ... WHERE c.table_name IS NULL`.

Só retorna tabela com o contador atrasado; o conserto é o mesmo `UPDATE ids`
trocando `table_name`/`field_name`. Ler o resultado com duas ressalvas:
`nextid` **maior** que o `MAX` é normal (ID reservado e não usado, ou
housekeeper que apagou linhas) e nem aparece nessa listagem; e se aparecer
tabela grande do server (`items`, `hosts`, `triggers`, `events`), tratar em
janela — ali quem reserva ID também é o Zabbix server, não só o frontend.

Lição pra qualquer módulo futuro: liberar action de módulo por SQL direto em
`role_rule` **exige** acertar `ids` na mesma transação. Preferir marcar o
módulo pela UI (Papéis de utilizador), que é o caminho que usa
`reserveIds()` corretamente.

### Máscara de telefone e importação em massa (2026-08-17)

`module_plantonistas_phones.phone` guarda **só dígitos** (quem salva já roda
`preg_replace('/\D/','')`), então máscara é assunto de exibição. Novo trait
`PhonesFormat` (`phoneDigits()` + `formatPhoneBr()`) é o único lugar que sabe
formatar, usado por `PhonesList`, `PhonesExport` e `PhonesImport`:

| dígitos | saída | caso |
|---|---|---|
| 11 iniciando com 0 | `0800 777-1234` | não geográfico |
| 11 | `(11) 98765-4321` | celular com DDD |
| 10 | `(11) 3456-7890` | fixo com DDD |
| 9 / 8 | `98765-4321` / `3456-7890` | sem DDD |
| resto | cru | ramal, +55 colado, cadastro errado |

O ramo do `0` existe porque **DDD brasileiro vai de 11 a 99, nunca começa com
0** — sem ele, `08007771234` sairia como `(08) 00777-1234`. Fora dos padrões
conhecidos devolve cru de propósito: parênteses em número sem DDD produz um
telefone com cara de certo e conteúdo errado.

A view exibe `phone_fmt` (montado no controller, view sem regra de negócio).
O `phnMask()` do JS espelha o PHP linha a linha — se as duas regras
divergirem, a linha muda de formato ao recarregar a página.

**Dois defeitos corrigidos no caminho**, ambos no `phnMask()` que já existia:

1. Ele rodava no `oninput` e assumia DDD sempre, então quem digitasse ramal de
   5 dígitos via o número virar `(12) 345` no meio da digitação. Agora roda no
   `onblur` — dá pra digitar em paz.
2. Ele fazia `substring(0, 11)` nos dígitos. Como o `value` do input é o que
   vai no submit, um número com `+55` colado (13 dígitos) seria **gravado
   mutilado**. O truncamento saiu; fora dos padrões o campo devolve os dígitos
   inteiros, igual ao PHP.

**Importação em massa** (`plantonistas.phones.import` → `PhonesImport`): lê o
MESMO CSV que a exportação gera (`Usuario;Nome;Telefone`), para o fluxo ser
exportar → editar no Excel → reimportar. Detalhes decididos com o Rafael:

- Casa por `users.username` (único, não muda com correção de cadastro); a
  coluna Nome é ignorada — existe só para quem preenche a planilha se situar.
- Cabeçalho detectado por sinônimos (`usuario/username/login/user` e
  `telefone/celular/fone/phone/contato/ramal`), com e sem acento, e separador
  detectado entre `;`, TAB e `,`. Aceita planilha de 2 colunas.
- **Telefone vazio NÃO apaga** o cadastro: o CSV traz todos os usuários, a
  maioria sem telefone, e um round-trip parcial limparia a base. Conta as
  linhas ignoradas no resultado. Para remover, limpa-se o campo na tela.
- Permissão: a mesma regra da edição individual (Super Admin altera qualquer
  um; os demais só quem compartilha pelo menos um grupo). Linha fora do
  alcance é recusada e reportada, nunca aplicada em silêncio.
- `INSERT ... ON DUPLICATE KEY UPDATE` (userid é PK) — nasceu sem a janela de
  corrida do SELECT-then-INSERT que o `PhonesSave` ainda tem (ver Backlog).
- Limites: 4 a 15 dígitos. O piso de 4 aceita ramal curto; o teto barra lixo.

**A exportação passou a gravar o telefone com máscara** por causa do Excel:
telefone só-dígitos é lido como número, o que come zero à esquerda e vira
notação científica em campo longo. A importação remove a máscara de volta. E
se ainda assim chegar `9,87654E+10`, a linha é **recusada com aviso** em vez de
limpar os não-dígitos — isso produziria um número plausível e errado, que
ninguém notaria depois.

Upload via base64 em campo hidden, mesmo padrão do import da Escala (a rota do
Zabbix nessa action não trata multipart). Como as outras actions de redirect do
módulo, `phones.import` não tem `"view"` no manifest — responde redirect.

### CSRF ligado nas actions de escrita (2026-08-19, issue #3)

Todas as 21 actions chamavam `disableCsrfValidation()`. Nas 10 que escrevem no
banco isso deixava qualquer página aberta por um Admin logado disparar
`plantonistas.delete` ou apagar um turno sem clique consciente.

**A regra que decide tudo**: `CController::checkCsrfToken()` começa com
`if (!isRequestMethod('post')) return false;` — em GET a validação não é
dispensada, ela **falha**. Então `disableCsrfValidation()` continua obrigatório
em toda action alcançável por GET (as 11 de leitura, incluindo os exports em
CSV e o PDF); tirar de lá derruba a tela na hora.

**Em módulo o token é por action COMPLETA.** O `checkCsrfToken()` tem um ramo
só para classes em `Modules\`: confere contra `$this->action` inteiro. O core
agrupa pelo primeiro segmento (o token `host` serve para `host.edit`,
`host.delete`…) — aqui não: `plantonistas.save` e `plantonistas.delete` têm
tokens diferentes. Cada view recebe um token por action que ela chama.

Onde o token entra, por tela:

| Tela | Como |
|---|---|
| Escala | hidden nos forms de salvar e de remover; `PLT_CSRF_IMPORT` no form de import montado em JS |
| Telefones | hidden no form de salvar; `PHN_CSRF_IMPORT` no form de import |
| Gerenciar Turnos | mapa `CSRF_TOKENS[action]` dentro do helper `post()` — um ponto só para as 3 actions |
| Repasse | `CSRF_NOTES_SAVE` no fetch da nota; `CSRF_MENTIONS_READ` no fetch e no `sendBeacon` (que envia `URLSearchParams` como form-urlencoded, então o token chega no `$_POST` igual) |

**"Remover" da Escala deixou de ser link GET.** Era
`<a href="zabbix.php?action=plantonistas.delete&scheduleid=N">` — com CSRF
ligado nunca passaria, e um link GET que apaga dado é exatamente o vetor da
issue. Virou submit de um form oculto (`plt-del-form`), com o id preenchido por
`pltDelete()`. Consequência aceita: URL de remoção salva em favorito passa a
responder "Acesso negado".

Dois defeitos corrigidos junto, nesse mesmo botão:

- **Nome com apóstrofo quebrava o `onclick`.** O rótulo era interpolado como
  `addslashes(htmlspecialchars($label))` dentro do atributo. Do PHP 8.1 em
  diante `htmlspecialchars()` usa `ENT_QUOTES` por padrão, então a aspa já
  virou `&#039;` e o `addslashes()` não acha mais nada para escapar — o
  navegador decodifica a entidade ao parsear o atributo e o JS recebe
  `pltDelete(1,'Sant'Ana')`, sintaxe inválida. O handler era descartado
  inteiro: o clique não removia, não fazia `stopPropagation` e ainda
  selecionava o dia. Agora o nome vai em `data-label` e o clique é tratado por
  um listener **na fase de captura** — precisa ser captura porque o `<td>` tem
  `onclick` inline, que roda no bubbling e selecionaria o dia antes.
- Duplo clique disparava dois POSTs, e o segundo respondia "Entrada não
  encontrada" logo depois de uma remoção bem-sucedida. Guarda `pltDeleting`.

**Modo de falha novo, que vale conhecer antes de debugar**: token inválido
lança `CAccessDeniedException` e o Zabbix responde a página **HTML** "Acesso
negado" — inclusive para action AJAX com `layout.javascript`, que cai no
`default` do `ZBase::denyPageAccess()`. Sem tratamento, o `r.json()` do fetch
estoura erro de parse e o usuário vê "erro de conexão". Por isso o `post()` de
Turnos e o fetch da nota checam `content-type` antes e devolvem uma mensagem
dizendo para recarregar a página. **Nada disso vai para log nenhum** — não há
`error_log` nesse caminho do Zabbix. E a causa mais provável no dia a dia não é
ataque: é a aba deixada aberta o turno inteiro, com a sessão expirada, porque o
token deriva do `secret` da sessão.

Os forms de POST nativo (salvar escala, salvar telefone, os dois imports) não
têm como exibir mensagem amigável — caem na página "Acesso negado" do Zabbix e
o conteúdo digitado se perde. Aceito por ora; a alternativa seria converter
esses saves em AJAX.

### Fim da conexão mysqli própria — ZbxDb (2026-08-19, issue #1 passo 1)

A família repasse abria uma conexão **mysqli própria a cada request**
(`getDb()`, lendo credencial de `$GLOBALS['DB']`), paralela à do Zabbix. Isso
custava uma conexão por request e deixava metade do módulo fora da única
camada do Zabbix que é agnóstica de banco — o que trava o PostgreSQL.

**Escolha de rota**: em vez de reescrever as ~50 consultas à mão (50 chances de
esquecer um `zbx_dbstr()` e criar SQL injection), entrou um adaptador fino em
`actions/ZbxDb.php` + `ZbxDbStmt.php` + `ZbxDbResult.php`. Ele implementa a
fatia da API do mysqli que o módulo usa — `prepare`/`query`/`close`/`error`/
`insert_id`, `bind_param`/`execute`/`get_result`, `fetch_all`/`fetch_assoc`/
`fetch_row` — por cima de `\DBselect`/`\DBexecute`/`\DBfetch`. Nenhuma consulta
foi reescrita; só o `getDb()` e os type hints `\mysqli $db` → `ZbxDb $db`.

O escape ficou num lugar só (`ZbxDbStmt::escape()`), com o valor entrando pelo
tipo declarado no `bind_param` — `i` vira cast int, `s` vai por `\zbx_dbstr()`,
`null` vira `NULL` literal. O parser de `?` respeita literal entre aspas
(inclusive `''` e escape com barra), porque `str_replace` trocaria o caractere
errado numa consulta futura que tivesse `?` dentro de string.

Autoload confirmado no fonte do Zabbix 7.0: `CAutoloader` resolve
`Modules\Plantonistas\Actions\ZbxDb` para `actions/ZbxDb.php` na pasta do
módulo. Classe auxiliar **não** precisa estar no manifest — é o mesmo caminho
que já carrega os traits `TurnosReportBase` e `PhonesFormat`.

**Três diferenças de comportamento que o adaptador teve que compensar** — e que
valem para qualquer código novo aqui:

- **`\DBselect`/`\DBexecute` não lançam**, devolvem `false`. Todo o módulo foi
  escrito contra `MYSQLI_REPORT_STRICT`, com try/catch em volta das consultas.
  Por isso o adaptador **lança `\RuntimeException`** quando a camada devolve
  false: sem isso, consulta quebrada voltaria a virar array vazio em silêncio,
  que é o defeito que já custou o diagnóstico de "grupos não listam usuários".
- **`\DBfetch()` converte NULL na string `'0'`** por padrão (`$convertNulls`).
  As três leituras passam `false`. Nenhum ponto quebraria hoje, mas o primeiro
  `=== null` ou `?? 'text'` escrito depois quebraria sem avisar.
- **`prepare()` não valida mais a consulta no servidor** — só monta a string. O
  mysqli validava e lançava ali; agora o erro nasce no `execute()`. Isso já
  tinha deixado o fallback de schema do `TurnosNotesSave` inalcançável (o
  `execute()` estava fora do try) — corrigido movendo o `execute()` para dentro
  de cada ramo. Regra: `prepare`, `bind_param` e `execute` ficam **juntos** no
  mesmo try.

**Erro de consulta ia virar banner vermelho na tela, com o SQL inteiro.** O
`trigger_error` do `DBselect` é capturado pelo `zbx_err_handler` do frontend e
vira mensagem na página. `ZbxDb::silenced()` troca o handler durante a chamada
e restaura depois — o erro continua chegando como exceção e como
`error_log('[plantonistas] …')`, mas não polui a tela do dado principal nem
expõe SQL na UI.

**Ganho colateral**: o `getDb()` antigo chamava
`mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)`, configuração
**global do processo**, que passava a valer também para a conexão nativa do
Zabbix no resto do request. Isso acabou.

O que **não** mudou: o SQL continua MySQL-only (`DATE_FORMAT`,
`FROM_UNIXTIME`, `IF()`, `TIMESTAMPDIFF`, `INTERVAL`, `ON DUPLICATE KEY
UPDATE`, e o `LAST_INSERT_ID()` do `insert_id`). O adaptador tira o bloqueio da
conexão paralela, não o do dialeto — esse é o passo 3 da issue #1.

### Escala alimenta o escalonamento do Zabbix (2026-08-19, issue #5)

`scripts/cron_sync_oncall.php` resolve o turno corrente de cada equipe, acha o
titular na escala e sincroniza um grupo de usuários do Zabbix com essa única
pessoa. A partir daí a **Ação nativa** escala para o grupo — o módulo não
reimplementa media type, mensagem nem escalonamento. É o que tira a Escala da
condição de tela de consulta.

Decisões do Rafael, todas com a alternativa oferecida e recusada:

- **Um grupo por equipe** (`Plantonista de Hoje - NOC`), não um grupo único:
  cada equipe cuida de hosts diferentes e precisa de Ação própria.
- **Dia sem cobertura mantém quem está no grupo.** Esvaziar deixaria alerta
  sem destinatário de madrugada; fallback exigiria manter mais um grupo. O
  preço é a pessoa receber alerta fora do turno dela — por isso sai `INFO:` no
  log toda vez que isso acontece.
- **5 minutos.** A virada de turno leva até um ciclo para refletir.

Decisões técnicas que não foram perguntadas:

- **Só o titular entra no grupo.** Reserva não está de plantão, está
  disponível.
- **O script não cria grupo de usuário.** Criar exigiria reservar `usrgrpid`
  na tabela `ids`, e o incidente do `role_rule`/`fcorr` (seção acima) mostra o
  estrago de escrever ID na mão em tabela do core. Grupo ausente → WARN e a
  equipe é pulada.
- **`users_groups.id` NÃO é auto-increment** — `reserveIds()` replica o
  `DB::reserveIds()` do Zabbix (SELECT ... FOR UPDATE em `ids`, UPDATE do
  contador, transação). `MAX(id)+1` aqui repetiria exatamente a colisão que
  impediu de salvar papel de usuário em produção.
- **Turno que vira o dia pertence à data em que começou**: às 02h, quem está
  de plantão é o escalado de *ontem*. `currentShiftDate()` espelha o
  `computeCustomShiftBounds()` do trait — duplicação proposital, o cron roda em
  CLI sem carregar o módulo. Errar isso escala a pessoa errada exatamente na
  madrugada.
- **Turnos que não cobrem 24h deixam buraco** (ex.: só diurno cadastrado). No
  intervalo descoberto não há turno corrente e o grupo fica como está — mexer
  seria chute.
- **INSERT antes do DELETE**, de propósito. Na virada de turno o caminho é
  sempre "sai o antigo, entra o novo"; com o DELETE primeiro o grupo fica sem
  ninguém entre as duas escritas — e, se o INSERT falhasse, ficaria vazio até
  o ciclo seguinte: 5 minutos de Ação sem destinatário, exatamente o que a
  decisão de "não esvaziar" existe para evitar. Invertido, o pior caso é o
  grupo ficar com duas pessoas por um ciclo. Não há transação em volta porque
  `reserveIds()` abre a sua própria, e em MySQL `START TRANSACTION` comita
  implicitamente a que estiver aberta — envolver tudo numa transação externa
  comitaria o DELETE em silêncio.
- **Grupo de destino com Status = Desabilitado é recusado.** O status de um
  usuário no Zabbix é `MAX(usrgrp.users_status)` sobre todos os grupos dele:
  pôr o plantonista num grupo desabilitado desabilitaria a conta — ele pararia
  de logar, sumiria das telas do próprio módulo (`enabledUserClause()`) e
  deixaria de ser notificado, que é o oposto do objetivo. Erro plausível, já
  que é um grupo "técnico" que ninguém pretende usar para login, e o sintoma
  apareceria longe da causa.
- **Falha ao ler os turnos pula a equipe, não cai no modo legado.** Turno não é
  dado acessório: é o que decide quem está de plantão. Com `$shifts = []` o
  script sincronizaria a entrada `shift_id = 0` — que existe mesmo em grupo com
  turnos, porque o import CSV grava sempre em modo legado — e escalaria a
  pessoa errada, com um `OK:` no log dizendo que deu certo.
- **Plantonista é validado antes** (existe + habilitado): `schedule.userid` não
  tem FK para `users`, mas `users_groups.userid` tem.
- Guarda contra o grupo de destino ser o próprio grupo da equipe (prefixo mal
  configurado esvaziaria a equipe inteira) e aviso quando dois turnos se
  sobrepõem — sem ele, um plantonista seria ignorado todo dia sem rastro.
- `DRY_RUN=1` mostra o que faria sem gravar. Falha numa equipe não impede as
  outras, mas o script sai com código 1 se houve erro — dá o que monitorar.

### Fechar turno — o repasse vira documento (2026-08-19, issue #2)

`module_plantonistas_shift_reports` era tabela morta: criada, migrada,
documentada e com **zero leitura e zero escrita**. Agora o botão "Fechar turno"
no Repasse grava um snapshot JSON do relatório nela, e o PDF renderiza esse
snapshot (`plantonistas.report.pdf&report_id=N`) — sem tela nova, reaproveitando
a navegação por data/turno que já existe.

**A decisão que rege tudo (Rafael): o fechamento é DOCUMENTO SEPARADO, não
cache da tela.** A tela continua consultando ao vivo para todos. O motivo é de
segurança, não de gosto: o payload do repasse varia por usuário — eventos e Top
Hosts pelo `host_filter` derivado de `rights`, MTTA restrito ao próprio usuário
quando role type = 1, Notas e Presença por grupo compartilhado. Usar o snapshot
como cache serviria a um usuário o que outro enxergava. Das três saídas
avaliadas (snapshot por contexto, documento separado, snapshot só para Admin+),
o documento é a única que não abre mão de nenhuma regra — o preço é abrir mão
do ganho de performance, que era um dos três motivos da issue.

**Quem lê o documento** (`canReadSnapshot()`): Super Admin lê tudo; fechamento
feito por Super Admin só Super Admin lê (o snapshot dele é sem filtro nenhum);
nos demais casos os grupos do autor têm que **caber** nos do leitor. Compartilhar
UM grupo não basta — autor em `[NOC, Redes]` e leitor só em `[NOC]` passaria, e
o leitor veria as notas dos analistas de Redes. Com os mesmos grupos, os
`rights` de host também coincidem, e é isso que sustenta a equivalência.

**MTTA é a exceção que nenhum filtro de grupo cobre**, e quase passou batido: a
restrição é por **papel**, não por grupo. Um Admin do NOC fecha o turno (MTTA de
todos gravado), um User do NOC abre o documento, passa no teste de grupo — e
veria a tabela inteira. Por isso `TurnosReportPdf` **reaplica**
`restrictMttaByRole()` com o papel do LEITOR e recalcula o MTTA global sobre o
resultado; usar o `global_mtta` do snapshot devolveria o agregado de todos no
KPI.

Outras decisões:

- **Append-only**: fechar de novo cria outra linha, nunca sobrescreve. O
  histórico fica imutável por construção e "refazer" é rastreável sem coluna de
  auditoria. Refechar exige Admin+; `countClosedReports()` roda **sem** filtro
  de visibilidade de propósito — com filtro, um User refecharia só porque não
  enxerga o fechamento anterior.
- `generated_at` é gerado em **PHP**, não com `NOW()`: o banco roda em outro
  host e pode estar em UTC, e num documento cuja razão de ser é o carimbo de
  hora, "congelado às 21:05" para um fechamento das 18:05 é defeito grave.
- A mensagem de erro do fechamento é **fixa**: o `RuntimeException` do `ZbxDb`
  carrega o SQL inteiro, com o JSON do snapshot dentro, e SQL não vai para a UI.
- `migrateColumns()` promove `report_json` para `LONGTEXT` se o schema antigo
  tiver deixado `TEXT`. A tabela nunca teve uma linha escrita até agora, então
  as colunas dela nunca foram exercitadas em produção — com `TEXT` (64 KB) e
  sem `sql_mode` STRICT o snapshot truncaria em silêncio, e a leitura devolveria
  "fechamento não encontrado" por JSON inválido.

**Armadilha de PHP que quase entrou** (achada na revisão): a versão do formato
do snapshot nasceu como `private const` **dentro do trait**. Constante em trait
só existe do **PHP 8.2** em diante; em 8.0/8.1 é erro de *compilação* ("Traits
cannot have constants") — e o `TurnosReportBase` é usado por 11 classes, ou
seja, o Repasse inteiro e Gerenciar Turnos cairiam em 500. O lab roda 8.4 e o
erro passaria no teste para explodir em produção, onde RHEL entrega 8.0/8.1 com
facilidade. Virou método (`snapshotVersion()`). **Regra: nada de `const` em
trait neste módulo enquanto o piso for PHP 8.0.**

~~Limitação conhecida: dois usuários fechando ao mesmo tempo passam ambos pela
checagem e gravam dois documentos.~~ **Fechada em 2026-08-19** — por trava
consultiva, e NÃO por `SELECT ... FOR UPDATE`, que não funcionaria no
PostgreSQL. Ver "Fechar turno: a corrida fechada por advisory lock".

### Menção notifica pelo media type do usuário (2026-08-19, issue #6)

A menção só existia no banner da tela do Repasse: quem fosse mencionado às 3h e
estivesse em outra tela (ou nem logado) não ficava sabendo. Agora o módulo
avisa pelo media type que a pessoa já configurou.

**A premissa que estava errada.** A nota interna dizia que "a tabela `alerts`
não pode ser usada por módulo, o jeito é `exec()` + `curl` no relay SMTP".
Conferido no fonte do Zabbix 7.0, está errado nas duas metades:

- Inserir em `alerts` realmente não serve, mas por outro motivo: `actionid` e
  `eventid` são NOT NULL com FK ON DELETE CASCADE (seria preciso emprestar uma
  Ação e um evento reais, que o housekeeper apaga depois levando a linha
  junto), e o `alertid` é alocado por um **contador em memória do server**, não
  pela tabela `ids` do frontend — dois alocadores para a mesma coluna, a mesma
  classe de incidente do `role_rule`/`fcorr`.
- Existe via oficial frontend→server: o request de socket **`alert.send`**,
  exposto por `CZabbixServer::testMediaType()` — o que o botão "Test" de Tipos
  de mídia usa. Não grava em `alerts`, não cria task, é síncrono.

Não há task de "enviar mensagem" (`ZBX_TM_TASK_*` não tem nada disso) e a API
JSON-RPC só expõe `alert.get`.

**As duas contrapartidas, e como estão tratadas** (hoje em
`scripts/ZbxServerClient.php`, isolado nos moldes do `ZbxDb` — a rota é interna
do Zabbix e pode mudar; a primeira versão vivia em `actions/ZbxAlertSender.php`,
removido quando o envio virou fila, ver a seção seguinte):

- **Exige Super Admin**, validado no server. Como quem escreve a nota é
  analista, a sessão dele não serve: é um token de API na env
  `PLANTONISTAS_ALERT_TOKEN`. **Sem token o módulo não notifica e nada
  quebra** — o banner continua. É por isso que `token-ausente` não vai para o
  log: é o estado normal de quem não ligou o recurso.
- **A janela de notificação não vem de graça**: quem avalia `media.period` e
  `active` é o escalonador, ao inserir em `alerts`; o `alert.send` recebe
  `sendto`/`mediatypeid` prontos e manda. O filtro foi replicado em PHP
  (`matchesPeriod()`), e é avaliado no **fuso do destinatário** (`users.timezone`)
  — no frontend o fuso do PHP é o do usuário logado, ou seja o AUTOR, e usá-lo
  deslocaria a janela de um colega em outro fuso. Terceiro lugar em que esse
  mesmo bug de fuso apareceu.

`media.severity` é ignorado de propósito: é bitmask de severidade de trigger, e
uma menção não tem severidade.

**O que a revisão pegou e valeu a implementação inteira:**

- **Webhook exige mapa `nome => valor`**, não lista de `{name, value}` — no
  formato errado o server não acha parâmetro nenhum e o webhook roda vazio. E
  as macros `{ALERT.SENDTO}`/`{ALERT.SUBJECT}`/`{ALERT.MESSAGE}` **não são
  expandidas** por esta rota (na tela de teste é o operador que digita o valor
  real no lugar da macro), então a substituição é nossa — sem ela o Telegram
  receberia literalmente `{ALERT.SENDTO}` como destinatário.
- **O envio síncrono podia travar o save da nota por minutos.** Cada envio é um
  socket novo, e o timeout default do teste de media type é **65s** — pensado
  para quem clicou em "Test" e sabe que vai esperar. Uma nota mencionando 10
  pessoas com 2 mídias seriam 20 sockets em série. A primeira correção foi
  timeout próprio (2s/5s) e teto de 5 envios por save; depois o envio saiu do
  save de vez e virou fila (seção seguinte).
- **Link do e-mail montado com `$_SERVER['HTTP_HOST']` era vetor de phishing**:
  o Host é controlado por quem faz a requisição, então quem escreve a nota
  poderia mandar um link para domínio próprio *pelo canal legítimo do Zabbix*.
  Sem a URL configurada em Administração → Geral, o link vai **relativo**.
- Media type "Script" (type 1) ficou de fora: o `alert.send` espera os
  parâmetros dele em outro formato (lista plana, sem sendto/subject), e mandar
  errado falharia só no log.

### Menção: o envio virou fila por cron (2026-08-19)

O envio síncrono dentro do save da nota funcionava, mas era um socket por
destinatário por mídia no caminho de quem só queria salvar um texto — daí o teto
de 5 e os timeouts curtos, que são remendo, não solução. Agora
`recordMentions()` só **grava a linha**: a tabela `module_plantonistas_mentions`
É a fila, e quem envia é `scripts/cron_notify_mentions.php`, de minuto em minuto.
Sem ninguém esperando, caíram o teto e a pressa.

O `CZabbixServer` do frontend não existe em CLI, então o protocolo ZBXD foi
reimplementado em `scripts/ZbxServerClient.php`: `"ZBXD" + 0x01` + 8 bytes
little-endian (`pack('P')`) com o tamanho, cabeçalho de 13 bytes na resposta.
`readExactly()` existe porque `fread()` em socket devolve menos do que se pediu
o tempo todo — ler uma vez e assumir que veio tudo é o erro clássico aqui.

Coluna nova `notified_at` (NULL = pendente), migrada idempotente no
`Module::migrateColumns()`. **Ordem de deploy importa**: a coluna nasce no
primeiro carregamento de página com o módulo habilitado, então agendar o cron
antes disso o faz falhar a cada minuto. Está no README.

**Os dois dedupes**, que era o outro pedido:

1. **Por pessoa, não por menção**: o `GROUP BY mentioned_userid` da fila
   transforma dez menções à mesma pessoa em um aviso ("você foi mencionado 10
   vezes"), em vez de dez e-mails.
2. **Quem está online não recebe e-mail**: `module_plantonistas_user_sessions`
   (o presence tracker) já diz quem está com sessão viva; para essa pessoa o
   banner da tela basta. A menção sai da fila marcada assim mesmo — não fica
   pendente esperando a pessoa deslogar.

Duas decisões que a revisão apontou e valem para o próximo cron:

- **O `UPDATE` que marca a fila tem teto por `id`** (`id <= MAX(id) lido no
  SELECT`), não só por data. Sem o teto, uma menção gravada entre o SELECT e o
  UPDATE seria marcada como notificada **sem ter sido enviada** — sumia do
  e-mail e sobrava só o banner. Teto por `id`, e não por data, porque data
  esbarra no problema de fuso abaixo.
- **`created_at` passou a ser gerado em PHP** no `recordMentions()`, em vez do
  `DEFAULT CURRENT_TIMESTAMP` do banco: o MariaDB roda em outro host e pode
  estar em UTC, enquanto o corte de idade do cron é calculado em
  `America/Sao_Paulo`. Quinto lugar do módulo em que fuso ia morder.

Continua no backlog: menção não tem histórico de lidas (só as pendentes
aparecem no banner) e o editor não aceita imagem/anexo.

### Telefones: o filtro por papel era bug (2026-08-19)

`PhonesList`/`PhonesSave`/`PhonesExport`/`PhonesImport` exigiam que o alvo
tivesse o **mesmo `roleid`** do operador, além do grupo compartilhado. Nunca
esteve claro se era regra ou herança; a checagem foi feita e é bug.

O argumento decisivo é a data: o commit `9959722` (2026-08-17) tornou a tela
Telefones **Admin (2)+**. A partir dali, "mesmo papel" passou a significar que
um Admin só enxerga outros Admins — e a pessoa para quem se liga às 3h da manhã
é o analista, que tem papel **User (1)**. Ou seja, a tela ficou exatamente sem
quem ela existe para mostrar. A leitura agora é só por grupo compartilhado, como
em todo o resto do módulo.

**A escrita ganhou uma guarda que não existia antes.** Sem o filtro de papel, um
Admin passaria a alterar o telefone de um Super Admin — coisa que o filtro
antigo impedia por acidente. `PhonesSave` e `PhonesImport::buildAllowedMap()`
recusam alvo com `role.type` maior que o do operador. Ver é o objetivo da tela;
escrever no cadastro de quem tem mais privilégio, não.

### Nome duplicado: fim dos CONCAT crus (2026-08-19)

A regra de bordas (um campo só absorve o outro se o contiver no começo ou no
fim, com espaço como limite de palavra) já existia em dois lugares:
`formatUserLabel()` no `TurnosReportBase` (família repasse) e `buildFullName()`
no `cron_presence_tracker.php` (CLI). Faltava a família escala, que montava o
nome **na view**, com `trim($u['name'].' '.$u['surname'])` em sete pontos.

Agora existe o trait `actions/UserLabel.php`, usado por `PlantaoList`,
`PlantaoOverview`, `PlantaoHistory`, `PlantaoExport`, `PlantaoImport`,
`PhonesList` e `PhonesExport`. O rótulo é montado no **controller** e entregue
pronto à view (`label`, `reserva_label`, `r_label`, `n_label`, `o_label`,
`rn_label`, `ro_label`, `cb_label`) — view não faz regra de negócio.

São **três cópias** da mesma regra de propósito, e está escrito no cabeçalho de
cada uma: o cron roda em CLI sem carregar o módulo, e as duas famílias de código
são independentes. Ao mexer numa, mexer nas três.

**Efeito colateral que precisou de conserto no mesmo commit**: o CSV da Escala
passou a exportar o nome deduplicado, e o `buildUserMap()` do `PlantaoImport`
indexava só o `CONCAT` cru — exportar e reimportar deixaria de casar justamente
com quem tem o nome duplicado no cadastro. O mapa ganhou o rótulo como chave a
mais (com e sem acento).

**Bug de brinde no Histórico**: as células testavam `$r['o_name'] !== null` para
decidir se havia alguém ali, mas o `\DBfetch()` do Zabbix converte NULL na
string `'0'` por padrão — o teste nunca dava falso e a célula imprimia `0`.
Passou a testar a coluna de id da própria tabela de histórico.

O que **não** mudou: os `CONCAT` que sobraram em SQL (`queryShiftAnalysts()`,
`queryMTTA()`, `listUsersByGroup()`) são só `ORDER BY` e busca — ordenar por um
nome duplicado dá a mesma posição, e a duplicação não chega à tela.

### Vínculo analista→turno em massa (2026-08-19)

Gerenciar Turnos ganhou uma barra com "selecionar todos", contador e "aplicar
aos selecionados": um turno para vários analistas de uma vez, em vez de um
clique por pessoa. Era o gargalo real por trás da coluna Turno da Presença
mostrando "Sem turno" para quase todo mundo — ninguém cadastra 16 vínculos um a
um.

Três detalhes que a revisão apontou e valem para qualquer UI parecida aqui:

- Os POSTs saem **em série** (cadeia de promises), não em paralelo: 16 requests
  simultâneos contra o mesmo endpoint de escrita não trazem ganho e enchem o
  log de contenção.
- A barra é renderizada **sempre**, escondida com `hidden` enquanto a equipe não
  tem turno. Renderizada condicionalmente, ela não apareceria ao criar o
  primeiro turno — só depois de recarregar a página.
- `hidden` sozinho não basta: `.rp-bulk-bar` tem `display:flex`, que vence a
  folha do navegador. Precisou de `.rp-bulk-bar[hidden] { display: none; }`.
  O seletor de turno da barra também entrou na sincronização de opções, senão
  criar um turno e aplicá-lo em massa na mesma visita não funcionaria.

### PostgreSQL: as armadilhas semânticas primeiro (2026-08-19, issue #1)

O levantamento completo do que é MySQL-only está no `ROADMAP.md` (Fase 3). O
que foi feito nesta primeira passada é o subconjunto que **também conserta ou
protege o MySQL de hoje** — nenhuma dessas mudanças espera por PostgreSQL para
valer a pena, e todas continuam corretas no MySQL.

O critério: erro de sintaxe o PG acusa na hora e a gente descobre no primeiro
teste. Perigoso é o que roda nos dois bancos e devolve resultado **diferente**.

- **Chaves do `INFORMATION_SCHEMA` em maiúsculo** era a pior de todas. O MySQL
  devolve `TABLE_NAME`, o PostgreSQL devolve `table_name`. Lendo em maiúsculo,
  no PG `existingTables()` devolveria `[]` e `migrateColumns()` cairia no
  `isset()` falso: **o RENAME das tabelas antigas nunca aconteceria e nenhuma
  migração de coluna rodaria** — sem erro, sem log, com as telas abrindo
  normalmente. Agora tudo passa por `array_change_key_case($row, CASE_LOWER)`
  antes de ser usado.
- **Expressão booleana crua em SELECT** (`(NOT EXISTS (...) AND ...) AS
  habilitado`, no `cron_sync_oncall`): MySQL devolve `1`/`0`, PostgreSQL
  devolve `t`/`f`, e `(int)'t'` é `0`. Todo plantonista viraria "desabilitado",
  toda equipe seria pulada e o log mandaria investigar um cadastro correto.
  Virou `CASE WHEN ... THEN 1 ELSE 0 END`.
- **Comparação de texto**: o MySQL com collation `_ci` ignora caixa; o PG não.
  Afetava a busca de menção (`@rafael` deixaria de achar `Rafael` — a feature
  ficaria inútil, com cara de bug intermitente), as exclusões de `guest` e
  `api_`, e o `usrgrp.name = ?` do cron de escalonamento, que passaria a dizer
  que um grupo visível na tela "não existe". Tudo com `LOWER()` dos dois lados
  agora — e no cron também `TRIM()`, porque nome de grupo é digitado à mão.
- **`SELECT DISTINCT` com `ORDER BY` de expressão fora da lista de seleção**: o
  MySQL aceita, o PG recusa. A expressão virou a coluna `sort_label`.
- **`IFNULL(bigint, '')`** não é troca mecânica por `COALESCE`: o PG recusa
  misturar `BIGINT` com `''`. Virou `COALESCE(..., 0)`, que mantém o contrato
  com a view (que testa por truthiness, e `'0'` é falsy em PHP) e alinha com o
  `PlantaoOverview`, que já usava `0`.
- `IF()` → `CASE WHEN`, e `DATABASE()` → `current_schema()` no ramo PG.
- `INFORMATION_SCHEMA.STATISTICS` não existe no PG: a leitura de índices passou
  a escolher entre ela e `pg_indexes`. E a coluna de tipo passou a ser
  `COLUMN_TYPE` (MySQL, traz o `unsigned`) ou `DATA_TYPE` (padrão), conforme o
  banco — no PG `unsigned` não existe, então aquele ramo de correção
  simplesmente não tem o que fazer.

**DDL com fonte única** (mesma passada): as 9 tabelas eram descritas duas
vezes, em MySQL puro — no `Module::tableDdl()` e no `sql/schema.mysql.sql`. Agora
`Schema.php` descreve cada tabela UMA vez, em tipos abstratos, e gera o DDL do
banco em uso; `sql/schema.mysql.sql` e `sql/schema.pgsql.sql` são **gerados**
por `scripts/gen_schema.php` e não devem ser editados à mão. Mapeamentos
decididos: `TINYINT(1)` → **`SMALLINT`** e não `BOOLEAN` (o código compara
`active = 1` em 10 lugares, e no PG `boolean = integer` é erro);
`BIGINT UNSIGNED` → `BIGINT` (o PG não tem unsigned, e o ganho não paga a
divergência — a migração que promovia `userid` para unsigned saiu junto, senão
toda instalação MySQL nova faria um ALTER inútil desfazendo o próprio CREATE);
`AUTO_INCREMENT` → `BIGSERIAL`; `KEY` inline → `CREATE INDEX` (só no PG:
`CREATE INDEX IF NOT EXISTS` **não existe no MySQL 8.0**, então lá o índice
continua inline, que é o que já rodava). `ON UPDATE CURRENT_TIMESTAMP` não tem
equivalente de coluna no PG e exigiria trigger — como as duas colunas assim
(`updated_at` de `shifts` e de `user_shift`) não são lidas por tela nenhuma,
no PG elas ficam sem auto-atualização, e isso está registrado no `Schema.php`.

**Regra para daqui em diante**: `Module::isPgsql()` (lê `$DB['TYPE']`) é o
único lugar que decide dialeto. Tabela nova ou coluna nova entra no
`Schema.php`, nunca em SQL escrito à mão nos dois lugares. Consulta nova não usa `IF()`, `IFNULL`,
`DATE_FORMAT`, `FROM_UNIXTIME`, `TIMESTAMPDIFF` nem devolve booleano cru — e
comparação de texto com valor digitado por gente leva `LOWER()`.

**Funções de dialeto num lugar só** (2026-08-19): `actions/SqlFn.php` gera
`DATE_FORMAT`/`to_char`, `FROM_UNIXTIME`/`to_timestamp`,
`TIMESTAMPDIFF`/`EXTRACT(EPOCH…)`, `INTERVAL` e o upsert
(`ON DUPLICATE KEY UPDATE`/`ON CONFLICT`) conforme o banco. As ~20 consultas
que usavam essas funções passaram a chamar o helper — nenhuma tem `if` de
dialeto dentro. O `insert_id` do `ZbxDb` escolhe entre `LAST_INSERT_ID()` e
`lastval()`.

Duas armadilhas que o helper documenta porque custariam caro:

- No `to_char` do PostgreSQL, **`HH` é relógio de 12 horas** — usá-lo faria as
  14h virarem `02` em silêncio. O certo é `HH24`.
- O módulo converte epoch somando o offset do PHP (`ev.clock + $tzOffset`) e só
  então formatando, o que **pressupõe banco em UTC**. A premissa não é nova; o
  que é novo é estar escrita. No ramo PG ela virou explícita com
  `AT TIME ZONE 'UTC'`, senão o `to_timestamp()` seria renderizado no fuso da
  sessão e o valor mudaria conforme a configuração do servidor.

**Migrações de coluna por dialeto** (2026-08-19): `migrateColumns()` passou a
montar todo o DDL por helper — `renameTableSql`, `addColumnSql`, `addIndexSql`,
`dropIndexSql`, `dropUniqueSql`, `addUniqueSql`, `modifyTypeSql`. Quatro coisas
que a revisão salvou:

- **`DROP INDEX` não remove UNIQUE no PostgreSQL.** Lá o `uniq_group_day` é
  CONSTRAINT (criado pelo próprio módulo), e o banco recusa: "cannot drop index
  … because constraint … requires it". Como `\DBexecute()` não lança, o
  try/catch do `init()` não pegaria nada — o erro sairia como banner vermelho
  com o SQL inteiro, **a cada carga de página**, porque a migração nunca
  concluiria. Daí o `dropUniqueSql()` separado.
- **`AFTER coluna` não existe no PG** (lá coluna não tem ordem) e `MODIFY
  COLUMN` vira dois statements (`ALTER COLUMN … TYPE` + `SET NOT NULL`) — por
  isso `modifyTypeSql()` e `addColumnSql()` devolvem **lista**, executada por
  `execAll()`.
- **O `COMMENT` da coluna É emitido no PG**, como `COMMENT ON COLUMN`. Não é
  enfeite: o `Schema.php` já emite numa instalação do zero, e se a migração não
  emitisse, dois ambientes PG teriam catálogos diferentes — a divergência
  silenciosa que o `Schema` existe para evitar.
- **`NOW()` do PG é `timestamptz`**, e as colunas são `TIMESTAMP` sem fuso: a
  conversão usaria o `TimeZone` da sessão, que ninguém configura no frontend.
  Com o servidor em UTC e o cron gravando em `America/Sao_Paulo`, o "online nos
  últimos 15 min" erraria por 3 h. `SqlFn::now()` devolve `LOCALTIMESTAMP` no
  PG — quarto lugar do módulo em que fuso ia morder.

**Os arquivos `sql/*.sql` são gerados** (`scripts/gen_schema.php` para os
schemas). Uma versão anterior do gerador tinha um bug que omitia 17 índices e
os 8 comentários de tabela — e o `install.sh` executa esses arquivos quando o
operador responde "banco novo", enquanto o `Module::init()` **não conserta
depois** (`existingTables()` vê a tabela pronta). Regenerar sempre que o
`Schema.php` mudar; nunca editar à mão.

**Os dois crons em PDO** (2026-08-19): `scripts/CliDb.php` faz para o CLI o que
o `ZbxDb` faz para o frontend — expõe a fatia da API do mysqli que os scripts
usam, por cima do PDO, com DSN escolhido pela env `DB_TYPE`. Nenhuma consulta
foi reescrita.

Duas armadilhas do PDO que o adaptador precisou compensar:

- **A string de tipos do `bind_param` NÃO é decorativa.** Com
  `ATTR_EMULATE_PREPARES => false`, passar os valores direto para
  `execute([...])` faz o PDO enviar **tudo como string**. O MySQL coage
  sozinho; o PostgreSQL não — `bigint = text` é `operator does not exist`. Como
  os dois scripts comparam `userid`, `usrgrpid`, `shift_id` e timestamps com
  `= ?`, ignorar os tipos deixaria o ramo PG quebrado em quase toda consulta.
  Cada valor é ligado com `bindValue()` e o tipo declarado (`i` → `PARAM_INT`),
  e o `execute()` vai **sem argumento** — passar o array ali sobrescreveria os
  tipos e devolveria tudo para string.
- **`rollBack()` lança se não há transação ativa**, ao contrário do mysqli, que
  devolve false. O único chamador está num `catch`, então sem a guarda a
  exceção original — a que diz o que aconteceu — seria trocada por "There is no
  active transaction".

**Homologado em produção (2026-08-31).** O passo 7 da issue #1 foi fechado:
as 7 telas, os 3 crons, a criação/migração de schema pelo `Module::init()` e o
fechamento de turno com PDF rodaram contra um PostgreSQL real. Os dois
backends deixam de ter status diferente — ao mexer em consulta ou DDL daqui em
diante, os dois ramos são caminho de produção, não um deles um plano B.

### Fechar turno: a corrida fechada por advisory lock (2026-08-19)

Conferir "já foi fechado?" e gravar eram dois passos. Dois usuários clicando ao
mesmo tempo passavam ambos pela checagem e gravavam dois documentos, driblando
o "refechar exige Admin+".

**`SELECT ... FOR UPDATE` não resolve.** No MySQL resolveria, pelo gap lock do
InnoDB, que bloqueia INSERT numa faixa lida mesmo vazia. O **PostgreSQL não tem
gap lock**: um SELECT sem linhas não trava nada, e as duas transações passariam.
Escrever `FOR UPDATE` daria a impressão de resolvido e continuaria quebrado num
dos dois bancos.

A saída foi trava consultiva em `SqlFn::tryLock()`/`releaseLock()` —
`GET_LOCK(nome, 0)` no MySQL, `pg_try_advisory_lock(chave)` no PostgreSQL —
**sem espera**: quem não pega recebe "outra pessoa está fechando agora" em vez
de ficar com a requisição pendurada segurando um worker do PHP-FPM.

Detalhes que a implementação exigiu:

- **`pg_try_advisory_lock` devolve booleano**, que chega como `t`/`f`, e
  `(int)'t'` é 0 — a trava obtida seria lida como negada. Por isso o ramo PG vai
  embrulhado em `CASE WHEN ... THEN 1 ELSE 0 END`. É a mesma armadilha do
  `cron_sync_oncall`, agora num lugar novo.
- **`GET_LOCK` devolve NULL em erro**, que é diferente de 0 (negada). Falha ao
  travar deixa o fechamento seguir: a trava protege de uma corrida rara, e
  derrubar o fechamento porque o banco não respondeu ao `GET_LOCK` trocaria um
  problema raro por um problema toda vez.
- **`die()` não executa `finally`.** O `TurnosReportClose` tinha `echo ...;
  die()` em cada caminho de saída; com a trava, todo caminho de erro deixaria
  ela pendurada. A action passou a montar `$resposta` numa variável e a ter um
  único `echo` no fim, com `finally` liberando a trava.
- **Recusa esperada virou exceção própria** (`actions/CloseBusy.php`). Sem essa
  distinção, ou a mensagem do `RuntimeException` do `ZbxDb` — que carrega o SQL
  inteiro — iria para a tela, ou toda recusa viraria o genérico "confira o log",
  que não diz nada a quem clicou.
- A trava do MySQL é da **conexão**. A do Zabbix não é persistente hoje, mas
  liberar explicitamente é o contrato: ligar conexão persistente no PHP-FPM
  deixaria a trava presa no worker.

Limitação que continua: `countClosedReports()` roda sem filtro de visibilidade
(de propósito — com filtro, um User refecharia só porque não enxerga o
fechamento anterior).

### Salvar Escala e Telefones sem perder o que foi digitado (2026-08-19)

Os forms de POST nativo caíam na página "Acesso negado" do Zabbix quando o token
CSRF expirava — e o conteúdo digitado ia junto. Nesta tela não é raro: a aba
fica aberta o turno inteiro e o token deriva do `secret` da sessão.

Agora o envio é por `fetch` (`pltPost()` / `phnPost()`), e a página não sai do
lugar: em caso de recusa aparece um aviso e tudo continua preenchido.

**Como distinguir sucesso de recusa sem mudar o PHP**: as actions respondem
**redirect** nos dois desfechos normais (salvou / erro de validação), então
`r.redirected` é verdadeiro. A recusa por CSRF não redireciona — devolve HTML.

Dois pontos registrados no código:

- **O custo do redirect seguido** (dois renders por salvamento) foi eliminado
  em 2026-08-20: as actions passaram a responder JSON — ver "Actions de escrita
  respondem JSON em AJAX".
- **O "remover" precisa de `alert`**: ele parte de uma célula do calendário, e o
  `#plt-post-hint` fica dentro do formulário lá embaixo, fora da viewport. Sem
  o alert a falha seria invisível — a página não muda e o clique parece não ter
  efeito.

Os dois **imports** também passaram a usar `fetch` (2026-08-20): antes o
`form.submit()` nativo levava para a página "Acesso negado" e a seleção do
arquivo se perdia.

### Histórico de menções, e um SQL que nunca funcionou (2026-08-19)

O banner mostra só menção pendente, porque é notificação. Quem foi mencionado às
3h, dispensou o banner e no dia seguinte quis achar a nota não tinha por onde.
Agora o botão `@` do cabeçalho abre um painel com pendentes E lidas (teto de
100, sem paginação: menção é rastro, não caixa de entrada — quem precisa de mais
está procurando a NOTA, e para isso existe a navegação por data).

**Bug achado no caminho, e ele era sério**: o `markMentionRead()` tinha a
interpolação do `SqlFn::now()` dentro de aspas **simples**, então o SQL saía com
o texto literal `" . SqlFn::now() . "`. No MySQL isso é uma string (data zerada,
ou erro em `sql_mode` STRICT); no PostgreSQL é **nome de coluna** — erro,
exceção engolida pelo `catch`, e a menção nunca era marcada como lida: o banner
voltava a cada carga da página sem nada no log. Varredura feita no módulo
inteiro; era o único caso.

Duas decisões de UI que valem a regra geral:

- A condição do botão é **idêntica** à do painel. `queryMentionHistory()`
  devolve `[]` quando falha (histórico é acessório, não derruba o relatório), e
  um botão preso a `pending_mentions` continuaria na tela sem nada para abrir.
- O `ORDER BY` é **qualificado** (`m.created_at`): o alias de saída é a string
  já formatada em `d/m/Y`, e os dois bancos preferem o alias — ordenar por ele
  daria resultado cronologicamente errado. Mesma armadilha de `queryNotes()`.

### Imagem e anexo: proibido explicitamente, não "indefinido" (2026-08-19)

Decisão: o Diário de Bordo é texto formatado + menções, sem anexo. Antes o
comportamento era *indefinido*, não proibido — colar um print inseria um
`<img src="data:image/png;base64,...">` de centenas de KB no `contenteditable`,
a pessoa via a imagem, salvava, e a sanitização do servidor descartava a tag
(`img` não está na allowlist). A nota voltava sem a imagem, sem mensagem, com
cara de bug.

**A parte que quase deu errado**: a primeira versão bloqueava quando havia
qualquer item `image/*` no clipboard. Só que copiar um trecho de planilha —
Excel, Google Sheets, tabela do próprio Zabbix — coloca `text/plain`,
`text/html` **e** um bitmap `image/png` ao mesmo tempo. Isso teria descartado a
colagem de TEXTO, que é o uso mais comum num repasse de NOC. A regra correta é:
**se veio representação de texto junto, não é anexo**.

### CSV da Escala aprendeu turnos (2026-08-19)

Era decisão consciente que o import/export ignorasse turnos; com a escala por
turno em uso, virou defeito. A coluna **Turno** entra como 3ª no arquivo, sempre
(vazia em grupo sem turno), e o import lê pelo nome do turno.

- **Arquivo antigo (7 colunas) continua importando.** O `detectColumns()` casa
  por igualdade EXATA do cabeçalho, então `dia da semana` não vira `dia` e
  `telefone reserva` não vira `reserva`. Sem coluna Turno → modo legado, com
  aviso no resultado dizendo quantas linhas caíram sem turno num grupo que tem
  turnos cadastrados.
- **O `shift_id` entra no WHERE do upsert.** A unique key é
  `(usrgrpid, schedule_date, shift_id)`; sem ele, importar o turno da tarde
  sobrescreveria o da manhã do mesmo dia.
- **Turno desativado não some do arquivo.** O export lista os turnos ativos, mas
  varre também os `shift_id` que sobraram no dia e os emite como
  `Nome (inativo)`. Sem isso, uma escala apontando para turno desativado
  existiria no banco, apareceria no calendário e sumiria no round-trip.
- **Linha sem plantonista é contada, não silenciada.** No arquivo exportado ela
  é buraco de cobertura (normal); numa planilha preenchida à mão é esquecimento
  — e "30 linhas importadas" esconderia as 3 que ficaram de fora.
- `logHistory()` do import passou a gravar `shift_id`/`shift_name` (snapshot do
  nome, sobrevive a rename/remoção depois).

### Visual unificado no tema do Zabbix (2026-08-19)

A família escala era escura fixa (`#2b2b2b`, `#f2f2f2` à mão em ~40 lugares); a
repasse, clara com detecção de tema escuro por JS. Quem usava o Zabbix no tema
claro via metade do menu Plantão escura.

`views/_theme.php` passou a ser a fonte única: emite a paleta `--plt-*` (claro
no `:root`, escuro em `.plt-dark`) e a detecção de tema, e é incluído pelas seis
views. Os valores do tema escuro são os originais da família escala — quem já
usava o Zabbix no escuro não vê diferença.

A detecção lê a **luminância do fundo já renderizado**, não o nome do arquivo de
tema: o Zabbix referencia arquivos de tema alternativos no HTML mesmo com outro
tema ativo, e procurar "dark-theme" no href dava falso positivo. A técnica já
existia na família repasse — estava era **triplicada** (report, shifts e uma
variação). Agora é uma só, e as duas famílias decidem "claro ou escuro" pelo
MESMO critério; heurísticas parecidas divergiriam num tema customizado e o menu
voltaria a ficar metade e metade.

**`--rp-bg-soft` nunca existiu.** Duas regras da família repasse usavam
`var(--rp-bg-soft, #fafafa)` sem a variável estar definida em lugar nenhum: o
fallback valia sempre, e no tema escuro o painel virava uma caixa branca com
texto branco (contraste 1.19:1). Definida nos dois blocos agora.

**Contraste conferido regra a regra no tema claro**, que é o padrão do Zabbix e
onde o risco estava. Três combinações reprovavam AA e foram corrigidas: aba de
grupo inativa (2.91:1), badge "não cadastrado" (1.89:1 — justamente a
informação que a tela existe para destacar) e `--plt-text-faint`, que a 2.24:1
carregava conteúdo real ("sem telefone", "Nenhum histórico registrado", o "—"
das células), não enfeite.

Regra daqui em diante: **cor nova entra no `_theme.php`**, nunca em hex dentro
da view. E `var(--x, fallback)` sem a variável definida é bug silencioso —
declarar a variável é o que garante que o tema escuro a sobrescreva.

### Actions de escrita respondem JSON em AJAX (2026-08-20)

O envio por `fetch` resolveu a perda do que foi digitado, mas as actions
continuavam respondendo **redirect** — e a única forma de o JS saber se deu
certo era seguir esse redirect, o que faz o navegador baixar a página de
destino inteira e descartá-la, para baixá-la de novo no `location.href`. Dois
renders por salvamento. Além disso, erro de validação recarregava a tela, que
é exatamente o que se queria evitar.

`actions/AjaxRedirect.php` dá às cinco actions de escrita (`PlantaoSave`,
`PlantaoDelete`, `PhonesSave`, `PlantaoImport`, `PhonesImport`) três desfechos:

| método | JSON | navega? |
|---|---|---|
| `respondOk` | `success:true` + `redirect` | sim |
| `respondPartial` | `success:false`, `warning:true` + `redirect` | sim |
| `respondErr` | `success:false`, **sem** `redirect` | não |

**O modo é escolhido por um campo do POST (`plt_ajax=1`), não pelo cabeçalho
`X-Requested-With`** — e essa foi a correção mais importante da revisão. Com
cabeçalho, o modo de falha seria péssimo neste ambiente: o frontend fica atrás
de um F5, proxy remove cabeçalho com facilidade, e sem ele a action responderia
302, o fetch seguiria, viria HTML, e o JS diria **"sessão expirada, nada foi
salvo" para uma operação que deu certo**. Em `plantonistas.delete` isso vira
"Entrada não encontrada" logo após uma remoção bem-sucedida; no import, histórico
duplicado por reenvio. Corpo de POST nenhum proxy reescreve. O cabeçalho ficou
como segunda via, e o JS ainda trata `r.redirected` como sucesso.

`plt_ajax` foi **declarado no `validateInput()`** das três actions que validam
entrada. Não é zelo: parâmetro não declarado pode ser recusado dependendo da
versão do Zabbix, e aí a action inteira responderia "Parâmetros inválidos".

**`respondPartial` existe por causa do desfecho mais comum de uma importação
real**: "30 linhas importadas. Avisos (3): …". Tratado como erro puro, a tela
não recarregaria e as linhas gravadas ficariam invisíveis até um F5 manual —
com cara de importação que falhou inteira. O `PlantaoSave` usa o mesmo caminho
no `catch` do laço de gravação, que roda dia a dia: uma exceção no meio deixa
parte da escala gravada.

Outras três coisas que a revisão apontou e valem como regra:

- **`json_encode` sem `JSON_INVALID_UTF8_SUBSTITUTE` devolvia `false`**, e
  `echo false` imprime string VAZIA com content-type de JSON — o usuário veria
  "erro de conexão" para algo que gravou. O caminho é concreto: as mensagens de
  erro do import ecoam bytes crus do arquivo, o leitor de CSV não converte
  encoding, e planilha salva como ANSI no Excel pt-BR é Latin-1 puro.
- **Rejeição tratada como segundo argumento do `.then`, não como `.catch` no
  fim da cadeia.** Com `.catch`, um `TypeError` do bloco de sucesso viraria
  "erro de conexão, nada foi salvo" — afirmação que o navegador não tem como
  sustentar depois de o POST ter chegado ao servidor.
- **O botão de importar só é reabilitado quando reenviar o mesmo arquivo pode
  dar certo** (rede, sessão). Em erro de conteúdo o arquivo é limpo: reenviar
  daria o mesmo erro, e o `logHistory()` do import grava linha nova a cada
  passada.

### Backlog fechado: Chart.js, role_rule e o que sobrou do TTL (2026-08-20)

**Chart.js continua estático, agora com reserva e sem falha silenciosa.**
É o único `.js` do módulo que faz alguma coisa (o outro em `assets/js/` é um
stub de 231 bytes exigido pelo manifest). Inlinar sempre custaria 204 KB em
toda carga do Repasse, sem cache — pior no caso normal, que é o F5 deixando
passar. Então: `<script src>` por padrão, e a env `PLANTONISTAS_CHART_INLINE`
(aceita `1`/`on`/`true`/`yes`) faz o PHP embutir a biblioteca. Nenhuma linha de
código para mexer se o bloqueio aparecer em produção.

Três coisas que a implementação exigiu:

- **A falha deixou de ser silenciosa.** Sem `Chart`, o `new Chart(...)` estourava
  no console e os dois cards ficavam vazios — ninguém ligava uma coisa à outra.
  Agora os canvas viram uma mensagem que já diz qual é a env. O botão "mudar
  formato" some junto: ele fica no **cabeçalho** do card, que é irmão do corpo
  substituído, e sobreviveria como botão que não faz nada.
- **Env ligada + arquivo ilegível vai para o log.** O fallback voltaria para o
  `<script src>` do MESMO arquivo, o navegador tomaria 403, e a tela mandaria
  ligar a env que já estava ligada. Causa provável: `git pull` como root sem o
  `chown`.
- **Embutir só é seguro porque o arquivo não contém `</script` nem `<!--`** —
  os dois fechariam o bloco no parser HTML. Conferido (0 ocorrências de cada) e
  anotado no código: trocar a versão do Chart.js exige conferir de novo.

**`role_rule`: o README estava factualmente errado.** Ele dizia que as regras
que liberavam as actions dos módulos antigos ficavam em `value_str`, e mandava
apagar por `LIKE 'plantao.%'`. Permissão de módulo no Zabbix 7.0 não guarda
nome de action: `CRole::RULE_TYPE_MODULE` grava `name = 'modules.module.N'` com
o módulo em **`value_moduleid`**, FK para `module.moduleid` — e remover o módulo
da tabela `module` tende a levar a regra junto. `value_str` é de `api.method.N`
e tag de serviço.

Ou seja, o SELECT antigo voltaria **vazio** em instalação normal, e o leitor
concluiria "não tem lixo" sem saber que estava olhando a coluna errada. O que o
`LIKE` de fato pega é regra **inserida à mão por SQL** — o padrão `fcorr` que
causou a colisão de `ids`. O README agora traz os dois SELECTs, explica quando
cada um devolve algo, e o DELETE vai por ID em vez de repetir o `LIKE`.

**Diário de Bordo continua sem TTL, por decisão** — é histórico permanente. O
que faltava era visibilidade: `sql/queries.<banco>.sql` ganhou "Crescimento do
Diário de Bordo" (contagem, MB de texto, tamanho em disco das 9 tabelas).
Arquivar segue sendo decisão de quem opera.

Detalhes que a revisão apontou nessas consultas e valem como regra:

- **`LENGTH`/`octet_length`, não `CHAR_LENGTH`/`length`**: o número tem de ser
  comparável com o tamanho em disco, e texto PT-BR com acento ocupa mais bytes
  que caracteres.
- **`GREATEST(reltuples, 0)` no PG**: a partir do 14, tabela que nunca sofreu
  ANALYZE tem `reltuples = -1`, e numa instalação nova as nove linhas sairiam
  com -1 parecendo defeito.
- **Escape de underscore no `LIKE` é assimétrico**: `'\\_'` no MySQL, `'\_'` no
  PostgreSQL (com `standard_conforming_strings`, que é o default). Está
  comentado nos dois arquivos.

**Guarda de `.xls` replicada para a Escala.** Existia só no `PhonesImport`; no
`PlantaoImport` um `.xls` binário caía no leitor de CSV e voltava "Arquivo vazio
ou formato não reconhecido" — ou, pior, "Coluna data não encontrada. Cabeçalho:
<bytes ilegíveis>". Junto, o teste de extensão virou case-insensitive: o
`$ext === 'xlsx'` estrito mandava `ESCALA.XLSX` do Windows para o leitor de CSV.

**`CHECKLIST-LAB.md`** reúne o que falta homologar. Dois itens dele nasceram
errados e foram corrigidos na revisão, e o motivo vale registrar:

- O passo de PostgreSQL mandava usar `scripts/install.sh`, que **não tem
  caminho PG** — os três blocos chamam `mysql` incondicionalmente. Virou
  `psql -f sql/schema.pgsql.sql`. (O `install.sh` só cobrir MySQL é limitação
  conhecida; o `Module::init()` cria as tabelas nos dois bancos.)
- O comando para carregar as env do cron era `set -a; source <(grep ...)`. O
  cron lê `/etc/cron.d/` **literalmente** depois do `=`; o bash não. Senha com
  `$`, espaço ou crase seria expandida — truncada no melhor caso, executada no
  pior. Virou laço `read -r` que atribui sem interpretar.

**Sobre o `php -l` do lab**: ele NÃO prova compatibilidade com PHP 8.0. O lab
roda 8.4, e o lint valida contra o interpretador que executa — `const` em trait
e afins passam limpos ali e explodem no RHEL. O checklist diz isso e oferece o
comando com `php:8.0-cli` em container. Aqui os 47 arquivos foram verificados
com um parser fixado no nível 8.0.

**O que ficou de propósito**: `manifest.json` declara `"view"` para
`plantonistas.report.pdf`, que faz `echo`/`die()` e nunca renderiza view. É
configuração morta e inofensiva; remover teria mais risco (tela em branco sem
erro nem log é o modo de falha clássico de action sem view) do que a confusão
que causa a quem audita o manifest.

### Cores/nomes de severidade reais no Repasse (2026-08-20/24, v5.1.0)

O Repasse tinha rótulo e cor de severidade **hardcoded** em 4 lugares
independentes, todos já marcados com `TODO buscar dinamicamente` no código:
`rp_sevLabel()`/`rp_sevClass()` na view, a cópia duplicada `sevLabel()`/
`sevClass()` no `TurnosReportPdf`, o `SEV_LABELS`/`sevBgColors` do gráfico de
rosca (JS), e os valores padrão do bloco `:root` do `turnos.report.css`. Nenhum
dos quatro lia `config.severity_name_0..5`/`severity_color_0..5` — a tabela que
Administração → Geral → Opções de exibição de acionadores realmente grava.

**Achado no caminho, e corrigido junto**: o fallback hardcoded de 3 dos 4
lugares (`sevLabel`/`rp_sevLabel`/PDF) usava os nomes errados —
`Minor`/`Major`/`Critical` em vez dos nomes reais do Zabbix
(`Average`/`High`/`Disaster`) — e o CSS padrão do `--sev-*` estava **um nível
deslocado**: `--sev-info` era `#00FFFF` (devia ser o azul `#7499FF` de
"Information"), `--sev-warn` tinha o azul (devia ter o amarelo `#FFC859` de
"Warning"), e assim em cascata até `--sev-high`. Sem instalação alguma essa
cor errada apareceria — mas nenhum código lia `config` ainda, então o CSS
estático sempre valia.

`TurnosReportBase::querySeverities(ZbxDb $db)` é o método único agora:
`SELECT severity_name_0..5, severity_color_0..5 FROM config`, com try/catch
(config indisponível → fallback com os nomes/cores certos, não os antigos
errados) e validação de cor por regex (`^[0-9A-Fa-f]{6}$`) antes de imprimir —
`config.severity_color_N` é `varchar` sem CHECK constraint no schema do
Zabbix, então um valor corrompido não vira CSS quebrado.

**Como a cor chega em cada um dos 4 lugares**, sem duplicar a query:

- **View e PDF**: `rp_sevLabel($sev, $rp_sev)`/`sevLabel($s, $severities)`
  passaram a receber o array como parâmetro (assinatura com default `[]` —
  chamador antigo não quebra) em vez de mapear estático; saída sempre por
  `htmlspecialchars()`.
- **Cor**: como o CSS estático não pode ser regerado por request (e mesmo que
  pudesse, perderia o cache do navegador), a cor real entra por um `<style>`
  inline com `:root{--sev-info:#7499FF;...}` **depois** da folha estática no
  documento — cascata resolve a favor do inline sem precisar de
  `!important`. Mesma técnica que o `_theme.php` já usa pra dark mode.
- **Gráfico de rosca**: `SEV_LABELS`/`SEV_COLORS` (JS) passaram a ser
  `json_encode()` do mesmo array PHP, com `JSON_HEX_TAG` (evita fechar
  `</script>` se algum nome de severidade tiver `<`/`>` — improvável no
  cadastro nativo, mas o mesmo cuidado dos outros pontos do módulo que lidam
  com string vinda do banco).

`TurnosReportPdf` **não inclui a view compartilhada** — é `echo`/`die()`
próprio (o `"view"` dele no manifest é config morta, documentado no Backlog) —
por isso a mesma correção teve que ser aplicada em duplicado ali; é a mesma
decisão de "3 cópias da mesma regra" já registrada pra `formatUserLabel()`.

### Reserva sem nome/telefone no hover da Escala e Visão Geral (2026-08-24)

Passar o mouse num dia com titular **e** reserva escalados mostrava nome e
telefone do titular normalmente, mas o campo do reserva vinha em branco —
mesmo com o cadastro correto em Telefones. Só acontecia com o **reserva**,
nunca com o titular do mesmo dia.

Causa: `PlantaoOverview` e `PlantaoHistory` já montavam o rótulo do reserva com
`userLabel($name, $surname, $username)` — nome + sobrenome, com o username
como reserva final quando os dois campos estão vazios (mesma regra descrita em
"Nome duplicado: fim dos CONCAT crus"). `PlantaoList` (a action que alimenta a
Escala e, por extensão, o tooltip da Visão Geral) fazia a MESMA chamada para o
titular, mas para o reserva passava **string vazia literal** no terceiro
argumento: `userLabel($row['reserva_name'], $row['reserva_surname'], '')`. Em
qualquer cadastro onde `name`/`surname` do reserva estejam vazios (comum —
muita conta antiga só tem `username` preenchido), o rótulo saía vazio, e o
telefone — buscado depois por esse rótulo/userid — não tinha o que exibir
junto.

Fix de uma linha na origem: a SQL de `PlantaoList` ganhou
`COALESCE(ur.username, '') AS reserva_username`, e a chamada de `userLabel()`
passou a usar essa coluna em vez de `''`. Efeito colateral aceito e
intencional: reserva com cadastro **removido** (userid órfão) agora aparece
como `(removido)` em vez de célula em branco no calendário/tooltip — mesmo
padrão já usado para "Turno removido"; célula vazia sem explicação era pior
sinal de bug do que um rótulo dizendo o que aconteceu.

### `<select>` desalinhado no Repasse (2026-08-24)

Os campos `<select>` de "Gerenciar Turnos" (`.rp-nh-input`/`.rp-input`)
herdavam `height`/`padding` de regra pensada para `<input type="text">` — o
CSS global do tema do Zabbix tem uma regra de maior especificidade para
elementos de formulário nativos e um `<select>` respeita métricas de caixa
diferentes de um `<input>` no mesmo bloco de estilo. Resultado: o combobox
ficava mais baixo/alto que os campos de texto ao lado, na mesma barra. É o
mesmo conflito de CSS conhecido que a skill `zabbix-module-dev` já documenta
(Critical Rule #20) — corrigido com o mesmo workaround: `height`/`min-height`/
`padding`/`box-sizing`/`line-height`/`appearance` explícitos com `!important`,
escopados a `select.rp-nh-input`/`select.rp-input` (não afeta os `<input>`
vizinhos, que já estavam certos).

### Testes automatizados (2026-08-24)

Sem `composer.json`/`vendor/` no repo (módulo Zabbix, não pacote Composer) —
instalar PHPUnit só pra 4 arquivos de teste seria mais atrito que valor, em
especial perto do `/usr/share/zabbix/modules/` de produção. `tests/bootstrap.php`
é um runner de ~90 linhas sem dependência (`php tests/run.php`, sai com código
1 se algo falhou — dá pra plugar em CI/pre-commit).

Só é testável fora do runtime do Zabbix a lógica **pura**, sem
`\DBselect`/`\CWebUser`/`ZbxDb` reais: `UserLabel`/`formatUserLabel()`
(dedup de nome), `PhonesFormat` (máscara de telefone), `SqlFn` (dialeto
MySQL × PostgreSQL — inclusive a regressão documentada do `HH` de 12h no
`to_char` do PG) e `TurnosReportBase::sanitizeNoteHtml()` (a mesma suíte que
o CLAUDE.md já citava como "`test_sanitizer.php`, não versionada — rodar de
novo se mexer nessa função"; agora está versionada em
`tests/SanitizeNoteHtmlTest.php`, com o bypass aninhado `<iframe><span
onclick>` e os esquemas `javascript:`/`data:` cobertos). Controllers e
consultas que dependem de `ZbxDb $db` continuam só verificáveis no lab —
não tem como isso ser unitário sem simular o Zabbix inteiro.

Técnica usada para testar trait sem carregar o framework: uma classe
"harness" mínima que só faz `use \Modules\Plantonistas\Actions\<Trait>;` e
expõe os métodos privados do trait por wrappers públicos — método privado de
trait vira privado da classe que o compõe, e um wrapper na mesma classe pode
chamá-lo. `SqlFn::tryLock()`/`releaseLock()` chamam o `\zbx_dbstr()` real do
Zabbix (função global, não existe fora do framework) — o bootstrap ganhou um
polyfill guardado (`function_exists('zbx_dbstr')`) só para os testes, nunca
usado em produção.

**Nota de transparência**: os testes foram escritos e revisados manualmente
linha a linha contra o código-fonte de cada trait (não há caminho de execução
automatizada nesta sessão — o shell do ambiente ficou indisponível o tempo
todo, ver commit/próximos passos). Rodar `php tests/run.php` antes do deploy
é o passo que falta para confirmar que passam de verdade.

### Histórico de ações e 2 tabelas novas no Repasse (2026-08-28, v5.2.0)

Pedido do Rafael: "dá pra ver as ações que aconteceram nos alarmes" (Herdados
e Sem ACK) e "cadastrar tabelas novas de alarmes em tratativas e alarmes
resolvidos (histórico do turno)". Perguntado antes de mexer (4 decisões, ver
histórico de perguntas): "ação" cobre TANTO o que o analista fez na mão
quanto o que o Zabbix notificou sozinho; "em tratativas" é o complemento
direto de "sem ACK" (aberto no turno + já tem ação); "resolvidos" mostra MTTR
e quem fechou; as 3 coisas entram na tela, no PDF e no documento de Fechar
Turno.

**De onde vêm as "ações"**: duas tabelas nativas do Zabbix, nunca antes
consultadas pelo módulo.

- `acknowledges` — toda atualização de problema (o que a tela "Update problem"
  do Zabbix grava): `action` é um **bitmask** (confirmado em
  `include/defines.inc.php` do Zabbix 7.0, não documentado num lugar só nos
  manuais): `0x01`=fechar, `0x02`=ACK, `0x04`=mensagem, `0x08`=severidade,
  `0x10`=remover ACK, `0x20`=suprimir, `0x40`=remover supressão,
  `0x80`=marcar como causa, `0x100`=marcar como sintoma. Uma linha pode somar
  vários bits (ACK + mensagem + fechar de uma vez, tudo no mesmo clique) —
  `decodeUpdateAction()` decompõe em um badge por bit, e um evento pode
  acumular vários badges de uma linha só. `action=0` é o formato ANTIGO
  (pré-5.4, só booleano) — tratado como ACK simples, não como "nada".
- `alerts` — o que as Ações (Actions) configuradas no Zabbix efetivamente
  dispararam (e-mail/SMS/webhook), com status de envio
  (`ALERT_STATUS_NOT_SENT/SENT/FAILED/NEW`, também confirmadas no
  `defines.inc.php`).

**Armadilha que não deu pra fechar com 100% de certeza, e a saída escolhida**:
threads antigas da comunidade Zabbix (2009-2011) relatam que `alerts.eventid`
de uma notificação de RECUPERAÇÃO pode não ser o eventid do problema original
— e a coluna `alerts.p_eventid` existe exatamente por causa desse pedido
histórico de correlação. Não achei fonte oficial que feche a semântica exata
pra 7.0 (se `p_eventid` hoje é só isso ou também serve pra correlação
causa/sintoma). Em vez de arriscar não mostrar notificação de recuperação
nenhuma, a query busca por `eventid IN (...) OR p_eventid IN (...)` e ancora
no que bateu — cobre os dois cenários sem exigir certeza sobre qual é o
comportamento real. Documentado no código para não ser tratado como "decisão
óbvia" numa próxima leitura.

**Uma query só para as 4 tabelas**: `queryEventActions()` recebe os eventids
já trazidos por Herdados/Sem ACK/Em Tratativas/Resolvidos e devolve tudo
indexado por eventid — mesma regra de sempre (informação acessória não entra
por JOIN na query principal; se `acknowledges`/`alerts` falharem, a lista de
alarmes continua na tela, só a coluna Ações fica vazia). A formatação em HTML
(chip colorido por tipo, tooltip com quem/quando/mensagem) É duplicada entre
a view e `TurnosReportPdf` — mas só ISSO; a query e a decodificação do
bitmask vivem uma vez só no trait, porque tanto a view quanto o PDF usam
`TurnosReportBase` e conseguem chamar `queryEventActions()` diretamente. Não
é o mesmo caso de `rp_sevLabel()`/`sevLabel()` (que duplicam a função
inteira porque a view não tem acesso a `$this` do controller) — aqui só a
etapa de "virar HTML" precisou de duas cópias.

**"Alarmes em Tratativas"** (`queryInProgressAlerts()`) é literalmente
`queryUnackedAlerts()` com `EXISTS` no lugar de `NOT EXISTS` — mesmo escopo
(eventos abertos DURANTE o turno), sem filtrar por estado atual (aberto ou já
resolvido não importa, mesmo espírito da tabela irmã). Foi a opção que o
Rafael escolheu explicitamente entre as duas oferecidas.

**"Alarmes Resolvidos"** (`queryResolvedAlerts()`) segue a MESMA escolha de
fonte que `queryInheritedAlerts()` já tinha feito por performance: tabela
`problem`, não `events`+`event_recovery` (a versão por `events` já tinha dado
200s+ sem terminar — PERF FIX v2.4.4, ver acima). O preço é o mesmo que
Herdados já paga: `problem` só guarda o problema resolvido até o housekeeper
limpar, então um turno de meses atrás pode aparecer vazio aqui mesmo tendo
tido resolução — documentado na tela (`.rp-card-desc`) e no README, não só no
código, porque é o tipo de coisa que gera chamado de "sumiu dado" sem ser bug.
MTTR é `r_clock - clock`, mesmo padrão de `age_seconds` de Herdados. "Quem
resolveu" NÃO tem subquery própria: é derivado do resultado de
`queryEventActions()` (`closed_by`, preenchido quando há um item
`type=close`) — se não tem `closed_by`, foi resolução automática (trigger
voltou ao normal sozinha). Evita duas consultas fazendo a mesma pergunta.

**Achados no caminho, corrigidos junto**:

- `TurnosReportPdf.php` já referenciava `.bg-blue`/`.bg-red`/`.bg-orange`/
  `.bg-yellow`/`.bg-purple`/`.bg-green` nos ícones dos KPIs (e a lista de
  print-color-adjust do CSS também citava essas classes) — mas elas nunca
  foram DEFINIDAS em `turnos.report.css`. Os KPIs do PDF sempre renderizaram
  sem o círculo colorido de fundo, um degrau visual que ninguém notou porque
  não dá erro nenhum, só um ícone "pelado". Definidas agora, e os 2 KPIs
  novos (Em Tratativas/Resolvidos) já nascem certos.
- `.rp-kpi-grid` era `grid-template-columns: repeat(6, 1fr)` fixo. Com 7 KPIs
  (os 2 novos), o 7º ficava sozinho numa linha nova ocupando 1/6 da largura.
  Virou `repeat(auto-fit, minmax(150px, 1fr))` — acomoda qualquer contagem
  sem precisar editar de novo a cada KPI adicionado.

**Snapshot de Fechar Turno**: `snapshotVersion()` subiu de 1 para 2 (só como
registro — não foi preciso migrar nada, porque `TurnosReportPdf` já lê
`in_progress`/`resolved`/`actions` com `?? []`, então um snapshot v1 antigo
simplesmente mostra essas 3 seções vazias, sem quebrar).

**Bitwise em SQL, decidido por evitar, não por incompatibilidade**: o MySQL e
o PostgreSQL têm o MESMO operador `&` para AND bit a bit — dava para calcular
"fechado manualmente" com uma subquery `(ak.action & 1) = 1` sem problema de
dialeto nenhum. Mesmo assim, a decisão foi NÃO fazer isso: como
`queryEventActions()` já decompõe o bitmask em PHP pra montar os badges,
calcular "quem fechou" de novo em SQL seria a mesma lógica escrita duas
vezes, uma em cada linguagem — decidiu-se reaproveitar o resultado de
`queryEventActions()` (ver acima) em vez de introduzir uma segunda fonte de
verdade para a mesma pergunta.

### Repasse quebrado em produção: `</script>` dentro do próprio comentário que avisava sobre `</script>` (2026-08-28, v5.2.1)

A 5.2.0 (histórico de ações) subiu quebrada: MTTA por Hora, Distribuição por
Severidade e o heatmap de 30 dias pararam de renderizar, e o fim da página
mostrava um bloco enorme de texto cru — pedaços do próprio JS do relatório
aparecendo como conteúdo da tela.

**Causa**: o comentário que documenta por que `SEV_LABELS` usa `JSON_HEX_TAG`
("sem o flag, um nome contendo `</script>` sairia cru...") está dentro de um
`<script>` **estático** (fora de `<?php ?>`, a partir da linha 705) — ou seja,
vai para o navegador exatamente como está escrito no arquivo, comentário e
tudo. O parser HTML procura a sequência de fechamento de `<script>` em
QUALQUER lugar do texto, inclusive dentro de comentário JS — ele não sabe (nem
liga) que `// ...` é comentário. Ao digitar a sequência por extenso dentro do
próprio aviso, o comentário fechou a tag no meio do arquivo, e tudo que vinha
depois (o resto do bloco `<script>`, incluindo os dois gráficos Chart.js e o
IIFE do heatmap) virou texto solto de página em vez de JavaScript executado.

Confirmado por eliminação: `grep -c '</script' views/plantonistas.report.view.php`
tinha só essa ocorrência fora de um bloco `<?php ?>` — os outros 5 matches no
arquivo (linhas do comentário sobre o Chart.js e as duas tags de fechamento
reais) estão todos dentro de `<?php ?>` ou são o fechamento de verdade, então
nunca chegam ao navegador como texto.

**Lição que fica**: em QUALQUER `<script>` estático deste módulo (fora de
`<?php ?>`), nunca escrever a sequência de fechamento da tag por extenso — nem
em comentário, nem em string, nem explicando por que ela é perigosa. Se
precisar documentar o risco, descrever em prosa ("a tag de fechamento do
elemento script") em vez de digitar os caracteres. Dado real vindo do banco
(nome de severidade, mensagem de ACK, etc.) já tinha essa proteção
(`JSON_HEX_TAG`, `htmlspecialchars()`); o que faltou proteger foi o
comentário estático escrito à mão pelo próprio desenvolvedor — a fonte do
risco não precisa ser input de usuário, o código-fonte literal do módulo
também é "conteúdo dentro de `<script>`".

Fix: reescrita do comentário sem a sequência literal, com um aviso extra
("ATENÇÃO ao editar") pra não reintroduzir o mesmo bug na próxima explicação.

### Lupa das 4 tabelas de alarme abria a lista geral, não o alarme clicado (2026-08-28, v5.2.1)

O ícone de lupa ("Ver no Zabbix") nas 4 tabelas de alarme (Herdados, Sem ACK,
Em Tratativas, Resolvidos) linkava para
`problem.view&filter_name=<trigger_desc>` — busca textual pelo NOME do
problema, não o alarme específico. Confirmado contra o código-fonte oficial
(`CControllerProblemView::checkInput()`, Zabbix 7.0): a lista de campos
aceitos por `problem.view` é `groupids/hostids/triggerids/name/severities/...`
— não existe parâmetro de eventid ali, então não há como `problem.view` abrir
UM alarme específico; `filter_name` sempre cai na lista inteira filtrada por
texto (e dois triggers com descrição parecida, ou o mesmo trigger disparando
de novo depois, aparecem juntos).

A página nativa certa é `tr_events.php` (`ui/tr_events.php`, ainda presente no
Zabbix 7.0), que aceita `triggerid`+`eventid` exatos e renderiza "Detalhes do
evento" — a mesma tela que abre ao clicar num problema na lista nativa do
Zabbix. `rp_eventLink($triggerid, $eventid)`, nova função na view, monta esse
link; sem triggerid (linha antiga que não tinha a coluna, ou consulta que
falhou) cai no link antigo por nome — pior que o ideal, mas não quebra a
tela. As 4 queries (`queryInheritedAlerts`, `queryUnackedAlerts`,
`queryInProgressAlerts`, `queryResolvedAlerts`) ganharam `MIN(t.triggerid) AS
triggerid` — `t.triggerid` já estava no JOIN de todas (`t.triggerid =
ev.objectid`/`p.objectid`), só não estava no SELECT.

Os links de Host e de Problema (colunas separadas, que abrem `problem.view`
filtrado por nome) **não foram tocados** — filtrar pelo nome do host/trigger
pra ver o histórico dele é o uso pretendido ali; só a lupa (que promete "ver
ESTE alarme") estava linkando errado.

### Auditoria de queries e escrita (2026-08-31)

Revisão criteriosa do módulo inteiro (queries, código, segurança). O que foi
corrigido, do mais grave para o mais simples:

**A multiplicação de linhas do JOIN `functions`/`items` inflava 5 números da
tela.** A cadeia `events → triggers → functions → items → hosts` devolve UMA
LINHA POR ITEM referenciado na expressão da trigger — uma trigger com 2 itens
duplica cada evento. `queryTopHosts`/`queryTopTriggers` já usavam
`COUNT(DISTINCT ev.eventid)`; cinco pontos não usavam:

- `queryEventTotals`: os recortes vinham de `SUM(CASE ...)` enquanto o total
  vinha de `COUNT(DISTINCT ...)` — crítico + médio + baixo somavam MAIS que o
  total, no mesmo SELECT.
- `querySeverityDistribution`: `COUNT(*)` — a rosca mostrava mais eventos que
  o KPI ao lado.
- `queryCalendarHeatmap`: célula com mais "críticos" que eventos no dia.
- `queryMTTA`: `COUNT(*)` contava o mesmo ACK várias vezes e o `AVG` ficava
  ponderado pelo número de itens da trigger — evento de trigger com 3 itens
  pesava 3× no MTTA do analista, e o MTTA global herdava a distorção.
- `queryMttaTimeline`: mesma ponderação indevida no gráfico por hora.

Correção: `COUNT(DISTINCT ... CASE ...)` nos agregados e `SELECT DISTINCT` na
subconsulta do MTTA (as colunas são idênticas nas linhas duplicadas, então o
DISTINCT colapsa exatamente o excesso). O `queryMttaTimeline` virou média sobre
subconsulta com DISTINCT. **Regra que fica: agregado sobre essa cadeia de JOIN
sempre por evento distinto, nunca `COUNT(*)`/`SUM(CASE ...)`.**

**`LIMIT 50` virava o valor do KPI.** As 4 tabelas de alarme cortam em 50
linhas e a tela imprimia `count()` desse array no card: 213 alertas sem ACK
apareciam como "50", sem nada indicando corte. Agora a consulta pede 51 (a
linha a mais é sonda), `capAlertRows()` corta e devolve se houve truncamento, e
a tela/PDF mostram "50+" com aviso no rodapé da tabela. Sem consulta extra de
COUNT. O snapshot de Fechar Turno grava a marca junto (`snapshotVersion()` foi
para 3; documento v1/v2 sem a chave = "não houve corte").

**Escrita não conferida na família escala.** `\DBexecute()` devolve `false` em
erro — não lança — e o retorno era ignorado em `PlantaoSave`, `PlantaoDelete`,
`PlantaoImport` e `PhonesSave`. INSERT recusado pelo banco seguia como sucesso:
"30 linhas importadas" sem nada gravado, "Plantão removido" com a linha ainda no
calendário. E o `try/catch` em volta dos laços nunca disparava, porque não havia
exceção. Novo trait `actions/DbWrite.php` com duas formas: `dbExec()` lança
(dado principal) e `dbExecOptional()` só loga (histórico — acessório não derruba
o principal). A mensagem da exceção é **fixa e sem SQL**, porque o import a ecoa
na lista de avisos da tela.

**`WHERE al.eventid IN (...) OR al.p_eventid IN (...)`** virou `UNION ALL` de
dois ramos, cada um filtrando por uma coluna: o OR entre colunas diferentes
tirava o índice e varria a tabela `alerts`. O segundo ramo exclui o que o
primeiro já trouxe, e a âncora do evento sai pronta do SQL.

**`MYSQLI_ASSOC` no `cron_sync_oncall`** (3 ocorrências): o script fala com o
banco pelo `CliDb`, que é PDO. Resolver a constante exige `ext-mysqli` — em
host sem ela (o frontend PostgreSQL, que é a razão de o CliDb existir) o PHP 8
aborta com "Undefined constant" e o cron morre. O argumento era ignorado.

**Migração de schema rodava em TODA requisição do frontend.** O `init()` é
chamado a cada página do Zabbix, para todo usuário, e disparava 3 consultas ao
`INFORMATION_SCHEMA` (tabelas, colunas, índices) — que em MariaDB obrigam a
abrir a definição de cada tabela. Agora há marcador em `sys_get_temp_dir()`
com banco+servidor+`SCHEMA_VERSION` no nome, revalidado a cada 24 h (rede de
segurança para tabela removida na mão ou restore por cima). **Ao adicionar
migração nova, subir `Module::SCHEMA_VERSION`** — é ele que invalida o
marcador. Perder o marcador é inofensivo: a migração é idempotente.

**N+1 em Gerenciar Turnos.** A tela chamava `listShiftsByGroup()` e
`listUsersByGroup()` dentro do laço de equipes — Super Admin com 40 grupos
abria 80 consultas. Novos `listShiftsByGroups()`/`listUsersByGroups()` fazem
duas, para todas as equipes. Os métodos no singular continuam no trait (sem
chamador hoje) para não mexer em contrato que outra tela possa vir a usar.

**`host_filter` com dezenas de KB de ids.** `resolveUserContext()`
materializava TODOS os hostids visíveis num `IN (...)` literal, repetido em ~10
consultas por carga de página. Agora a leitura tem `LIMIT 501`: até 500 hosts o
filtro continua sendo a lista literal de sempre (plano conhecido, testado em
produção), acima disso vira subconsulta com aliases `_hf` (escolhidos para não
colidir com `ev`/`t`/`f`/`i`/`h`/`p`/`ak` nem com o `ugx` do
`enabledUserClause()`). "Sem rights → sem filtro" continua igual.

**Segurança:**

- `TurnosNotesSave`/`TurnosNotesGet` ecoavam `$e->getMessage()` para o
  navegador — e a `\RuntimeException` do `ZbxDb` carrega o **SQL montado
  inteiro**, com o texto da nota dentro. Mensagem fixa + `error_log`, como o
  `TurnosReportClose` já fazia.
- `TurnosNotesGet` era a única action sem `validateInput()` (lia `$_GET`/
  `$_POST` direto). Passou a validar como as outras. Continua sem chamador,
  mas a rota existe no manifest e é alcançável por URL.
- Nota com `notes_format='html'` é impressa crua confiando na sanitização
  feita no save — qualquer falha futura do `sanitizeNoteHtml()`, ou uma linha
  inserida por fora do módulo, viraria XSS armazenado permanente. Novo
  `sanitizeNotesForDisplay()` sanitiza de novo na exibição (`queryNotes()`,
  `TurnosNotesGet` e o caminho de snapshot do PDF).
- `CURRENT_FULLNAME` saía por `addslashes()`, que não trata quebra de linha
  nem U+2028/U+2029 (fim de instrução em JS). Virou `json_encode()` com
  `JSON_HEX_TAG`, como todas as outras constantes do mesmo bloco.

**Outros:**

- Relatório e PDF não tinham tratamento nenhum nas consultas principais: falha
  virava 500 mudo. Agora cai no mesmo aviso já usado para "sem conexão", com a
  tela renderizando vazia e `[plantonistas]` no log.
- `queryNotes()` era o último ponto com `prepare()`/`bind_param()` FORA do try.
- Corrida no upsert da escala: `PlantaoSave`/`PlantaoImport` faziam
  SELECT-then-INSERT contra a unique key `uniq_group_day_shift`. Agora usam
  `SqlFn::upsert()`, como `PhonesSave`/`PhonesImport`.
- `PlantaoList` passava `''` no lugar do username ao montar o rótulo do
  técnico (mesmo defeito corrigido para o reserva em 2026-08-24) e a view
  tapava o buraco repetindo a regra com `CONCAT` cru. Corrigido na origem, e o
  remendo saiu da view.
- Fallback de aba do XLSX **nunca funcionou**: montava `xl/worksheets/rId1.xml`
  a partir do ID de RELACIONAMENTO. O caminho real está em
  `xl/_rels/workbook.xml.rels` — planilha com a primeira aba fora do nome
  padrão voltava "formato não reconhecido".
- Busca da sessão do dia no cron de presença usava
  `CAST(session_start AS DATE) = ...`, que não usa o índice; virou intervalo.
- Rodapé do Repasse dizia "v2.5.0" desde o fork; agora lê a versão do
  `manifest.json`.

### Lista de repasses abertos e fechados (2026-08-31, v5.4.0)

Pedido do Rafael: "ao clicar em ver repasse ou fechar plantão ele mantém o
registro dos outros" — investigado e **não é bug de dado**. O fechamento
sempre foi append-only e `findClosedReport()` sempre filtrou por (data,
turno); o que existia era um buraco de navegação: o banner do Repasse mostra
só o ÚLTIMO fechamento, e os anteriores ficavam sem porta — chegava neles
quem soubesse o `report_id` na mão, na URL do PDF. Pediu então uma tela de
lista, com o clique abrindo o documento e o download em PDF.

Decisões respondidas por ele antes de implementar: "aberto" é turno **com
atividade e sem fechamento** (não "todos os turnos dos últimos N dias"); a
tela entra **no menu E num botão** do cabeçalho do Repasse; o clique abre o
**documento congelado com botão de baixar**, não o PDF direto.

**Atividade = nota no Diário de Bordo.** É o rastro barato de que alguém
trabalhou aquele turno: tabela do módulo, indexada por `shift_date`. Varrer
`events` por trinta dias, turno a turno, para descobrir a mesma coisa é
exatamente a consulta que já derrubou este módulo uma vez (PERF FIX v2.4.4).
Consequência aceita e escrita na tela: turno movimentado em que ninguém
escreveu nada não aparece como aberto.

**A armadilha das duas colunas de mesmo nome**, que era o jeito fácil de
errar: `shift_notes.shift_name` guarda o NOME legível ("Diurno") e o código
fica em `shift_id`; `shift_reports.shift_name` guarda o próprio CÓDIGO ("24h"
ou o id numérico). Casar shift_name com shift_name nunca reconheceria um
turno cadastrado — cada turno com fechamento apareceria DUPLICADO, uma vez
como fechado e outra como aberto. A chave dos dois lados é montada como
`data|código`, com o código derivado de `COALESCE(shift_id,0)`.

Outras decisões:

- **Uma linha por documento**, não por (data, turno): mostrar só o vigente
  reproduziria o buraco que a tela existe para tapar. O refechamento aparece
  como `refeito #N`, em cinza — está lá, mas não disputa atenção com o
  vigente, que é o do topo.
- **A visibilidade não ganhou regra nova.** Fechado passa pelo mesmo
  `canReadSnapshot()` do PDF; aberto vem da contagem de notas, que já é
  segmentada por grupo compartilhado. Tela de listagem é justamente onde uma
  regra "parecida" viraria vazamento: a lista revelaria a EXISTÊNCIA do
  documento que a tela de detalhe recusa a abrir.
- **`report_json` não é lido para Super Admin.** É LONGTEXT, e num intervalo
  largo seriam megabytes trazidos do banco só para decidir algo que
  `canReadSnapshot()` decide na primeira linha, sem olhar o conteúdo.
- **Teto de 500 fechamentos, lendo 501.** A linha extra não vai para a tela —
  serve para saber se sobrou coisa fora da janela e avisar. Sem o aviso, a
  lista cortada pareceria completa.
- **Falha na contagem de notas tem aviso próprio**, separado do erro dos
  fechados: é ela que DEFINE o turno aberto, então quebrar em silêncio faria
  a tela dizer que está tudo fechado — a conclusão mais perigosa possível
  para quem usa a lista para saber o que falta fechar.
- **Intervalo invertido é corrigido, não obedecido**: `from > to` troca a
  ordem. Devolver zero linhas mandaria procurar um repasse que existe.
- **"Baixar PDF" é `window.print()`** — o módulo nunca gerou PDF no servidor;
  quem produz o arquivo é o navegador, e o `<title>` já montado vira o nome
  sugerido. A barra usa classe própria (`.rp-doc-bar`) e NÃO `.rp-nh-btn`:
  aquela é escondida com `display:none!important` na folha do PDF, o que está
  certo para filtro e "fechar turno" e seria absurdo para o botão de
  imprimir. O que tira a barra do papel é o `@media print`.
- **`plantonistas.report.pdf` saiu dos aliases do Repasse** e foi para o item
  novo: alias serve para manter o item do menu marcado, e deixar nos dois
  marcaria dois itens ao mesmo tempo.
- Atalhos de 7/30/90 dias montam a data por `getFullYear/getMonth/getDate`,
  nunca por `toISOString()` — em UTC-3, das 21h em diante o ISO devolve a
  data de amanhã e o período inteiro desliza um dia. Sexto lugar do módulo em
  que fuso ia morder.

**Quatro defeitos achados na revisão da própria implementação**, todos do
tipo que só aparece em produção:

- **`CAST('' AS TEXT)` não existe no MySQL.** A coluna de reserva que evita
  ler o JSON no ramo Super Admin nasceu com o CAST do PostgreSQL escrito para
  os dois bancos — e o CAST do MySQL não aceita TEXT como destino (a lista
  dele é CHAR, BINARY, DATE, DECIMAL…). A tela cairia **só para Super Admin**,
  e só no banco de produção. Agora o CAST é emitido apenas no ramo PG.
- **`<=>` no código do turno não é transitivo.** O código é `'24h'` ou o id do
  turno em texto (`'9'`, `'12'`). No PHP 8, duas strings numéricas comparam
  como NÚMERO e uma numérica contra uma não-numérica compara como TEXTO —
  `'9' < '12' < '24h' < '9'` fecha um ciclo, e comparador não transitivo faz o
  `usort` devolver ordem indefinida: os refechamentos deixariam de ficar logo
  abaixo do vigente, que é a única pista de qual documento vale. Virou
  `strcmp()`.
- **Turno renomeado dividia a contagem de notas.** As notas guardam o nome
  legível da época e o `shift_id`; agrupar por nome quebrava o mesmo turno em
  duas linhas — contagem pela metade e um "última nota" vindo da linha que o
  banco devolvesse primeiro. O agrupamento passou a ser por `shift_id`, com o
  nome saindo por `MAX()`; o nome só continua no agrupamento quando não há id
  (turno legado, onde o nome É o código — sem isso 24h/manhã/tarde/noite do
  mesmo dia virariam uma linha só).
- **`fetch_all()` de 500 snapshots estourava o `memory_limit`.** Um fechamento
  v2 passa fácil de 100 KB de JSON, e o filtro de visibilidade precisa lê-lo
  linha a linha. Materializar tudo de uma vez é falha fatal sem log útil —
  agora é `fetch_assoc()` num laço, descartando o JSON a cada volta.

Prefixo CSS `rpl-` para a tela nova, seguindo "um prefixo por tela". Entrou
junto `.rp-alert-warn` (aviso âmbar): só existia `.rp-alert-danger`, e pintar
de vermelho um aviso de lista cortada manda procurar um problema que não há.

**Duas assimetrias conhecidas, aceitas e escritas na tela:**

- **"Aberto" quer dizer "sem fechamento visível PARA VOCÊ".** As duas fontes
  usam regras diferentes por construção: fechado exige que os grupos do autor
  caibam nos do leitor (`canReadSnapshot()`), nota exige compartilhar ao menos
  um grupo (`sameGroupExists()`). Autor em `[NOC, Redes]` e leitor em `[NOC]`:
  o documento some da lista, as notas dos analistas de NOC continuam contando,
  e a linha aparece como aberta. Um Admin nessa situação pode refechar sem
  saber — a regra de "refechar exige Admin+" continua valendo, porque
  `countClosedReports()` não filtra visibilidade de propósito. A tela avisa em
  texto, para quem não é Super Admin.
- **Turno REMOVIDO gera linha aberta fantasma.** `TurnosNotesSave::
  resolveShiftName()` devolve `'24h'` quando o turno não é encontrado, então
  nota escrita depois da remoção cai na chave `data|24h` enquanto o fechamento
  segue em `data|12` — o turno aparece fechado e um "24h aberto" surge do
  nada. Comportamento antigo do save de nota, não desta tela; a lista só é o
  primeiro lugar onde ele fica visível. Ver Backlog.

**Não validado no lab** — `listClosedReports()` e `countNotesByShift()`
dependem de `ZbxDb $db` e não entram na suíte de testes puros. Ver Backlog.

### Host visível, idade no formato do Zabbix e tema escuro neutro (2026-09-02, v5.4.2)

Dois pedidos do Rafael sobre o Repasse, um de dado e um de cor.

**1. Coluna Host mostrava o nome TÉCNICO.** As quatro consultas de alarme já
traziam `MIN(h.name) AS host_name` ao lado de `MIN(h.host) AS host` — quem
renderizava é que pegava o `host`. Trocado por `rp_hostLabel()` (view) /
`hostLabel()` (PDF), que devolve o visível e só cai no técnico se ele vier
vazio. Em ambiente onde ninguém preenche "Nome visível" os dois são iguais e
nada muda; onde muda (host cadastrado por IP, host renomeado para o cliente) o
relatório passa a falar o mesmo nome que o resto do Zabbix. Aplicado nas quatro
tabelas de alarme e em Top Hosts — a mesma coluna nas cinco, senão o relatório
diria dois nomes para o mesmo host na mesma página. O link mudou junto (o
`filter_name` leva o nome exibido), porque um link que busca um texto diferente
do que está escrito nele é pior que o nome errado.

**2. Idade fora do padrão.** `rp_duration()` (usada também em MTTA/MTTR)
satura em horas: um alarme de 40 dias saía como "960h 12m". A coluna Idade
passou a usar `rp_age()`, e quem formata é a função NATIVA `convertUnitsS()`
— a mesma que `zbx_date2age()` chama por baixo da coluna "Duração" de
Monitoramento > Problemas. Sai "1M 3d 4h", "2d 5h 50m", "3h 55m 20s": até três
unidades, começando na maior que existe e pulando as vazias.

*Por que a nativa e não uma reimplementação:* "padronizar com o Zabbix" e
"copiar a regra do Zabbix" divergem no primeiro upgrade que mexer na regra. O
fallback local (para o caso improvável de a função global sumir) reproduz o
algoritmo — ano = 365 dias, mês = 30 dias, corte três níveis abaixo do primeiro
preenchido, segundos só quando o maior nível é hora ou menos, e os 12 meses
inteiros que viram um ano. Conferido contra uma cópia literal do
`convertUnitsS()` nas 260.016 durações de 1s a 3 anos: nenhuma divergência.
`rp_duration()` continua servindo MTTA/MTTR, que são duração medida e não idade
de alarme.

**3. Tema escuro destoante.** O Repasse tinha paleta escura PRÓPRIA, azul-ardósia
(card `#2b3c51`, borda `#3d5166`, faixa `#121c29`, azul `#02a0ff`), dentro de um
Zabbix cinza-neutro (`#0e1012` de página, `#2b2b2b` de tabela, `#383838` de
linha, `#f2f2f2` de texto, `#4796c4` de link). O azul só se percebe ao lado do
cinza — e é exatamente aí que ele aparecia. Os tokens `--rp-*` do tema escuro
passaram a sair de `assets/styles/dark-theme.css`, e o mesmo foi feito na paleta
`.plt-dark` da família escala (texto auxiliar azulado, bordas claras demais).
É a escolha que os tokens `--cs-*` do CrowdStrike já fazem na suíte.

Três coisas que o ajuste de cor obrigou a corrigir, todas invisíveis no tema
claro:

- **`--rp-dark` era superfície E tinta.** `.rp-kpi-val` e `.rp-hm-month` pintavam
  texto com a cor da FAIXA do topo: no escuro, `#121c29` sobre card escuro — o
  numeral do KPI existia e não se lia. Virou `--rp-strong`, token separado.
- **Chips pastel do Material** (`.rp-perf`, `.rp-act-*`, `.rp-alert-danger`) são
  fundo claro com tinta escura: no escuro viravam caixinhas brancas acesas.
  Passaram a fundo lavado (mesma cor em alfa baixo) com a tinta clara do tema
  escuro do Zabbix. **A ordem no arquivo importa:** `.rp-act` e `.rp-act-ack` têm
  a mesma especificidade, então o chip sem tipo próprio vem PRIMEIRO — invertido,
  todo chip fica cinza.
- **Quadradinho "Menos" do mapa de calor** tinha `#ebedf0` escrito na legenda e
  `rgba(255,255,255,.06)` no JS. Só o do JS sabia do tema escuro; agora os dois
  leem `--rp-hm-empty`. Mesmo motivo do `SEV_CUTOUT`: dois literais do mesmo
  valor divergem na primeira vez que um deles é ajustado.

Também: `.rp-ack-yes` e `.rp-resolve-manual` (verde `#2e7d32`, 2,1:1 sobre o card
escuro) e o hover dos links (que ESCURECIA no escuro, apagando o link sob o
ponteiro) seguem agora o verde/azul do tema escuro. A cor da barra do gráfico de
MTTA passou a ser lida do token `--rp-blue` via `getComputedStyle` — canvas não
herda CSS, e era o único lugar em que o `#02a0ff` antigo sobrevivia.

**Não validado no lab** — as duas mudanças são de view/CSS e não entram na suíte
de testes puros. Conferir no lab: coluna Host de um host com nome visível
diferente do técnico; um alarme com mais de um mês na coluna Idade; e as quatro
tabelas + Diário de Bordo nos temas Dark e High-contrast dark.

### Tipografia e cores no padrão do Zabbix (2026-09-02, v5.4.3)

Continuação do ajuste de tema. O Rafael apontou dois lugares — "o nome das
severidades ao lado da pizza está ruim" e "os números do Volume de Alertas
também não" — e pediu para pegar **o padrão do Zabbix** para fonte e cor, não
para escolher outro tom. Foi o que se fez: cada valor abaixo tem origem em
arquivo do frontend, e está anotada no CSS ao lado dele.

**De onde saiu cada coisa**

| O quê | Fonte no Zabbix | Valor |
|---|---|---|
| Fonte | `body` do blue/dark-theme.css | `Arial, Tahoma, Verdana, sans-serif` |
| Corpo de texto | `body` (`font-size: 75%`, `line-height: 1.4em`) | 12px / 1.4 |
| Tinta primária | `body` | `#1f2c33` claro, `#f2f2f2` escuro |
| Tinta de rótulo | `.list-table thead th` | `#768d99` claro, `#737373` escuro (aqui `#9ca1a4`, ver abaixo) |
| Título de card | `.dashboard-grid-widget-header h4` (1,167em) | 14px, negrito, tinta primária |
| Texto sobre severidade | `.disaster-bg`, `.high-bg`, … dos temas | `#4b0c0c`, `#52190b`, `#733100`, `#734d00`, `#00268e`, `#2a353a` |
| Eixo, legenda e grade de gráfico | tabela `graph_theme` do tema ativo | `textcolor` / `gridcolor` |
| Legenda de pizza | `.svg-pie-chart-legend` | embaixo, em colunas, marcador 10×4 |

**Fonte.** A tela era a única da suíte com pilha própria (`-apple-system,
BlinkMacSystemFont, 'Segoe UI'…`) — as telas da família escala nunca
declararam fonte e por isso já herdavam a do Zabbix. Agora as duas famílias
usam a mesma. Junto foi a coluna `.td-mono`, que trocava para uma pilha
monoespaçada só para alinhar hora e duração: os algarismos do Arial já têm
largura fixa, e `font-variant-numeric: tabular-nums` garante o mesmo se a
pilha cair no Tahoma/Verdana. Duas famílias na mesma tela é o tipo de
diferença que se percebe sem se saber nomear.

**Cabeçalho de tabela.** Era 10px em CAIXA ALTA com espaçamento de letra — a
tipografia mais distante do Zabbix que a tela tinha. Virou o `thead th` do
Zabbix: mesmo corpo do resto da tabela, caixa normal, cor de rótulo, borda de
2px. O padding maior ficou: é respiro do card, não tipografia.

**Texto sobre severidade — era chute, e um deles ilegível.** O Zabbix trata
severidade como *fundo configurável + tinta fixa*: `getTriggerSeverityCss()`
(`ui/include/html.inc.php`) gera o `background-color` a partir de
Administração > Geral, e o CSS do tema traz a cor do texto escrita à mão por
classe, igual nos quatro temas. O módulo já lia o fundo da mesma configuração,
mas inventava a tinta: branco sobre o amarelo de Warning dá **1,54:1** e
branco sobre o cinza de "Não classificado", 2,41:1. Com as tintas do Zabbix a
faixa toda fica entre 4,3:1 e 6,6:1. Caixa alta saiu também — o nome da
severidade pode vir traduzido, e versalete estica justamente o mais comprido.

**Mapa de calor: os números.** Duas coisas erradas ao mesmo tempo. A cor do
número era do TEMA (`rgba(0,0,0,.5)` claro, `rgba(255,255,255,.8)` escuro)
enquanto o fundo da célula era da RAMPA, igual nos dois temas — no escuro, o
número saía branco sobre verde-claro. E a rampa era a escala verde do GitHub
com um vermelho solto no fim. Agora a rampa é a escala de severidade de
fábrica do Zabbix (normal → aviso → média → alta → desastre) e cada nível
carrega **o par fundo+tinta**, nas classes `.rp-hm-l0..l5` do CSS — nada de
hex no JS, que era a regra do módulo sendo furada. O número subiu de 9px para
11px: 9px sobre cor é o tamanho em que o algarismo vira mancha.

O nível 0 (dia sem alerta) é a exceção que confirma a regra: ali o fundo É do
tema, então a tinta também é — `#3c5563` no claro (a tinta de rótulo, #768d99,
dá 2,96:1 sobre o cinza vazio e o número some) e `#9ca1a4` no escuro.

**Gráficos.** O Chart.js desenha em canvas, que não herda CSS: sem
`Chart.defaults` ele usa a fonte dele (Helvetica) e um cinza próprio. Agora
`Chart.defaults.font` recebe a pilha do Zabbix em 12px e `Chart.defaults.color`
recebe o `textcolor` do tema de gráfico — que **não é escolhido aqui**: vem da
tabela `graph_theme`, a mesma linha que os gráficos nativos usam, por
`TurnosReportBase::graphTheme()`. Ela existe porque o Zabbix já tem um lugar
canônico (e customizável) para essa cor; inventar um segundo era garantir
divergência na primeira mudança. A armadilha está documentada no método:
`getUserGraphTheme()` devolve o tema AZUL quando não encontra a linha do tema
ativo, o que num frontend escuro daria texto quase preto sobre gráfico escuro
— por isso o retorno só é aceito se o `theme` que veio for o tema ativo.

A cor vem do **controller** e não da detecção por luminância do `_theme.php`:
a heurística acerta "claro ou escuro", que é tudo que ela precisa acertar para
escolher paleta, mas não a cor exata que os gráficos nativos usam. As duas
convivem — `IS_DARK_THEME` continua servindo de rede quando o CSS não carrega.

**Legenda da rosca.** Ganhou o marcador retangular de 10×4 do widget de pizza
do Zabbix (`.svg-pie-chart-legend`) e foi movida para baixo, em colunas.

> **As duas conclusões deste parágrafo estavam erradas** — ver "A cor da
> legenda da rosca não era `labels.color`" (v5.4.4): a mudança de posição foi
> revertida a pedido, e a cor não trocou porque `labels.color` nunca chegava a
> ser lida.

**Não validado no lab** — tudo é view/CSS e não entra na suíte de testes
puros. Conferir: a rosca com os seis nomes de severidade (de preferência num
ambiente com os nomes em PT-BR, que são mais compridos), o mapa de calor com
dias cheios e vazios nos dois temas, e uma tabela do Repasse ao lado de uma
tabela nativa do Zabbix — é a comparação que denuncia diferença de fonte.

### A cor da legenda da rosca não era `labels.color` (2026-09-02, v5.4.4)

Depois do ajuste de tipografia o Rafael respondeu: o mapa de calor ficou bom, a
mudança de posição da legenda não agradou, e **a cor do nome das severidades
continuou preta**. As duas coisas são independentes e as duas foram atendidas —
a posição voltou para a direita, e a cor mudou de verdade agora.

**Por que `labels.color` não fazia nada.** O Chart.js pinta o texto da legenda
com a cor de CADA ITEM, não com a opção:

```js
this.legendItems.forEach((y, v) => { s.strokeStyle = y.fontColor, s.fillStyle = y.fontColor;
```

Quem copia `labels.color` para dentro de cada item são as implementações
**padrão** de `generateLabels()`. Esta rosca tem um `generateLabels()` próprio
(existe para juntar o valor ao nome: "Disaster (12)") e ele nunca devolveu
`fontColor` — então a propriedade chegava `undefined`, o canvas **ignora em
silêncio** um `fillStyle` inválido, e o texto saía com o `fillStyle` que o
contexto tinha: preto. Mexer em `labels.color` não mudava nada porque a opção
nunca era lida; o preto sobre card escuro estava lá desde antes, e sobreviveu
justamente à rodada que foi mexer na cor.

O desenho do marcador não salva a situação: ele roda entre `s.save()` e
`s.restore()`, então a cor que ele usa não vaza para o `fillText` seguinte.

Correção: `fontColor: GRAPH_THEME.text` em cada item devolvido por
`generateLabels()`. `labels.color` fica onde está, para o dia em que este
`generateLabels()` sumir.

**Regra que fica:** ao customizar `generateLabels()` no Chart.js, devolver
`fontColor` junto — toda opção de estilo de texto da legenda passa pelo item, e
a falha é muda.

**Posição.** Voltou para `position: 'right'`. O ganho de diâmetro que a versão
embaixo trazia era real, mas é troca de layout, não padronização — e não foi o
que se pediu.

### Severidade na linha da tabela: só a barra (2026-09-02, v5.4.5)

Pedido do Rafael: tirar a cor da linha inteira nas quatro tabelas de alarme e
deixar só a barrinha da esquerda.

O que existia era meia regra: `.row-disaster` e `.row-high` pintavam o fundo da
linha (`--sev-*-bg`) **e** a barra; as outras quatro severidades pintavam só a
barra. Duas leituras diferentes para a mesma coluna — e num turno ruim, em que a
maioria dos alarmes é High/Disaster, a tabela saía quase toda tingida, que é o
oposto de destacar. Ficou só `border-left-color`, igual para as seis.

Efeitos colaterais tratados:

- **Cinco tokens `--sev-*-bg` ficaram sem uso** e saíram (dos dois temas). Só
  `--sev-disaster-bg` continua: é o hover do botão de remover turno
  (`.rp-action-danger`), que precisa de área vermelha e não de linha.
- **`print-color-adjust` das `.row-*` no PDF fica.** Elas não têm mais fundo,
  mas continuam com a barra, e é ela que precisa sair impressa em navegador
  configurado para não imprimir gráficos de fundo. A lista original já incluía
  `.row-avg`/`.row-warn`, que nunca tiveram fundo — ou seja, sempre foi pela
  barra. Anotado lá, para ninguém "limpar" de novo.
- O comentário do bloco de chips no tema escuro citava a linha tingida como um
  dos fundos que o alfa acompanha; atualizado.

### Chip "Mensagem" abre a mensagem num hintbox (2026-09-02, v5.4.6)

Pedido do Rafael: nas tabelas de alarme, o chip "Mensagem" da coluna Ações só
dizia que existia mensagem — o texto ficava no `title`, que some ao mover o
mouse e não dá para copiar. Agora o chip é clicável e abre a mensagem com quem
a escreveu.

**A caixa é o hintbox NATIVO do Zabbix**, o mesmo componente que a tela
Monitoramento > Problemas usa para mensagens de ACK (`makeEventMessagesIcon()`,
em `ui/include/actions.inc.php`), inclusive com a mesma tabela `list-table` de
três colunas (Hora | Usuário | Mensagem) por dentro. **Não há JS novo:** o
`init.js` do frontend roda `hintBox.bindEvents()`, que delega em `document`
para `[data-hintbox=1]` — marcação renderizada por módulo é atendida igual à do
core. Basta obedecer ao contrato do `CTag::setHint()`:

```
data-hintbox="1"            liga o componente
data-hintbox-static="1"     clique FIXA a caixa (com botão de fechar);
                            passar o mouse continua mostrando
data-hintbox-class=…        classe do invólucro interno
data-hintbox-contents="…"   o HTML da caixa
```

Vale lembrar por que isso funciona atrás do balanceador que bloqueia `.js`
estático: o JS do Zabbix não é servido como arquivo `.js`, e sim pelo
`jsLoader.php` (o `init.js` está no pacote padrão de toda página). É o mesmo
motivo pelo qual a UI nativa funciona lá e o `chart.min.js` do módulo precisa
do modo inline.

Três detalhes que o formato exige, todos anotados na função:

- **`<button>`, não `<span>`.** O handler nativo também escuta `keydown` de
  Enter/Espaço, e só elemento focável recebe esse evento — é como o core faz
  (lá é um `CButtonIcon`). O CSS devolve a aparência de chip.
- **Quebra de linha por `str_replace`, nunca `nl2br()`.** O `nl2br()` INSERE a
  tag e MANTÉM o `\n`, e o `hintBox.createBox()` faz
  `hintText.replace(/\n/g, '<br />')` no que recebe: as duas juntas dobram toda
  quebra de linha da mensagem.
- **Sem `title`.** O hintbox já abre no hover; com o atributo, a dica nativa do
  navegador apareceria por cima da caixa.

**Escape em duas camadas, e as duas são necessárias:** `htmlspecialchars()` no
texto (mensagem de ACK é escrita por analista) e de novo no HTML inteiro ao
entrar em `data-hintbox-contents`. O navegador desfaz a segunda camada ao ler o
atributo e entrega ao JS um HTML em que o conteúdo do usuário já é texto — um
`<script>` digitado na mensagem aparece escrito, não executa.

**Achado no caminho:** `.rp-act-message` estava fora da lista de chips do tema
escuro (a v5.4.3 assumiu que bastavam os tokens `--rp-blue-light`/`--rp-blue`,
que já viram sozinhos). Só que o seletor escuro do `.rp-act` base tem
especificidade maior que a de `.rp-act-message` sozinha, e o chip caía no cinza
dos sem-tipo — justamente o único clicável. Corrigido.

O chip vale nas quatro tabelas de alarme, não só em Herdados: é a mesma coluna
Ações, montada pela mesma `rp_actionChips()`. No PDF nada muda — lá a mensagem
inteira já é impressa na linha de detalhe abaixo do alarme, que é o que serve
no papel.

**Não validado no lab** — `rp_messageHint()` vive numa view e não entra na
suíte de testes puros (mesma limitação de `rp_age()`); o escape foi conferido
fora da tela, com mensagem contendo aspas, quebras de linha e uma tag
`<script>`. Conferir no lab: clique no chip, tecla Enter com o chip focado, e
uma mensagem de várias linhas (para ver se a quebra não dobrou).

### Coluna Ações: todo chip abre caixa, e o alinhamento veio junto (2026-09-02, v5.4.7)

O Rafael notou que o "ACK" ficava mais alto que o "Mensagem" na mesma linha e
pediu para padronizar: qualquer informação da coluna Ações clicável, com caixa.
As duas coisas têm a mesma causa e a mesma correção.

**O desalinhamento era o `stretch` do flex.** Na v5.4.6 só o chip de mensagem
virou `<button>`; o resto continuou `<span>`. Em `.rp-act-list`
(`display: flex`) o padrão é `align-items: stretch`: os dois esticam para a
altura da linha, mas um `<button>` centraliza o próprio texto e um `<span>`
deixa o texto no topo. Daí o "ACK" alto e o "Mensagem" no meio — nada a ver com
padding ou fonte. Agora todo chip é botão **e** `.rp-act-list` ganhou
`align-items: center`: o alinhamento passa a ser garantido pela regra, não pela
coincidência de todos os chips serem do mesmo elemento.

**`rp_messageHint()` virou `rp_actionHint()`** e atende os cinco tipos. A caixa
continua sendo o hintbox nativo (ver a entrada da v5.4.6 para o contrato do
`CTag::setHint()` e as armadilhas do `nl2br()`/`title`); o que muda é a terceira
coluna da tabela, que acompanha o tipo da ação:

| Tipo do item | Cabeçalho | Conteúdo |
|---|---|---|
| `message` | Mensagem | o texto escrito, com as quebras de linha |
| `severity` | Severidade | "Average → High", nos nomes reais do Zabbix |
| `notify` | Status | "Enviada" / "Falhou: …" — e Usuário sai como travessão, porque quem disparou foi o Zabbix |
| `ack`, `close`, `unack`, `suppress`, `unsuppress`, `rank_*` | Ação | o próprio rótulo; o que interessa é quem fez e quando |

Cabeçalho por tipo e não um "Detalhe" genérico porque "Mensagem" e "Enviada"
não são a mesma informação com nomes diferentes — e a caixa é lida por quem
está pegando o turno, não por quem escreveu o código.

O ganho não é só visual: o que estava só no `title` — que some ao mover o mouse
e não dá para copiar — passou a ter um lugar fixo e igual para toda ação.

**Hover do chip:** contorno em `currentColor` (`box-shadow: inset`) em vez de
`filter: brightness()`. Brilho escurece no tema claro e apaga no escuro, ou
seja, precisaria de duas regras para dizer a mesma coisa; `currentColor` já é a
tinta certa nos dois.

No PDF nada muda: lá os chips continuam `<span>` com `title`, e a mensagem
inteira já é impressa na linha de detalhe abaixo do alarme — caixa que abre com
clique não serve para papel.

**Não validado no lab** — `rp_actionHint()` vive numa view e não entra na suíte
de testes puros. Conferida fora da tela a saída dos cinco tipos, incluindo
mensagem com aspas, quebra de linha e uma tag `<script>`. No lab: uma linha que
tenha ACK, mensagem, mudança de severidade e notificação ao mesmo tempo — é
onde o alinhamento aparecia.

### Supressão na lista expandida do PDF (2026-09-02, v5.4.8)

Pedido do Rafael: o PDF já imprime, abaixo de cada alarme, a mensagem escrita e
as notificações que falharam; a supressão também precisa aparecer ali.

**Por que ela não aparecia.** `actionDetailRow()` só imprime item COM conteúdo
(`$body === ''` → `continue`), e o item de supressão vinha sem nenhum: o
`queryEventActions()` preenchia `message` apenas para o tipo `message`. O chip
"Suprimido" existia na linha, mas não tinha o que expandir.

**O que a supressão carrega é o PRAZO**, e ele estava no banco sem ser lido:
`acknowledges.suppress_until`. Agora entra na consulta e vira o conteúdo do
item — "Até 06/09/2026 08:00" ou "Por tempo indeterminado".

`0` em `suppress_until` significa **sem prazo** (`ZBX_PROBLEM_SUPPRESS_TIME_INDEFINITE`
em include/defines.inc.php), não "expirou em 1970" — que é no que dá formatar o
valor cru, e é o erro que esta linha existe para não cometer.

**Onde a frase é montada:** no controller (`suppressUntilLabel()`), ao lado do
`alertStatusLabel()`, que já fazia exatamente isto para o status de envio das
notificações. É o precedente da própria função — e é o que faz a supressão
aparecer no PDF **sem uma linha sequer de mudança no renderizador**: ele já
imprime todo item com conteúdo.

O Zabbix mostra só a HORA quando o prazo cai no dia corrente e data+hora nos
demais casos; aqui é sempre data+hora, porque o Repasse é lido por data e um
"08:00" solto num relatório de um turno da semana passada não diz de que dia se
trata.

Efeitos, todos de graça pelo mesmo caminho: o `title` do chip no PDF passou a
trazer o prazo, e a caixa da tela ao vivo ganhou a coluna "Supressão" (a
`rp_actionHint()` recebeu o `case`, senão a supressão cairia no ramo genérico e
mostraria só "Suprimido", que é o que o chip já diz).

`unsuppress` continua fora da lista expandida: não carrega dado nenhum além de
quem removeu e quando, que é o que o chip e a caixa já mostram.

**Não validado no lab.** Conferidas fora da tela as duas saídas: a caixa da tela
(prazo e "por tempo indeterminado") e a lista expandida do PDF, que passou a
imprimir Mensagem, Suprimido e notificação falha — e a continuar deixando de
fora ACK, supressão removida e notificação bem-sucedida. No lab: um alarme
suprimido com prazo e outro sem, no PDF e na tela.

### Legenda da rosca: estado desativado e total que acompanha (2026-09-02, v5.4.9)

Clicar numa severidade da legenda já escondia a fatia — é o `onClick` padrão da
legenda do Chart.js (`chart.toggleDataVisibility()`), que continua valendo
porque o `generateLabels()` próprio devolve o `index` de cada item. Faltavam as
duas consequências que o Rafael cobrou: a severidade clicada não parecia
desligada, e o total no centro não descia.

**O `hidden: false` fixo.** O item devolvido pelo `generateLabels()` trazia
`hidden` escrito como `false`, e é esse campo que o Chart.js lê para riscar o
rótulo. Como estava mentindo, a legenda ficava idêntica antes e depois do
clique. Agora sai de `chart.getDataVisibility(i)`.

Riscar sozinho é discreto demais para um clique parecer ter efeito, então o
marcador e o texto do item escondido vão para o cinza de rótulo do tema
(`--rp-text-muted`, lido pelo `rpToken()`). É a segunda vez que o mesmo objeto
morde: **tudo que a legenda desenha vem do ITEM**, não das opções — a cor foi na
v5.4.4, o estado agora.

**O total do centro somava o array cru.** `toggleDataVisibility()` não mexe em
`data`: quem sabe o que está escondido é o `getDataVisibility()`. A soma virou
`sevTotalVisivel(chart)`, que pula os índices invisíveis; o mesmo helper passou
a alimentar a porcentagem do tooltip, senão as fatias visíveis somariam menos de
100% com alguma severidade escondida.

**Achado ao mexer:** o furo era medido em `getDatasetMeta(0).data[0]` — o
PRIMEIRO arco. Com a legenda escondendo fatias, o primeiro pode ser justamente
um escondido, e a medida não serve. Passou a pegar o primeiro arco com raio
utilizável, e o fallback pela área do gráfico continua cobrindo o caso de todas
escondidas (aí o centro diz "0", que é a resposta certa).

**Não validado no lab** — é JS de view. Conferir: clicar em cada severidade e
ver o número do centro descer, o rótulo ficar riscado e cinza, clicar de novo e
tudo voltar; e esconder TODAS, que é o caso em que o furo não tem arco para
medir.

### Campo de formulário no tema escuro e atalhos de seleção na Escala (2026-09-03, v5.5.0)

Dois pedidos do Rafael, em telas diferentes.

**1. O combobox de turno destoava no escuro (Gerenciar Turnos > Analista da
Equipe).** O `.rp-input` usava `--rp-white`/`--rp-border`, ou seja, a cor do
CARD — e no escuro o campo sumia dentro da linha da tabela. O Zabbix trata
controle de formulário como uma superfície própria, diferente da tabela:

| | claro | escuro |
|---|---|---|
| fundo | `#ffffff` | `#383838` |
| borda | `#acbbc2` | `#4f4f4f` |
| texto | `#1f2c33` | `#f2f2f2` |

(regra `.multiselect, …, input[type="text"], …` dos temas, e `select` para o
combobox nativo). Viraram os tokens `--rp-field-bg` / `--rp-field-border`.

**O que realmente estava feio era a lista suspensa, e ela não é alcançável por
CSS.** A lista do `<select>`, o relógio do `input[type=time]`, as setas do
`[type=number]` e a barra de rolagem são desenhados pelo navegador: com
`appearance: menulist` (que esta tela usa de propósito, para o campo ter a
altura do resto) o autor pinta a caixa fechada e mais nada — a lista abria
branca por cima da tela escura. Quem resolve é **`color-scheme: dark`** no
contêiner, que diz ao navegador em que esquema desenhar o que é dele. O Zabbix
não declara `color-scheme` em tema nenhum, então isto é nosso, e fica escopado
ao `.rp-native-container`.

`!important` nas três cores do `.rp-input` **por especificidade, não por
gosto**: a regra do tema mira `input[type="text"]` (elemento + atributo, 0-1-1)
e vence uma classe simples (0-1-0) mesmo carregando antes. É a mesma razão já
documentada em `.rp-nh-btn` e `select.rp-input`. Consequência: o `:focus`
também precisa de `!important`, senão a borda azul perde para a borda base.

Junto foi a mensagem de "salvo"/"erro" da mesma tela, que estava em hex claro
dentro do JS (`#2e7d32` / `#c62828` — 2,1:1 e 2,6:1 sobre o card escuro): virou
`.rp-status-ok` / `.rp-status-err`, nas cores semânticas do Zabbix, aplicadas
por classe.

**2. Atalhos de seleção de dias na Escala.** Eram 30 cliques para escalar o mês.
Agora a barra de seleção tem *Todos os dias*, *Dias pares*, *Dias ímpares*,
*Dias de semana* e *Fim de semana*.

Decisões:

- **Cada atalho SUBSTITUI a seleção**, que é o que "selecionar dias pares"
  quer dizer. Para combinar dois padrões, clica-se um e ajusta-se o resto na
  mão, como já era; "Limpar seleção" continua onde estava.
- **Par/ímpar é pelo NÚMERO DO DIA** (2, 4, 6…), que é como a escala é
  combinada em voz alta — não pela posição no calendário.
- **Dia de semana sai do dia da semana da DATA, não da coluna.** O Zabbix tem
  "primeiro dia da semana" configurável (Administração > Geral): ler a coluna
  quebraria em quem começa a semana no domingo.
- **`new Date(+a, +m-1, +d)`, nunca `new Date('aaaa-mm-dd')`.** A string ISO é
  lida como UTC pela spec e, a oeste de Greenwich, devolve o dia anterior — o
  dia 1 viraria o último do mês passado e todo par/ímpar sairia trocado. Mesmo
  tropeço já documentado no mapa de calor do Repasse.
- **Sem pré-preenchimento do técnico**, que é o que `pltToggleDay()` faz ao
  clicar um dia: num lote de 15 dias, herdar o escalado de um deles seria
  escolher um por sorteio e escrevê-lo nos outros catorze.
- Dias passados entram na conta, porque clicar num dia passado sempre foi
  permitido nesta tela — o atalho não inventa uma regra que a tela não tem.

Os atalhos só alcançam as células do mês exibido: as de preenchimento das
pontas da grade não têm `data-date`.

**Não validado no lab** — as duas são view/CSS. Conferido fora da tela o
conjunto de dias de cada atalho em setembro/2026 (fim de semana = 5, 6, 12, 13,
19, 20, 26, 27; semana = 22 dias), que é o que a regra de data precisa acertar.
No lab: o combobox de turno ABERTO no tema escuro (é a lista que estava branca)
e um mês inteiro escalado por atalho.

### Atalhos que ligam e desligam, e os serviços num comando só (2026-09-03, v5.5.1)

**1. Atalhos de dia na Escala viraram liga/desliga.** Na v5.5.0 cada atalho
substituía a seleção; o Rafael pediu para poder desmarcar o que o atalho marcou.
Agora cada botão mexe **só no próprio conjunto**: se todos os dias dele já estão
marcados, o clique tira aqueles; senão, acrescenta. Com isso dá para somar
padrões (semana + um sábado) e desfazer um sem levar o outro — o "Limpar
seleção" continua para zerar tudo.

O estado vai em `aria-pressed`, não numa classe própria: é o atributo que diz a
um leitor de tela que aquilo é um botão de liga/desliga, e o CSS pinta o botão
lendo o MESMO atributo, em vez de manter uma segunda verdade que poderia
divergir. `pltMarkPresets()` roda dentro de `pltUpdateBar()`, então clicar um dia
solto também apaga o destaque do atalho que deixou de valer.

**Texto fora do centro nos botões.** A folha do Zabbix estiliza `button`
(especificidade 0-0-1) com `height:24px; line-height:22px; padding:0 11px`, e uma
classe simples só vence no que ELA declara: o padding daqui entrava, a altura e a
entrelinha de lá ficavam, e o texto assentava fora do meio. Corrigido com
`inline-flex` + `height`/`line-height` explícitos — centrar por flex não depende
de acertar entrelinha. O mesmo valia para o "Limpar seleção", que foi junto.

**2. Os serviços do módulo agora saem num comando só: `install.sh --services`.**

O pedido era "que habilitar o módulo execute tudo que está no install.sh". Isso
**não é possível, e não é só limitação**: o módulo roda dentro do PHP-FPM como o
usuário do servidor web. Não tem root para escrever em `/etc/systemd/system`,
instalar pacote ou dar `systemctl daemon-reload` — e um módulo de frontend capaz
de escrever unidade systemd daria, a quem consegue habilitar módulo pela UI,
execução de código como root no host. Habilitar o módulo cria as TABELAS (o
`init()` faz a migração); o que roda fora do frontend continua sendo trabalho de
root.

O que dava para melhorar era o custo desse passo. Antes: reabrir o instalador
interativo, escolher modo, redigitar host/porta/base/usuário/senha e responder
três perguntas. Agora:

    sudo ./scripts/install.sh --services

- **Sem perguntas.** Agenda presença e escalonamento; menções só entra se
  `PLANTONISTAS_ALERT_TOKEN` estiver no ambiente, porque sem token aquele cron
  não notifica nada.
- **Sem redigitar banco.** Lê `$DB['…']` do `zabbix.conf.php`. Duas correções
  que isso exigiu, ambas achadas neste host: o arquivo REAL dos pacotes
  RHEL/Amazon fica em `/etc/zabbix/web/` e o `detect_db_type()` só olhava ao
  lado do `index.php` (em host sem o symlink em `conf/`, não achava nada); e o
  Zabbix grava `PORT = '0'` para "porta padrão", que passado adiante faz o
  cliente tentar conectar na porta zero — agora vira 5432/3306.
- **Idempotente**, e reaproveita o `install_scheduled_job()` que já existia: em
  host com `/etc/cron.d` escreve lá; sem ele, crontab do usuário; sem cron
  nenhum (Amazon Linux 2023), unidade systemd + timer. Neste ambiente é o
  terceiro caminho — **não há cronie instalado**, o que também responde à parte
  de "instalação de pacotes": o `install.sh` nunca instalou pacote, o
  `check_deps()` só avisa; e o caminho systemd existe justamente para não
  precisar de nenhum.
- `SERVICES_TARGET` e `SERVICES_USER` cobrem host com o módulo fora do lugar
  padrão ou com apache e nginx instalados ao mesmo tempo (é o caso aqui: a
  detecção escolhe nginx, mas o pool do PHP-FPM roda como apache).

**3. Lista de presença vazia parou de ter uma explicação só.** "Nenhum dado de
presença. Execute o cron" tratava como iguais duas coisas bem diferentes: turno
em que ninguém acessou o Zabbix, e ambiente onde o coletor nunca foi agendado.
Agora `queryLastPresence()` (roda **apenas** quando a lista veio vazia) responde
qual dos dois: sem registro nenhum na tabela → "o coletor provavelmente não está
agendado" + o comando; com registro → "nenhum analista ativo nesta janela;
último registro em <data>".

**Não validado no lab.** O `--services` foi exercitado aqui em modo seco (com o
`install_scheduled_job` substituído por um eco): achou o `zabbix.conf.php` em
`/etc/zabbix/web/`, converteu POSTGRESQL→pgsql e a porta 0→5432, e montou os dois
jobs com os caminhos certos. Falta rodá-lo de verdade, **em um frontend só**.

### Atalho da Escala continuava "apertado" depois do clique (2026-09-03, v5.5.2)

O atalho marcava e desmarcava certo, mas o botão ficava com cara de apertado até
o foco sair. Não era o `aria-pressed` (esse já voltava para `false`): era a folha
do Zabbix.

```css
button:active, .btn:active, button:focus, .btn:focus {
    color: #ffffff; background-color: #02659f; border-color: #02659f;
}
```

`button:focus` é 0-1-1; `.plt-sel-preset` é 0-1-0. **O tema vence**, e depois do
clique o botão fica azul sólido com texto branco enquanto mantiver o foco — o
que, num botão de liga/desliga, é exatamente a aparência de "ligado" que ele
não deveria ter. É a mesma armadilha de especificidade já documentada em
`.rp-input` e `.rp-nh-btn`, agora no estado em vez da cor.

Correção sem `!important`: `.plt-sel-preset:focus` (0-2-0) devolve a aparência
normal. O foco **continua no botão** — quem navega por teclado precisa dele —,
só deixa de ser pintado por clique de mouse; o anel volta em `:focus-visible`,
que o navegador só liga quando o foco veio do teclado. `:active` entra pelo
mesmo motivo: sem ele o botão pisca azul sólido enquanto o clique está
pressionado.

Duas coisas que a ordem no arquivo decide, porque as regras têm a mesma
especificidade:

- `:hover` vem **depois** de `:focus`. Com o ponteiro parado sobre o botão
  recém-clicado os dois casam, e o certo ali é o realce de hover.
- `[aria-pressed="true"]` vem depois dos dois, e ganhou a variante
  `[aria-pressed="true"]:focus` (0-3-0) para o botão ligado continuar com cara
  de ligado enquanto estiver focado.

O "Limpar seleção" recebeu o mesmo tratamento: hoje ele some logo após o clique
(a seleção zera e ele é escondido), então o defeito não aparecia — mas a regra
é a mesma e não custa nada.

**Onde a mesma armadilha ainda vale:** os chips da coluna Ações do Repasse
(`.rp-act-btn`) são `<button>` e caem no mesmo `button:focus` — **só no tema
claro**. No escuro os seletores de chip são mais específicos que o do tema
(`[data-theme="dark-theme"] .rp-act-ack` é 0-2-0) e já ganham. Como cada tipo de
chip tem cor própria, devolver a aparência ali é subir a especificidade de todas
as regras de tipo do bloco claro — trabalho maior que o defeito, num tema que
esta equipe não usa. Fica anotado.

### "Analistas Escalados" sempre offline: dois relógios (2026-09-03, v5.5.3)

O coletor de presença passou a rodar (v5.5.1) e o bloco continuou mostrando todo
mundo como offline. **O coletor estava certo**; a comparação é que usava outro
relógio.

O `is_online` de `queryShiftAnalysts()` era
`last_seen >= SqlFn::nowMinusMinutes(15)`, e o `SqlFn::now()` perguntava a hora
ao BANCO (`LOCALTIMESTAMP` no PG, `NOW()` no MySQL). Só que quem grava
`lastaccess` é o cron, com o `date()` do PHP. Neste ambiente:

| quem | relógio | valor às 12:49 |
|---|---|---|
| cron de presença (`date()` do PHP, TZ America/Sao_Paulo) | −03 | `12:42` |
| `LOCALTIMESTAMP` (PostgreSQL com `timezone = UTC`) | UTC | `15:49` |

A conta virava `12:42 >= 15:34` — falso, sempre, para todo mundo. Conferido no
banco de produção: a mesma linha dá `offline` pela expressão antiga e `ONLINE`
pela nova.

**Correção: quem responde "que horas são" passa a ser a APLICAÇÃO.** `SqlFn::now()`
devolve a hora do PHP como literal entre aspas, e `nowMinusMinutes()` sai do
mesmo relógio. É coerente com todo o resto do módulo, que já grava com `date()`:
os limites do turno, o `lastaccess` do cron, o fechamento. De quebra some a
diferença de dialeto — o literal vale nos dois bancos.

O docblock antigo do `now()` descrevia **exatamente** este cenário ("com o
servidor em UTC e o cron gravando em America/Sao_Paulo…") ao justificar o
`LOCALTIMESTAMP`. A troca resolvia outra coisa (o `now()` do PG é `timestamptz`
e converter usa o fuso da sessão) e não essa: continuava sendo o relógio do
banco. Fica o aprendizado: **não basta escolher a função de data certa; tem de
ser o relógio certo.**

O mesmo desencontro carimbava nota do Diário de Bordo, `read_at` de menção e
`notified_at` três horas adiantados — os quatro chamadores do `now()` foram
corrigidos de uma vez. Os testes do `SqlFn` deixaram de afirmar
`LOCALTIMESTAMP`/`NOW()` e passaram a verificar o formato e a distância em
relação ao relógio (45 casos, era 44).

**Limite que fica, e é de configuração, não de código.** O carimbo gravado é
hora de parede sem fuso, então frontend e cron precisam concordar. Neste
ambiente `settings.default_timezone = system` e o `date.timezone` do php.ini
está comentado: quem tem fuso no perfil (`America/Sao_Paulo`) vê certo, e quem
está em "default" cai no UTC do PHP e veria o mesmo erro de 3 horas. O acerto é
definir o fuso em **Administração > Geral > GUI** (ou no php.ini), não no
módulo.

### Scripts de cron ganharam guarda de CLI (2026-09-03, v5.5.3)

`scripts/` mora dentro do módulo, e o módulo mora dentro da raiz web — o Zabbix
serve `modules/` a partir do document root. Ou seja, os quatro scripts tinham
URL, e nenhum verificava o SAPI: quem acertasse o endereço fazia o servidor web
executar, sem autenticação, um script que escreve no banco — e via na tela o
erro de conexão com pistas de caminho e usuário. Agora todos começam com
`if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }`. 404 e não 403
porque quem chuta URL não precisa saber que acertou o nome.

Achado no mesmo diagnóstico: há **cópias antigas do módulo dentro da raiz web**
(`/usr/share/zabbix/ui/modules_old/` e `/usr/share/zabbix/ui/scripts/cron_presence_tracker.php`),
que não recebem correção nenhuma deste repositório e continuam sem a guarda.
Não foram tocadas — apagar arquivo fora do repo é decisão de quem opera.

### `--services` em produção: ler a config, não adivinhar o formato (2026-09-03, v5.5.4)

Funcionou em homologação e morreu em produção com *"Faltam dados do banco"*. A
causa não era o arquivo estar ausente — ele foi **encontrado e mal lido**.

O `zabbix.conf.php` é **código PHP**, e o parser era um `sed` que só entendia
`$DB['CHAVE'] = 'valor';` com aspa simples e valor literal. Basta o arquivo usar
aspas duplas, uma constante ou concatenação para o parser devolver vazio — sem
erro, o que é pior. Reproduzido aqui com um conf no estilo que produção
provavelmente usa:

| chave | parser de texto (antigo) | leitura pelo PHP (nova) |
|---|---|---|
| `SERVER` (vinha de constante) | *(vazio)* | `db-prd.interno` |
| `DATABASE` (`"zabbix" . "_prd"`) | `zabbix` | `zabbix_prd` |
| `PASSWORD` (com aspas dentro) | `s3nh4\` | `s3nh4"com'aspas` |

**Quem lê o arquivo agora é o próprio PHP** (`include` + leitura do `$DB`), que
é o único que sabe o valor final de uma constante, de uma concatenação ou de um
include. O parser de texto continua como reserva para host sem PHP na linha de
comando — e ganhou aspas duplas de quebra.

**E a busca deixou de depender de um arquivo só.** Cada campo é resolvido pelo
primeiro lugar que o tiver, nesta ordem:

1. variáveis `DB_*` já exportadas — quem manda é quem digitou;
2. `zabbix.conf.php` (mais caminhos, `ZBX_CONF=` para apontar na mão, e uma
   busca com profundidade curta em `/etc/zabbix` e na raiz do frontend);
3. `/etc/zabbix/zabbix_server.conf` — `DBHost`/`DBName`/`DBUser`/`DBPassword`,
   que existem em qualquer host que rode o server;
4. o agendamento do módulo **já instalado** (cron.d ou unidade systemd): são
   exatamente os valores com que o coletor roda hoje;
5. `ZBX_DB_*`, a convenção das imagens de container.

Campo a campo, e não "a primeira fonte completa vence": frontend sem senha +
`zabbix_server.conf` com a senha resulta numa configuração completa, que é o
caso mais comum de instalação segmentada.

Se ainda faltar algo, a mensagem agora **lista onde procurou** em vez de só
mandar preencher tudo na mão. E entrou `--show-config`, que faz toda a
descoberta e imprime o resultado **sem escrever nada** — era o que teria
resolvido este chamado sozinho.

**Duas armadilhas de `set -e` corrigidas no caminho**, as duas capazes de matar o
script em silêncio depois de já ter descoberto tudo:

- `[[ … ]] && cmd` como **última linha de função**: quando o teste dá falso, a
  função devolve 1, e sob `set -e` o chamador morre sem imprimir nada. Era o
  caso do aviso de senha vazia — com senha definida, o `--services` abortava
  logo após "Banco: …". Virou `if`, com `return 0` explícito.
- `conf="$(find_zbx_conf …)"`: a função devolve 1 quando não acha, e a
  atribuição carrega esse status. Agora tem `|| true`.

Também dá para apontar o `zabbix_server.conf` com `ZBX_SERVER_CONF=`, para o
caso de instalação fora do padrão.

### Notificações consolidadas: um selo em vez de trinta (2026-09-03, v5.6.0)

Com escalonamento configurado, um alarme sozinho gera dezenas de linhas em
`alerts` — uma por destinatário, por passo e por repetição. Na coluna Ações isso
virava dezenas de chips iguais que enterravam o que interessa no repasse: a
mensagem que o analista escreveu, a supressão, o ACK. Num caso real de teste,
**37 itens viravam 37 chips; agora são 4**.

**Quem consolida é o controller**, em `summarizeNotifications()`, chamado no fim
do `queryEventActions()` — o resumo entra no mesmo array que a view e o PDF já
recebem (`$actions[$eventid]['notify_summary']`). Nem a view nem o PDF contam
nada: recebem as linhas agrupadas e ordenadas e só formatam. Foi o jeito de as
duas telas terem a MESMA conta sem duplicar a lógica, já que a view não enxerga
o trait.

Para isso o item de notificação passou a carregar **`media` e `status`
separados** do rótulo. Antes o nome da mídia só existia dentro da frase
"Notificação: E-mail", e agrupar por mídia significaria fazer o parse de um
texto de exibição — que quebra no dia em que o texto mudar.

**O que o resumo conta, e por quê:**

| coluna | por que existe |
|---|---|
| total por mídia | "por onde isso saiu, e quanto" |
| falhas (`alerts.status = 2`) | é o único número que pede ação |
| última | com escalonamento longo, "há 4 minutos" e "há 4 horas" pedem coisas diferentes |

Ordena por falhas e depois por total: quem tem problema aparece primeiro, não a
mídia mais falante.

**O aviso de falha não pode depender de abrir a caixa.** Antes, uma notificação
que falhou era um chip próprio, visível na linha. Consolidando, ela sumiria atrás
de um clique — então o rótulo do chip leva o número (`Notificações (34 · 4
falhas)`) e o chip fica vermelho quando há qualquer falha.

**No PDF, o selo consolidado aparece na linha do alarme**, e a sub-linha de
detalhe continua imprimindo mensagem, supressão e notificação que falhou, item a
item com o texto do erro. Notificação bem-sucedida segue fora da lista.

> A sub-linha chegou a receber TAMBÉM o resumo por mídia
> (`SMS 8 (3 falhas) · E-mail 24`); foi **retirado a pedido** na v5.6.2 — ver lá.

**Snapshot de turno fechado antes desta versão não tem o resumo** (ele nasce na
consulta, não no banco). Nesse caso a notificação volta a ser um chip por item,
como era: documento fechado não pode PERDER informação por causa de uma melhoria
que veio depois dele. Vale na tela e no PDF, e está verificado — com resumo, 4
chips; sem resumo, os 37 de antes.

**Não validado no lab** — view/PDF. Conferido fora da tela com um cenário de
escalonamento tagarela (34 envios em 3 mídias, 4 falhas, mais ACK, mensagem e
supressão): a contagem por mídia, a ordem (falhas primeiro), o plural de "1
falha", os 4 chips e a sub-linha do PDF. No lab: um alarme com escalonamento de
verdade, na tela e no PDF.

### Consolidação para todo tipo de ação repetida (2026-09-03, v5.6.1)

A v5.6.0 consolidou notificação; o Rafael pediu o mesmo para o resto. Agora
**qualquer tipo que aconteça mais de uma vez no mesmo alarme vira um chip com a
contagem**, e a caixa lista todas as ocorrências — quem fez e quando, que é
justamente a informação que some quando os selos se repetem.

    8 itens → 5 chips:  ACK (3) | ACK removido | Suprimido (2) | Mensagem | Severidade alterada

**Agrupa por TIPO, não por "família".** `ACK` e `ACK removido` continuam
separados de propósito: juntar colocar e tirar num contador só esconderia
exatamente a diferença entre os dois. O mesmo vale para `Suprimido` e `Supressão
removida`.

Tipo que aconteceu uma vez só continua idêntico ao que era — o chip com a caixa
de uma linha. Nada muda para o caso comum.

**Refatoração que isso pediu:** a "terceira coluna" da caixa (Mensagem,
Severidade, Supressão, Status, Ação) virou `rp_actionColuna()`, e a montagem do
botão virou `rp_actionBotao()`. As duas caixas — a de uma ação e a do grupo —
passam pelo mesmo caminho, então uma coluna nova aparece nas duas sem ninguém
lembrar de mexer em dois lugares.

**No PDF, os selos agrupam igual** (`ACK (3)`), e a sub-linha de detalhe
continua imprimindo mensagem, supressão e notificação FALHA item a item: no
papel a contagem resume, mas o conteúdo é o que se lê no repasse.

**Snapshot antigo:** notificação sem resumo continua um chip por item — e agora
com uma proteção a mais. Agrupar notificação por tipo daria um contador com o
rótulo da PRIMEIRA mídia ("Notificação: E-mail (34)" contando também os SMS),
que é pior que 34 chips. As demais ações agrupam normalmente também nesse
caminho, porque ali agrupar não esconde nada.

**Não validado no lab** — view/PDF. Conferido fora da tela: 8 itens de tipos
variados → 5 chips com as caixas certas; 39 itens (34 notificações + 3 ACKs +
mensagem + supressão) → 4 chips; snapshot sem resumo → 37 chips, com as
notificações uma a uma; e a chave interna do agrupamento (`notify#3`) não vaza
para a classe CSS.

### Resumo de notificações sai da lista expandida do PDF (2026-09-03, v5.6.2)

Retirada a pedido do Rafael a linha de resumo por mídia que a v5.6.0 tinha
acrescentado à sub-linha de detalhe. A lista volta a mostrar **só o que
aconteceu de diferente**: mensagem escrita, supressão e mídia com erro.

O raciocínio de quem lê o documento: a sub-linha é CONTEÚDO, não contagem. Quem
quer o número já o tem no selo da linha do alarme, logo acima
(`Notificações (34 · 4 falhas)`) — repetir o mesmo dado em duas alturas gastava
espaço de papel para dizer o que já estava dito.

O `notifySummaryText()` continua vivo: é o `title` do selo. Só deixou de ser
impresso.

Fica assim:

    selos:  ACK (3) | Mensagem | Suprimido | Notificações (34 · 4 falhas)
    lista:  • Notificação: SMS            Falhou: conexão recusada
            • Notificação: SMS            Falhou: conexão recusada
            • Notificação: SMS            Falhou: conexão recusada
            • Notificação: Webhook Teams  Falhou: conexão recusada
            • Mensagem   Ana — 11:50      Serviço reiniciado.
            • Suprimido  Rafael — 11:51   Até 06/09/2026 08:00

### Filtro por grupo de host no Repasse (2026-09-03, v5.7.0)

Pedido do Rafael: ambiente grande demais para validar olhando o total. O
cabeçalho do Repasse ganhou um seletor de **grupo de host**, visível só para
Super Admin.

**Custou quase nada nas consultas, e o motivo é a forma do filtro que já
existia.** O `host_filter` de permissão é um trecho `AND h.hostid IN (…)`
injetado nas nove consultas do relatório, todas com o alias `h` em escopo. O
recorte por grupo tem a MESMA forma, então é só concatenar — nenhuma das nove
consultas foi tocada:

    $hostFilter .= $this->groupFilter($groupid);

**Compõe, nunca substitui.** Os dois trechos são somados com `AND`: não existe
combinação de parâmetros em que escolher um grupo mostre host que o usuário não
enxergaria. E a checagem de "só Super Admin" é feita no **servidor**, nos dois
controllers — esconder o `<select>` nunca foi controle de acesso, e a action tem
URL própria (mesma regra que vale para os itens de menu deste módulo).

**Duas exclusões na lista de grupos, e as duas importam num ambiente grande:**

- `hstgrp.type = 0`. Desde o 6.2 a mesma tabela guarda grupo de host (0) e
  grupo de TEMPLATE (1). Sem isso, o seletor deste ambiente listava 15 opções,
  **12 delas "Templates/…"** — e filtrar por grupo de template devolve tela
  vazia, porque template não tem evento.
- o grupo precisa ter host de verdade (`hosts.status IN (0,1)`). Com as duas, a
  lista foi de 15 para 3.

**No PDF, o recorte vai carimbado no documento** — badge "Grupo: X" no cabeçalho
e o nome no `<title>` (que é o nome sugerido do arquivo). Um PDF de um grupo só é
indistinguível de um PDF do ambiente inteiro depois de impresso, e é justamente
ele que circula por e-mail. O snapshot de turno fechado nunca leva recorte: ele é
do turno inteiro, por definição.

O seletor entra **dentro do form GET** que já navega por data/turno/Top N, então
o grupo escolhido acompanha toda troca sem uma linha de JS, e o link "Gerar PDF"
leva o mesmo `groupid`.

**Conferido contra o banco de produção:** a consulta de alarmes herdados devolve
1030 sem filtro, 1022 no grupo "TESTE" e 5 em "Zabbix servers".

**Filtro por TAG** ficou para a rodada seguinte, e o combobox de grupo também
mudou de forma — ver a entrada da v5.8.0, logo acima.

### Filtro por tag, e o seletor de grupos vira diálogo (2026-09-03, v5.8.0)

Duas coisas pedidas juntas: o filtro por tag prometido, e a troca do combobox de
grupo por um **botão que abre um seletor** — o que também virou multi-seleção,
que é o que um ambiente grande pede.

**Um botão "Filtros" no lugar de dois controles.** Ele abre um diálogo com as
duas seções — grupos de host e tags (linhas de nome/operador/valor) —, e o
rótulo mostra quantos critérios estão ativos.

> A seção de grupos nasceu como lista de caixas de seleção e virou o multiselect
> nativo do Zabbix na v5.8.1 — ver lá o porquê. Ficou
um botão em vez de dois porque o cabeçalho já carrega data, turno e Top N; e
porque escolher grupo e tag é a mesma tarefa ("recortar"), feita na mesma hora.

O diálogo usa as classes NATIVAS do Zabbix (`overlay-bg`,
`overlay-dialogue modal modal-popup`, `list-table`) — o mesmo caminho que a tela
de Escala já faz para escolher técnico. Sem CSS de modal próprio, e com a
aparência da casa nos quatro temas.

Os valores viajam em dois `<input hidden>` DENTRO do form de navegação que já
existia: o diálogo preenche e submete. Data, turno, Top N e filtro andam juntos
sem montar URL na mão em lugar nenhum, e o link "Gerar PDF" leva os mesmos
parâmetros.

**Por que a tag NÃO coube no `$hostFilter`.** O recorte por host é um
`AND h.hostid IN (…)` que serve às onze consultas porque todas têm o alias `h`
em escopo. Tag é do EVENTO, e o alias do evento muda: `ev` nas nove que leem
`events`, `p` nas duas que leem `problem`. A saída foi passar as TAGS (não o
SQL) para cada consulta, e deixar que cada uma monte o trecho com o próprio
alias — `$this->tagFilter($tags, 'ev')`. Onze assinaturas ganharam
`array $tags = []` no fim, sem tocar em nenhuma cláusula existente.

**Sempre `event_tag`, nunca `problem_tag`**, mesmo nas duas consultas sobre
`problem`: as duas tabelas são indexadas por `eventid` e valem para o mesmo
evento, mas o Zabbix APAGA a linha de `problem_tag` quando o problema fecha —
filtrar "Alarmes Resolvidos" por tag olhando `problem_tag` daria uma lista que
encolhe sozinha conforme os problemas são resolvidos.

**Seis operadores** (Existe, Igual a, Contém, Não existe, Diferente de, Não
contém), combinados com **E**: cada linha estreita mais o recorte, que é o que
"validar" pede. Detalhes que a implementação exigiu:

- `LOWER()` dos dois lados em igual/contém — o MySQL `_ci` ignora caixa, o
  PostgreSQL não (regra do CLAUDE.md).
- `ESCAPE '!'` no LIKE, com `%`, `_` e o próprio `!` neutralizados no valor
  digitado: sem isso um valor com `%` casaria com qualquer coisa.
- "Contém" com valor VAZIO vira "a tag existe" — é o que a pessoa quis dizer, e
  `LIKE '%%'` casaria com tudo.
- Saneamento no controller (`parseTagFilter()`): nome vazio e operador fora da
  lista são descartados, texto é cortado em 255 (tamanho de `event_tag.tag` e
  `.value`) e o filtro para em 10 condições. Cada condição vira um EXISTS por
  consulta — dez é mais do que qualquer validação real precisa, e é o que separa
  "filtro" de "jeito de derrubar o banco pela querystring".

**As linhas de tag são montadas por DOM, não por `innerHTML`**: nome e valor são
texto livre, e uma aspa dentro deles quebraria o atributo. Escapar para HTML
resolveria o `<`, mas não a aspa; atribuir em `.value` não passa por parser
nenhum.

**"Marcar todos" marca só o que está VISÍVEL** na lista de grupos. Com a busca
filtrando por "prod", um "marcar todos" que pegasse o ambiente inteiro seria uma
armadilha.

**No PDF os dois recortes vão carimbados** no cabeçalho e no `<title>` (nome
sugerido do arquivo): "Grupo: X" e a lista de condições de tag. Documento
filtrado que circula por e-mail precisa dizer que é um recorte.

**Conferido contra o banco de produção**, na consulta de alarmes herdados:

| recorte | linhas |
|---|---|
| sem filtro | 1030 |
| grupo TESTE | 1022 |
| grupo TESTE + `scope` igual a `coverage` | 1020 |
| grupo TESTE + `scope` diferente de `coverage` | 2 |
| grupo TESTE + `scope` não existe | 0 |

O último zero é resposta certa, não bug: naquele grupo TODO problema tem a tag
`scope` (1020 `coverage` + 2 `security`). Foi por causa desse caso que valeu
conferir a contagem complementar em vez de só olhar se "voltou alguma coisa".

A geração do SQL e o saneamento foram exercitados fora da tela: escape de `%`,
`_` e aspa, negação, duas condições somadas, JSON quebrado, operador inventado,
15 linhas (cortadas em 10) e texto de 400 caracteres (cortado em 255).

### Grupos de host: multiselect nativo, não lista (2026-09-03, v5.8.1)

A v5.8.0 desenhava TODOS os grupos como caixas de seleção dentro do diálogo. Num
ambiente com centenas, isso é uma lista impossível — e, pior, obrigava o módulo
a carregar o catálogo inteiro a cada abertura da tela.

Agora a caixa de grupos é o **multiselect nativo do Zabbix**, o mesmo componente
da aba Problemas: digitar busca no servidor e a lupa abre o pop-up de seleção;
o que entra vira pílula dentro da caixa.

    (new CMultiSelect([
        'name' => 'rp_groupids[]', 'object_name' => 'hostGroup',
        'data' => …selecionados…, 'popup' => ['parameters' => ['srctbl' => 'host_groups', …]],
    ]))

O `CMultiSelect` monta sozinho a URL de busca —
`jsrpc.php?method=multiselect.get&object_name=hostGroup&with_hosts=1&enrich_parent_groups=1` —
e é o SERVIDOR que responde, já aplicando permissão. Três consequências boas:

- **o módulo não carrega mais lista de grupo nenhuma.** `queryHostGroups()` (a
  consulta que trazia o catálogo) foi substituída por `queryHostGroupsByIds()`,
  que só resolve os ids que a tela mandou — para validar e para rotular a
  pílula. A conta não cresce mais com o tamanho do ambiente;
- `with_hosts=1` vem de graça no componente e faz o que a consulta antiga fazia
  na mão (não oferecer grupo sem host);
- some o `type = 0` escrito por nós na listagem: quem busca é o endpoint de
  `hostGroup`, que já não devolve grupo de template. A regra continua em
  `queryHostGroupsByIds()`, onde ainda importa — ali o id vem da querystring e
  pode ser qualquer coisa.

**Três detalhes que o componente nativo exigiu**, todos anotados no código:

- **`name` no `<form>` do cabeçalho.** O pop-up de seleção recebe o nome do
  formulário de destino (`dstfrm`) e o procura por nome na página; sem ele, o
  "Selecionar" abre e não devolve nada.
- **`add_post_js => false` + init próprio.** O `CMultiSelect` registraria o
  `multiSelect()` no post-JS da página; aqui ele sai no bloco da view, dentro de
  `jQuery(...)`, para não depender da ordem de flush do rodapé.
- **Esc do diálogo cede a vez.** Com o pop-up do Zabbix aberto por cima, um Esc
  fechava os dois de uma vez — agora o handler desiste se houver
  `.overlay-dialogue.modal[data-dialogueid]` na página.

Ler a seleção é `multiSelect('getData')`, a API do componente, e não a marcação
interna dele: é o que sobrevive a uma atualização do Zabbix. O que viaja na URL
continua sendo a mesma lista de ids de antes.

Conferido fora da tela: a marcação renderizada, a URL de busca e os parâmetros
do pop-up (`multiselect: 1` entra sozinho).

### Linha de tag: valor some em "Existe", e o padrão vira "Contém" (2026-09-03, v5.8.2)

Dois acertos no diálogo de filtros, os dois para bater com o filtro nativo de
Problemas do Zabbix.

**"Existe" e "Não existe" escondem a caixa de valor.** Esses dois operadores
perguntam pela TAG, não pelo valor — deixar a caixa aceitando um texto que o
filtro ignora é convidar a pessoa a achar que filtrou algo que não filtrou. A
troca é feita no `change` do operador e também na montagem da linha, para uma
condição já salva abrir no estado certo.

Some por **`visibility`, não por `display:none`** (corrigido na v5.8.3, depois
de aparecer na tela): tirar o elemento do fluxo faz a linha se rearranjar — o
campo de nome (`flex: 1 1 0`) estica para ocupar a sobra e o seletor de operador
escorrega para o lado a cada troca. Com `visibility` o espaço continua
reservado: some o campo, não muda o lugar de nada. De quebra, `visibility:
hidden` já tira o campo da ordem de tabulação e da árvore de acessibilidade,
que é o que "escondido" deve significar.

A primeira tentativa usou o atributo `hidden` e precisou de `!important` para
vencer o `display` da classe — sinal de que estava resolvendo o problema
errado. Classe com `visibility` não disputa com ninguém.

**Condição nova começa em "Contém".** É o padrão do próprio Zabbix
(`TAG_OPERATOR_LIKE` é o valor default do filtro de Problemas) e é o que quase
sempre se quer ao começar a digitar. O valor sai de `tagOperatorDefault()`, no
controller, e serve aos três lugares que precisavam dele: o `<select>` da linha
nova, o fallback do `parseTagFilter()` quando o operador vem ausente e o do
`tagFilter()`. Antes o fallback era `exists` escrito em dois lugares.

**O valor é ZERADO no controller para operador que não o usa**, e não só
ignorado na hora de montar o SQL. Assim o que fica guardado, o que viaja na URL
e o que o PDF carimba dizem a mesma coisa — "env existe", nunca "env existe
<texto que não filtra nada>". Vale inclusive para URL montada à mão, que é o
caminho que a tela não controla.

Conferido fora da tela: operador ausente cai em `like`; `exists`/`nexists`
guardam valor vazio mesmo recebendo texto; `like`/`eq` preservam o valor.

### Performance do MTTA: dois números contando histórias diferentes (2026-09-04, v5.9.0)

O Rafael reportou que a coluna Performance mostrava "Atenção" para analista que
estava, aparentemente, dentro do parâmetro descrito. Estava mesmo — e o parâmetro
descrito não era o parâmetro usado.

| onde | o que dizia |
|---|---|
| descrição do card | "Meta: abaixo de **60 minutos**" |
| classificação, na view | `$avg<300 ? 'Excelente' : ($avg<900 ? 'Aceitável' : 'Atenção')` — **5 e 15 minutos** |

`avg_mtta` é `a.clock - ev.clock`, ou seja SEGUNDOS: 300 = 5 min, 900 = 15 min.
Quem tinha 20 minutos de MTTA — um terço da meta anunciada — via "Atenção". Não
era a conta que estava errada: eram dois números, em dois lugares, sobre a mesma
coisa. O clássico deste módulo (ver "cor nova entra no arquivo de tema", "o
`SEV_CUTOUT` precisa ser um valor só", "`--ar-chart-min` tem de bater com
`$chart_min_w`").

**Agora há UM lugar.** `mttaThresholds()` devolve os dois limites, e eles pintam
o selo **e** escrevem a frase da meta na descrição do card — que passou a ser
gerada ("Excelente abaixo de 15 min, aceitável abaixo de 1 h") em vez de escrita
à mão. Divergir de novo exigiria mudar os dois de propósito.

**Os novos padrões são 15 min e 1 h**, e a escolha tem motivo: 60 minutos era o
que a tela já prometia ao usuário. O padrão passa a ser o que estava escrito
para quem lê, não o que estava escrito para o interpretador.

**E agora se configura pela tela** (Super Admin), que foi a segunda parte do
pedido:

- engrenagem **no cabeçalho do próprio card** de MTTA, não numa tela de
  preferências à parte: o limite só faz sentido olhando a coluna que ele pinta;
- diálogo com as mesmas classes nativas do de filtros, em MINUTOS (o banco
  guarda segundos — a conversão fica na borda, e ninguém digita 3600);
- `TurnosSettingsSave`, action nova: **Super Admin conferido no servidor**, POST
  + `_csrf_token` da action completa, sem `disableCsrfValidation()`;
- tabela nova `module_plantonistas_settings` (chave/valor), declarada no
  `Schema.php` — **chave/valor e não uma coluna por parâmetro**, senão cada
  ajuste futuro vira migração de schema. `SCHEMA_VERSION` subiu para 2, que é o
  que invalida o marcador e faz os frontends criarem a tabela.

**Três guardas contra o mesmo tiro no pé**, porque limites invertidos fazem a
faixa do meio sumir e TODO MUNDO cair em "Atenção" — exatamente o sintoma que
originou a tarefa: a tela recusa antes de enviar, a action recusa antes de
gravar, e `mttaThresholds()` corrige na leitura se ainda assim chegar invertido
(linha gravada na mão no banco, por exemplo).

Falha ao ler cai no default com log: um selo com o limite de fábrica é melhor
que uma tela em branco — vale inclusive no intervalo entre subir o código e o
`init()` criar a tabela.

Conferido: a DDL nos dois dialetos, o upsert em transação revertida contra o
banco de produção (segundo INSERT atualiza a linha, como esperado), e a
classificação em três conjuntos de limites — com 15/60, os 20 minutos do
relatado saem como "Aceitável".

### "MTTA por Analista" é bloco gerencial: some para o papel User (2026-09-04, v5.9.1)

Pedido do Rafael depois de olhar a tela pronta: comparar o tempo de resposta de
um analista com o dos colegas é leitura de quem coordena o plantão, não de quem
está nele — e o selo de Performance ao lado do nome deixa isso explícito. O card
passa a aparecer só para **Admin (2) e Super Admin (3)**.

**É escopo de tela, não controle de acesso** — e a diferença importa para quem
for mexer nisto depois. O dado de outro analista já era negado no SERVIDOR:
`restrictMttaByRole()` reduz a lista ao próprio usuário antes de chegar à view.
O que o User via ali era a linha DELE, informação que ele podia ver mas que,
sozinha, não diz nada (uma tabela de um analista só, com um selo comparando-o
com ninguém).

**O KPI "Seu MTTA" fica**, na linha de cima: é a mesma informação no formato que
serve a quem está no plantão — o próprio número, sem a moldura de comparação. E
é por isso que a consulta e a restrição por papel continuam existindo: o KPI sai
delas.

**O PDF segue a mesma regra.** Se o bloco saísse só da tela, bastaria clicar em
"Gerar PDF" para reaver o que a tela decidiu não mostrar.

Duas limpezas que a mudança tornou possíveis: a descrição do card perdeu o ramo
"o MTTA de outros analistas é visível apenas para Admin/Super Admin" (não há
mais quem leia essa frase) e o título no PDF deixou de ser ternário.

**Achado no caminho:** a dica do KPI ainda dizia "Meta: abaixo de 60 minutos"
escrito à mão — o MESMO número solto que a v5.9.0 acabara de eliminar do card. Se
alguém mudasse o limite pela engrenagem, a dica continuaria prometendo 60. Agora
sai de `rp_limiteLabel($rp_mtta_lim['ok'])`, como o resto.

### Filtro por grupo de host ignorava a árvore aninhada (2026-09-04, v5.9.2)

Grupo de host no Zabbix é hierárquico **por nome**, com `/` de separador:
"HOSTS/PRD/EMPRESAS/ROCK" é pai de "HOSTS/PRD/EMPRESAS/ROCK/CONTAS" e de
"HOSTS/PRD/EMPRESAS/ROCK/CLOUD/AWS/EC2". O filtro pegava só o id escolhido — e
num ambiente organizado em árvore isso costuma dar **nenhum host**, porque os
hosts moram nas folhas, não no galho.

**A expansão passou a ser a nativa**, `getSubGroups()`
(ui/include/hostgroups.inc.php, carregada pelo `ZBase::init()` em toda
requisição). É a definição canônica do Zabbix — busca por `nome + '/'` com
`startSearch`, a mesma regra da tela de Problemas. Reimplementar em SQL daria a
mesma resposta hoje e outra no dia em que o Zabbix mudar de critério, e ainda
obrigaria a escapar `%` e `_` no nome do grupo (que aparecem em nome de grupo
de verdade). De quebra ela passa pela API, então aplica permissão — e isso
substituiu a consulta de validação que existia antes.

**Escolhido e filtrado são conjuntos diferentes, de propósito:**

| | o que é | onde aparece |
|---|---|---|
| `selected` | só o que a pessoa marcou | pílulas do multiselect, URL, carimbo do PDF |
| `expanded` | escolhidos **+ descendentes** | o `IN (…)` do SQL |

Misturar os dois faria a caixa de seleção encher de grupos que ninguém escolheu
(e o carimbo do PDF virar uma lista de 15 nomes).

Medido neste ambiente: escolher "HOSTS" passou de **1 para 15 grupos** no
recorte (HOSTS/PRD, HOSTS/PRD/EMPRESAS, …/HINDIANA/ALPE/CLOUD/AWS/EC2 e por aí).

**Teto na ESCOLHA (50), não na expansão.** Limitar o que a árvore devolve
esconderia hosts sem avisar — que é exatamente o defeito que esta entrada
corrige, só que mais difícil de perceber.

Falha na expansão cai nos ids escolhidos, com log: recorte mais **estreito** que
o pedido, nunca mais largo. E o rótulo some junto, para a tela não afirmar um
nome de grupo que não conseguiu confirmar.

O PDF resolve pelo mesmo caminho: se ele expandisse diferente, os números do
papel não bateriam com os da tela.

Conferido contra a árvore do banco (44 grupos com `/` no nome) e a lógica de
resolução exercitada fora da tela com a árvore espelhada: pai → tudo abaixo,
folha → só ela, seleção múltipla → união, id inexistente → nada selecionado.

### Meta de MTTA por equipe: cada empresa com o seu número (2026-09-04, v5.10.0)

O Rafael colocou o problema real: são muitas empresas atendidas, e "aceitável"
para a empresa A é 30 min enquanto para a B é 1 h. Um par de limites global não
serve.

**A ideia inicial era meta por TAG do alarme, e ela não fecha para esta tabela.**
A linha de "MTTA por Analista" é um ANALISTA, não um alarme: se o mesmo analista
deu ACK em alarmes de duas empresas, a média dele é uma só e não existe qual das
duas metas aplicar. Meta por tag só funcionaria com a tela recortada para uma
empresa, ou quebrando a tabela em analista × empresa (mais linhas, outro
significado).

**A informação que faltava veio dele:** cada empresa tem time próprio e o
permissionamento já separa quem vê quem. Ou seja, **a equipe é a empresa** — e a
equipe é um atributo do ANALISTA, que é justamente o sujeito da linha. Com isso a
meta cai sobre a linha sem ambiguidade e **sem depender de filtro na tela**: o
relatório diário, misto, já sai colorido certo.

**Formato: chave/valor, sem tabela nova.**

    mtta_good            / mtta_ok            → padrão
    mtta_good.<usrgrpid> / mtta_ok.<usrgrpid> → meta daquela equipe

É para isto que `module_plantonistas_settings` nasceu chave/valor (v5.9.0):
empresa nova é uma linha, não uma migração de schema.

**Decisões que valem ficar escritas:**

- **Analista em duas equipes fica com a meta mais RÍGIDA.** Errar para o lado de
  cobrar mais deixa um selo pessimista, que alguém questiona e corrige; errar
  para o lado frouxo esconde atraso, e ninguém vai perguntar por um selo verde.
- **A dica do selo diz de onde veio a meta** ("meta da equipe NOC ROCK" ou "meta
  padrão"). Número de SLA que muda em silêncio é como se perde a confiança no
  indicador.
- **Meia configuração herda o resto do padrão**: equipe com só o "aceitável"
  gravado pega o "excelente" geral, em vez de virar faixa invertida.
- **Equipe fora do envio é APAGADA** — é assim que o botão de remover linha
  funciona sem uma action de exclusão. O DELETE mira só as chaves COM sufixo
  (`mtta_good.%` / `mtta_ok.%`): um DELETE largo levaria junto a meta padrão e a
  tela voltaria ao limite de fábrica sem ninguém ter pedido.
- **Lote inválido é recusado inteiro**, não gravado pela metade: configuração
  parcial é um estado que ninguém pediu.
- A equipe entra por **multiselect nativo** (`object_name: usersGroups`), mesmo
  motivo do filtro de grupos — com muitas empresas, um `<select>` com o catálogo
  inteiro é uma lista impossível; aqui quem busca é o servidor.
- **O par de cima é a meta PADRÃO**, e vale para toda equipe que não estiver
  listada abaixo: declarar a empresa B não muda nada para a A. O diálogo dizia
  só "valem para o selo", e o Rafael precisou perguntar se era global — sinal de
  rótulo ruim, corrigido na v5.10.1: a seção passou a se chamar "Meta padrão",
  com a frase "vale para todo analista que não tiver meta de equipe declarada
  abaixo", e a seção das exceções explica que equipe não listada usa o padrão.

**Uma armadilha de PostgreSQL no caminho:** o sufixo da chave é TEXTO e os ids
são inteiros — `SUBSTRING(...) NOT IN (7)` falha com *"operator does not exist:
text <> integer"*. Um CAST resolveria, mas estouraria numa chave malformada;
comparar texto com texto (`NOT IN ('7')`) não tem esse risco. `SUBSTRING(x FROM
POSITION('.' IN x) + 1)` é padrão SQL e vale nos dois bancos.

**Conferido:** o SELECT de leitura, o DELETE de uma equipe e o de todas, em
transação revertida contra o banco de produção (padrão sempre intacto); e a
resolução linha a linha fora da tela — empresa A 5/30, empresa B só com metade
configurada herdando o padrão, analista sem meta caindo no padrão, analista em
duas equipes ficando com a mais rígida, e meta gravada invertida sendo saneada
na leitura.

**Não validado no lab** — a tela e o diálogo. Conferir: salvar uma meta de
equipe, ver a dica do selo mudar na linha de quem é daquela equipe, remover a
linha e confirmar que o padrão continua de pé.

### Texto cortado dentro dos diálogos (2026-09-04, v5.10.2)

A explicação das metas por equipe aparecia truncada no meio da palavra ("então
vale linha a li"). Não era largura de fonte nem excesso de texto: é regra do
tema do Zabbix.

```css
.overlay-dialogue-body            { white-space: nowrap; }
.overlay-dialogue.modal .overlay-dialogue-body { overflow-x: hidden; }
```

Parágrafo dentro de diálogo **não quebra linha**, e o que passa da largura é
cortado sem reticência e sem barra de rolagem — some, e some no meio da palavra.
O próprio Zabbix contorna isso com a classe `wordbreak` (é o que o `hintBox`
aplica na caixa dele).

A correção vale para o **corpo inteiro dos dois diálogos do módulo**, e não item
a item: assim vale também para o próximo texto que alguém acrescentar, sem
depender de lembrar de uma classe. O diálogo de limites foi de 460 para 560px de
largura, já que ele ganhou a seção de metas por equipe.

Fica o registro para as próximas telas: **texto explicativo dentro de
`overlay-dialogue` precisa de `white-space: normal`** — o padrão ali é não
quebrar, o que faz sentido para as tabelas e formulários que o Zabbix costuma
pôr em diálogo, e não para prosa.

### Rótulos do multiselect em PT-BR (2026-09-04, v5.10.3)

A caixa de busca abria com "type here to search" no meio de uma tela inteira em
português. O componente nativo traz os rótulos dele traduzidos pelo idioma **do
frontend**, e esta instalação roda em `en_US` (`settings.default_lang`) — então
não era falta de tradução, era a tradução da instalação.

O módulo é PT-BR fixo por convenção (regra do CLAUDE.md), então os cinco rótulos
do componente passam a vir daqui:

    No matches found      → Nenhum resultado encontrado
    More matches found... → Há mais resultados — refine a busca
    type here to search   → digite para buscar
    new                   → novo
    Select                → Selecionar

**Ficam num lugar só** (`$rp_ms_labels`, na view) porque servem às DUAS caixas:
a de grupos de host, montada em PHP pelo `CMultiSelect`, e a de equipe, montada
em JS uma por linha de meta. Duas listas de tradução divergiriam na primeira vez
que alguém corrigisse uma delas.

**Detalhe de implementação:** `labels` não passa pelo construtor do
`CMultiSelect` — ele monta o dele com `_()` e a lista de opções aceitas
(`$options_list`) não inclui esse campo. A troca é feita no `data-params` já
pronto (`getParams()` → ajusta → `setAttribute()`), que é de onde o plugin lê
tudo. `placeholder` até passaria pelo construtor, mas fica junto do resto, no
mesmo lugar.

Conferido no HTML renderizado: os cinco rótulos e o placeholder saem em
português no `data-params`.

### "Erro de conexão" ao salvar meta de equipe: faltava o `die()` (2026-09-04, v5.10.4)

Sintoma enganoso: a tela dizia "Erro de conexão" e **o log do PHP não tinha
nada** — porque, do lado do PHP, nada falhou.

`TurnosSettingsSave` imprimia o JSON e voltava para o framework. Só que o
manifest declara `layout.javascript` para ela, e o `ZBase::processResponseFinal()`
faz:

```php
if ($router->getLayout() !== null) {
    if (!($response instanceof CControllerResponseData)) {
        throw new Exception(_s('Unexpected response for action %1$s.', …));
```

Sem `setResponse()`, o Zabbix lança a exceção **depois** do JSON já impresso: o
corpo da resposta vira `{"success":true,…}` seguido do HTML de erro. O
`Content-Type` continua `application/json` (o `header()` saiu primeiro), então a
guarda de content-type do JS passa — e quem estoura é o `r.json()`, caindo no
`.catch()`, que mostra "Erro de conexão". Daí o diagnóstico enganoso: parece
rede, é resposta suja.

As outras actions AJAX do módulo já terminavam com `$db->close(); die();` — esta
nasceu sem, e o defeito só aparece quando a action é de fato chamada.

Correção: um `responder()` privado que imprime, fecha o banco e encerra. Todas as
saídas passam por ele, inclusive as de validação — antes elas usavam `return`,
que tinha o mesmo problema e só não apareceu porque ninguém tinha caído numa
ainda.

Auditei as nove actions `layout.javascript` do módulo: todas encerram a
requisição. A regra entrou nas convenções do módulo, porque o modo de falha é
mudo no servidor e mentiroso na tela.

### Rótulo de coluna nas metas por equipe (2026-09-04, v5.10.5)

Depois de salvar, a linha ficava só com dois números e ninguém lembrava qual era
qual — os `placeholder` ("exc.", "aceit.") somem justamente quando o campo é
preenchido, que é quando a informação passa a fazer falta. O `title` também não
resolve: exige descobrir que existe.

Entrou um cabeçalho de coluna — **Grupo de usuário · Excelente (min) · Aceitável
(min)** — com a unidade no rótulo, que também não estava escrita em lugar nenhum
da linha.

> A primeira tentativa alinhava por geometria repetida (cabeçalho em flex com as
> mesmas larguras da linha) e **desalinhou** — corrigido na v5.10.6 com uma grade
> única; ver lá.

Três detalhes que a implementação exigiu:

- **O cabeçalho repete a geometria da linha** (mesmo flex, mesmas larguras),
  porque é assim que cada rótulo fica sobre o campo certo. Inclusive uma célula
  vazia reservando a largura do botão de remover: sem ela, os dois rótulos de
  número escorregam para a direita.
- **Classe própria, e não a classe da linha.** O JS pergunta "já existe linha?"
  com `#rpLimEquipes .rp-lim-equipe` para decidir se carrega as metas salvas —
  se o cabeçalho levasse essa classe, a resposta seria sempre "sim" e as metas
  gravadas nunca apareceriam ao abrir o diálogo.
- **`hidden` precisou de regra explícita** — pela TERCEIRA vez neste módulo (ver
  `.rp-bulk-bar` e a caixa de valor da tag). O atributo vale `display:none` na
  folha do navegador e perde para o `display:flex` da classe; sem a regra, o
  cabeçalho apareceria sozinho, sem nenhuma equipe embaixo.

### O cabeçalho desalinhou: alinhar por grade, não por largura repetida (2026-09-04, v5.10.6)

O cabeçalho da v5.10.5 saiu torto. Duas causas, e as duas vinham de tentar
alinhar **repetindo a geometria**: cabeçalho em flex com as mesmas larguras da
linha.

1. **"Excelente (min)" não cabia na coluna de 76px** (≈84px a 11px), então
   quebrava em duas linhas e empurrava a linha inteira.
2. **A largura do botão de remover era palpite.** Reservei 26px no cabeçalho
   chutando o tamanho de um `.rp-action` com ícone — e chute de largura só acerta
   por acidente.

Agora cabeçalho e linhas compartilham a **mesma grade** (`#rpLimEquipes`), e
cada linha entra nela com `display: contents`: os filhos da linha viram células
da grade de cima. Rótulo e campo caem na mesma coluna **por construção**, sem
ninguém repetir medida — a coluna do botão é `auto`, então ela vale o tamanho
real dele, seja qual for.

Os rótulos ficaram abreviados como o Rafael sugeriu (**Exc. (min)** / **Aceit.
(min)**), com `white-space: nowrap`: rótulo que quebra desfaz o alinhamento que
a grade acabou de garantir. E os números do cabeçalho ganharam o mesmo
`padding-right: 8px` do input, para o texto do rótulo ficar sobre o texto do
campo, e não sobre a borda dele.

Fica a lição, que vale para as próximas telas do módulo: **duas listas de
larguras que precisam concordar sempre acabam discordando.** Onde há cabeçalho e
linha, a grade única resolve por construção; flex com medidas repetidas é dívida.

### MTTA que respeita o turno, e MTTA por severidade (2026-09-04, v5.11.0)

Dois pedidos, e o primeiro é uma correção de justiça na conta.

**1. Empresa sem cobertura noturna estava sendo punida pela madrugada.** Alarme
abre 00:01, o analista entra 07:00 e reconhece 07:01: o tempo de RESPOSTA dele
foi 1 minuto, mas a conta crua (`ack − abertura`) dizia 7 h e o selo de
Performance o marcava de vermelho por uma janela em que, por contrato, não havia
ninguém.

Agora cada equipe tem uma caixa **24/7** ao lado da meta. Marcada (padrão, que é
o comportamento de sempre), nada muda. Desmarcada, o relógio começa no que vier
DEPOIS: a abertura do alarme ou o início do turno do analista.

O detalhe que erra de dia se for feito na pressa: o início do turno é uma HORA
(`19:00:00`), não um instante — a ocorrência que vale é a **mais recente antes
do ACK**. Para um ACK às 02:00 de um turno que começa 19:00, é o 19:00 de ONTEM;
comparar com o de hoje daria início no futuro e MTTA negativo. Conferido:

    alarme 00:01 → ACK 07:01, turno 07:00     24/7: 7h00   |  não-24/7: 0h01
    alarme 08:00 → ACK 08:05 (dentro do turno)             |  0h05 (não muda)
    turno 19:00, alarme 20:00 → ACK 02:00                  |  6h00 (não muda)
    turno 19:00, alarme 12:00 → ACK 02:00     cru: 14h00   |  ajustado: 7h00

**2. MTTA por Severidade**, card novo — responde "o mais grave é atendido
primeiro?", que o MTTA médio sozinho esconde. Barras HORIZONTAIS porque são até
seis categorias de nome comprido ("Não classificado"): na horizontal o rótulo
cabe sem girar texto. Cada barra usa a cor real da severidade, a mesma da rosca
ao lado.

**Layout:** os dois blocos por severidade na primeira linha, lado a lado (mesma
chave, mesma paleta, comparam-se de relance), e **MTTA por Hora sozinho na
segunda**, em largura inteira. Foi o que o Rafael propôs e é o certo: o gráfico
por hora é série temporal com até 24 rótulos no eixo, e espremido em 2fr os
rótulos colidem — problema que a Recorrência já teve e que está documentado no
CLAUDE.md da suíte.

**A refatoração que os dois pedidos tornaram inevitável.** Havia duas consultas
quase idênticas agregando no banco (`queryMTTA` e `queryMttaTimeline`), e a
terceira visão pediria uma terceira. Pior: o corte por turno depende do turno DE
CADA ANALISTA — informação de tabela do MÓDULO, que pela regra da casa não pode
entrar por JOIN na consulta do dado principal (se a tabela não existir no
ambiente, o dado principal some inteiro).

As duas viraram **uma consulta de linhas cruas** (`queryAckRows`) e três
agregações em PHP. Ganhos: o ajuste por turno é aritmética, as três visões não
podem divergir entre si, e o turno de cada analista vem em consulta separada —
se ela falhar, perde-se o ajuste, não o relatório. O volume é o dos ACKs da
janela do turno, não o histórico.

Os quatro chamadores (tela, PDF, fechamento de turno e o próprio card) passaram a
usar o mesmo caminho: dois cálculos diferentes dariam dois MTTAs para o mesmo
turno.

**Não validado no lab** — conferir com dados reais: uma equipe marcada como não
24/7 e um alarme da madrugada reconhecido na entrada do turno; o card novo com
severidades de nome longo; e o PDF, que precisa mostrar o mesmo MTTA da tela.

### A refatoração do MTTA levou três métodos vizinhos junto (2026-09-04, v5.11.1)

O Repasse inteiro parou com *"Não foi possível carregar os dados do relatório"*.
Causa: ao remover `queryMTTA()` e `queryMttaTimeline()` na v5.11.0, o corte
apagou também **`getUserRoleType()`, `resolveUserContext()` e
`sameGroupExists()`** — 188 linhas que estavam entre a âncora usada e o método
que se queria remover.

Os três são a base de tudo (papel do usuário, filtro de permissão por host,
segmentação por grupo), então a primeira chamada estourava e o `catch` do
controller devolvia a mensagem genérica.

**Por que passou pelo `php -l` e pelos testes:** apagar um método inteiro não é
erro de sintaxe, e a suíte é de funções puras — não instancia o trait. O sintoma
só aparece em tela.

**A verificação que faltava, e que agora está no roteiro:** depois de remover ou
mover método, comparar o INVENTÁRIO de métodos com o do commit anterior, e
conferir que toda chamada `$this->…` das actions ainda resolve. É uma linha de
`preg_match_all` e teria apontado o dedo em segundos:

    métodos removidos: queryMTTA, queryMttaTimeline
    métodos novos:     mttaAdjust, queryAckRows, queryAnalystShiftStart, queryMttaData

**Lição sobre a ferramenta, não sobre o código:** o corte foi feito ancorando em
`rindex('    /**')` para pegar o docblock do método — mas `rindex` anda para trás
até o PRIMEIRO docblock que encontrar, que pode ser o de outro método muitos
métodos acima. Recorte por âncora textual precisa ser verificado pelo que SOBROU,
nunca só pelo que saiu.

Restauração feita a partir do `git show HEAD:` — os três voltaram idênticos ao
original, conferido linha a linha.

**Nota de ambiente:** o `error_log()` do módulo não aparece no log deste pool
(`catch_workers_output` comentado em `/etc/php-fpm.d/www.conf`), então a falha
foi diagnosticada por inventário de métodos, não pelo log. Ligar essa opção
continua sendo o que o CLAUDE.md recomenda para 500 sem rastro.

### Ordem dos dois blocos por severidade (2026-09-04, v5.11.2)

A "Distribuição por Severidade" tinha trocado de lado ao ganhar o vizinho novo,
e o Rafael pediu para inverter. Os dois trocaram de posição: **MTTA por
Severidade à esquerda, Distribuição à direita**.

Além do hábito — que já bastaria —, a ordem nova cai melhor nas larguras: a
linha é `3fr 2fr`, então as barras horizontais ficam na coluna larga (é a
largura que decide se o rótulo "Não classificado" cabe sem girar texto) e a
rosca volta para a coluna estreita, para a qual já vinha dimensionada.

**Achado ao mexer:** a guarda de "Chart.js não carregou" listava só
`chartMtta` e `chartSev` — o canvas novo ficaria em branco **sem mensagem
nenhuma**, que é exatamente o que essa guarda existe para impedir. Corrigido, e
a lista ganhou um comentário dizendo que precisa citar TODOS os canvas da tela.

### Corte por turno: de onde sai a hora de início (2026-09-04, v5.11.3)

O Rafael perguntou se equipe não declarada é tratada como 24/7 (é: o corte é
opt-in) e reafirmou a regra do corte. Ao conferir, apareceu uma brecha em que o
ajuste existia e **não se aplicava, em silêncio**.

A hora de início vinha só do turno VINCULADO ao analista em Gerenciar Turnos.
Sem vínculo, caía no início da janela do relatório — e quem estivesse vendo o
Repasse em "24 Horas" tinha janela começando 00:00, ou seja, corte nenhum: o
alarme das 00:01 continuava valendo 7 h.

Agora a origem tem três degraus, do mais específico ao mais geral:

1. **turno vinculado ao analista** — o mais específico;
2. **turnos da EQUIPE dele** (`module_plantonistas_shifts.usrgrpid`), quando não
   há vínculo individual: é a equipe que define a cobertura, o vínculo só diz em
   qual turno a pessoa está;
3. **início da janela do relatório**, se não houver turno cadastrado nenhum.

E o cálculo passou a receber uma LISTA de horas, não uma só, porque a equipe
costuma ter mais de um turno. Vale a ocorrência **mais recente, entre todos os
turnos, até o momento do ACK**:

    ACK 07:01, turnos 07:00 e 19:00  → 07:00 de hoje
    ACK 02:00, turnos 07:00 e 19:00  → 19:00 de ONTEM

Conferido:

| caso | cru | ajustado |
|---|---|---|
| alarme 00:01, ACK 07:01, turno 07:00 | 7h00 | **0h01** |
| o mesmo, equipe 24/7 | 7h00 | 7h00 |
| sem vínculo, equipe com 07:00 e 19:00 | 7h00 | **0h01** |
| turno 19:00, alarme 12:00, ACK 02:00 | 14h00 | 7h00 |
| alarme 08:00 → ACK 08:05 (dentro do turno) | 0h05 | 0h05 |
| nenhum turno cadastrado (janela 24h) | 7h00 | 7h00 |

A dica da caixa e o texto do diálogo passaram a dizer isso com o exemplo — a
pergunta do Rafael mostrou que "24/7" sozinho não explica o que muda no cálculo.

### Por que 24/7 ligado NÃO considera o turno (2026-09-04)

Confirmação de regra, sem mudança de código — o comportamento já era este, e o
motivo agora está escrito onde alguém pode querer "melhorar" depois.

Com **24/7 ligado**, o MTTA conta desde a abertura do alarme, mesmo que o ACK
tenha saído no turno seguinte. O caso que define a regra, nas palavras do
Rafael: alarme às 00:05, o plantonista da madrugada dorme e passa o turno sem
reconhecer, quem entra às 07:00 dá o ACK às 07:06. **O atraso foi de 7h01 e tem
de aparecer como 7h01** — havia gente escalada, e encurtar o número esconderia a
falha de cobertura justamente de quem precisa vê-la.

O expurgo da madrugada existe para o caso OPOSTO: equipe que, por contrato, não
cobre aquele horário. Ali ninguém falhou, e cobrar as 7 h seria punir o analista
por uma janela em que ele nem devia estar.

Conferido no código real:

    alarme 00:05 → ACK 07:06, turnos 07:00 e 19:00
      24/7 ligado ..... 7h01  (= a conta crua, sem corte)
      24/7 desligado ... 0h06

Ou seja: **quem manda no expurgo é a flag, e só ela.** O turno do analista é
consultado apenas quando ela está desligada.

### `/usr/bin/php: No such file or directory` nas unidades systemd (2026-09-04, v5.11.4)

O `--services` da v5.5.1 rodou em produção e os três serviços subiram quebrados:

    plantonistas-presence.service: Failed to locate executable /usr/bin/php: No such file or directory
    Failed to start plantonistas-presence.service

**O caminho do PHP era um chute que virava literal.** O script resolvia o
interpretador com

    php_bin=$(command -v php || echo "/usr/bin/php")

e `sudo` não usa o PATH de quem chamou: usa o `secure_path` do `/etc/sudoers`.
Um PHP fora dele — Remi, SCL, `/usr/local/bin` — é invisível para o `command -v`,
o `|| echo` entrega `/usr/bin/php` como se fosse fato, e esse texto é escrito
no `ExecStart` da unidade. O systemd não tem como saber que aquilo é palpite:
ele tenta executar e falha no boot, longe de quem instalou.

Agora `detect_php_bin()` procura, nesta ordem: `PHP_BIN` (quem digitou manda),
o PATH (`php`, `php8.4`…`php8.0`), `/usr/local/bin/php`, `/usr/bin/php`,
`/usr/bin/php8.*`, `/opt/remi/php8*/root/usr/bin/php`,
`/opt/rh/php*/root/usr/bin/php` e `/usr/local/php*/bin/php`. E
`check_php_bin()` **valida antes de escrever**: o binário tem de existir,
executar (`php -r 'exit(0);'`) e ter o driver PDO do banco resolvido. Sem isso o
script recusa com o comando pronto (`PHP_BIN=/caminho ... --services`) em vez de
gerar unidade que só falha depois.

`--show-config` passou a imprimir o PHP e a versão, ao lado do banco — a
descoberta inteira, sem escrever nada.

**Quatro canos que morriam por SIGPIPE sob `set -o pipefail`**, achados ao
verificar a correção. É a armadilha mais cara desta rodada, porque **testar uma
vez não a revela**:

    "$php_bin" -m 2>/dev/null | grep -qi "^${driver}$"

`grep -q` sai no primeiro casamento; o `php` que ainda estiver escrevendo leva
SIGPIPE e termina com 141; e o `pipefail` entrega esse 141 como status do cano
INTEIRO, mesmo tendo o grep achado o que procurava. Quem termina primeiro é
corrida: medido neste host, **85 falhas em 200 execuções** do mesmo comando. A
primeira medição isolada passou limpa e quase enterrou o diagnóstico.

O sintoma variava conforme o lugar:

| onde | o que acontecia |
|---|---|
| `check_php_bin` | avisava "não tem a extensão pdo_pgsql" para um PHP que tem |
| `find … \| head -1` (busca do `zabbix.conf.php`) | sob `set -e`, **matava o script calado** no meio da descoberta — e com mais de um candidato, que é o caso de produção |
| `sed … \| head -1`, última linha de função | o status do cano vira o da função; o chamador morre |
| o mesmo `sed` dentro do laço de `read_job_env_value` | idem |

Correção igual nos quatro: a saída vai para uma VARIÁVEL e o recorte é feito
nela (`${out%%$'\n'*}` para a primeira linha, `grep <<<` para a busca). Sem
cano, sem corrida.

**Regra que fica:** `| head`, `| grep -q` e qualquer consumidor que sai cedo são
proibidos sob `set -o pipefail` — não porque falham, mas porque falham às vezes.
Isso soma-se às duas armadilhas de `set -e` já registradas na v5.5.4
(`[[ … ]] && cmd` como última linha de função, e `$(func)` carregando o
`return 1`).

**Verificado neste host**: dez execuções seguidas de `--show-config` sem um
falso aviso sequer; `PHP_BIN` respeitado; `PHP_BIN` inválido recusado com a
instrução certa; `sudo ./install.sh --show-config` achando o PHP; e o
`--services` em modo seco gerando
`ExecStart=/usr/bin/php /…/scripts/cron_presence_tracker.php` — caminho
detectado, não literal.

**Falta rodar em produção**: `sudo scripts/install.sh --show-config` (confere o
que ele descobre) e depois `sudo scripts/install.sh --services`, que é
idempotente e reescreve as unidades. Se o PHP de lá não estiver em lugar
previsto, a mensagem agora diz qual variável usar.

**Segunda rodada em produção: o `PHP_BIN` apontado era o do FPM.** A busca não
achou CLI nenhuma em PRD (indício forte de que só há `php-fpm` instalado lá), o
Rafael apontou `PHP_BIN=/usr/bin/php-fpm` e o script recusou — certo — dizendo a
coisa errada: *"existe mas não executa. Confira permissão e dependências dele."*
Não é permissão nem dependência: **é outro programa**. O binário do FPM atende o
frontend por socket e não sabe executar script por `-r` nem por arquivo.

Três coisas mudaram por causa disso:

- **Distinguir CLI de FPM não pode depender do código de saída.** Neste host
  `php-fpm -r` imprime o próprio modo de usar e sai com **64**; há build que faz
  o mesmo saindo com **0** — e aí o binário errado passaria no teste. O que não
  dá falso positivo é mandar o interpretador ECOAR uma sentinela
  (`php_runs_code()`): só quem de fato executa `-r` devolve o texto. É o mesmo
  raciocínio do resto do módulo — confiar no efeito observado, não no status.
- **A busca valida cada candidato** com essa sentinela, e `php-fpm`/`php-cgi`
  ficam fora da lista por nome. As duas redes são necessárias: um `/usr/bin/php`
  que seja link para o FPM passa no `-x` e viraria unidade quebrada de novo.
- **Cada falha tem diagnóstico próprio**, porque o conserto é diferente em cada
  uma: `PHP_BIN` inexistente, `PHP_BIN` sem permissão, binário de FPM/CGI
  (manda instalar `php-cli`), e binário que não executa (manda olhar permissão,
  SELinux e `ldd`).

Detalhe achado no caminho: o `--show-config` montava a linha da versão com
`$("$php_bin" -r 'echo PHP_VERSION;')`, e com o FPM o **modo de usar inteiro ia
parar dentro da linha "PHP: …"**, ocupando a tela no lugar do número. A versão
agora só é impressa depois de o binário provar que executa código.

**Nota de ambiente para PRD:** frontend RHEL/Amazon com `php-fpm` instalado
**não tem CLI garantida** — `php-cli` é pacote separado, e o `php-fpm` não
depende dele. Como os três coletores do módulo são CLI, esse host precisa do
pacote. Não há como contornar pelo FPM.


### `plantonistas-oncall.service` em failed: pendência não é erro (2026-09-04, v5.11.5)

Com os coletores agendados, o serviço de escalonamento aparecia em `failed` no
lab **e** em produção:

    Job for plantonistas-oncall.service failed because the control process exited with error code

O script não estava quebrado — o log mostra que ele fez exatamente o que devia:

    Equipes com escala desde 2026-09-02: 1
    WARN: [Zabbix administrators] grupo "Plantonista de Hoje - Zabbix administrators" não existe …
    Resumo: 0 sincronizada(s), … 1 com erro.

Ele encerrava com `exit(1)` porque contava aquilo como erro. **O defeito é de
classificação**, e só apareceu quando o agendamento saiu do cron para o systemd:

| | cron | systemd |
|---|---|---|
| `exit(1)` vira | linha num log / e-mail que ninguém lê | unidade em **`failed` permanente** |

E "permanente" é o problema: a pendência se repete a cada ciclo até alguém criar
o grupo, então a unidade fica vermelha para sempre — `systemctl restart` não
apaga, porque não há nada de errado com a execução. Luz vermelha que ninguém
consegue apagar é a que faz a PRÓXIMA falha, a de verdade, passar despercebida.

**Os dois contadores agora são separados**, e a régua é "quem resolve isto?":

- **ERRO** — falha de execução: banco fora, consulta quebrada, exceção ao
  sincronizar. Pode ser passageira e justifica acordar alguém. **Sai com 1.**
- **PENDÊNCIA** — estado que só uma pessoa resolve na UI do Zabbix: grupo de
  destino que ainda não existe, `ONCALL_GROUP_PREFIX` apontando para o próprio
  grupo da equipe, grupo de destino desabilitado, plantonista removido ou
  desabilitado. O script já fez tudo que podia. **Sai com 0**, com a linha
  `PENDENTE:` no log e a contagem no resumo.

O resumo passou a trazer as duas (`… 1 pendente(s), 0 com erro.`) e, quando há
pendência sem erro, sai uma linha final dizendo isso — `systemctl status` mostra
as últimas linhas do log, e sem ela a unidade verde com aviso no meio pareceria
contraditória.

**O que NÃO foi feito, e por quê:** dava para calar a unidade com
`SuccessExitStatus=1` no `.service`. Seria mais curto e esconderia junto toda
falha real, que é o oposto do que a unidade existe para mostrar. Também não se
criou o grupo automaticamente — continua valendo a decisão registrada em
"Escala alimenta o escalonamento do Zabbix": criar grupo exigiria reservar
`usrgrpid` na tabela `ids`, que foi o que causou o incidente do `role_rule`.

**Verificado neste host:** senha de banco errada → `exit=1` e unidade em failed
(como deve ser); configuração correta com o grupo faltando → `exit=0`,
`Result=success` nas três unidades, e a pendência gritando no log.

**A pendência em si continua de pé e é ação de quem opera** — em lab e em PRD.
Para cada equipe que tem escala, criar em *Usuários → Grupos de utilizadores* um
grupo vazio e **Habilitado** chamado `<ONCALL_GROUP_PREFIX> - <nome da equipe>`
(aqui: `Plantonista de Hoje - Zabbix administrators`). Sem ele o escalonamento
não tem para onde sincronizar, e é isso que a linha `PENDENTE:` diz.

### A tabela `config` não existe mais, e o `@` pagou a conta (2026-09-04, v5.12.0)

O `@` do Diário de Bordo não devolvia **ninguém** — nem com texto digitado, nem
vazio. `_h` e `_hg` funcionavam, o que descartava JS, permissão e rota.

**Causa:** `notBlockedUserClause()` faz

    u.attempt_failed < (SELECT MIN(login_attempts) FROM config)

e **`config` não existe neste Zabbix**. O lab roda **7.4** (`dbversion` 7040000),
onde a configuração global deixou de ser uma linha da tabela `config` — com uma
coluna por parâmetro — e virou a tabela **`settings`**, chave/valor, com o valor
em `value_str` ou `value_int` conforme o tipo.

Consultar tabela inexistente não devolve vazio: é **erro de SQL**. O `catch` da
busca fazia o resto — `return []` com log — e a tela dizia, em silêncio, que não
havia ninguém para mencionar. Só o ramo de usuário quebrava porque só ele
consultava `config`.

**O mesmo defeito estava, mudo, em `querySeverities()`**, que lê
`severity_name_0..5`/`severity_color_0..5` da mesma tabela. Ali o fallback é o
padrão de fábrica, então nada parecia errado — mas a v5.1.0 inteira ("cores e
nomes REAIS de severidade") **nunca funcionou nesta instalação**: qualquer
customização em Administração → Geral era descartada sem aviso.

Agora `configTable()` sonda o `INFORMATION_SCHEMA` uma vez por requisição e
`queryGlobalSettings()` lê dos dois formatos. `settings` ganha da `config`
quando as duas existirem — num upgrade elas coexistem, e a que vale é a nova.

Uma armadilha do formato novo: **`COALESCE(value_str, value_int)` não serve.**
Parâmetro numérico tem `value_str` como string VAZIA, não NULL — o COALESCE
devolveria `''` e o número sumiria. É o caso do `login_attempts` deste ambiente
(`value_str` = `''`, `value_int` = 5). Quem decide é a coluna `type`.

**Busca insensível a caixa E a acento** (a segunda queixa: "aceita escrita
exatamente idêntica"). `searchHostgroups()` e `searchHosts()` comparavam com
`LIKE` cru, sem `LOWER()` — no PostgreSQL isso exige acerto exato de maiúscula,
e é a regra da casa que já estava escrita e foi furada. Pior no ambiente real:
ninguém digita "Leão" para achar um colega, digita "leao".

`SqlFn::foldText()`/`foldTerm()` dobram caixa e acento dos dois lados. O
dobramento é `REPLACE` aninhado porque é o que existe NOS DOIS bancos:
`TRANSLATE` é do PostgreSQL e `unaccent()` exige extensão instalada — nenhum dos
dois pode virar pré-requisito de um módulo que se instala onde já há um Zabbix.

**E o custo é proporcional ao termo, não fixo.** A primeira versão emitia os 24
pares sempre: com 4 colunas × 5 termos, vinte pilhas de 24 `REPLACE` numa
consulta só — sobre `hosts`, que neste ambiente é grande. Só entra o par cuja
letra simples aparece no termo: para casar, o caractere dobrado da coluna tem de
ser igual a algum do termo, então o acento de `ç` não muda nada em quem procura
"leao". Medido:

| termo | REPLACEs |
|---|---|
| `srv01` | **0** (volta a ser `LOWER()` puro) |
| `jose` | 9 |
| `leao` | 14 |

As três buscas passaram a usar o mesmo motor (`buildSearchTerms()`): cada
palavra é uma condição AND que precisa aparecer em alguma coluna, então a ordem
digitada não importa e dá para misturar nome e login. Antes só a de usuário
fazia isso. Curinga do LIKE digitado (`%`, `_`) passou a ser escapado com
`ESCAPE '!'` — sem isso um `%` casava com o cadastro inteiro.

`hstgrp.type = 0` entrou na busca de grupo: sem ele o `_hg` oferecia grupo de
TEMPLATE, que não tem host nem evento.

**A caixa flutuante.** A translucidez tinha causa concreta:

    animation: rp-mention-in 0.14s ease-out;   /* fill-mode: none */
    @keyframes rp-mention-in { from { opacity: 0 } to { opacity: 1 } }

O ciclo de digitação é fechar → buscar → reabrir, e **cada reabertura reinicia a
animação**: teclando depressa, a caixa vivia nos primeiros quadros — ou seja,
permanentemente semitransparente e piscando. A opacidade saiu do `@keyframes`
(sobrou o deslize, que já diz o que precisa) e o `fill-mode` virou `both`.

Junto, o fundo ganhou **cor literal antes do token**
(`background: #fff; background: var(--rp-white, #fff)`): a caixa flutua por cima
do texto da nota, e se o token não resolver — folha não carregada, tema detectado
tarde, CSS velho em cache — `var()` sem reserva vira `transparent` e a lista
aparece por cima das letras. Vale para o cabeçalho e o rodapé, que são `sticky`.

Estrutura e mouse: linha de ~40px (alvo de clique confortável), barra azul à
esquerda no item ativo — o realce só por fundo é sutil demais no tema escuro para
dizer onde o Enter vai agir — e rodapé anunciando **"clique para inserir"**. O
clique sempre funcionou (`mousedown` com `preventDefault`, que é o certo num
`contenteditable`: o `click` chega depois de o editor já ter perdido a seleção);
faltava dizer. Entrou um `click` como segunda via para toque e leitor de tela.

**Achado que fica de aviso:** este Zabbix renderiza `<body>` **sem classe e sem
`data-theme`**. As 90 regras de tema escuro do módulo dependem inteiramente da
detecção por luminância do `views/_theme.php`, que marca `data-theme` e
`theme-dark-blue` por JS. Se ela falhar, tudo cai no tema claro — mais uma razão
para a cor literal de reserva.

**Verificado contra o banco real** (PostgreSQL 7.4): a busca de usuário devolve
3 nomes onde antes devolvia zero; `settings` lida nos dois formatos; a consulta
de grupo com `type = 0`; e o dobramento fazendo `leao`/`LEAO`/`leão`/`LEÃO`
convergirem. Suíte: 48 (eram 45).

**Falta ver na tela**, que é o que não dá para verificar daqui: o `@` listando,
a aparência da caixa nos dois temas e o clique inserindo.

### A lista de menções era translúcida por um `!important` do editor (2026-09-04, v5.12.1)

A v5.12.0 tratou a animação (opacidade presa no primeiro quadro) e pôs cor
literal de reserva no fundo — e a caixa **continuou translúcida**. Faltava a
causa real, que estava a 300 linhas dali:

```css
[data-theme="dark-theme"] .rp-editor,
… .rp-editor-btn,
… .rp-mention-dropdown {
    background: rgba(0, 0, 0, 0.15) !important;
}
```

A lista tinha sido agrupada no seletor do **editor**. Para o editor o véu está
certo: ele repousa sobre o card e o translúcido dá profundidade. Para a lista é
defeito, porque ela **flutua sobre o texto que a pessoa está escrevendo** — 15%
de preto deixa as letras da nota aparecendo por entre os nomes. E o
`!important` vencia o `background` sólido declarado na própria classe, que é por
que o conserto anterior não teve efeito nenhum.

Lição, que vale além deste caso: **agrupar seletores por semelhança de aparência
junta coisas que têm camadas diferentes.** Editor e lista pareciam "as duas
superfícies do Diário de Bordo"; uma está NO documento e a outra POR CIMA dele,
e essa diferença decide se transparência é acabamento ou defeito.

**A lista passou a ter paleta própria, e é a única peça do módulo que não usa os
tokens `--rp-*`.** Três razões, todas anotadas no CSS:

1. **Opacidade não é negociável ali.** Toda cor do painel é chapada; não há
   `rgba` no fundo de nada que fique sobre texto.
2. **Não pode depender da detecção de tema.** Este Zabbix (7.4) renderiza
   `<body>` sem classe e sem `data-theme` — quem decide claro/escuro é o JS de
   luminância do `_theme.php`. Se ele demorar, falhar, ou o CSS vier de cache, a
   caixa cairia no token claro: branco sobre branco. Com paleta literal ela
   nasce certa antes de qualquer JS rodar.
3. **Escura nos dois temas, por decisão** — foi o pedido ("mais elegante, não
   tão clara"). É o padrão de paleta de comandos (Spotlight, Slack, VS Code): o
   painel escuro se destaca do documento claro sem competir com ele, e no tema
   escuro continua coerente.

O resto é acabamento: sombra em duas camadas (a difusa eleva, a rente desenha o
contorno que a borda sozinha perde em fundo escuro), contagem em pílula no
cabeçalho, ícone em quadradinho que inverte no item ativo, segunda linha com o
username em `ellipsis` (exige `min-width: 0` no filho flex, senão o corte nunca
acontece), cabeçalho e rodapé `sticky` com fundo chapado, e `color-scheme: dark`
para a barra de rolagem que o NAVEGADOR desenha não sair clara dentro do painel
— mesma razão do `.rp-native-container` em Gerenciar Turnos.

Teclado e ponteiro recebem o MESMO destaque (`:hover` e `.rp-mention-active` na
mesma regra): navegar com um e clicar com o outro tem de parecer a mesma lista.

Verificado no arquivo: chaves balanceadas, nenhuma outra regra mirando
`.rp-mention-dropdown`/`.rp-mention-opt`, nenhum `!important` sobrando em
`rp-mention`, e nenhum `rgba` no fundo do painel. **A aparência em si continua
precisando de olho na tela** — é CSS, não entra na suíte.

### Barra verde nos botões do editor, e protótipo de host na busca (2026-09-04, v5.12.2)

**1. A barra verde e os ícones tortos foram defeito MEU, da v5.12.1.** Ao tirar
a lista de menções do seletor do editor, apaguei o CORPO da regra e deixei a
lista de seletores pendurada com vírgula:

```css
[data-theme="dark-theme"] .rp-editor,
…
body[class*="dark"] .rp-editor-btn,     ← vírgula, e nenhum { } depois
/* comentário */
.rp-closed-banner {                      ← o parser emendou AQUI
    border-left: 4px solid var(--rp-green);
    margin-bottom: 14px;
}
```

O CSS não tem erro de sintaxe nisso — a vírgula só continua a lista, e o
navegador aplicou a faixa de "turno fechado" ao editor e a cada botão da barra.
Daí a barrinha verde **e** o desalinhamento: `border-left: 4px` empurra o
conteúdo do botão para a direita.

**A contagem de chaves não pega isso** (removi um `{}` inteiro, então ela
continuou balanceada, e foi o que eu conferi na hora). O que pega é olhar o que
ficou ANTES do trecho editado — mesma lição já registrada na v5.11.1: recorte
por âncora textual se verifica pelo que SOBROU, não pelo que saiu.

**2. Os ícones nunca estiveram centrados por regra própria.** `.rp-editor-btn`
não declarava centragem nenhuma, e a folha do Zabbix estiliza `button` (0-0-1)
com `padding: 0 11px` — 11px de cada lado dentro de um botão de 26px de largura.
Sem a barra verde o desalinhamento era menor, mas continuava. Agora é
`inline-flex` centrado, com `padding: 0` e `line-height: 1` explícitos, e o
glifo com `width: 1em` para os quatro ícones ficarem no mesmo eixo (o Font
Awesome varia a largura por glifo). Entrou junto a neutralização de
`button:focus`, a mesma da v5.5.2 — senão o botão fica azul sólido depois do
clique, com cara de "ligado".

**3. `_h` listava protótipo de host.** A busca filtrava só `status IN (0,1)`, e
protótipo passa nisso: neste ambiente eram **130 resultados possíveis para 4
hosts reais**, com nomes que são a macro não resolvida
(`{#AWS.EC2.INSTANCE.ID}`, `Task Replication {#DMS_TASK_ID}`).

O que separa host de protótipo é `flags`, e a condição correta é a **lista
positiva `IN (0, 4)`** — NORMAL e DISCOVERY_CREATED —, não uma exclusão de
`flags = 2`. O bit de protótipo aparece sozinho **e somado ao 4**
(`flags = 6`, PROTOTYPE_CREATED): aqui são 100 linhas com 2 e 24 com 6, e a
exclusão simples deixaria essas 24 passarem. É a mesma condição que o
`CHost.php` da API usa.

**Grupo de host não precisa do mesmo tratamento**, e isso foi conferido em vez
de suposto: protótipo de grupo mora em `group_prototype`, não em `hstgrp` —
nenhum nome de grupo neste banco contém macro. Os `flags = 4` de `hstgrp` são
grupos DESCOBERTOS, que são reais e devem aparecer.

**4. Achado ao investigar, com a hipótese errada pelo caminho.** Supus que
protótipos também inflassem a cota de 500 hosts do `host_filter` e escrevi o
JOIN com essa justificativa. A medição desmentiu: **zero** protótipos em
`hosts_groups` (411 ids com e sem o filtro de flags). Quem infla ali é
**TEMPLATE** — template é linha de `hosts` com `status = 3` e está em
`hosts_groups` como qualquer host. Com `status IN (0,1)` a lista cai de **411
para 4**. O filtro ficou, com a condição certa e a justificativa medida:
estourar a cota troca a lista literal — o caminho rodado em produção — pela
subconsulta, sem que houvesse host de verdade para justificar.

Fica o registro do erro de método: a primeira versão do comentário afirmava um
número que os dados contradiziam. Comentário que explica o porquê vale tanto
quanto o número que ele cita.

### Backlog conhecido

- ~~Salvar vínculo analista→turno em massa~~ — **resolvido em 2026-08-19**:
  barra de seleção em Gerenciar Turnos aplica um turno a vários analistas de
  uma vez.
- ~~`install.sh` gerava `/etc/cron.d/plantonistas-presence` sem env nenhuma~~ —
  **corrigido em 2026-08-19**: os três blocos de cron do `scripts/install.sh`
  (docker, RHEL e Ubuntu) passaram a escrever `DB_HOST`/`DB_NAME`/`DB_USER`/
  `DB_PASS` no arquivo, com `chmod 600` em vez de 644, porque ali tem senha.
  (Correção de registro: uma versão anterior desta nota dizia que o
  `install.sh` não existia mais no repo. Existe — está em `scripts/`, não na
  raiz, que foi onde procurei.)
- ~~Chart.js estático vs F5~~ — **resolvido em 2026-08-20**: a env
  `PLANTONISTAS_CHART_INLINE` embute a biblioteca, e a falha deixou de ser
  silenciosa.
- ~~Limpeza opcional de `role_rule` órfãs~~ — **o SQL do README estava errado e
  foi corrigido em 2026-08-20** (a coluna é `value_moduleid`, não `value_str`).
- ~~`scripts/install.sh` só tem caminho MySQL~~ — **resolvido em 2026-08-24**:
  o script agora lê `zabbix.conf.php` e detecta o dialeto sozinho (ou
  pergunta, se não conseguir ler), com `psql`/`mysql` escolhidos conforme o
  banco. (PostgreSQL foi **homologado em produção** em 2026-08-31 — o passo 7
  da issue #1, que esta nota dizia estar pendente, está fechado.)
- ~~Caminho de instalação do módulo defasado (`/usr/share/zabbix/ui/modules`
  hardcoded)~~ — **resolvido em 2026-08-24**: `detect_frontend_dir()` testa
  `/usr/share/zabbix/modules` (caminho real de produção deste projeto, ver
  seção 1) antes de `/usr/share/zabbix/ui/modules` (layout dos pacotes
  oficiais do Zabbix 7.0 mais recentes) — os dois são aceitos, sem exigir
  resposta manual.
- ~~Cron não agenda em Amazon Linux (sem `/etc/cron.d`)~~ — **resolvido em
  2026-08-24**: `install_scheduled_job()` cai para `crontab` do usuário e,
  na ausência dos dois, gera unidades systemd (`.service`+`.timer`) —
  cobre os três crons do módulo (presença, sincronismo de escalonamento,
  fila de menções), não só o de presença.
- **Pendências da auditoria de 2026-08-31** (não aplicadas por exigirem
  regeneração dos `sql/*.sql`, que precisa de `php scripts/gen_schema.php`):
  guardar `author_is_superadmin`/`author_group_ids` em colunas próprias de
  `module_plantonistas_shift_reports` — hoje `findClosedReport()` lê o
  `report_json` (LONGTEXT) de TODOS os fechamentos da data só para decidir
  visibilidade e depois descarta; e índice composto `(shift_date, shift_id)`
  em `module_plantonistas_shift_notes`, que hoje só tem `(shift_date,
  shift_name)` e `(shift_id)` enquanto o caminho normal filtra pelos dois.
  Editar `Schema.php` sem regenerar os `sql/*.sql` cria divergência entre
  instalação nova e migrada — o defeito que o gerador existe para evitar.
- Também da mesma auditoria, **não** aplicado por ser refactor grande sem ganho
  funcional: extrair um renderizador único para as 4 tabelas de alarme. Hoje o
  `TurnosReportPdf` reimplementa ~500 linhas da view e a própria view repete o
  mesmo `<tr>` quatro vezes — uma coluna nova é seis edições. Vale fazer, mas
  numa janela em que dê para revalidar tela e PDF lado a lado.
- Suíte de testes automatizados (`tests/`) foi escrita e revisada
  manualmente linha a linha contra o código-fonte, mas **ainda não foi
  executada** — o shell desta sessão ficou indisponível o tempo todo
  (infraestrutura do ambiente, não do módulo). Rodar `php tests/run.php`
  é o próximo passo antes de confiar nela como regressão.
- `TurnosNotesSave::resolveShiftName()` devolve `'24h'` quando o turno
  cadastrado não existe mais, em vez de preservar o vínculo. A nota escrita
  depois da remoção do turno muda de "balde" e, na tela de lista, vira um
  turno "24h aberto" que nunca existiu. Raro (exige remover turno com
  movimento) mas é linha que convida a refechar por engano.
- Tela de lista de repasses (2026-08-31, v5.3.0) não foi validada no lab.
  Conferir com dados reais: turno cadastrado e turno legado na mesma lista
  (é onde a divergência de `shift_name` entre as duas tabelas apareceria,
  como turno duplicado em aberto+fechado); turno fechado duas vezes (deve
  render duas linhas, `refeito #2` na de baixo); turno com nota e sem
  fechamento (deve aparecer como aberto); e a leitura por um usuário
  não-Super-Admin, para confirmar que o filtro de snapshot esconde da LISTA
  o que já esconde do PDF.
- Histórico de ações + Alarmes em Tratativas/Resolvidos (2026-08-28) não
  foram validados no lab — `queryInProgressAlerts()`/`queryResolvedAlerts()`/
  `queryEventActions()` dependem de `ZbxDb $db` (não é lógica pura, não entra
  na suíte de testes acima). Validar: as 4 tabelas do Repasse com dados reais
  de `acknowledges`/`alerts`, o PDF, e o documento de Fechar Turno com um
  turno que tenha pelo menos um ACK, uma mensagem, uma mudança de severidade
  e um fechamento manual — pra ver os 5 tipos de badge de uma vez.
- ~~Unificação visual das duas famílias~~ — **resolvido em 2026-08-19**: as
  duas seguem o tema do Zabbix, paleta única em `views/_theme.php`.
- ~~CSV import/export da Escala não sabe de turnos~~ — **resolvido em
  2026-08-19**: coluna Turno nos dois sentidos, arquivo antigo continua
  importando em modo legado com aviso.
- Diário de Bordo sem expiração — **decisão: não expira**, é histórico
  permanente. A consulta de crescimento está em `sql/queries.<banco>.sql`;
  arquivar é decisão de quem opera, não algo que o módulo faça sozinho.
- ~~Conexão mysqli própria (`getDb()`) reconectando a cada request~~ —
  **resolvido em 2026-08-19** pelo `ZbxDb` (ver "Fim da conexão mysqli
  própria").
- ~~`getUserRoleType()`/`resolveUserContext()` duplicados~~ — **resolvido em
  2026-08-19**: `TurnosNotesGet` passou a usar o trait.
- ~~`PhonesSave` faz SELECT-then-UPDATE-or-INSERT (janela de corrida)~~ —
  **resolvido em 2026-08-19**: virou upsert via `SqlFn::upsert()`, a mesma
  forma do `PhonesImport`.
- ~~`readCsv()` duplicado entre `PlantaoImport` e `PhonesImport`~~ —
  **resolvido em 2026-08-19**: trait `SpreadsheetReader`.
- ~~Importação de telefones aceita só CSV~~ — **resolvido em 2026-08-19**: o
  leitor saiu do `PlantaoImport` para o trait `SpreadsheetReader` e as duas
  telas aceitam CSV e XLSX.
- ~~Telefones: Admin/User só se veem dentro do mesmo `roleid`~~ — **era bug,
  corrigido em 2026-08-19**; ver "Telefones: o filtro por papel era bug".
- ~~Nome duplicado (`name` + `surname` concatenados às cegas)~~ — **resolvido
  em 2026-08-19** nas duas famílias; ver "Nome duplicado: fim dos CONCAT crus".
  O que sobrou de `CONCAT` em SQL é só `ORDER BY`/busca, onde a duplicação não
  aparece na tela.
- ~~Notificação de menção síncrona dentro do save da nota~~ e ~~sem dedupe~~ —
  **resolvidos em 2026-08-19** pela fila (`scripts/cron_notify_mentions.php`);
  ver "Menção: o envio virou fila por cron".
- Editor rico do Diário de Bordo: imagem e anexo **não são suportados por
  decisão** e agora são bloqueados explicitamente, com aviso. ~~Sem histórico
  de menções lidas~~ — resolvido em 2026-08-19 (painel no Repasse).
- ~~Salvamento de Escala/Telefones renderiza a página de destino duas vezes~~
  e ~~os dois imports ainda usam `form.submit()` nativo~~ — **resolvidos em
  2026-08-20**: as cinco actions de escrita respondem JSON em request AJAX;
  ver "Actions de escrita respondem JSON em AJAX".

---

## 3. Instruções

### Regras de código (Zabbix 7.0 — sempre carregar a skill `zabbix-module-dev`)

- Toda action de página no manifest **precisa** de `"view"` explícita — sem ela
  a página renderiza em branco, sem erro e sem log (bug mais traiçoeiro).
- Funções globais de DB com `\` dentro de namespace: `\DBselect`, `\DBfetch`,
  `\DBexecute`, `\DBstart`/`\DBend` (nunca DBbegin/DBcommit), `\zbx_dbstr`
  (DBquote NÃO existe).
- Permissão: `$this->getUserType()` ou `CWebUser::getType()` — **nunca** SQL em
  `users.type` (coluna não existe; falha silenciosa vira "Acesso negado").
- AJAX: action sempre na query string (`zabbix.php?action=...`), nunca no body.
- JS inline nas views (F5). Emoji/FontAwesome local pra ícones internos; `zi-*` só no menu.
- MariaDB com `ONLY_FULL_GROUP_BY`: usar `MIN()`, não `ANY_VALUE()`.

### Convenções deste módulo

- Actions novas: prefixo `plantonistas.`; tabelas novas: `module_plantonistas_`;
  registrar no manifest e, se página, no menu do Module.php.
- Prefixo CSS por tela (`plt-`, `rp-`, `ov-`, `phn-`, `rpl-`) — não misturar
  famílias.
- Texto de UI e mensagens em PT-BR; logs com prefixo `[plantonistas]`.
- Action nova que **escreve** no banco: nada de `disableCsrfValidation()`; o
  caller tem que ser POST e mandar `_csrf_token` gerado com
  `\CCsrfTokenHelper::get('<action completa>')`. Action alcançável por **GET**
  (página, filtro, export, PDF) mantém `disableCsrfValidation()` — em GET a
  validação falha, não é dispensada.
- Nunca engolir exceção de DB em silêncio: `error_log` + UI distinguindo
  "vazio de verdade" de "consulta falhou". Na família repasse, `prepare()`,
  `bind_param()` e `execute()` ficam **juntos dentro** do try — quem lança
  agora é o `execute()` (ver "Fim da conexão mysqli própria").
- Consulta nova na família repasse passa pelo `ZbxDb` (`$db->prepare(...)` com
  `?` + `bind_param`), nunca por conexão própria nem por SQL interpolado à mão.
- Nome de usuário para exibir nunca é `CONCAT(name,' ',surname)`: usa o trait
  `UserLabel` (família escala) ou `formatUserLabel()` (família repasse), sempre
  montado no controller, nunca na view.
- Informação acessória (turno, rótulo, enfeite) não entra por JOIN na query
  do dado principal: se a tabela do enfeite não existir naquele ambiente, o
  dado principal desaparece inteiro. Query separada + fallback na UI.
- Migrações: sempre idempotentes dentro do `migrateSchema()`; nunca DROP de
  tabela com dados; RENAME/ALTER guardados por INFORMATION_SCHEMA.
- Action AJAX (layout `layout.javascript`) que imprime JSON **termina com
  `die()`** — em geral `$db->close(); die();`. Sem isso o
  `ZBase::processResponseFinal()` encontra a action sem `setResponse()`, lança
  "Unexpected response for action …" DEPOIS do JSON impresso, e o corpo vira
  JSON + HTML: o `r.json()` do navegador estoura e a tela mostra "Erro de
  conexão" sem nada no log do PHP (ver a entrada da v5.10.4).
- Gráfico do Chart.js: cor e fonte não vêm de CSS (canvas não herda) — fonte e
  tinta saem de `Chart.defaults` + `TurnosReportBase::graphTheme()`, que lê a
  tabela `graph_theme` do tema ativo. E `generateLabels()` customizado tem de
  devolver `fontColor` em cada item: a legenda pinta com a cor DO ITEM, e sem
  ela o texto sai preto sem erro nenhum (ver a entrada da v5.4.4).
- Script de shell do módulo roda com `set -euo pipefail`, e ali **cano com
  consumidor que sai cedo é proibido** (`| head`, `| grep -q`): quem escreve
  antes leva SIGPIPE e o `pipefail` reprova o cano inteiro — às vezes, por
  corrida, que é o que torna o defeito caro (ver a entrada da v5.11.4).
  Capture em variável e recorte nela.
- Diff mínimo: em refactors, não renomear classes/arquivos sem necessidade.
- Commits em PT-BR, prefixo `fix:`/`feat:`/`docs:`, corpo explicando o porquê.

### Deploy e debug

- Deploy sempre nos **dois** frontends: `git pull` + `chown -R apache:apache .`
  + `systemctl restart php-fpm` em cada um (LB serve código velho de nó esquecido).
- `git pull` como root deixa dono root → sempre chown depois.
- Erro 500 sem rastro: `catch_workers_output = yes` no pool
  (`/etc/php-fpm.d/zabbix.conf`) e tail no log do FPM.
- Tela em branco com menu ok = falta `"view"` no manifest. "Acesso negado"
  indevido = checkPermissions com query quebrada. Grupo "vazio" na tela de
  turnos = ver `[plantonistas]` no log antes de assumir que é dado.
- Testar no lab-zbx antes de produção; validar as 7 telas após qualquer deploy.
- Rollback da unificação: desabilitar Plantonistas ANTES de rodar
  `sql/rollback-to-old-modules.<banco>.sql`, depois reabilitar os antigos (README).
