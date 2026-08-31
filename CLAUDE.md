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

Resultado: **Plantonistas v4.0.0** — id `plantonistas`, namespace `Modules\Plantonistas`,
repo `leaoereno/module-zbx-plantonistas`. Rebranding completo (decisão do Rafael):
18 actions `plantonistas.*`, 8 tabelas `module_plantonistas_*`. De-para completo
de actions e tabelas está no README.md.

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
(Guest não vê nada), mas **User (1) só enxerga dois itens: Visão Geral e
Repasse Plantão**. Escala, Histórico, Telefones e Gerenciar Turnos são
Admin (2)+ — commit `9959722`, ver abaixo.

| Tela | User (1) | Admin (2) | Super Admin (3) |
|---|---|---|---|
| Visão Geral | Vê só os próprios grupos | idem User | Todos os grupos |
| Escala / Histórico | **Sem acesso** (menu não aparece; `checkPermissions()` recusa) | Vê e edita só os próprios grupos | Todos os grupos |
| Telefones | **Sem acesso** (idem) | Vê quem compartilha **pelo menos um grupo** (edita quem não tem papel mais alto) | Todos os usuários habilitados do sistema |
| Repasse Plantão (relatório) | Eventos seguem `rights` do Zabbix; MTTA só o próprio; Notas/Presença só do(s) próprio(s) grupo(s) | MTTA de todos; Notas/Presença do(s) próprio(s) grupo(s) | Sem filtro nenhum |
| Diário de Bordo (escrever) | Pode escrever nota | idem | idem |
| Gerenciar Turnos | **Sem acesso** (idem) | Só as próprias equipes | Todas as equipes com ≥1 membro |

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

O que **falta**: homologar. Nada disso foi executado contra um PostgreSQL de
verdade.

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
  banco. PostgreSQL continua **não homologado** (ver Roadmap) — o script
  aceita, mas o passo 7 da issue #1 segue pendente.
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
